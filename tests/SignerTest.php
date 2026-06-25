<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__, 4));

require_once dirname(__DIR__) . '/src/Support/Signer.php';

use TypechoPlugin\TypechoPay\Support\Signer;

$payload = [
    'amount' => '500',
    'currency' => 'JPY',
    'subject' => 'AppFlex 30 day',
    'biz_type' => 'post',
    'biz_id' => '100',
    'gateway' => 'paypay',
    'return_to' => 'https://example.com/post/100.html',
    'ts' => '1780000000',
    'nonce' => '1234567890abcdef',
];

$secret = 'test-secret';
$signature = Signer::sign($payload, $secret);

if (Signer::verify($payload, $secret, $signature) !== true) {
    fwrite(STDERR, "Expected valid signature\n");
    exit(1);
}

$tampered = $payload;
$tampered['amount'] = '1';

if (Signer::verify($tampered, $secret, $signature) !== false) {
    fwrite(STDERR, "Expected tampered signature to fail\n");
    exit(1);
}

$tamperedGateway = $payload;
$tamperedGateway['gateway'] = 'wechat';

if (Signer::verify($tamperedGateway, $secret, $signature) !== false) {
    fwrite(STDERR, "Expected tampered gateway to fail\n");
    exit(1);
}

echo "SignerTest passed\n";
