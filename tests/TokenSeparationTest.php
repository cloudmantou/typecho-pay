<?php

/**
 * Tests for P0 #1: Token separation — poll_token, return_token, delivery_token.
 *
 * Verifies that:
 * - poll_token is only for frontend polling (never sent to payment platforms)
 * - return_token is one-time use for payment platform redirects
 * - delivery_token is long-lived for revisiting the card delivery page
 * - Gateway create() only receives return_token, not poll_token
 */

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__, 4));

$root = dirname(__DIR__);
require_once $root . '/Support/Signer.php';

use TypechoPlugin\TypechoPay\Support\Signer;

$passed = 0;
$failed = 0;

function tt_assert(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo '.';
    } else {
        $failed++;
        echo "\nFAIL: {$label}\n";
    }
}

// ---- Test 1: Signer still works with the new payload format ----
$secret = 'test-secret-key-for-tokens';
$payload = ['product_id' => '42', 'return_to' => 'https://example.com/post/1', 'gateway' => 'alipay'];
$signature = Signer::sign($payload, $secret);
tt_assert(Signer::verify($payload, $secret, $signature), 'Signer::sign/verify works with product payload');

// ---- Test 2: Tampered payload fails verification ----
$tampered = $payload;
$tampered['product_id'] = '99';
tt_assert(!Signer::verify($tampered, $secret, $signature), 'Tampered product_id fails verification');

// ---- Test 3: Token format validation ----
$token = bin2hex(random_bytes(32));
tt_assert(preg_match('/^[a-f0-9]{64}$/', $token) === 1, 'Generated token is 64 hex chars');
tt_assert(strlen($token) === 64, 'Token length is exactly 64');

// ---- Test 4: Token hash is SHA-256 ----
$hash = hash('sha256', $token);
tt_assert(strlen($hash) === 64, 'SHA-256 hash is 64 hex chars');
tt_assert(hash_equals($hash, hash('sha256', $token)), 'hash_equals matches for same token');

// ---- Test 5: Different tokens have different hashes ----
$token2 = bin2hex(random_bytes(32));
$hash2 = hash('sha256', $token2);
tt_assert($hash !== $hash2, 'Different tokens produce different hashes');

// ---- Test 6: return_token format validation regex ----
tt_assert(preg_match('/^[a-f0-9]{64}$/', $token) === 1, 'Valid return_token passes regex');
tt_assert(preg_match('/^[a-f0-9]{64}$/', 'not-hex-at-all') === 0, 'Invalid return_token fails regex');
tt_assert(preg_match('/^[a-f0-9]{64}$/', str_repeat('a', 63)) === 0, 'Short return_token fails regex');
tt_assert(preg_match('/^[a-f0-9]{64}$/', str_repeat('a', 65)) === 0, 'Long return_token fails regex');

// ---- Test 7: Verify Alipay Page/Wap Pay use return_token not poll_token ----
$alipaySource = file_get_contents($root . '/Gateways/AlipayGateway.php');
tt_assert(strpos($alipaySource, 'return_token') !== false, 'AlipayGateway references return_token');

$alipayCreateMethods = '';
foreach (['createPagePay', 'createWapPay'] as $methodName) {
    if (preg_match('/private function ' . $methodName . '\(.*?^    \}/ms', $alipaySource, $m)) {
        $alipayCreateMethods .= "\n" . $m[0];
    }
}
tt_assert(strpos($alipayCreateMethods, 'poll_token') === false, 'Alipay payment creation does NOT use poll_token');
tt_assert(substr_count($alipayCreateMethods, 'return_token') >= 2, 'Alipay Page Pay and Wap Pay use return_token');

// ---- Test 8: Verify WeChat Native gateway never sends poll_token upstream ----
$wechatSource = file_get_contents($root . '/Gateways/WechatNativeGateway.php');

$createMethod = '';
if (preg_match('/public function create\(array \$order\).*?^    \}/ms', $wechatSource, $m)) {
    $createMethod = $m[0];
}
tt_assert(strpos($createMethod, 'poll_token') === false, 'WechatNativeGateway::create() does NOT use poll_token');

// ---- Test 9: Verify OrderService exposes only atomic return-token consumption ----
$orderServiceSource = file_get_contents($root . '/Services/OrderService.php');
tt_assert(strpos($orderServiceSource, 'function verifyPollToken') !== false, 'OrderService has verifyPollToken');
tt_assert(strpos($orderServiceSource, 'function verifyReturnToken') === false, 'OrderService does not expose non-atomic verifyReturnToken');
tt_assert(strpos($orderServiceSource, 'function consumeReturnToken') !== false, 'OrderService has atomic consumeReturnToken');
tt_assert(strpos($orderServiceSource, 'function verifyDeliveryToken') !== false, 'OrderService has verifyDeliveryToken');
tt_assert(strpos($orderServiceSource, 'function rotateDeliveryToken') !== false, 'OrderService can rotate delivery tokens');

// ---- Test 10: return_token is one-time ----
tt_assert(strpos($orderServiceSource, 'return_token_used') !== false, 'OrderService tracks return_token_used');
tt_assert(strpos($orderServiceSource, 'return_token_expires_at > ?') !== false, 'Return token consumption checks expiry');
tt_assert(strpos($orderServiceSource, 'return_token_hash = ?') !== false, 'Return token consumption checks token hash atomically');

// ---- Test 11: Action.php sets security headers ----
$actionSource = file_get_contents($root . '/Action.php');
tt_assert(strpos($actionSource, 'Referrer-Policy') !== false, 'Action sets Referrer-Policy');
tt_assert(strpos($actionSource, 'X-Robots-Tag') !== false, 'Action sets X-Robots-Tag');
tt_assert(strpos($actionSource, 'X-Frame-Options') !== false, 'Action sets X-Frame-Options');
tt_assert(strpos($actionSource, 'X-Content-Type-Options') !== false, 'Action sets X-Content-Type-Options');

// ---- Test 12: Delivery page uses HttpOnly cookie ----
tt_assert(strpos($actionSource, '__typechopay_delivery') !== false, 'Action sets delivery cookie');
tt_assert(strpos($actionSource, 'httponly') !== false || strpos($actionSource, 'HttpOnly') !== false, 'Cookie is HttpOnly');

// ---- Test 13: delivery() checks delivery_token ----
tt_assert(strpos($actionSource, 'verifyDeliveryToken') !== false, 'Action::delivery() verifies delivery_token');
tt_assert(strpos($actionSource, 'consumeReturnToken') !== false, 'Payment return atomically consumes return_token');
tt_assert(strpos($actionSource, 'rotateDeliveryToken') !== false, 'Payment return rotates delivery_token');
tt_assert(strpos($actionSource, 'redirectSeeOther') !== false, 'Payment return redirects to clean delivery URL with 303');
tt_assert(strpos($actionSource, "do=delivery&out_trade_no=' . rawurlencode((string) \$order['out_trade_no'])\n            . (\$deliveryToken") === false, 'Delivery refresh URL does not include delivery_token');

// ---- Summary ----
echo "\n\n--- TokenSeparationTest ---\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
