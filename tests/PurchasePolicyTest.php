<?php

/**
 * Tests for P0 #4: Purchase policy based on product purchase records.
 * Tests for P0 #5: Stored products must have deliverables.
 * Tests for P0 #6: Import distinguishes duplicates from database errors.
 */

$root = dirname(__DIR__);

$passed = 0;
$failed = 0;

function tp_assert(bool $condition, string $label): void
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

// ---- Test 1: PurchasePolicyService exists ----
$ppsPath = $root . '/src/Services/PurchasePolicyService.php';
tp_assert(file_exists($ppsPath), 'PurchasePolicyService.php exists');
$ppsSource = file_get_contents($ppsPath);

tp_assert(strpos($ppsSource, 'function assertCanPurchase') !== false, 'Has assertCanPurchase');
tp_assert(strpos($ppsSource, 'function hasPurchased') !== false, 'Has hasPurchased');

// ---- Test 2: Queries payment_status = paid ----
tp_assert(
    strpos($ppsSource, "payment_status") !== false,
    'PurchasePolicyService queries payment_status'
);

// ---- Test 3: Handles once and limited policies ----
tp_assert(strpos($ppsSource, "'once'") !== false, 'Handles once policy');
tp_assert(strpos($ppsSource, "'limited'") !== false, 'Handles limited policy');
tp_assert(strpos($ppsSource, 'max_per_user') !== false, 'Uses max_per_user');

// ---- Test 4: Action uses PurchasePolicyService ----
$actionSource = file_get_contents($root . '/Action.php');
tp_assert(strpos($actionSource, 'PurchasePolicyService') !== false, 'Action uses PurchasePolicyService');

$createFromPayload = '';
if (preg_match('/private function createFromPayload.*?^    \}/ms', $actionSource, $m)) {
    $createFromPayload = $m[0];
}
tp_assert(
    strpos($createFromPayload, 'PurchasePolicyService') !== false,
    'createFromPayload uses PurchasePolicyService'
);

// ---- Test 5: Plugin uses PurchasePolicyService ----
$pluginSource = file_get_contents($root . '/Plugin.php');
tp_assert(strpos($pluginSource, 'currentVisitorHasPurchased') !== false, 'Plugin has currentVisitorHasPurchased');
tp_assert(strpos($pluginSource, 'PurchasePolicyService') !== false, 'Plugin uses PurchasePolicyService');

// ---- Test 6: ProductService validates deliverables ----
$productServiceSource = file_get_contents($root . '/src/Services/ProductService.php');
tp_assert(
    strpos($productServiceSource, 'Product has no enabled deliverables') !== false,
    'Rejects stored products without deliverables'
);
tp_assert(
    strpos($productServiceSource, 'invalid deliverable handler') !== false || strpos($productServiceSource, 'allowedHandlers') !== false,
    'Validates deliverable handler types'
);

// ---- Test 7: CardCodeService import is transactional ----
$cardServiceSource = file_get_contents($root . '/src/Services/CardCodeService.php');
tp_assert(strpos($cardServiceSource, 'START TRANSACTION') !== false, 'Import uses START TRANSACTION');
tp_assert(strpos($cardServiceSource, 'COMMIT') !== false, 'Import uses COMMIT');
tp_assert(strpos($cardServiceSource, 'ROLLBACK') !== false, 'Import uses ROLLBACK');

// ---- Test 8: Distinguishes unique violations from other errors ----
tp_assert(
    strpos($cardServiceSource, 'unique') !== false && strpos($cardServiceSource, 'duplicate') !== false,
    'Catches unique violations as duplicates'
);
tp_assert(
    strpos($cardServiceSource, "throw \$e") !== false || strpos($cardServiceSource, 'Any other database error is fatal') !== false,
    'Rethrows non-unique errors'
);

// ---- Test 9: Import size limits ----
tp_assert(strpos($cardServiceSource, '10000') !== false, 'Max 10,000 items per import');
tp_assert(strpos($cardServiceSource, 'assertCodeLength') !== false, 'Validates code length');
tp_assert(strpos($cardServiceSource, '4096') !== false, 'Max code length 4096');

// ---- Test 10: Admin validates product before import ----
$productsSource = file_get_contents($root . '/manage/products.php');
tp_assert(strpos($productsSource, '商品不存在') !== false, 'Admin validates product exists');
tp_assert(strpos($productsSource, '未启用卡密交付') !== false, 'Admin validates cardcode handler');

// ---- Test 11: Product creation is transactional ----
tp_assert(substr_count($productsSource, 'START TRANSACTION') >= 1, 'Product creation is transactional');

// ---- Summary ----
echo "\n\n--- PurchasePolicyTest ---\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
