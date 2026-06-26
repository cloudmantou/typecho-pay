<?php

/**
 * Tests for P0 #3: Concurrent reservation — same order reserves at most one card.
 * Tests for P0 #2: Rate limiting and active order reuse.
 * Tests for P0 #8: Schema migration improvements.
 */

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__, 4));

$root = dirname(__DIR__);

$passed = 0;
$failed = 0;

function tc_assert(bool $condition, string $label): void
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

// ---- Test 1: CardCodeService has re-check logic in reserveForOrder ----
$cardServiceSource = file_get_contents($root . '/src/Services/CardCodeService.php');

tc_assert(
    preg_match('/for\s*\(\s*\$attempt.*?\)/s', $cardServiceSource) === 1,
    'CardCodeService has retry loop in reserveForOrder'
);

$loopStart = strpos($cardServiceSource, 'for ($attempt = 0');
if ($loopStart !== false) {
    $loopBody = substr($cardServiceSource, $loopStart, 2000);
    tc_assert(
        strpos($loopBody, 'findOrderCard') !== false,
        'findOrderCard is called INSIDE the retry loop'
    );
    tc_assert(
        substr_count($loopBody, 'findOrderCard') >= 2,
        'findOrderCard called multiple times (re-check after failed claim)'
    );
} else {
    tc_assert(false, 'Could not find retry loop');
}

// ---- Test 2: OrderService has findActiveOrderForBuyer ----
$orderServiceSource = file_get_contents($root . '/src/Services/OrderService.php');
tc_assert(
    strpos($orderServiceSource, 'function findActiveOrderForBuyer') !== false,
    'OrderService has findActiveOrderForBuyer'
);

// ---- Test 3: create() reuses active orders ----
$createMethod = '';
if (preg_match('/public function create\(array \$input.*?\n    \}/s', $orderServiceSource, $m)) {
    $createMethod = $m[0];
}
tc_assert(
    strpos($createMethod, 'findActiveOrderForBuyer') !== false,
    'create() checks for active order before creating fresh'
);
tc_assert(strpos($orderServiceSource, 'makePollToken') === false, 'OrderService does not call removed makePollToken');
tc_assert(strpos($createMethod, 'return_token_hash') === false, 'create() does not clear return token on reuse');
tc_assert(strpos($orderServiceSource, 'skip_gateway_create') !== false, 'Reusable active orders skip duplicate gateway create');
tc_assert(strpos($orderServiceSource, 'expired_at > ?') !== false, 'Active order reuse requires non-expired order');
tc_assert(strpos($orderServiceSource, 'product_version = ?') !== false, 'Active order reuse requires same product version');
tc_assert(strpos($orderServiceSource, 'amount = ?') !== false, 'Active order reuse requires same amount');
tc_assert(strpos($orderServiceSource, 'currency = ?') !== false, 'Active order reuse requires same currency');

// ---- Test 4: Rate limiting ----
tc_assert(strpos($orderServiceSource, 'function assertRateLimit') !== false, 'Has assertRateLimit');
tc_assert(strpos($orderServiceSource, 'RATE_LIMIT_MAX_PREPARES') !== false, 'Has RATE_LIMIT_MAX_PREPARES');
tc_assert(strpos($orderServiceSource, "hash('sha256', \$scope . ':' . bin2hex(random_bytes(16)))") !== false, 'Rate-limit nonce hash stays 64 chars');
tc_assert(strpos($orderServiceSource, 'quoteValue') === false, 'Rate limiting does not call Db::quoteValue');
tc_assert(strpos($orderServiceSource, "select('COUNT(*) AS cnt')") !== false, 'Rate limiting uses query builder count');

$actionSource = file_get_contents($root . '/Action.php');
tc_assert(strpos($actionSource, 'assertRateLimit') !== false, 'Action calls assertRateLimit');

// ---- Test 5: Plugin schema ----
$pluginSource = file_get_contents($root . '/Plugin.php');
tc_assert(strpos($pluginSource, 'uniq_reserved_order') !== false, 'Schema has uniq_reserved_order');
tc_assert(strpos($pluginSource, 'SCHEMA_VERSION = 7') !== false, 'Schema version is 7');
tc_assert(strpos($pluginSource, 'return_token_hash') !== false, 'Schema has return_token_hash');
tc_assert(strpos($pluginSource, 'return_token_expires_at') !== false, 'Schema has return_token_expires_at');
tc_assert(strpos($pluginSource, 'delivery_token_hash') !== false, 'Schema has delivery_token_hash');
tc_assert(strpos($pluginSource, 'return_token_used') !== false, 'Schema has return_token_used');

// ---- Test 6: Migration backfills ----
tc_assert(
    strpos($pluginSource, "payment_status = 'paid', fulfillment_status = 'fulfilled' WHERE status = 'paid'") !== false,
    'Migration backfills paid orders'
);
tc_assert(
    strpos($pluginSource, "payment_status = 'paid', fulfillment_status = 'pending' WHERE status = 'paid_pending_grant'") !== false,
    'Migration backfills paid_pending_grant'
);
tc_assert(strpos($pluginSource, 'function tablesAreUsable') !== false, 'Migration has tablesAreUsable check');

// ---- Test 7: syncProviderStatus releases AFTER update ----
$updateComment = strpos($orderServiceSource, 'Only release card reservations if the transition succeeds');
$releaseCall = strpos($orderServiceSource, 'releaseOrder($order)', $updateComment ?: 0);
tc_assert($updateComment !== false && $releaseCall > $updateComment, 'syncProviderStatus: update before release');

// ---- Summary ----
echo "\n\n--- ConcurrentReservationTest ---\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
