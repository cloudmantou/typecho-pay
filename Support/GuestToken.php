<?php

namespace TypechoPlugin\TypechoPay\Support;

use Typecho\Cookie;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class GuestToken
{
    private const COOKIE = '__typechopay_guest';
    private const TTL = 2592000;

    public static function getOrCreate(): string
    {
        $token = self::get();
        if ($token !== null) {
            return $token;
        }

        $token = bin2hex(random_bytes(16));
        self::setCookie($token);

        return $token;
    }

    public static function get(): ?string
    {
        $token = Cookie::get(self::COOKIE);
        if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        return $token;
    }

    public static function hash(?string $token): ?string
    {
        return $token === null ? null : hash('sha256', $token);
    }

    private static function setCookie(string $token): void
    {
        $name = Cookie::getPrefix() . self::COOKIE;
        $_COOKIE[$name] = $token;

        if (headers_sent()) {
            return;
        }

        $domain = Cookie::getDomain();
        $options = [
            'expires' => time() + self::TTL,
            'path' => Cookie::getPath(),
            'secure' => Cookie::getSecure() || self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        if ($domain !== '') {
            $options['domain'] = $domain;
        }

        setcookie($name, $token, $options);
    }

    private static function isHttps(): bool
    {
        $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        $requestScheme = strtolower(trim((string) ($_SERVER['REQUEST_SCHEME'] ?? '')));
        if ($requestScheme === 'https') {
            return true;
        }

        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        if ($forwardedProto === 'https') {
            return true;
        }

        $forwardedScheme = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SCHEME'] ?? $_SERVER['HTTP_X_URL_SCHEME'] ?? '')));
        if ($forwardedScheme === 'https') {
            return true;
        }

        $forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
        if (in_array($forwardedSsl, ['on', '1', 'true'], true)) {
            return true;
        }

        if ((string) ($_SERVER['HTTP_X_FORWARDED_PORT'] ?? '') === '443') {
            return true;
        }

        $forwarded = (string) ($_SERVER['HTTP_FORWARDED'] ?? '');
        return preg_match('/(?:^|[;,]\s*)proto="?https"?/i', $forwarded) === 1;
    }
}
