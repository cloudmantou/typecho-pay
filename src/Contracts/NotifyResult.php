<?php

namespace TypechoPlugin\TypechoPay\Contracts;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class NotifyResult
{
    public string $status;
    public string $outTradeNo;
    public ?string $platformTradeNo;
    public ?int $amount;
    public ?string $currency;
    public bool $signatureOk;
    public array $raw;

    public function __construct(
        string $status,
        string $outTradeNo,
        ?string $platformTradeNo,
        ?int $amount,
        ?string $currency,
        bool $signatureOk,
        array $raw = []
    ) {
        $this->status = $status;
        $this->outTradeNo = $outTradeNo;
        $this->platformTradeNo = $platformTradeNo;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->signatureOk = $signatureOk;
        $this->raw = $raw;
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
