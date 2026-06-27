<?php

namespace TypechoPlugin\TypechoPay\Support;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class AlipaySdk
{
    public static function ensureAop(array $requestClasses = []): void
    {
        if (!class_exists('\\AopClient', false)) {
            self::requireFile('AopClient.php');
        }

        foreach ($requestClasses as $class) {
            if (!class_exists('\\' . $class, false)) {
                self::requireFile('request/' . $class . '.php');
            }
        }
    }

    private static function requireFile(string $relativePath): void
    {
        $roots = [
            dirname(__DIR__) . '/vendor/alipaysdk/openapi/v2/aop',
            dirname(__DIR__) . '/vendor/alipay/alipay-sdk-php-all/v2/aop',
        ];

        foreach ($roots as $root) {
            $path = $root . '/' . $relativePath;
            if (is_file($path)) {
                self::requireSdkFile($path);
                return;
            }
        }

        throw new \RuntimeException('Install alipaysdk/openapi before using Alipay.');
    }

    private static function requireSdkFile(string $path): void
    {
        $previous = error_reporting();

        try {
            error_reporting($previous & ~E_DEPRECATED & ~E_USER_DEPRECATED);
            require_once $path;
        } finally {
            error_reporting($previous);
        }
    }
}
