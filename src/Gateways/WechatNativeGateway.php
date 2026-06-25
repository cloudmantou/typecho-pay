<?php

namespace TypechoPlugin\TypechoPay\Gateways;

use TypechoPlugin\TypechoPay\Contracts\GatewayInterface;
use TypechoPlugin\TypechoPay\Contracts\NotifyResult;
use TypechoPlugin\TypechoPay\Contracts\PayCreateResult;
use TypechoPlugin\TypechoPay\Support\HttpHeaders;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class WechatNativeGateway extends AbstractGateway implements GatewayInterface
{
    public function create(array $order): PayCreateResult
    {
        $this->requireConfig(['wechatAppId', 'wechatMchId', 'wechatMerchantSerial', 'wechatPrivateKeyPath']);
        if (strtoupper((string) $order['currency']) !== 'CNY') {
            throw new \InvalidArgumentException('WeChat Pay orders must use CNY.');
        }

        if (!class_exists('\\WeChatPay\\Builder') || !class_exists('\\WeChatPay\\Crypto\\Rsa')) {
            throw new \RuntimeException('Install wechatpay/wechatpay before creating WeChat Pay orders.');
        }

        $privateKey = \WeChatPay\Crypto\Rsa::from('file://' . $this->config['wechatPrivateKeyPath'], \WeChatPay\Crypto\Rsa::KEY_TYPE_PRIVATE);
        $certs = [];
        if ($this->config['wechatPlatformPublicKeyPath'] !== '' && $this->config['wechatPlatformSerial'] !== '') {
            $certs[$this->config['wechatPlatformSerial']] = \WeChatPay\Crypto\Rsa::from(
                'file://' . $this->config['wechatPlatformPublicKeyPath'],
                \WeChatPay\Crypto\Rsa::KEY_TYPE_PUBLIC
            );
        }

        $client = \WeChatPay\Builder::factory([
            'mchid' => $this->config['wechatMchId'],
            'serial' => $this->config['wechatMerchantSerial'],
            'privateKey' => $privateKey,
            'certs' => $certs,
        ]);

        $response = $client->chain('v3/pay/transactions/native')->post([
            'json' => [
                'mchid' => $this->config['wechatMchId'],
                'out_trade_no' => $order['out_trade_no'],
                'appid' => $this->config['wechatAppId'],
                'description' => $order['subject'],
                'notify_url' => $this->notifyUrl('wechat'),
                'amount' => [
                    'total' => (int) $order['amount'],
                    'currency' => 'CNY',
                ],
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid WeChat Pay response.');
        }

        return new PayCreateResult('qr', null, $data['code_url'] ?? null, null, $data);
    }

    public function notify(array $headers, string $rawBody, array $query, array $post): NotifyResult
    {
        $this->requireConfig(['wechatApiV3Key', 'wechatPlatformPublicKeyPath']);
        $signatureOk = $this->verifySignature($headers, $rawBody);
        $payload = json_decode($rawBody, true);
        if (!$signatureOk || !is_array($payload) || empty($payload['resource'])) {
            throw new \RuntimeException('Invalid WeChat Pay notification.');
        }

        $resource = $payload['resource'];
        $plain = $this->decryptResource(
            (string) $resource['ciphertext'],
            (string) $resource['nonce'],
            (string) ($resource['associated_data'] ?? '')
        );
        $transaction = json_decode($plain, true);
        if (!is_array($transaction)) {
            throw new \RuntimeException('Invalid WeChat Pay resource.');
        }

        $amount = $transaction['amount']['total'] ?? null;
        $currency = $transaction['amount']['currency'] ?? 'CNY';

        return new NotifyResult(
            ($transaction['trade_state'] ?? '') === 'SUCCESS' ? 'paid' : strtolower((string) ($transaction['trade_state'] ?? 'ignored')),
            (string) ($transaction['out_trade_no'] ?? ''),
            isset($transaction['transaction_id']) ? (string) $transaction['transaction_id'] : null,
            $amount === null ? null : (int) $amount,
            strtoupper((string) $currency),
            true,
            $transaction
        );
    }

    public function query(array $order): NotifyResult
    {
        throw new \RuntimeException('WeChat active query is not implemented in this plugin slice.');
    }

    private function verifySignature(array $headers, string $rawBody): bool
    {
        $timestamp = HttpHeaders::get($headers, 'wechatpay-timestamp');
        $nonce = HttpHeaders::get($headers, 'wechatpay-nonce');
        $signature = HttpHeaders::get($headers, 'wechatpay-signature');
        $serial = HttpHeaders::get($headers, 'wechatpay-serial');

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return false;
        }

        if ($this->config['wechatPlatformSerial'] !== '' && !hash_equals($this->config['wechatPlatformSerial'], $serial)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $publicKey = file_get_contents($this->config['wechatPlatformPublicKeyPath']);
        if ($publicKey === false) {
            return false;
        }

        $message = $timestamp . "\n" . $nonce . "\n" . $rawBody . "\n";
        return openssl_verify($message, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    private function decryptResource(string $ciphertext, string $nonce, string $associatedData): string
    {
        $decoded = base64_decode($ciphertext);
        $tag = substr($decoded, -16);
        $data = substr($decoded, 0, -16);
        $plain = openssl_decrypt($data, 'aes-256-gcm', $this->config['wechatApiV3Key'], OPENSSL_RAW_DATA, $nonce, $tag, $associatedData);

        if ($plain === false) {
            throw new \RuntimeException('Failed to decrypt WeChat Pay resource.');
        }

        return $plain;
    }
}
