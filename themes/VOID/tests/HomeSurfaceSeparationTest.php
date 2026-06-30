<?php

/**
 * Static regression checks for the VOID home/product presentation split.
 */

$root = dirname(__DIR__);
$indexSource = file_get_contents($root . '/index.php');
$siteCss = file_get_contents($root . '/assets/site-polish.css');
$payCss = file_get_contents($root . '/typechopay/style.css');
$passed = 0;
$failed = 0;

function hsst_assert(bool $condition, string $label): void
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

hsst_assert(strpos($indexSource, '最新文章') !== false, 'Home copy labels the feed as latest articles');
hsst_assert(strpos($indexSource, '最新内容') === false, 'Home copy no longer mixes all content types under latest content');
hsst_assert(strpos($indexSource, 'cm-product-zone') !== false, 'Home has a separate product zone');
hsst_assert(strpos($indexSource, '[typechopay_shop limit="3" columns="3"]') !== false, 'Home product preview uses the TypechoPay shop shortcode');
hsst_assert(strpos($indexSource, 'renderPayShortcodes') !== false, 'Home product preview goes through the plugin shortcode renderer');
hsst_assert(strpos($indexSource, '$payBadge') !== false, 'Home loop stores the TypechoPay badge before rendering cards');
hsst_assert(strpos($indexSource, '$isProductPost') !== false, 'Home loop derives product-post state from the badge');
hsst_assert(strpos($indexSource, 'cm-post--product') !== false, 'Home loop adds a product card class');
hsst_assert(strpos($indexSource, 'cm-post--article') !== false, 'Home loop adds an article card class');
hsst_assert(strpos($indexSource, 'pay_products') === false, 'Theme home template does not query TypechoPay tables directly');

hsst_assert(strpos($siteCss, '.cm-home-products') !== false, 'Site CSS styles the product zone');
hsst_assert(strpos($siteCss, '.cm-post--product') !== false, 'Site CSS differentiates product cards');
hsst_assert(strpos($siteCss, '.cm-product-entry') !== false, 'Site CSS styles product entry affordance');
hsst_assert(strpos($siteCss, '.cm-read-entry') !== false, 'Site CSS styles article entry affordance');
hsst_assert(strpos($payCss, '.typechopay-card__price') !== false, 'TypechoPay CSS emphasizes product card price');
hsst_assert(strpos($payCss, '.typechopay-card__buy') !== false, 'TypechoPay CSS styles product card action');

echo "\n\n--- HomeSurfaceSeparationTest ---\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
