<?php

namespace TypechoPlugin\TypechoPay\Gateways;

use TypechoPlugin\TypechoPay\Contracts\GatewayInterface;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class GatewayFactory
{
    public static function make(string $gateway, array $config, Options $options): GatewayInterface
    {
        switch ($gateway) {
            case 'paypay':
                return new PayPayGateway($config, $options);
            case 'wechat':
                return new WechatNativeGateway($config, $options);
            case 'alipay':
                return new AlipayGateway($config, $options);
            default:
                throw new \InvalidArgumentException('Unsupported payment gateway.');
        }
    }
}
