<?php

/**
 * Static regression checks for the article-as-product frontend integration.
 */

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function ap_assert(bool $condition, string $label): void
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

$pluginSource = file_get_contents($root . '/Plugin.php');
$cssSource = file_get_contents($root . '/assets/typechopay.css');

ap_assert(strpos($pluginSource, 'productAutoInjectPosition') !== false, 'Plugin has auto-inject config');
ap_assert(strpos($pluginSource, 'function autoInjectProductPanel') !== false, 'Plugin has autoInjectProductPanel');
ap_assert(strpos($pluginSource, 'function findActiveProductByContentId') !== false, 'Plugin can find product by article cid');
ap_assert(strpos($pluginSource, 'function renderProductPanelHtml') !== false, 'Plugin renders article product panel');
ap_assert(strpos($pluginSource, 'containsTypechoPayShortcode') !== false, 'Auto inject skips manual shortcodes');
ap_assert(strpos($pluginSource, 'typechopay_product(?:\\s+') !== false, 'typechopay_product shortcode supports empty attrs');
ap_assert(strpos($pluginSource, 'product-panel') !== false, 'Theme can override product-panel template');
ap_assert(strpos($pluginSource, 'typechopay-status--') !== false, 'Product panel emits status classes');
ap_assert(strpos($pluginSource, '登录后购买') !== false, 'Product panel exposes login-required state');
ap_assert(strpos($pluginSource, '商品已售罄') !== false, 'Product panel exposes soldout state');

ap_assert(strpos($cssSource, '.typechopay-product-panel') !== false, 'CSS has article product panel styles');
ap_assert(strpos($cssSource, '--typechopay-primary') !== false, 'CSS exposes TypechoPay variables');

echo "\n\n--- ArticleProductTest ---\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
