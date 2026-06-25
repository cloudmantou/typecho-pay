<?php

namespace TypechoPlugin\TypechoPay\Support;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class Money
{
    public static function assertAmount($amount): int
    {
        $value = filter_var($amount, FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0) {
            throw new \InvalidArgumentException('Invalid payment amount.');
        }

        return (int) $value;
    }

    public static function assertCurrency($currency): string
    {
        $value = strtoupper(trim((string) $currency));
        if (!in_array($value, ['CNY', 'JPY'], true)) {
            throw new \InvalidArgumentException('Unsupported payment currency.');
        }

        return $value;
    }

    public static function cnyFenToYuan(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }
}
