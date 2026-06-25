<?php

namespace TypechoPlugin\TypechoPay\Support;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class Signer
{
    /**
     * @param array $payload
     * @param string $secret
     * @return string
     */
    public static function sign(array $payload, string $secret): string
    {
        return hash_hmac('sha256', self::canonicalPayload($payload), $secret);
    }

    /**
     * @param array $payload
     * @param string $secret
     * @param string $signature
     * @return bool
     */
    public static function verify(array $payload, string $secret, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        return hash_equals(self::sign($payload, $secret), $signature);
    }

    private static function canonicalPayload(array $payload): string
    {
        unset($payload['signature'], $payload['gateway']);
        ksort($payload);

        $pairs = [];
        foreach ($payload as $key => $value) {
            $pairs[] = $key . '=' . (string) $value;
        }

        return implode('&', $pairs);
    }
}
