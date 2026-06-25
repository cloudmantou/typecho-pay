<?php

namespace TypechoPlugin\TypechoPay\Gateways;

use TypechoPlugin\TypechoPay\Contracts\GatewayInterface;
use TypechoPlugin\TypechoPay\Contracts\NotifyResult;
use TypechoPlugin\TypechoPay\Contracts\PayCreateResult;
use TypechoPlugin\TypechoPay\Support\Money;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class AlipayGateway extends AbstractGateway implements GatewayInterface
{
    public function create(array $order): PayCreateResult
    {
        $this->requireConfig(['alipayAppId', 'alipayPrivateKey', 'alipayPublicKey']);
        if (strtoupper((string) $order['currency']) !== 'CNY') {
            throw new \InvalidArgumentException('Alipay orders must use CNY.');
        }

        if (!class_exists('\\AopClient')) {
            throw new \RuntimeException('Install Alipay PHP SDK before creating Alipay orders.');
        }

        $client = $this->aopClient();
        if ($this->config['alipayMode'] === 'precreate') {
            if (!class_exists('\\AlipayTradePrecreateRequest')) {
                throw new \RuntimeException('AlipayTradePrecreateRequest class is missing.');
            }

            $request = new \AlipayTradePrecreateRequest();
            $request->setNotifyUrl($this->notifyUrl('alipay'));
            $request->setBizContent(json_encode([
                'out_trade_no' => $order['out_trade_no'],
                'total_amount' => Money::cnyFenToYuan((int) $order['amount']),
                'subject' => $order['subject'],
            ], JSON_UNESCAPED_UNICODE));

            $response = $client->execute($request);
            $data = json_decode(json_encode($response), true);

            return new PayCreateResult(
                'qr',
                null,
                $data['alipay_trade_precreate_response']['qr_code'] ?? null,
                null,
                is_array($data) ? $data : []
            );
        }

        if (!class_exists('\\AlipayTradePagePayRequest')) {
            throw new \RuntimeException('AlipayTradePagePayRequest class is missing.');
        }

        $request = new \AlipayTradePagePayRequest();
        $request->setNotifyUrl($this->notifyUrl('alipay'));
        $request->setReturnUrl($this->returnUrl('alipay'));
        $request->setBizContent(json_encode([
            'out_trade_no' => $order['out_trade_no'],
            'product_code' => 'FAST_INSTANT_TRADE_PAY',
            'total_amount' => Money::cnyFenToYuan((int) $order['amount']),
            'subject' => $order['subject'],
        ], JSON_UNESCAPED_UNICODE));

        return new PayCreateResult('html', null, null, $client->pageExecute($request, 'POST'), []);
    }

    public function notify(array $headers, string $rawBody, array $query, array $post): NotifyResult
    {
        $this->requireConfig(['alipayAppId', 'alipayPrivateKey', 'alipayPublicKey']);
        $signatureOk = $this->aopClient()->rsaCheckV1($post, null, 'RSA2');
        $status = in_array($post['trade_status'] ?? '', ['TRADE_SUCCESS', 'TRADE_FINISHED'], true) ? 'paid' : 'ignored';
        $amount = isset($post['total_amount']) ? (int) round(((float) $post['total_amount']) * 100) : null;

        if (($post['app_id'] ?? '') !== $this->config['alipayAppId']) {
            $signatureOk = false;
        }

        if ($this->config['alipaySellerId'] !== '' && ($post['seller_id'] ?? '') !== $this->config['alipaySellerId']) {
            $signatureOk = false;
        }

        return new NotifyResult(
            $signatureOk ? $status : 'invalid_signature',
            (string) ($post['out_trade_no'] ?? ''),
            isset($post['trade_no']) ? (string) $post['trade_no'] : null,
            $amount,
            'CNY',
            $signatureOk,
            $post
        );
    }

    public function query(array $order): NotifyResult
    {
        throw new \RuntimeException('Alipay active query is not implemented in this plugin slice.');
    }

    private function aopClient()
    {
        $client = new \AopClient();
        $client->gatewayUrl = 'https://openapi.alipay.com/gateway.do';
        $client->appId = $this->config['alipayAppId'];
        $client->rsaPrivateKey = $this->config['alipayPrivateKey'];
        $client->alipayrsaPublicKey = $this->config['alipayPublicKey'];
        $client->signType = 'RSA2';
        $client->format = 'json';
        $client->charset = 'UTF-8';

        return $client;
    }
}
