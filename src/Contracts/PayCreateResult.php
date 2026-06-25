<?php

namespace TypechoPlugin\TypechoPay\Contracts;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class PayCreateResult
{
    public string $type;
    public ?string $payUrl;
    public ?string $qrContent;
    public ?string $html;
    public array $raw;

    public function __construct(string $type, ?string $payUrl = null, ?string $qrContent = null, ?string $html = null, array $raw = [])
    {
        $this->type = $type;
        $this->payUrl = $payUrl;
        $this->qrContent = $qrContent;
        $this->html = $html;
        $this->raw = $raw;
    }
}
