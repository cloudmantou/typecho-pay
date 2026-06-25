<?php

namespace TypechoPlugin\TypechoPay\Contracts;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

interface GatewayInterface
{
    public function create(array $order): PayCreateResult;

    public function notify(array $headers, string $rawBody, array $query, array $post): NotifyResult;

    public function query(array $order): NotifyResult;
}
