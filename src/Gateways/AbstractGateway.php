<?php

namespace TypechoPlugin\TypechoPay\Gateways;

use Typecho\Common;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

abstract class AbstractGateway
{
    protected array $config;
    protected Options $options;

    public function __construct(array $config, Options $options)
    {
        $this->config = $config;
        $this->options = $options;
    }

    protected function requireConfig(array $keys)
    {
        foreach ($keys as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                throw new \RuntimeException('Missing gateway config: ' . $key);
            }
        }
    }

    protected function notifyUrl(string $gateway): string
    {
        return Common::url('/action/typechopay?do=notify&gateway=' . rawurlencode($gateway), $this->options->index);
    }

    protected function returnUrl(string $gateway): string
    {
        return Common::url('/action/typechopay?do=return&gateway=' . rawurlencode($gateway), $this->options->index);
    }
}
