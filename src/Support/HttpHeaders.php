<?php

namespace TypechoPlugin\TypechoPay\Support;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class HttpHeaders
{
    /**
     * @return array
     */
    public static function fromServer(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    public static function get(array $headers, string $name): string
    {
        return (string) ($headers[strtolower($name)] ?? '');
    }
}
