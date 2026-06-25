<?php

namespace TypechoPlugin\TypechoPay\Gateways;

use Typecho\Http\Client;
use TypechoPlugin\TypechoPay\Contracts\GatewayInterface;
use TypechoPlugin\TypechoPay\Contracts\NotifyResult;
use TypechoPlugin\TypechoPay\Contracts\PayCreateResult;
use TypechoPlugin\TypechoPay\Support\HttpHeaders;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class PayPayGateway extends AbstractGateway implements GatewayInterface
{
    public function create(array $order): PayCreateResult
    {
        $this->requireConfig(['paypayApiKey', 'paypayApiSecret', 'paypayMerchantId']);
        if (strtoupper((string) $order['currency']) !== 'JPY') {
            throw new \InvalidArgumentException('PayPay orders must use JPY.');
        }

        $payload = [
            'merchantPaymentId' => $order['out_trade_no'],
            'amount' => [
                'amount' => (int) $order['amount'],
                'currency' => 'JPY',
            ],
            'codeType' => 'ORDER_QR',
            'orderDescription' => $order['subject'],
            'requestedAt' => time(),
            'redirectUrl' => $this->returnUrl('paypay') . '&out_trade_no=' . rawurlencode($order['out_trade_no']),
            'redirectType' => 'WEB_LINK',
            'isAuthorization' => false,
        ];

        $response = $this->request('POST', '/v2/codes', $payload);
        $data = $response['data'] ?? [];
        $payUrl = $data['url'] ?? ($data['deeplink'] ?? null);

        return new PayCreateResult('qr', $payUrl, $payUrl, null, $response);
    }

    public function notify(array $headers, string $rawBody, array $query, array $post): NotifyResult
    {
        $signatureOk = $this->verifyIncomingHmac($headers, $rawBody);
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid PayPay webhook payload.');
        }

        $event = $data['data'] ?? $data;
        $state = strtoupper((string) ($event['state'] ?? ''));
        $outTradeNo = (string) ($event['merchantPaymentId'] ?? ($event['merchant_order_id'] ?? ''));
        if ($outTradeNo === '') {
            throw new \RuntimeException('Missing PayPay merchant payment id.');
        }

        $amount = null;
        $currency = null;
        if (isset($event['amount']) && is_array($event['amount'])) {
            $amount = isset($event['amount']['amount']) ? (int) $event['amount']['amount'] : null;
            $currency = isset($event['amount']['currency']) ? strtoupper((string) $event['amount']['currency']) : null;
        }

        $status = $signatureOk && $state === 'COMPLETED' ? 'paid' : strtolower($state ?: 'ignored');

        return new NotifyResult(
            $status,
            $outTradeNo,
            isset($event['paymentId']) ? (string) $event['paymentId'] : null,
            $amount,
            $currency,
            $signatureOk,
            $data
        );
    }

    public function query(array $order): NotifyResult
    {
        $response = $this->request('GET', '/v2/payments/' . rawurlencode($order['out_trade_no']));
        $event = $response['data'] ?? [];
        $state = strtoupper((string) ($event['state'] ?? ''));
        $amount = null;
        $currency = null;
        if (isset($event['amount']) && is_array($event['amount'])) {
            $amount = isset($event['amount']['amount']) ? (int) $event['amount']['amount'] : null;
            $currency = isset($event['amount']['currency']) ? strtoupper((string) $event['amount']['currency']) : null;
        }

        return new NotifyResult(
            $state === 'COMPLETED' ? 'paid' : strtolower($state ?: 'pending'),
            $order['out_trade_no'],
            isset($event['paymentId']) ? (string) $event['paymentId'] : null,
            $amount,
            $currency,
            true,
            $response
        );
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $client = Client::get();
        if (!$client) {
            throw new \RuntimeException('PHP cURL extension is required for PayPay requests.');
        }

        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $contentType = $payload === null ? 'empty' : 'application/json;charset=UTF-8;';
        $authorization = $this->authorizationHeader($method, $path, $body, $contentType);

        $client->setTimeout(30)
            ->setHeader('Authorization', $authorization)
            ->setHeader('X-ASSUME-MERCHANT', $this->config['paypayMerchantId']);

        if ($payload !== null) {
            $client->setHeader('Content-Type', $contentType)
                ->setMultipart(false)
                ->setData($body, $method);
        } else {
            $client->setMethod($method);
        }

        $client->send($this->baseUrl() . $path);
        $response = json_decode($client->getResponseBody(), true);
        if (!is_array($response)) {
            throw new \RuntimeException('Invalid PayPay response.');
        }

        if ($client->getResponseStatus() >= 300) {
            throw new \RuntimeException('PayPay request failed: HTTP ' . $client->getResponseStatus());
        }

        return $response;
    }

    private function authorizationHeader(string $method, string $path, string $body, string $contentType): string
    {
        $nonce = bin2hex(random_bytes(4));
        $epoch = (string) time();
        $hash = $contentType === 'empty' ? 'empty' : base64_encode(md5($contentType . $body, true));
        $dataToSign = implode("\n", [$path, strtoupper($method), $nonce, $epoch, $contentType, $hash]);
        $mac = base64_encode(hash_hmac('sha256', $dataToSign, $this->config['paypayApiSecret'], true));

        return 'hmac OPA-Auth:' . $this->config['paypayApiKey'] . ':' . $mac . ':' . $nonce . ':' . $epoch . ':' . $hash;
    }

    private function verifyIncomingHmac(array $headers, string $rawBody): bool
    {
        $auth = HttpHeaders::get($headers, 'authorization');
        if (!preg_match('/^hmac OPA-Auth:([^:]+):([^:]+):([^:]+):([0-9]+):(.+)$/', $auth, $matches)) {
            return false;
        }

        if (!hash_equals($this->config['paypayApiKey'], $matches[1])) {
            return false;
        }

        $epoch = (int) $matches[4];
        if (abs(time() - $epoch) > 120) {
            return false;
        }

        $contentType = HttpHeaders::get($headers, 'content-type') ?: 'application/json;charset=UTF-8;';
        $hash = $rawBody === '' ? 'empty' : base64_encode(md5($contentType . $rawBody, true));
        if (!hash_equals($hash, $matches[5])) {
            return false;
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/action/typechopay', PHP_URL_PATH) ?: '/action/typechopay';
        $dataToSign = implode("\n", [$path, 'POST', $matches[3], $matches[4], $contentType, $hash]);
        $expected = base64_encode(hash_hmac('sha256', $dataToSign, $this->config['paypayApiSecret'], true));

        return hash_equals($expected, $matches[2]);
    }

    private function baseUrl(): string
    {
        switch ($this->config['paypayEnvironment']) {
            case 'production':
                return 'https://apigw.paypay.ne.jp';
            case 'staging':
                return 'https://apigw.stg.paypay.ne.jp';
            case 'sandbox':
            default:
                return 'https://apigw.sandbox.paypay.ne.jp';
        }
    }
}
