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
$productsSource = file_get_contents($root . '/manage/products.php');

ap_assert(strpos($pluginSource, 'productAutoInjectPosition') !== false, 'Plugin has auto-inject config');
ap_assert(strpos($pluginSource, "factory('admin/write-post.php')->content") !== false, 'Registers post editor content hook');
ap_assert(strpos($pluginSource, "factory('admin/write-page.php')->content") !== false, 'Registers page editor content hook');
ap_assert(strpos($pluginSource, "Widget\\Contents\\Post\\Edit')->write") !== false, 'Registers post write hook');
ap_assert(strpos($pluginSource, "Widget\\Contents\\Page\\Edit')->write") !== false, 'Registers page write hook');
ap_assert(strpos($pluginSource, "Widget\\Contents\\Post\\Edit')->finishPublish") !== false, 'Registers post finishPublish hook');
ap_assert(strpos($pluginSource, "Widget\\Contents\\Page\\Edit')->finishPublish") !== false, 'Registers page finishPublish hook');
ap_assert(strpos($pluginSource, 'function autoInjectProductPanel') !== false, 'Plugin has autoInjectProductPanel');
ap_assert(strpos($pluginSource, 'function containsExplicitProductUiShortcode') !== false, 'Auto inject ignores protected-content-only shortcodes');
ap_assert(strpos($pluginSource, 'function findActiveProductByContentId') !== false, 'Plugin can find product by article cid');
ap_assert(strpos($pluginSource, 'function renderProductPanelHtml') !== false, 'Plugin renders article product panel');
ap_assert(strpos($pluginSource, 'function renderArticleProductPanel') !== false, 'Plugin exposes theme article product panel helper');
ap_assert(strpos($pluginSource, 'function renderPostBadge') !== false, 'Plugin exposes theme post badge helper');
ap_assert(strpos($pluginSource, 'function renderArticlePayPanel') !== false, 'Plugin renders article editor panel');
ap_assert(strpos($pluginSource, 'function injectArticleProductShortcode') !== false, 'Plugin can insert product shortcode while saving article');
ap_assert(strpos($pluginSource, 'function saveArticlePaySettings') !== false, 'Plugin saves article pay settings');
ap_assert(strpos($pluginSource, 'function upsertArticleProduct') !== false, 'Plugin upserts product from article cid');
ap_assert(strpos($pluginSource, 'function importArticleCardLines') !== false, 'Editor can import pasted card lines on save');
ap_assert(strpos($pluginSource, 'function articleCardStats') !== false, 'Editor shows card inventory stats');
ap_assert(strpos($pluginSource, 'function recentArticleCards') !== false, 'Editor shows recent card masks');
ap_assert(strpos($pluginSource, 'new CardCodeService') !== false, 'Editor import uses CardCodeService');
ap_assert(strpos($pluginSource, 'typechopay_pay_mode') !== false, 'Editor panel has pay mode field');
ap_assert(strpos($pluginSource, 'typechopay_insert_shortcode') !== false, 'Editor panel can insert shortcode');
ap_assert(strpos($pluginSource, 'typechopay_card_lines') !== false, 'Editor panel has pasted card import field');
ap_assert(strpos($pluginSource, 'name="typechopay_currency" value="CNY"') !== false, 'Editor panel fixes article products to CNY');
ap_assert(strpos($pluginSource, '付费下载') === false, 'Editor panel hides reserved delivery modes');
ap_assert(strpos($pluginSource, '$deliverableTargetType') !== false, 'Editor save keeps page/post deliverable target type');
ap_assert(substr_count($pluginSource, 'containsExplicitProductUiShortcode') >= 3, 'Auto inject skips only explicit product UI shortcodes');
ap_assert(strpos($pluginSource, 'typechoCategoryContentIdsFromShopAttrs') !== false, 'Shop shortcode supports Typecho category filtering');
ap_assert(strpos($pluginSource, 'typechoCategoryLabelsForProducts') !== false, 'Product cards can show Typecho article categories');
ap_assert(strpos($pluginSource, 'category_slug') !== false, 'Shop shortcode supports Typecho category slug');
ap_assert(strpos($pluginSource, 'typecho_category') !== false, 'Shop shortcode supports Typecho category name');
ap_assert(strpos($pluginSource, 'loadFrontendCss') !== false, 'Plugin can disable default frontend CSS');
ap_assert(strpos($pluginSource, 'function shopCssLink') !== false, 'Frontend CSS is returned with rendered HTML');
ap_assert(strpos($pluginSource, 'function enqueueShopCss') === false, 'Frontend CSS is not echoed directly');
ap_assert(strpos($pluginSource, 'function adminDiagnosticComment') !== false, 'Plugin has admin-only diagnostics');
ap_assert(strpos($pluginSource, 'TypechoPay: ') !== false, 'Admin diagnostics use TypechoPay comment prefix');
ap_assert(strpos($pluginSource, 'auto inject off') !== false, 'Auto inject reports off state to admins');
ap_assert(strpos($pluginSource, 'no product found for content_id=') !== false, 'Auto inject reports missing bound product to admins');
ap_assert(strpos($pluginSource, 'product paused') !== false, 'Auto inject reports paused products to admins');
ap_assert(strpos($pluginSource, 'typechopay_product(?:\\s+') !== false, 'typechopay_product shortcode supports empty attrs');
ap_assert(strpos($pluginSource, 'product-panel') !== false, 'Theme can override product-panel template');
ap_assert(strpos($pluginSource, 'post-badge') !== false, 'Theme can override post-badge template');
ap_assert(strpos($pluginSource, 'typechopay-status--') !== false, 'Product panel emits status classes');
ap_assert(strpos($pluginSource, '登录后购买') !== false, 'Product panel exposes login-required state');
ap_assert(strpos($pluginSource, '商品已售罄') !== false, 'Product panel exposes soldout state');

ap_assert(strpos($cssSource, '.typechopay-product-panel') !== false, 'CSS has article product panel styles');
ap_assert(strpos($cssSource, '.typechopay-post-badge') !== false, 'CSS has post badge styles');
ap_assert(strpos($cssSource, '--typechopay-primary') !== false, 'CSS exposes TypechoPay variables');
ap_assert(strpos($productsSource, '商城专题') !== false, 'Admin labels TypechoPay categories as shop topics');
ap_assert(strpos($productsSource, '绑定文章') !== false, 'Admin shows bound article information');
ap_assert(strpos($productsSource, '$boundContentCategories') !== false, 'Admin shows bound article categories');
ap_assert(strpos($pluginSource, '绑定商品 ID') !== false, 'Editor shows bound product id');
ap_assert(strpos($pluginSource, '商品状态') !== false, 'Editor shows product status');
ap_assert(strpos($pluginSource, '当前库存') !== false, 'Editor shows current stock summary');
ap_assert(strpos($pluginSource, '自动插入') !== false, 'Editor shows auto-insert status');
ap_assert(strpos($pluginSource, '保存付费模式时在正文插入 [typechopay_product]') !== false, 'Editor defaults toward shortcode insertion');

echo "\n\n--- ArticleProductTest ---\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
