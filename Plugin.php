<?php

namespace TypechoPlugin\TypechoPay;

use Typecho\Common;
use Typecho\Db;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use TypechoPlugin\TypechoPay\Services\AccessService;
use TypechoPlugin\TypechoPay\Services\CardCodeService;
use TypechoPlugin\TypechoPay\Services\GuestClaimService;
use TypechoPlugin\TypechoPay\Services\ProductService;
use TypechoPlugin\TypechoPay\Services\PurchasePolicyService;
use TypechoPlugin\TypechoPay\Support\GuestToken;
use Utils\Helper;
use Widget\Notice;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

// Typecho's core autoloader (Common.php) handles TypechoPlugin\ classes,
// mapping them to the plugin root directory. This fallback covers edge cases.
spl_autoload_register(function ($class) {
    $prefix = __NAMESPACE__ . '\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $relativePath = str_replace('\\', '/', $relative) . '.php';
    $path = __DIR__ . '/' . $relativePath;
    if (is_file($path)) {
        require_once $path;
    }
});

/**
 * Typecho Pay
 *
 * 订单中心与人民币支付网关适配器，支持微信支付、支付宝的统一接入骨架。
 *
 * @package TypechoPay
 * @author mantou
 * @version 0.4.9
 * @link https://github.com/
 */
class Plugin implements PluginInterface
{
    private const ACTION = 'typechopay';
    private const MENU = 'TypechoPay';
    private const ORDERS_PANEL = 'TypechoPay/manage/orders.php';
    private const PRODUCTS_PANEL = 'TypechoPay/manage/products.php';
    private const CARD_INVENTORY_PANEL = 'TypechoPay/manage/card-inventory.php';
    private const CARD_SALES_PANEL = 'TypechoPay/manage/card-sales.php';
    private const SETTINGS_HELP_PANEL = 'TypechoPay/manage/settings-help.php';
    private const SCHEMA_VERSION = 9;

    /**
     * 启用插件。
     */
    public static function activate()
    {
        self::installTables();

        Helper::addAction(self::ACTION, '\\' . __NAMESPACE__ . '\\Action');
        $menuIndex = Helper::addMenu(self::MENU);
        Helper::addPanel($menuIndex, self::ORDERS_PANEL, _t('支付订单'), _t('TypechoPay'), 'administrator');
        Helper::addPanel($menuIndex, self::PRODUCTS_PANEL, _t('商品管理'), _t('TypechoPay'), 'administrator');
        Helper::addPanel($menuIndex, self::CARD_INVENTORY_PANEL, _t('卡密库存'), _t('TypechoPay'), 'administrator');
        Helper::addPanel($menuIndex, self::CARD_SALES_PANEL, _t('卡密销售'), _t('TypechoPay'), 'administrator');
        Helper::addPanel($menuIndex, self::SETTINGS_HELP_PANEL, _t('支付设置说明'), _t('TypechoPay'), 'administrator');

        \Typecho\Plugin::factory('Widget\Base\Contents')->contentEx = __CLASS__ . '::renderPayShortcodes';
        \Typecho\Plugin::factory('admin/write-post.php')->content = __CLASS__ . '::renderArticlePayPanel';
        \Typecho\Plugin::factory('admin/write-page.php')->content = __CLASS__ . '::renderArticlePayPanel';
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->write = __CLASS__ . '::injectArticleProductShortcode';
        \Typecho\Plugin::factory('Widget\Contents\Page\Edit')->write = __CLASS__ . '::injectArticleProductShortcode';
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->finishSave = __CLASS__ . '::saveArticlePaySettings';
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->finishPublish = __CLASS__ . '::saveArticlePaySettings';
        \Typecho\Plugin::factory('Widget\Contents\Page\Edit')->finishSave = __CLASS__ . '::saveArticlePaySettings';
        \Typecho\Plugin::factory('Widget\Contents\Page\Edit')->finishPublish = __CLASS__ . '::saveArticlePaySettings';

        return _t('TypechoPay 已启用，订单、商品和交付数据表已准备完成。');
    }

    /**
     * 禁用插件。
     */
    public static function deactivate()
    {
        Helper::removeAction(self::ACTION);
        $menuIndex = Helper::removeMenu(self::MENU);
        Helper::removePanel($menuIndex, self::ORDERS_PANEL);
        Helper::removePanel($menuIndex, self::PRODUCTS_PANEL);
        Helper::removePanel($menuIndex, self::CARD_INVENTORY_PANEL);
        Helper::removePanel($menuIndex, self::CARD_SALES_PANEL);
        Helper::removePanel($menuIndex, self::SETTINGS_HELP_PANEL);

        return _t('TypechoPay 已禁用，订单表会保留以便审计和恢复。');
    }

    /**
     * 插件配置。
     *
     * @param Form $form
     */
    public static function config(Form $form)
    {
        // ============================================================
        // 基础设置
        // ============================================================

        $enabledGateways = new Checkbox(
            'enabledGateways',
            [
                'wechat' => '微信支付（中国，CNY）',
                'alipay' => '支付宝（中国，CNY）',
            ],
            ['alipay'],
            _t('启用支付方式'),
            _t('当前后台只保留人民币支付方式。未勾选的支付方式不会在前端显示。')
        );
        $form->addInput($enabledGateways);

        $endpointSecret = new Password(
            'endpointSecret',
            null,
            '',
            _t('入口签名密钥'),
            _t('用于文章付款入口金额防篡改。留空时回退到 Typecho 站点 secret。<br><strong>生产环境强烈建议单独设置</strong>，避免与其他功能共用密钥。')
        );
        $form->addInput($endpointSecret);

        $productAutoInjectPosition = new Select(
            'productAutoInjectPosition',
            [
                'off' => '不自动插入',
                'top' => '正文顶部',
                'bottom' => '正文底部',
                'after_first_paragraph' => '第一段之后',
            ],
            'off',
            _t('文章商品卡自动插入位置'),
            _t('文章 cid 绑定了上架商品时，自动在详情页显示购买模块。若文章只包含 [typechopay_content]，仍会自动插入；只有 [typechopay]、[typechopay_product]、[typechopay_shop] 会阻止重复插入。若文章页不显示卡密商品，请先确认这里不是“不自动插入”。')
        );
        $form->addInput($productAutoInjectPosition);

        $loadFrontendCss = new Select(
            'loadFrontendCss',
            [
                '1' => '开启 - 加载插件默认前台样式',
                '0' => '关闭 - 由主题完全接管样式',
            ],
            '1',
            _t('加载默认前台样式'),
            _t('关闭后插件不再输出 assets/typechopay.css；适合主题已通过 typechopay/style.css 或自定义 CSS 完全覆盖前台展示。')
        );
        $form->addInput($loadFrontendCss);

        // ============================================================
        // 微信支付配置
        // ============================================================

        $form->addInput(new Text('wechatAppId', null, '', _t('微信支付 AppID'), _t('当前支持 Native 扫码支付，仅支持 CNY（人民币）。公众号、小程序或网站应用绑定的 AppID。<br>在 <a href="https://mp.weixin.qq.com/" target="_blank">微信公众平台</a> 或 <a href="https://open.weixin.qq.com/" target="_blank">微信开放平台</a> 获取。详细申请和回调配置请查看左侧 TypechoPay → 支付设置说明。')));
        $form->addInput(new Text('wechatMchId', null, '', _t('微信支付商户号（MchID）'), _t('在 <a href="https://pay.weixin.qq.com/" target="_blank">微信支付商户平台</a> → 账户中心 → 商户信息 中查看。')));
        $form->addInput(new Text('wechatMerchantSerial', null, '', _t('商户 API 证书序列号'), _t('在微信支付商户平台 → API 安全 → 证书序列号 中查看。<br>格式类似：<code>7D578B5A...</code>')));
        $form->addInput(new Text('wechatPrivateKeyPath', null, '', _t('商户 API 私钥文件路径'), _t('下载证书时获得的 <code>apiclient_key.pem</code> 文件的<strong>绝对路径</strong>。<br>建议放在网站根目录外，例如：<code>/www/secure/apiclient_key.pem</code><br>确保 PHP 有读取权限。')));
        $form->addInput(new Text('wechatPlatformPublicKeyPath', null, '', _t('微信支付平台公钥/证书路径'), _t('用于回调验签的平台证书文件路径。<br>从微信支付商户平台下载，例如：<code>/www/secure/wechatpay_platform.pem</code>')));
        $form->addInput(new Text('wechatPlatformSerial', null, '', _t('微信支付平台证书序列号/公钥 ID'), _t('在微信支付商户平台 → API 安全 → 平台证书 中查看。')));
        $form->addInput(new Password('wechatApiV3Key', null, '', _t('微信支付 APIv3 Key'), _t('在微信支付商户平台 → API 安全 中设置的 32 位密钥。<br>用于回调通知的 AES-GCM 解密。<strong>请妥善保管，不要泄露。</strong>')));

        // ============================================================
        // 支付宝配置
        // ============================================================

        $alipayMode = new Select(
            'alipayMode',
            [
                'page' => '电脑网站支付（Page Pay）- 用户跳转支付宝页面',
                'precreate' => '当面付（Precreate）- 生成二维码扫码支付',
            ],
            'page',
            _t('支付宝支付模式'),
            _t('Page Pay 适合电脑端，会跳转到支付宝收银台；Precreate 适合生成二维码让用户扫码支付。当前仅支持支付宝普通公钥模式，暂不支持公钥证书模式。')
        );
        $form->addInput($alipayMode);

        $form->addInput(new Text('alipayAppId', null, '', _t('支付宝 AppID'), _t('在 <a href="https://open.alipay.com/" target="_blank">支付宝开放平台</a> → 应用详情 中查看。详细申请和回调配置请查看左侧 TypechoPay → 支付设置说明。')));
        $form->addInput(new Text('alipayGatewayUrl', null, 'https://openapi.alipay.com/gateway.do', _t('支付宝网关地址'), _t('正式环境使用 <code>https://openapi.alipay.com/gateway.do</code>；沙箱测试填写 <code>https://openapi-sandbox.dl.alipaydev.com/gateway.do</code>。')));
        $form->addInput(new Textarea('alipayPrivateKey', null, '', _t('支付宝应用私钥'), _t('在支付宝开放平台普通公钥模式下生成的应用私钥（RSA2），以 <code>-----BEGIN RSA PRIVATE KEY-----</code> 开头。<br><strong>这是敏感信息，请勿截图外泄！</strong>')));
        $form->addInput(new Textarea('alipayPublicKey', null, '', _t('支付宝公钥'), _t('支付宝开放平台普通公钥模式下生成的支付宝公钥（用于验签），以 <code>-----BEGIN PUBLIC KEY-----</code> 开头。<br>注意：这是<strong>支付宝的公钥</strong>，不是应用公钥；公钥证书模式暂不支持。')));
        $form->addInput(new Text('alipaySellerId', null, '', _t('支付宝 Seller ID（可选）'), _t('填写后会校验收款账号，提高安全性。<br>在支付宝商家中心 → 账户管理 中查看，格式类似：<code>2088xxxxxxxxxxxx</code>')));
    }

    /**
     * 个人配置。
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * Render TypechoPay settings inside the article/page editor.
     *
     * @param object $content
     */
    public static function renderArticlePayPanel($content): void
    {
        $cid = self::archiveContentId($content);
        $product = $cid > 0 ? self::findProductByContentId($cid) : null;
        $productId = $product ? (int) $product['id'] : 0;
        $handlers = $product ? self::productDeliverableHandlers((int) $product['id']) : [];
        $hasCardcode = in_array('cardcode', $handlers, true);
        $hasPostAccess = in_array('post_access', $handlers, true);
        $mode = 'off';
        if ($product && (string) ($product['status'] ?? '') === 'active') {
            $mode = $hasCardcode ? 'cardcode' : ($hasPostAccess ? 'post_access' : 'off');
        }

        $title = $product ? (string) ($product['title'] ?? '') : '';
        $amount = $product ? (int) ($product['amount'] ?? 0) : 0;
        $policy = $product ? (string) ($product['purchase_policy'] ?? 'repeatable') : 'repeatable';
        $maxPerUser = $product ? (int) ($product['max_per_user'] ?? 0) : 0;
        $allowGuest = $product ? (int) ($product['allow_guest'] ?? 1) : 1;
        $stockDisplayMode = $product ? (string) ($product['stock_display_mode'] ?? 'exact') : 'exact';
        $summary = $product ? (string) ($product['summary'] ?? '') : '';
        $coverUrl = $product ? (string) ($product['cover_url'] ?? '') : '';
        $productKey = $product ? (string) ($product['product_key'] ?? '') : '';
        $containsShortcode = is_string($content->text ?? null) && self::containsExplicitProductUiShortcode((string) $content->text);
        $shouldInsert = !$containsShortcode;
        $cardStats = ($productId > 0 && $hasCardcode) ? self::articleCardStats($productId) : null;
        $recentCards = ($productId > 0 && $hasCardcode) ? self::recentArticleCards($productId, 8) : [];

        $options = Options::alloc();
        $config = self::pluginConfig($options);
        $autoInjectLabel = [
            'off' => _t('关闭'),
            'top' => _t('正文顶部'),
            'bottom' => _t('正文底部'),
            'after_first_paragraph' => _t('第一段之后'),
        ][$config['productAutoInjectPosition']] ?? _t('关闭');
        $productStatus = $product ? (string) ($product['status'] ?? '-') : _t('未创建');
        $stockText = $cardStats
            ? _t('可用 %d / 预留 %d / 已售 %d', (int) $cardStats['available'], (int) $cardStats['reserved'], (int) $cardStats['delivered'])
            : _t('保存卡密商品后显示');
        $visibilityStatus = self::articleProductVisibilityStatus($containsShortcode, $config['productAutoInjectPosition']);
        $productsUrl = $options->adminUrl . 'extending.php?panel=TypechoPay%2Fmanage%2Fproducts.php';
        $inventoryUrl = $options->adminUrl . 'extending.php?panel=TypechoPay%2Fmanage%2Fcard-inventory.php';
        $salesUrl = $options->adminUrl . 'extending.php?panel=TypechoPay%2Fmanage%2Fcard-sales.php';
        $previewUrl = self::editorContentPermalink($content);
        if ($product) {
            $productsUrl .= '&edit=' . (int) $product['id'];
            $inventoryUrl .= '&product_id=' . (int) $product['id'];
            $salesUrl .= '&product_id=' . (int) $product['id'];
        }

        ?>
        <section class="typechopay-editor-panel">
            <style>
                .typechopay-editor-panel{margin:20px 0 22px;padding:0;border:1px solid #dfe5ec;background:#fff}
                .typechopay-editor-panel__head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 16px;border-bottom:1px solid #edf1f5;background:#fafafa}
                .typechopay-editor-panel h3{margin:0;font-size:16px}
                .typechopay-editor-panel__body{padding:16px}
                .typechopay-editor-row{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin:12px 0}
                .typechopay-editor-row strong{min-width:76px;color:#0f62fe}
                .typechopay-editor-grid{display:grid;grid-template-columns:minmax(160px,220px) minmax(180px,1fr) minmax(180px,1fr);gap:12px;margin-top:12px}
                .typechopay-editor-grid label{display:block;font-weight:600;margin-bottom:4px}
                .typechopay-editor-grid input,.typechopay-editor-grid select{max-width:100%}
                .typechopay-editor-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
                .typechopay-editor-meta{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 14px}
                .typechopay-editor-meta span{display:inline-flex;gap:5px;align-items:center;padding:4px 9px;border-radius:4px;background:#f3f4f6;color:#374151}
                .typechopay-editor-cardbox{margin-top:16px;border:1px solid #e5e7eb;background:#fff}
                .typechopay-card-tabs__nav{display:flex;gap:0;border-bottom:1px solid #e5e7eb;background:#f9fafb}
                .typechopay-card-tabs__tab{appearance:none;border:0;border-right:1px solid #e5e7eb;background:transparent;padding:12px 18px;color:#374151;cursor:pointer;font-weight:600}
                .typechopay-card-tabs__tab.is-active{background:#fff;color:#111827;box-shadow:inset 0 -2px 0 #ff8a3d}
                .typechopay-card-tabs__pane{display:none;padding:14px}
                .typechopay-card-tabs__pane.is-active{display:block}
                .typechopay-editor-stats{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 14px}
                .typechopay-editor-stat{display:inline-flex;gap:5px;align-items:center;padding:4px 9px;border-radius:4px;background:#eef2ff;color:#374151;font-weight:600}
                .typechopay-editor-stat--ok{background:#e0f2fe;color:#0369a1}
                .typechopay-editor-stat--sold{background:#ffe4e6;color:#be123c}
                .typechopay-editor-import textarea{width:100%;min-height:126px}
                .typechopay-card-submit-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:12px 0 0}
                .typechopay-editor-cards{width:100%;margin-top:10px;border-collapse:collapse}
                .typechopay-editor-cards th,.typechopay-editor-cards td{padding:7px 8px;border:1px solid #e5e7eb;text-align:left}
                .typechopay-editor-muted{color:#6b7280}
                @media(max-width:768px){.typechopay-editor-grid{grid-template-columns:1fr}}
            </style>
            <div class="typechopay-editor-panel__head">
                <h3><?php _e('文章付费与卡密'); ?></h3>
                <?php if ($product): ?><span class="typechopay-editor-muted"><?php echo htmlspecialchars($productKey); ?></span><?php endif; ?>
            </div>
            <div class="typechopay-editor-panel__body">
                <div class="typechopay-editor-meta">
                    <span><?php _e('绑定商品 ID'); ?>: <?php echo $productId > 0 ? (int) $productId : '-'; ?></span>
                    <span><?php _e('商品状态'); ?>: <?php echo htmlspecialchars($productStatus); ?></span>
                    <span><?php _e('当前库存'); ?>: <?php echo htmlspecialchars($stockText); ?></span>
                    <span><?php _e('自动插入'); ?>: <?php echo htmlspecialchars($autoInjectLabel); ?></span>
                    <span><?php _e('前台显示'); ?>: <?php echo htmlspecialchars($visibilityStatus); ?></span>
                    <?php if ($containsShortcode): ?><span><?php _e('正文已有购买短代码'); ?></span><?php endif; ?>
                </div>
                <div class="typechopay-editor-row">
                    <strong><?php _e('付费模式'); ?></strong>
                    <label><input type="radio" name="typechopay_pay_mode" value="off" <?php if ($mode === 'off') echo 'checked'; ?>> <?php _e('关闭'); ?></label>
                    <label><input type="radio" name="typechopay_pay_mode" value="post_access" <?php if ($mode === 'post_access') echo 'checked'; ?>> <?php _e('付费阅读'); ?></label>
                    <label><input type="radio" name="typechopay_pay_mode" value="cardcode" <?php if ($mode === 'cardcode') echo 'checked'; ?>> <?php _e('卡密管理'); ?></label>
                </div>
                <div class="typechopay-editor-grid">
                    <p>
                        <label><?php _e('价格（元）'); ?></label>
                        <input type="number" name="typechopay_amount" min="0.01" step="0.01" value="<?php echo $amount > 0 ? htmlspecialchars(Support\Money::formatCnyInput((int) $amount)) : ''; ?>" placeholder="<?php _e('最低 0.01'); ?>" class="w-100">
                    </p>
                    <p>
                        <label><?php _e('购买权限'); ?></label>
                        <select name="typechopay_purchase_permission" class="w-100">
                            <option value="all" <?php if ($allowGuest === 1) echo 'selected'; ?>><?php _e('所有人可购买'); ?></option>
                            <option value="login" <?php if ($allowGuest !== 1) echo 'selected'; ?>><?php _e('仅登录用户'); ?></option>
                        </select>
                    </p>
                    <p>
                        <label><?php _e('商品标题'); ?></label>
                        <input type="text" name="typechopay_product_title" value="<?php echo htmlspecialchars($title); ?>" placeholder="<?php _e('留空则使用文章标题'); ?>" class="w-100">
                    </p>
                </div>

                <input type="hidden" name="typechopay_product_key" value="<?php echo htmlspecialchars($productKey); ?>">
                <input type="hidden" name="typechopay_currency" value="CNY">
                <input type="hidden" name="typechopay_purchase_policy" value="<?php echo htmlspecialchars($policy !== '' ? $policy : 'repeatable'); ?>">
                <input type="hidden" name="typechopay_max_per_user" value="<?php echo $maxPerUser > 0 ? (int) $maxPerUser : ''; ?>">
                <input type="hidden" name="typechopay_stock_display_mode" value="<?php echo htmlspecialchars($stockDisplayMode !== '' ? $stockDisplayMode : 'exact'); ?>">
                <input type="hidden" name="typechopay_cover_url" value="<?php echo htmlspecialchars($coverUrl); ?>">
                <input type="hidden" name="typechopay_summary" value="<?php echo htmlspecialchars($summary); ?>">

                <div class="typechopay-editor-row">
                    <label><input type="checkbox" name="typechopay_unlock_article" value="1" <?php if ($hasPostAccess || $mode === 'post_access') echo 'checked'; ?>> <?php _e('购买后解锁本文'); ?></label>
                    <label><input type="checkbox" name="typechopay_insert_shortcode" value="1" <?php if ($shouldInsert) echo 'checked'; ?>> <?php _e('保存时在正文顶部插入购买模块'); ?></label>
                    <button type="button" class="btn btn-xs" id="typechopay-insert-product-shortcode"><?php _e('插入购买模块到光标'); ?></button>
                    <?php if ($containsShortcode): ?><small class="typechopay-editor-muted"><?php _e('正文已包含 TypechoPay 购买短代码，不会重复插入。'); ?></small><?php endif; ?>
                    <small class="typechopay-editor-muted"><?php _e('类似附件“插入到正文”。不插入时，仅保存商品绑定；前台显示取决于自动插入设置或主题 helper。'); ?></small>
                </div>

                <div class="typechopay-editor-cardbox" data-typechopay-card-tabs>
                    <div class="typechopay-card-tabs__nav">
                        <button type="button" class="typechopay-card-tabs__tab is-active" data-typechopay-card-tab="list"><?php _e('卡密列表'); ?></button>
                        <button type="button" class="typechopay-card-tabs__tab" data-typechopay-card-tab="import"><?php _e('添加卡密'); ?></button>
                    </div>

                    <div class="typechopay-card-tabs__pane is-active" data-typechopay-card-pane="list">
                        <?php if ($cardStats): ?>
                            <div class="typechopay-editor-stats">
                                <span class="typechopay-editor-stat"><?php _e('全部'); ?> <?php echo (int) $cardStats['total']; ?></span>
                                <span class="typechopay-editor-stat typechopay-editor-stat--ok"><?php _e('库存'); ?> <?php echo (int) $cardStats['available']; ?></span>
                                <span class="typechopay-editor-stat typechopay-editor-stat--sold"><?php _e('已售'); ?> <?php echo (int) $cardStats['delivered']; ?></span>
                                <?php if ((int) $cardStats['reserved'] > 0): ?><span class="typechopay-editor-stat"><?php _e('占用'); ?> <?php echo (int) $cardStats['reserved']; ?></span><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p class="typechopay-editor-muted"><?php _e('保存文章后会自动创建绑定商品，并在这里显示库存。'); ?></p>
                        <?php endif; ?>

                        <?php if ($recentCards): ?>
                            <table class="typechopay-editor-cards">
                                <thead><tr><th><?php _e('卡密'); ?></th><th><?php _e('状态'); ?></th><th><?php _e('创建时间'); ?></th></tr></thead>
                                <tbody>
                                <?php foreach ($recentCards as $card): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string) ($card['code_mask'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string) ($card['status'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string) ($card['created_at'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                        <div class="typechopay-editor-actions">
                            <?php if ($product): ?>
                                <?php if ($hasCardcode): ?>
                                    <a class="btn btn-xs" href="<?php echo htmlspecialchars($inventoryUrl); ?>"><?php _e('完整库存'); ?></a>
                                    <a class="btn btn-xs" href="<?php echo htmlspecialchars($salesUrl); ?>"><?php _e('销售记录'); ?></a>
                                <?php endif; ?>
                                <a class="btn btn-xs" href="<?php echo htmlspecialchars($productsUrl); ?>"><?php _e('高级设置'); ?></a>
                                <?php if ($previewUrl !== ''): ?>
                                    <a class="btn btn-xs" href="<?php echo htmlspecialchars($previewUrl); ?>" target="_blank" rel="noopener noreferrer"><?php _e('查看前台'); ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="typechopay-card-tabs__pane typechopay-editor-import" data-typechopay-card-pane="import">
                        <p class="typechopay-editor-muted"><?php _e('一行一张卡密，粘贴后保存文章即可导入。'); ?></p>
                        <p>
                            <label><?php _e('批次名称'); ?></label>
                            <input type="text" name="typechopay_card_batch_name" value="" placeholder="<?php _e('可留空'); ?>" style="width:220px;margin-left:8px;">
                        </p>
                        <textarea name="typechopay_card_lines" placeholder="<?php _e('支持：卡号----卡密、卡号|卡密、Tab 分隔或单独兑换码。'); ?>"></textarea>
                        <p class="typechopay-card-submit-row">
                            <button type="submit" name="do" value="save" class="btn primary" id="typechopay-card-import-submit"><?php _e('确认提交'); ?></button>
                            <small class="typechopay-editor-muted"><?php _e('提交后会保存文章并导入卡密，页面刷新后回到卡密列表。'); ?></small>
                        </p>
                    </div>
                </div>
            </div>
            <script>
            (function () {
                var button = document.getElementById('typechopay-insert-product-shortcode');
                if (!button) {
                    return;
                }
                button.addEventListener('click', function () {
                    var textarea = document.getElementById('text') || document.querySelector('textarea[name="text"]');
                    if (!textarea) {
                        return;
                    }
                    var shortcode = '[typechopay_product]\n\n';
                    if (textarea.value.match(/\[(typechopay|typechopay_product|typechopay_shop)(\s|\])/i)
                        && !window.confirm('<?php echo addslashes(_t('正文已经有购买短代码，仍然插入吗？')); ?>')) {
                        return;
                    }
                    textarea.focus();
                    if (typeof textarea.selectionStart === 'number') {
                        var start = textarea.selectionStart;
                        var end = textarea.selectionEnd;
                        textarea.value = textarea.value.slice(0, start) + shortcode + textarea.value.slice(end);
                        textarea.selectionStart = textarea.selectionEnd = start + shortcode.length;
                    } else {
                        textarea.value += '\n\n' + shortcode;
                    }
                    textarea.dispatchEvent(new Event('input', {bubbles: true}));
                });
            }());
            (function () {
                var roots = document.querySelectorAll('[data-typechopay-card-tabs]');
                for (var i = 0; i < roots.length; i++) {
                    roots[i].addEventListener('click', function (event) {
                        var tab = event.target.closest('[data-typechopay-card-tab]');
                        if (!tab || !this.contains(tab)) {
                            return;
                        }
                        var name = tab.getAttribute('data-typechopay-card-tab');
                        var tabs = this.querySelectorAll('[data-typechopay-card-tab]');
                        var panes = this.querySelectorAll('[data-typechopay-card-pane]');
                        for (var t = 0; t < tabs.length; t++) {
                            tabs[t].classList.toggle('is-active', tabs[t] === tab);
                        }
                        for (var p = 0; p < panes.length; p++) {
                            panes[p].classList.toggle('is-active', panes[p].getAttribute('data-typechopay-card-pane') === name);
                        }
                    });
                }
            }());
            (function () {
                var importButton = document.getElementById('typechopay-card-import-submit');
                var lines = document.querySelector('textarea[name="typechopay_card_lines"]');
                if (!importButton || !lines) {
                    return;
                }
                importButton.addEventListener('click', function (event) {
                    if (lines.value.replace(/\s+/g, '') !== '') {
                        return;
                    }
                    event.preventDefault();
                    lines.focus();
                    window.alert('<?php echo addslashes(_t('请先粘贴卡密后再提交。')); ?>');
                });
            }());
            </script>
        </section>
        <?php
    }

    public static function injectArticleProductShortcode(array $contents, $widget, ...$ignored): array
    {
        $mode = (string) $widget->request->get('typechopay_pay_mode');
        if (!in_array($mode, ['post_access', 'cardcode'], true)) {
            return $contents;
        }

        if ((string) $widget->request->get('typechopay_insert_shortcode') !== '1') {
            return $contents;
        }

        try {
            Support\Money::assertCnyYuanAmount($widget->request->get('typechopay_amount'));
        } catch (\Throwable $e) {
            return $contents;
        }

        $text = (string) ($contents['text'] ?? '');
        if ($text === '' || self::containsExplicitProductUiShortcode($text)) {
            return $contents;
        }

        $contents['text'] = self::prependProductShortcode($text);
        return $contents;
    }

    public static function saveArticlePaySettings(array $contents, $widget): void
    {
        $mode = (string) $widget->request->get('typechopay_pay_mode');
        if (!in_array($mode, ['off', 'post_access', 'cardcode'], true)) {
            return;
        }

        $cid = (int) ($widget->cid ?? 0);
        if ($cid <= 0) {
            return;
        }

        try {
            $productId = self::upsertArticleProduct($cid, $contents, $widget, $mode);
        } catch (\Throwable $e) {
            error_log('[TypechoPay] Failed to save article pay settings: ' . $e->getMessage());
            Notice::alloc()->set(_t('TypechoPay 付费设置保存失败：%s', $e->getMessage()), 'error');
            return;
        }

        if ($mode === 'cardcode' && $productId !== null) {
            try {
                self::importArticleCardLines($productId, $widget);
            } catch (\Throwable $e) {
                error_log('[TypechoPay] Failed to import article card lines: ' . $e->getMessage());
                Notice::alloc()->set(_t('卡密导入失败：%s', $e->getMessage()), 'error');
            }
        }
    }

    /**
     * 渲染文章中的 [typechopay ...] 付款入口。
     *
     * @param string|null $content
     * @param object $archive
     * @return string|null
     */
    public static function renderPayShortcodes($content, $archive)
    {
        if (!is_string($content)) {
            return $content;
        }

        $hasExplicitProductUi = self::containsExplicitProductUiShortcode($content);
        $content = self::renderProtectedContent($content, $archive);
        if (!$hasExplicitProductUi) {
            $content = self::autoInjectProductPanel($content, $archive);
        }

        // Render [typechopay_shop ...] — product listing page.
        $content = preg_replace_callback('/\[typechopay_shop(?:\s+([^\]]*))?\]/i', function ($matches) {
            $attrs = self::parseShortcodeAttrs($matches[1] ?? '');
            return self::renderShopShortcode($attrs);
        }, $content);

        // Render [typechopay_product ...] — single product card.
        $content = preg_replace_callback('/\[typechopay_product(?:\s+([^\]]*))?\]/i', function ($matches) use ($archive) {
            $attrs = self::parseShortcodeAttrs($matches[1] ?? '');
            return self::renderProductCardShortcode($attrs, $archive);
        }, $content);

        $content = preg_replace_callback('/\[typechopay\s+([^\]]+)\]/i', function ($matches) use ($archive) {
            $attrs = self::parseShortcodeAttrs($matches[1]);
            $options = Options::alloc();
            $config = self::pluginConfig($options);
            [$bizType, $bizId] = self::resolveAccessTarget($attrs, $archive);
            $productService = new ProductService(Db::get());

            try {
                $product = $productService->resolve($attrs, [
                    'currency' => $config['defaultCurrency'] ?: 'CNY',
                    'subject' => $archive->title ?? 'TypechoPay Order',
                    'biz_type' => $bizType,
                    'biz_id' => $bizId,
                ]);
            } catch (\Throwable $e) {
                return self::shopCssLink($config)
                    . '<p class="typechopay-error">' . htmlspecialchars($e->getMessage()) . '</p>';
            }

            $currency = (string) $product['currency'];
            $gateways = self::normalizeGateways($attrs['gateways'] ?? implode(',', $config['enabledGateways']));
            $gateways = array_values(array_intersect($gateways, $config['enabledGateways']));
            if (!$gateways) {
                return self::shopCssLink($config)
                    . '<p class="typechopay-error">' . htmlspecialchars(_t('没有可用支付方式')) . '</p>';
            }
            $gateways = array_values(array_filter($gateways, function ($gateway) use ($currency) {
                return self::gatewaySupportsCurrency($gateway, $currency);
            }));
            if (!$gateways) {
                return self::shopCssLink($config)
                    . '<p class="typechopay-error">' . htmlspecialchars(_t('当前币种没有可用支付方式')) . '</p>';
            }

            if (($product['purchase_policy'] ?? 'once') === 'once'
                && self::currentVisitorHasPurchased($product)) {
                return self::shopCssLink($config)
                    . '<div class="typechopay-owned">' . htmlspecialchars(_t('已购买')) . '</div>';
            }

            $returnTo = self::archiveReturnTo($archive, $options);
            $entryPayload = $productService->entryPayload($product, $returnTo);

            return self::renderPayBox($product, $entryPayload, $gateways, $options, $config);
        }, $content);

        return $content;
    }

    /**
     * Render the current article's bound product panel for themes that do not use contentEx.
     *
     * Theme usage: echo \TypechoPlugin\TypechoPay\Plugin::renderArticleProductPanel($this);
     *
     * @param object|null $archive
     */
    public static function renderArticleProductPanel($archive = null): string
    {
        if (!is_object($archive)) {
            return '';
        }

        $cid = self::archiveContentId($archive);
        if ($cid <= 0) {
            return '';
        }

        $product = self::findProductByContentId($cid);
        if (!$product) {
            return self::adminDiagnosticComment('no product found for content_id=' . $cid);
        }

        if ((string) ($product['status'] ?? '') !== 'active') {
            return self::adminDiagnosticComment('product paused');
        }

        $options = Options::alloc();
        $config = self::pluginConfig($options);
        return self::renderProductPanelHtml($product, $archive, $options, $config);
    }

    /**
     * Render a small badge for theme article-list templates.
     *
     * Theme usage: echo \TypechoPlugin\TypechoPay\Plugin::renderPostBadge($this);
     *
     * @param object|null $archive
     */
    public static function renderPostBadge($archive = null): string
    {
        if (!is_object($archive)) {
            return '';
        }

        $cid = self::archiveContentId($archive);
        if ($cid <= 0) {
            return '';
        }

        $product = self::findActiveProductByContentId($cid);
        if (!$product) {
            return '';
        }

        $options = Options::alloc();
        $config = self::pluginConfig($options);
        $css = self::shopCssLink($config);
        $stats = self::productDisplayStats($product);
        $state = self::productDisplayState($product, $stats, $config);
        $templateData = [
            'product' => $product,
            'archive' => $archive,
            'stats' => $stats,
            'state' => $state,
        ];
        $themed = self::renderThemeTemplate('post-badge', $templateData);
        if ($themed !== null) {
            return $css . $themed;
        }

        $typeLabel = ((string) ($product['stock_policy'] ?? 'none') === 'reserve_on_order') ? _t('自动售卡') : _t('付费内容');
        $price = Support\Money::formatForDisplay((int) $product['amount'], (string) ($product['currency'] ?? 'CNY'));
        $stock = (string) ($stats['stock_text'] ?? '');

        return $css . '<span class="typechopay-post-badge typechopay-status--' . htmlspecialchars((string) $state['status']) . '">'
            . '<span class="typechopay-post-badge__label">' . htmlspecialchars($typeLabel) . '</span>'
            . '<strong class="typechopay-post-badge__price">' . htmlspecialchars($price) . '</strong>'
            . ($stock !== '' ? '<span class="typechopay-post-badge__stock">' . htmlspecialchars($stock) . '</span>' : '')
            . '</span>';
    }

    /**
     * @param Options $options
     * @return array
     */
    public static function pluginConfig(Options $options): array
    {
        $plugin = $options->plugin('TypechoPay');
        $enabledGateways = self::normalizeGateways($plugin->enabledGateways ?? ['alipay']);
        if (!$enabledGateways) {
            $enabledGateways = ['alipay'];
        }

        return [
            'enabledGateways' => $enabledGateways,
            'defaultCurrency' => 'CNY',
            'endpointSecret' => (string) ($plugin->endpointSecret ?? ''),
            'productAutoInjectPosition' => self::normalizeAutoInjectPosition(
                (string) ($plugin->productAutoInjectPosition ?? 'off')
            ),
            'loadFrontendCss' => (string) ($plugin->loadFrontendCss ?? '1') !== '0',
            'wechatAppId' => (string) ($plugin->wechatAppId ?? ''),
            'wechatMchId' => (string) ($plugin->wechatMchId ?? ''),
            'wechatMerchantSerial' => (string) ($plugin->wechatMerchantSerial ?? ''),
            'wechatPrivateKeyPath' => (string) ($plugin->wechatPrivateKeyPath ?? ''),
            'wechatPlatformPublicKeyPath' => (string) ($plugin->wechatPlatformPublicKeyPath ?? ''),
            'wechatPlatformSerial' => (string) ($plugin->wechatPlatformSerial ?? ''),
            'wechatApiV3Key' => (string) ($plugin->wechatApiV3Key ?? ''),
            'alipayMode' => (string) ($plugin->alipayMode ?? 'page'),
            'alipayAppId' => (string) ($plugin->alipayAppId ?? ''),
            'alipayGatewayUrl' => self::normalizeAlipayGatewayUrl((string) ($plugin->alipayGatewayUrl ?? '')),
            'alipayPrivateKey' => (string) ($plugin->alipayPrivateKey ?? ''),
            'alipayPublicKey' => (string) ($plugin->alipayPublicKey ?? ''),
            'alipaySellerId' => (string) ($plugin->alipaySellerId ?? ''),
        ];
    }

    private static function normalizeAlipayGatewayUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return 'https://openapi.alipay.com/gateway.do';
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || ($parts['path'] ?? '') === '') {
            return 'https://openapi.alipay.com/gateway.do';
        }

        return $url;
    }

    /**
     * @param Options $options
     * @param array $config
     * @return string
     */
    public static function signingSecret(Options $options, array $config): string
    {
        return $config['endpointSecret'] !== '' ? $config['endpointSecret'] : (string) $options->secret;
    }

    /**
     * Render [typechopay_shop] — product listing with optional category filter.
     */
    private static function renderShopShortcode(array $attrs): string
    {
        $db = Db::get();
        $options = Options::alloc();
        $config = self::pluginConfig($options);
        $css = self::shopCssLink($config);
        $categorySlug = trim((string) ($attrs['category'] ?? ''));
        $columns = max(1, min(6, (int) ($attrs['columns'] ?? 3)));
        $limit = max(1, min(100, (int) ($attrs['limit'] ?? 20)));
        $featured = (string) ($attrs['featured'] ?? '') === '1';
        $typechoCategoryContentIds = self::typechoCategoryContentIdsFromShopAttrs($attrs);

        $select = $db->select()->from('table.pay_products')
            ->where('status = ?', 'active')
            ->order('sort_order', Db::SORT_ASC)
            ->order('id', Db::SORT_DESC)
            ->limit($limit);

        if ($categorySlug !== '') {
            $cat = $db->fetchRow(
                $db->select('id')->from('table.pay_product_categories')
                    ->where('slug = ?', $categorySlug)
                    ->where('status = ?', 'active')
                    ->limit(1)
            );
            if ($cat) {
                $select->where('category_id = ?', (int) $cat['id']);
            } else {
                return $css . '<div class="typechopay-shop"><p class="typechopay-shop__empty">' . htmlspecialchars(_t('商城专题不存在')) . '</p></div>';
            }
        }

        if ($featured) {
            $select->where('is_featured = ?', 1);
        }

        if ($typechoCategoryContentIds !== null) {
            if (!$typechoCategoryContentIds) {
                return $css . '<div class="typechopay-shop"><p class="typechopay-shop__empty">' . htmlspecialchars(_t('暂无商品')) . '</p></div>';
            }
            $select->where('content_id IN ?', $typechoCategoryContentIds);
        }

        $products = $db->fetchAll($select);
        if (!$products) {
            return $css . '<div class="typechopay-shop"><p class="typechopay-shop__empty">' . htmlspecialchars(_t('暂无商品')) . '</p></div>';
        }

        // Load categories for display.
        $categories = [];
        $allCats = $db->fetchAll($db->select()->from('table.pay_product_categories')->where('status = ?', 'active'));
        foreach ($allCats as $c) {
            $categories[(int) $c['id']] = $c;
        }
        $typechoCategories = self::typechoCategoryLabelsForProducts($products);

        // Try theme template first.
        $templateData = compact('products', 'categories', 'typechoCategories', 'columns', 'categorySlug', 'attrs');
        $themed = self::renderThemeTemplate('shop', $templateData);
        if ($themed !== null) {
            return $css . $themed;
        }

        // Default template.
        $html = '<div class="typechopay-shop">';
        $html .= '<div class="typechopay-shop__grid" style="display:grid;grid-template-columns:repeat(' . $columns . ',1fr);gap:20px;">';

        foreach ($products as $product) {
            $html .= self::renderProductCardHtml($product, $categories, $options, $config, $typechoCategories);
        }

        $html .= '</div></div>';
        return $css . $html;
    }

    /**
     * Render [typechopay_product product="key"] — single product card.
     */
    private static function renderProductCardShortcode(array $attrs, $archive = null): string
    {
        $productKey = trim((string) ($attrs['product'] ?? ''));

        $db = Db::get();
        $options = Options::alloc();
        $config = self::pluginConfig($options);
        $css = self::shopCssLink($config);
        if ($productKey !== '') {
            $product = $db->fetchRow(
                $db->select()->from('table.pay_products')
                    ->where('product_key = ?', $productKey)
                    ->where('status = ?', 'active')
                    ->limit(1)
            );
        } else {
            $cid = self::archiveContentId($archive);
            $product = $cid > 0 ? self::findActiveProductByContentId($cid) : null;
        }

        if (!$product) {
            return $css . '<p class="typechopay-error">' . htmlspecialchars(_t('商品不存在、未绑定当前文章或已下架')) . '</p>';
        }

        $categories = self::activeProductCategories();
        $typechoCategories = self::typechoCategoryLabelsForProducts([$product]);
        $stats = self::productDisplayStats($product);
        $state = self::productDisplayState($product, $stats, $config);

        // Try theme template first.
        $templateData = [
            'product' => $product,
            'categories' => $categories,
            'typechoCategories' => $typechoCategories,
            'attrs' => $attrs,
            'stats' => $stats,
            'state' => $state,
        ];
        $themed = self::renderThemeTemplate('product-card', $templateData);
        if ($themed !== null) {
            return $css . $themed;
        }

        return $css . '<div class="typechopay-shop">'
            . self::renderProductCardHtml($product, $categories, $options, $config, $typechoCategories)
            . '</div>';
    }

    private static function autoInjectProductPanel(string $content, $archive): string
    {
        if (!self::isSingleContentArchive($archive)) {
            return $content;
        }

        $options = Options::alloc();
        $config = self::pluginConfig($options);
        $cid = self::archiveContentId($archive);
        if ($cid <= 0) {
            return $content;
        }

        $position = $config['productAutoInjectPosition'];
        if ($position === 'off') {
            return $content . self::adminDiagnosticComment('auto inject off');
        }

        $product = self::findProductByContentId($cid);
        if (!$product) {
            return $content . self::adminDiagnosticComment('no product found for content_id=' . $cid);
        }

        if ((string) ($product['status'] ?? '') !== 'active') {
            return $content . self::adminDiagnosticComment('product paused');
        }

        $panel = self::renderProductPanelHtml($product, $archive, $options, $config);
        if ($panel === '') {
            return $content;
        }

        if ($position === 'bottom') {
            return $content . "\n" . $panel;
        }

        if ($position === 'after_first_paragraph' && preg_match('/<\/p>/i', $content, $match, PREG_OFFSET_CAPTURE)) {
            $offset = $match[0][1] + strlen($match[0][0]);
            return substr($content, 0, $offset) . "\n" . $panel . substr($content, $offset);
        }

        return $panel . "\n" . $content;
    }

    private static function renderProductPanelHtml(array $product, $archive, Options $options, array $config): string
    {
        $css = self::shopCssLink($config);
        $categories = self::activeProductCategories();
        $stats = self::productDisplayStats($product);
        $state = self::productDisplayState($product, $stats, $config);
        $returnTo = self::archiveReturnTo($archive, $options);

        $templateData = [
            'product' => $product,
            'archive' => $archive,
            'categories' => $categories,
            'stats' => $stats,
            'state' => $state,
            'returnTo' => $returnTo,
        ];
        $themed = self::renderThemeTemplate('product-panel', $templateData);
        if ($themed !== null) {
            return $css . $themed . self::productPanelDiagnosticComments($product, $stats, $state, $config);
        }

        $pid = (int) $product['id'];
        $currency = (string) ($product['currency'] ?? 'CNY');
        $amount = (int) $product['amount'];
        $title = (string) ($product['title'] ?? _t('自动售卡'));
        $summary = trim((string) ($product['summary'] ?? ''));
        if ($summary === '') {
            $summary = _t('此内容为自动售卡，请付款后获取卡密信息。');
        }

        $coverUrl = trim((string) ($product['cover_url'] ?? ''));
        $coverHtml = $coverUrl !== ''
            ? '<div class="typechopay-product-panel__cover"><img src="' . htmlspecialchars($coverUrl) . '" alt="' . htmlspecialchars($title) . '"></div>'
            : '';

        $typeLabel = ((string) ($product['stock_policy'] ?? 'none') === 'reserve_on_order') ? _t('自动售卡') : _t('付费内容');
        $stockHtml = $stats['stock_text'] !== ''
            ? '<span class="typechopay-product-panel__stock">' . htmlspecialchars($stats['stock_text']) . '</span>'
            : '';
        $soldHtml = $stats['sold'] > 0
            ? '<span class="typechopay-product-panel__sold">' . htmlspecialchars(_t('已售 %d', $stats['sold'])) . '</span>'
            : '';

        $actions = self::renderProductActionArea(
            $product,
            $options,
            $config,
            $returnTo,
            'typechopay-product-panel__buy',
            $state
        );

        return $css . '<section class="typechopay-product-panel typechopay-status--' . htmlspecialchars($state['status']) . '" data-product-id="' . $pid . '">'
            . $coverHtml
            . '<div class="typechopay-product-panel__main">'
            . '<div class="typechopay-product-panel__label">' . htmlspecialchars($typeLabel) . '</div>'
            . '<h2 class="typechopay-product-panel__title">' . htmlspecialchars($title) . '</h2>'
            . '<p class="typechopay-product-panel__desc">' . htmlspecialchars($summary) . '</p>'
            . '<div class="typechopay-product-panel__meta">'
            . '<span class="typechopay-product-panel__price">' . htmlspecialchars(Support\Money::formatForDisplay($amount, $currency)) . '</span>'
            . $soldHtml
            . $stockHtml
            . '</div>'
            . '<div class="typechopay-product-panel__actions">' . $actions . '</div>'
            . '</div>'
            . '</section>'
            . self::productPanelDiagnosticComments($product, $stats, $state, $config);
    }

    /**
     * Render a single product card HTML.
     */
    private static function renderProductCardHtml(array $product, array $categories, Options $options, array $config, array $typechoCategories = []): string
    {
        $pid = (int) $product['id'];
        $currency = (string) ($product['currency'] ?? 'CNY');
        $amount = (int) $product['amount'];
        $catName = '';
        if (!empty($product['category_id']) && isset($categories[(int) $product['category_id']])) {
            $catName = (string) $categories[(int) $product['category_id']]['name'];
        }
        if ($catName === '' && !empty($product['content_id']) && isset($typechoCategories[(int) $product['content_id']])) {
            $catName = (string) $typechoCategories[(int) $product['content_id']];
        }

        $stats = self::productDisplayStats($product);
        $state = self::productDisplayState($product, $stats, $config);
        $stockHtml = $stats['stock_text'] !== ''
            ? '<div class="typechopay-card__stock">' . htmlspecialchars($stats['stock_text']) . '</div>'
            : '';

        $summary = (string) ($product['summary'] ?? '');
        $coverUrl = (string) ($product['cover_url'] ?? '');
        $coverHtml = '';
        if ($coverUrl !== '') {
            $coverHtml = '<div class="typechopay-card__cover"><img src="' . htmlspecialchars($coverUrl) . '" alt="' . htmlspecialchars($product['title']) . '"></div>';
        }

        $catHtml = '';
        if ($catName !== '') {
            $catHtml = '<span class="typechopay-card__category">' . htmlspecialchars($catName) . '</span>';
        }

        $summaryHtml = '';
        if ($summary !== '') {
            $summaryHtml = '<p class="typechopay-card__summary">' . htmlspecialchars($summary) . '</p>';
        }

        $buttonsHtml = '<div class="typechopay-card__actions">'
            . self::renderProductActionArea($product, $options, $config, (string) $options->index, 'typechopay-card__buy', $state)
            . '</div>';

        return '<article class="typechopay-card" data-product-id="' . $pid . '">'
            . $coverHtml
            . '<div class="typechopay-card__body">'
            . $catHtml
            . '<h3 class="typechopay-card__title">' . htmlspecialchars($product['title']) . '</h3>'
            . '<div class="typechopay-card__price">' . htmlspecialchars(Support\Money::formatForDisplay($amount, $currency)) . '</div>'
            . $stockHtml
            . $summaryHtml
            . $buttonsHtml
            . '</div>'
            . '</article>';
    }

    private static function containsExplicitProductUiShortcode(string $content): bool
    {
        return preg_match('/\[(typechopay|typechopay_product|typechopay_shop)(\s|\])/i', $content) === 1;
    }

    private static function normalizeAutoInjectPosition(string $position): string
    {
        $position = trim($position);
        return in_array($position, ['off', 'top', 'bottom', 'after_first_paragraph'], true) ? $position : 'off';
    }

    private static function isSingleContentArchive($archive): bool
    {
        if (!is_object($archive) || !method_exists($archive, 'is')) {
            return false;
        }

        try {
            if (!$archive->is('single')) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        $type = (string) ($archive->type ?? 'post');
        return in_array($type, ['post', 'page'], true) && self::archiveContentId($archive) > 0;
    }

    private static function archiveContentId($archive): int
    {
        return is_object($archive) && isset($archive->cid) ? (int) $archive->cid : 0;
    }

    private static function findActiveProductByContentId(int $contentId): ?array
    {
        if ($contentId <= 0) {
            return null;
        }

        $db = Db::get();
        $row = $db->fetchRow(
            $db->select()->from('table.pay_products')
                ->where('content_id = ?', $contentId)
                ->where('status = ?', 'active')
                ->order('sort_order', Db::SORT_ASC)
                ->order('id', Db::SORT_DESC)
                ->limit(1)
        );

        return $row ?: null;
    }

    private static function activeProductCategories(): array
    {
        $categories = [];
        $rows = Db::get()->fetchAll(
            Db::get()->select()->from('table.pay_product_categories')->where('status = ?', 'active')
        );
        foreach ($rows as $row) {
            $categories[(int) $row['id']] = $row;
        }

        return $categories;
    }

    private static function typechoCategoryContentIdsFromShopAttrs(array $attrs): ?array
    {
        $midRaw = trim((string) ($attrs['mid'] ?? ($attrs['typecho_mid'] ?? '')));
        $slug = trim((string) ($attrs['category_slug'] ?? ($attrs['typecho_category_slug'] ?? '')));
        $name = trim((string) ($attrs['typecho_category'] ?? ''));
        if ($midRaw === '' && $slug === '' && $name === '') {
            return null;
        }

        $mid = $midRaw !== '' ? filter_var($midRaw, FILTER_VALIDATE_INT) : false;
        $mid = $mid !== false && (int) $mid > 0 ? (int) $mid : self::typechoCategoryMid($slug, $name);
        if ($mid <= 0) {
            return [];
        }

        $rows = Db::get()->fetchAll(
            Db::get()->select('cid')->from('table.relationships')->where('mid = ?', $mid)
        );
        $contentIds = [];
        foreach ($rows as $row) {
            $cid = (int) ($row['cid'] ?? 0);
            if ($cid > 0) {
                $contentIds[$cid] = $cid;
            }
        }

        return array_values($contentIds);
    }

    private static function typechoCategoryMid(string $slug, string $name): int
    {
        $select = Db::get()->select('mid')->from('table.metas')
            ->where('type = ?', 'category')
            ->limit(1);
        if ($slug !== '') {
            $select->where('slug = ?', $slug);
        } elseif ($name !== '') {
            $select->where('name = ?', $name);
        } else {
            return 0;
        }

        $row = Db::get()->fetchRow($select);
        return $row ? (int) ($row['mid'] ?? 0) : 0;
    }

    private static function typechoCategoryLabelsForProducts(array $products): array
    {
        $contentIds = [];
        foreach ($products as $product) {
            $contentId = (int) ($product['content_id'] ?? 0);
            if ($contentId > 0) {
                $contentIds[$contentId] = $contentId;
            }
        }
        if (!$contentIds) {
            return [];
        }

        $relationships = Db::get()->fetchAll(
            Db::get()->select('cid', 'mid')->from('table.relationships')
                ->where('cid IN ?', array_values($contentIds))
        );
        if (!$relationships) {
            return [];
        }

        $mids = [];
        $cidToMids = [];
        foreach ($relationships as $relationship) {
            $cid = (int) ($relationship['cid'] ?? 0);
            $mid = (int) ($relationship['mid'] ?? 0);
            if ($cid > 0 && $mid > 0) {
                $cidToMids[$cid][] = $mid;
                $mids[$mid] = $mid;
            }
        }
        if (!$mids) {
            return [];
        }

        $metas = Db::get()->fetchAll(
            Db::get()->select('mid', 'name')->from('table.metas')
                ->where('type = ?', 'category')
                ->where('mid IN ?', array_values($mids))
        );
        $namesByMid = [];
        foreach ($metas as $meta) {
            $namesByMid[(int) $meta['mid']] = (string) $meta['name'];
        }

        $labels = [];
        foreach ($cidToMids as $cid => $categoryMids) {
            $names = [];
            foreach ($categoryMids as $mid) {
                if (isset($namesByMid[$mid])) {
                    $names[] = $namesByMid[$mid];
                }
            }
            if ($names) {
                $labels[(int) $cid] = implode(', ', array_values(array_unique($names)));
            }
        }

        return $labels;
    }

    private static function productDisplayStats(array $product): array
    {
        $stats = [
            'available' => null,
            'reserved' => 0,
            'delivered' => 0,
            'sold' => 0,
            'total' => 0,
            'stock_text' => '',
        ];

        if ((string) ($product['stock_policy'] ?? 'none') !== 'reserve_on_order') {
            return $stats;
        }

        try {
            $counts = (new Services\CardCodeService(Db::get()))->stockCounts((int) $product['id']);
        } catch (\Throwable $e) {
            return $stats;
        }

        $stats['available'] = (int) $counts['available'];
        $stats['reserved'] = (int) $counts['reserved'];
        $stats['delivered'] = (int) $counts['delivered'];
        $stats['sold'] = (int) $counts['delivered'];
        $stats['total'] = (int) $counts['total'];
        $displayMode = (string) ($product['stock_display_mode'] ?? 'exact');

        if ($displayMode === 'hidden') {
            return $stats;
        }

        if ($displayMode === 'range') {
            if ($stats['available'] >= 100) {
                $stats['stock_text'] = _t('库存充足');
            } elseif ($stats['available'] >= 10) {
                $stats['stock_text'] = _t('库存 %d+', $stats['available'] - $stats['available'] % 10);
            } elseif ($stats['available'] > 0) {
                $stats['stock_text'] = _t('库存少量');
            } else {
                $stats['stock_text'] = _t('已售罄');
            }
            return $stats;
        }

        $stats['stock_text'] = _t('库存 %d', $stats['available']);
        return $stats;
    }

    private static function productDisplayState(array $product, array $stats, array $config): array
    {
        if ((string) ($product['status'] ?? 'active') !== 'active') {
            return ['status' => 'paused', 'can_buy' => false, 'label' => _t('商品已下架'), 'gateways' => []];
        }

        if ((string) ($product['purchase_policy'] ?? 'repeatable') === 'once' && self::currentVisitorHasPurchased($product)) {
            return ['status' => 'owned', 'can_buy' => false, 'label' => _t('已购买'), 'gateways' => []];
        }

        if ((string) ($product['stock_policy'] ?? 'none') === 'reserve_on_order'
            && $stats['available'] !== null
            && (int) $stats['available'] <= 0) {
            return ['status' => 'soldout', 'can_buy' => false, 'label' => _t('商品已售罄'), 'gateways' => []];
        }

        if ((int) ($product['allow_guest'] ?? 1) !== 1 && !User::alloc()->hasLogin()) {
            return ['status' => 'login_required', 'can_buy' => false, 'label' => _t('登录后购买'), 'gateways' => []];
        }

        $gateways = self::availableProductGateways($product, $config);
        if (!$gateways) {
            return ['status' => 'unavailable', 'can_buy' => false, 'label' => _t('暂无可用支付方式'), 'gateways' => []];
        }

        return ['status' => 'available', 'can_buy' => true, 'label' => _t('立即购买'), 'gateways' => $gateways];
    }

    private static function availableProductGateways(array $product, array $config): array
    {
        $currency = (string) ($product['currency'] ?? 'CNY');
        $gateways = self::normalizeGateways(implode(',', $config['enabledGateways']));
        return array_values(array_filter($gateways, function ($gateway) use ($currency) {
            return self::gatewaySupportsCurrency($gateway, $currency);
        }));
    }

    private static function articleProductVisibilityStatus(bool $containsShortcode, string $autoInjectPosition): string
    {
        if ($containsShortcode) {
            return _t('会显示：正文已插入购买模块');
        }

        $labels = [
            'top' => _t('正文顶部'),
            'bottom' => _t('正文底部'),
            'after_first_paragraph' => _t('第一段之后'),
        ];
        if (isset($labels[$autoInjectPosition])) {
            return _t('会显示：全局自动插入%s', $labels[$autoInjectPosition]);
        }

        return _t('可能不显示：需插入短代码或主题调用 helper');
    }

    private static function editorContentPermalink($content): string
    {
        try {
            $permalink = is_object($content) ? (string) ($content->permalink ?? '') : '';
        } catch (\Throwable $e) {
            return '';
        }

        return $permalink !== '' ? $permalink : '';
    }

    private static function productPanelDiagnosticComments(array $product, array $stats, array $state, array $config): string
    {
        $comments = '';
        $productId = (int) ($product['id'] ?? ($product['product_id'] ?? 0));
        if ($productId > 0 && !self::productDeliverableHandlers($productId)) {
            $comments .= self::adminDiagnosticComment('no deliverable');
        }

        if (($state['status'] ?? '') === 'unavailable' || !self::availableProductGateways($product, $config)) {
            $comments .= self::adminDiagnosticComment('no gateway');
        }

        if ((string) ($product['stock_policy'] ?? 'none') === 'reserve_on_order'
            && $stats['available'] !== null
            && (int) $stats['available'] <= 0) {
            $comments .= self::adminDiagnosticComment('no stock');
        }

        return $comments;
    }

    private static function currentVisitorCardDeliveryUrl(array $product, Options $options): string
    {
        $productId = (int) ($product['id'] ?? ($product['product_id'] ?? 0));
        if ($productId <= 0 || !in_array('cardcode', self::productDeliverableHandlers($productId), true)) {
            return '';
        }

        $user = User::alloc();
        $userId = $user->hasLogin() ? (int) $user->uid : null;
        $guestTokenHash = GuestToken::hash(GuestToken::get());
        if ($userId !== null && $guestTokenHash !== null) {
            (new GuestClaimService(Db::get()))->claimAll($userId, $guestTokenHash);
        }

        if ($userId === null && ($guestTokenHash === null || $guestTokenHash === '')) {
            return '';
        }

        $db = Db::get();
        $select = $db->select('id', 'out_trade_no')->from('table.pay_orders')
            ->where('product_id = ?', $productId)
            ->where('payment_status = ?', 'paid')
            ->order('id', Db::SORT_DESC)
            ->limit(10);

        if ($userId !== null) {
            $select->where('user_id = ?', $userId);
        } else {
            $select->where('guest_token_hash = ?', $guestTokenHash)
                ->where('user_id IS NULL');
        }

        foreach ($db->fetchAll($select) as $order) {
            $card = $db->fetchRow(
                $db->select('id')->from('table.pay_card_items')
                    ->where('delivered_order_id = ?', (int) ($order['id'] ?? 0))
                    ->where('status = ?', 'delivered')
                    ->limit(1)
            );
            if ($card) {
                return Common::url(
                    '/action/' . self::ACTION . '?do=delivery&out_trade_no=' . rawurlencode((string) $order['out_trade_no']),
                    $options->index
                );
            }
        }

        return '';
    }

    private static function renderProductActionArea(
        array $product,
        Options $options,
        array $config,
        string $returnTo,
        string $buttonClass,
        array $state
    ): string {
        $deliveryUrl = self::currentVisitorCardDeliveryUrl($product, $options);
        if (empty($state['can_buy'])) {
            if ($deliveryUrl !== '') {
                return '<a class="' . htmlspecialchars($buttonClass) . ' typechopay-button--secondary" href="'
                    . htmlspecialchars($deliveryUrl) . '">' . htmlspecialchars(_t('查看我的卡密')) . '</a>';
            }

            if (($state['status'] ?? '') === 'login_required') {
                return '<a class="' . htmlspecialchars($buttonClass) . ' typechopay-button--secondary" href="'
                    . htmlspecialchars((string) $options->loginUrl) . '">' . htmlspecialchars((string) $state['label']) . '</a>';
            }

            return '<button type="button" class="' . htmlspecialchars($buttonClass)
                . ' typechopay-button--disabled" disabled>' . htmlspecialchars((string) $state['label']) . '</button>';
        }

        $action = Common::url('/action/' . self::ACTION . '?do=prepare', $options->index);
        $labels = ['wechat' => '微信支付', 'alipay' => '支付宝'];
        $buttons = [];
        foreach ($state['gateways'] as $gateway) {
            $payload = [
                'product_id' => (string) (int) $product['id'],
                'gateway' => $gateway,
                'return_to' => $returnTo,
            ];
            $payload['entry_signature'] = Support\Signer::sign($payload, self::signingSecret($options, $config));

            $fields = '';
            foreach ($payload as $key => $value) {
                $fields .= '<input type="hidden" name="' . htmlspecialchars($key) . '" value="'
                    . htmlspecialchars((string) $value) . '">';
            }
            $buttons[] = '<form method="post" action="' . htmlspecialchars($action) . '" class="typechopay-form">'
                . $fields
                . '<button type="submit" class="' . htmlspecialchars($buttonClass) . '">'
                . htmlspecialchars($labels[$gateway] ?? $gateway)
                . '</button></form>';
        }

        if ($deliveryUrl !== '') {
            $buttons[] = '<a class="' . htmlspecialchars($buttonClass) . ' typechopay-button--secondary" href="'
                . htmlspecialchars($deliveryUrl) . '">' . htmlspecialchars(_t('查看我的卡密')) . '</a>';
        }

        return implode('', $buttons);
    }

    private static function prependProductShortcode(string $text): string
    {
        $shortcode = "[typechopay_product]\n\n";
        $markdownPrefix = '<!--markdown-->';
        if (strpos($text, $markdownPrefix) === 0) {
            return $markdownPrefix . "\n" . $shortcode . substr($text, strlen($markdownPrefix));
        }

        return $shortcode . $text;
    }

    private static function upsertArticleProduct(int $cid, array $contents, $widget, string $mode): ?int
    {
        $db = Db::get();
        $product = self::findProductByContentId($cid);
        $now = date('Y-m-d H:i:s');

        if ($mode === 'off') {
            if ($product && (string) ($product['status'] ?? '') !== 'paused') {
                $db->query($db->update('table.pay_products')->rows([
                    'status' => 'paused',
                    'version' => (int) ($product['version'] ?? 1) + 1,
                    'updated_at' => $now,
                ])->where('id = ?', (int) $product['id']));
            }
            return null;
        }

        $title = trim((string) $widget->request->get('typechopay_product_title'));
        if ($title === '') {
            $title = trim((string) ($contents['title'] ?? ''));
        }
        $titleLength = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
        if ($title === '' || $titleLength > 255) {
            throw new \InvalidArgumentException('请填写 1-255 字的商品标题。');
        }

        $contentType = (string) ($contents['type'] ?? 'post');
        $deliverableTargetType = strpos($contentType, 'page') === 0 ? 'page' : 'post';

        $productKey = trim((string) $widget->request->get('typechopay_product_key'));
        if ($productKey === '') {
            $productKey = $deliverableTargetType . '-' . $cid;
        }
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/', $productKey)) {
            throw new \InvalidArgumentException('商品标识只允许字母、数字、点、横线、下划线和冒号。');
        }

        $duplicate = $db->fetchRow(
            $db->select('id')->from('table.pay_products')
                ->where('product_key = ?', $productKey)
                ->limit(1)
        );
        if ($duplicate && (!$product || (int) $duplicate['id'] !== (int) $product['id'])) {
            throw new \InvalidArgumentException('商品标识已存在，请换一个标识。');
        }

        $amount = Support\Money::assertCnyYuanAmount($widget->request->get('typechopay_amount'));
        $currency = 'CNY';
        $policy = strtolower(trim((string) $widget->request->get('typechopay_purchase_policy'))) ?: 'repeatable';
        if (!in_array($policy, ['once', 'repeatable', 'limited'], true)) {
            throw new \InvalidArgumentException('购买策略无效。');
        }
        $maxPerUser = filter_var($widget->request->get('typechopay_max_per_user'), FILTER_VALIDATE_INT);
        $maxPerUser = ($policy === 'limited' && $maxPerUser !== false && (int) $maxPerUser > 0) ? (int) $maxPerUser : null;
        $allowGuest = (string) $widget->request->get('typechopay_purchase_permission') === 'all' ? 1 : 0;
        $stockDisplayMode = in_array((string) $widget->request->get('typechopay_stock_display_mode'), ['exact', 'range', 'hidden'], true)
            ? (string) $widget->request->get('typechopay_stock_display_mode')
            : 'exact';
        $coverUrl = trim((string) $widget->request->get('typechopay_cover_url'));
        $coverUrl = $coverUrl !== '' ? $coverUrl : null;
        $summary = trim((string) $widget->request->get('typechopay_summary'));
        $summary = $summary !== '' ? $summary : null;
        $stockPolicy = $mode === 'cardcode' ? 'reserve_on_order' : 'none';
        $enablePostAccess = $mode === 'post_access'
            || (string) $widget->request->get('typechopay_unlock_article') === '1';
        $enableCardcode = $mode === 'cardcode';

        $oldHandlers = $product ? self::productDeliverableHandlers((int) $product['id']) : [];
        $newHandlers = [];
        if ($enablePostAccess) {
            $newHandlers[] = 'post_access';
        }
        if ($enableCardcode) {
            $newHandlers[] = 'cardcode';
        }
        sort($oldHandlers);
        sort($newHandlers);

        $versionBump = 1;
        if ($product) {
            $oldMaxPerUser = isset($product['max_per_user']) && (int) $product['max_per_user'] > 0
                ? (int) $product['max_per_user']
                : null;
            $versionBump = ((string) ($product['product_key'] ?? '') !== $productKey
                || (int) ($product['amount'] ?? 0) !== $amount
                || (string) ($product['currency'] ?? '') !== $currency
                || (string) ($product['status'] ?? '') !== 'active'
                || (int) ($product['allow_guest'] ?? 1) !== $allowGuest
                || (string) ($product['purchase_policy'] ?? '') !== $policy
                || $oldMaxPerUser !== $maxPerUser
                || (int) ($product['content_id'] ?? 0) !== $cid
                || (string) ($product['stock_policy'] ?? '') !== $stockPolicy
                || $oldHandlers !== $newHandlers) ? 1 : 0;
        }

        $db->query('START TRANSACTION', Db::WRITE, '');
        try {
            if ($product) {
                $productId = (int) $product['id'];
                $db->query($db->update('table.pay_products')->rows([
                    'product_key' => $productKey,
                    'title' => $title,
                    'content_id' => $cid,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => 'active',
                    'allow_guest' => $allowGuest,
                    'purchase_policy' => $policy,
                    'max_per_user' => $maxPerUser,
                    'stock_policy' => $stockPolicy,
                    'cover_url' => $coverUrl,
                    'summary' => $summary,
                    'stock_display_mode' => $stockDisplayMode,
                    'version' => (int) ($product['version'] ?? 1) + $versionBump,
                    'updated_at' => $now,
                ])->where('id = ?', $productId));
            } else {
                $productId = (int) $db->query($db->insert('table.pay_products')->rows([
                    'product_key' => $productKey,
                    'title' => $title,
                    'content_id' => $cid,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => 'active',
                    'allow_guest' => $allowGuest,
                    'purchase_policy' => $policy,
                    'max_per_user' => $maxPerUser,
                    'duration_seconds' => null,
                    'version' => 1,
                    'stock_policy' => $stockPolicy,
                    'category_id' => null,
                    'cover_url' => $coverUrl,
                    'summary' => $summary,
                    'description' => null,
                    'sort_order' => 0,
                    'is_featured' => 0,
                    'stock_display_mode' => $stockDisplayMode,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }

            $db->query($db->delete('table.pay_product_deliverables')->where('product_id = ?', $productId));
            if ($enablePostAccess) {
                $db->query($db->insert('table.pay_product_deliverables')->rows([
                    'product_id' => $productId,
                    'handler' => 'post_access',
                    'target_type' => $deliverableTargetType,
                    'target_id' => $cid,
                    'target_key' => null,
                    'config_json' => null,
                    'sort_order' => 10,
                    'enabled' => 1,
                ]));
            }
            if ($enableCardcode) {
                $db->query($db->insert('table.pay_product_deliverables')->rows([
                    'product_id' => $productId,
                    'handler' => 'cardcode',
                    'target_type' => 'cardcode',
                    'target_id' => null,
                    'target_key' => 'default',
                    'config_json' => null,
                    'sort_order' => 20,
                    'enabled' => 1,
                ]));
            }
            $db->query('COMMIT', Db::WRITE, '');
            return $productId;
        } catch (\Throwable $e) {
            try {
                $db->query('ROLLBACK', Db::WRITE, '');
            } catch (\Throwable $rollback) {
            }
            throw $e;
        }
    }

    private static function importArticleCardLines(int $productId, $widget): void
    {
        $rawLines = (string) $widget->request->get('typechopay_card_lines');
        if (trim($rawLines) === '') {
            return;
        }

        $batchName = trim((string) $widget->request->get('typechopay_card_batch_name'));
        if ($batchName === '') {
            $batchName = 'article-' . date('YmdHis');
        }

        $user = User::alloc();
        $result = (new CardCodeService(Db::get()))->importBatch(
            $productId,
            $batchName,
            $rawLines,
            $user->hasLogin() ? (int) $user->uid : null
        );

        Notice::alloc()->set(
            _t(
                '卡密导入完成：原始 %d 条，文件内重复 %d 条，成功 %d 条，数据库重复 %d 条。',
                $result['raw_count'],
                $result['duplicate_in_file'],
                $result['imported'],
                $result['duplicates']
            ),
            $result['imported'] > 0 ? 'success' : 'notice'
        );
    }

    private static function articleCardStats(int $productId): array
    {
        if ($productId <= 0) {
            return self::emptyArticleCardStats();
        }

        return (new CardCodeService(Db::get()))->stockCounts($productId);
    }

    private static function recentArticleCards(int $productId, int $limit): array
    {
        if ($productId <= 0) {
            return [];
        }

        $limit = max(1, min(20, $limit));
        return Db::get()->fetchAll(
            Db::get()->select('code_mask', 'status', 'created_at')->from('table.pay_card_items')
                ->where('product_id = ?', $productId)
                ->order('id', Db::SORT_DESC)
                ->limit($limit)
        );
    }

    private static function emptyArticleCardStats(): array
    {
        return [
            'total' => 0,
            'available' => 0,
            'reserved' => 0,
            'delivered' => 0,
            'void' => 0,
            'compromised' => 0,
        ];
    }

    private static function findProductByContentId(int $contentId): ?array
    {
        if ($contentId <= 0) {
            return null;
        }

        $row = Db::get()->fetchRow(
            Db::get()->select()->from('table.pay_products')
                ->where('content_id = ?', $contentId)
                ->order('id', Db::SORT_DESC)
                ->limit(1)
        );

        return $row ?: null;
    }

    private static function adminDiagnosticComment(string $message): string
    {
        try {
            $user = User::alloc();
            if (!$user->hasLogin() || !$user->pass('administrator', true)) {
                return '';
            }
        } catch (\Throwable $e) {
            return '';
        }

        $message = str_replace(['--', '<', '>'], ['-', '', ''], $message);
        return "\n<!-- TypechoPay: " . $message . " -->";
    }

    private static function productDeliverableHandlers(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $rows = Db::get()->fetchAll(
            Db::get()->select('handler')->from('table.pay_product_deliverables')
                ->where('product_id = ?', $productId)
                ->where('enabled = ?', 1)
                ->order('sort_order', Db::SORT_ASC)
        );

        $handlers = [];
        foreach ($rows as $row) {
            $handler = (string) ($row['handler'] ?? '');
            if ($handler !== '') {
                $handlers[] = $handler;
            }
        }

        return array_values(array_unique($handlers));
    }

    /**
     * Try to render a theme template. Returns null if no theme template found.
     */
    private static function renderThemeTemplate(string $name, array $data): ?string
    {
        $options = Options::alloc();
        $themeDir = __TYPECHO_ROOT_DIR__ . '/usr/themes/' . $options->theme . '/typechopay';
        $templateFile = $themeDir . '/' . $name . '.php';

        if (is_file($templateFile)) {
            extract($data, EXTR_SKIP);
            ob_start();
            try {
                include $templateFile;
            } catch (\Throwable $e) {
                ob_end_clean();
                error_log('[TypechoPay] Theme template error (' . $name . '): ' . $e->getMessage());
                return null;
            }
            return ob_get_clean();
        }

        return null;
    }

    /**
     * Return the default frontend CSS link once per request.
     */
    private static function shopCssLink(?array $config = null): string
    {
        $config = $config ?? self::pluginConfig(Options::alloc());
        if (empty($config['loadFrontendCss'])) {
            return '';
        }

        static $loaded = false;
        if ($loaded) {
            return '';
        }
        $loaded = true;

        $options = Options::alloc();
        $pluginUrl = Common::url('usr/plugins/TypechoPay/', $options->siteUrl);
        $cssUrl = $pluginUrl . 'assets/typechopay.css';

        // Check if theme provides its own override CSS.
        $themeDir = __TYPECHO_ROOT_DIR__ . '/usr/themes/' . $options->theme . '/typechopay';
        if (is_file($themeDir . '/style.css')) {
            $cssUrl = Common::url('usr/themes/' . $options->theme . '/typechopay/style.css', $options->siteUrl);
        }

        return '<link rel="stylesheet" href="' . htmlspecialchars($cssUrl) . '">' . "\n";
    }

    private static function renderPayBox(array $display, array $entryPayload, array $gateways, Options $options, array $config): string
    {
        $action = Common::url('/action/' . self::ACTION . '?do=prepare', $options->index);
        $labels = [
            'wechat' => '微信支付',
            'alipay' => '支付宝',
        ];

        $buttons = [];
        foreach ($gateways as $gateway) {
            $signedPayload = $entryPayload + [
                'gateway' => $gateway,
            ];
            $signedPayload['entry_signature'] = Support\Signer::sign($signedPayload, self::signingSecret($options, $config));

            $fields = '';
            foreach ($signedPayload as $key => $value) {
                $fields .= '<input type="hidden" name="' . htmlspecialchars($key) . '" value="'
                    . htmlspecialchars((string) $value) . '">';
            }
            $buttons[] = '<form method="post" action="' . htmlspecialchars($action)
                . '" class="typechopay-form">' . $fields
                . '<button type="submit">' . htmlspecialchars($labels[$gateway] ?? $gateway) . '</button></form>';
        }

        return self::shopCssLink($config)
            . '<div class="typechopay-box" data-typechopay="1">'
            . '<strong>' . htmlspecialchars((string) $display['subject']) . '</strong>'
            . '<span class="typechopay-amount">' . htmlspecialchars(Support\Money::formatForDisplay((int) $display['amount'], (string) $display['currency'])) . '</span>'
            . implode('', $buttons)
            . '</div>';
    }

    private static function renderProtectedContent(string $content, $archive): string
    {
        if (strpos($content, '[typechopay_content') === false) {
            return $content;
        }

        return preg_replace_callback('/\[typechopay_content(?:\s+([^\]]+))?\](.*?)\[\/typechopay_content\]/is', function ($matches) use ($archive) {
            $attrs = self::parseShortcodeAttrs($matches[1] ?? '');
            [$bizType, $bizId] = self::resolveAccessTarget($attrs, $archive);
            if (self::currentVisitorCanAccess($bizType, $bizId)) {
                return $matches[2];
            }

            return self::shopCssLink()
                . '<div class="typechopay-locked">' . htmlspecialchars(_t('此内容需要购买后查看。')) . '</div>';
        }, $content);
    }

    private static function resolveAccessTarget(array $attrs, $archive): array
    {
        if (!empty($attrs['product']) && strpos($attrs['product'], ':') !== false) {
            [$type, $id] = explode(':', (string) $attrs['product'], 2);
            return [trim($type) ?: 'post', (int) $id];
        }

        return [
            trim((string) ($attrs['biz_type'] ?? 'post')) ?: 'post',
            (int) ($attrs['biz_id'] ?? ($archive->cid ?? 0)),
        ];
    }

    private static function currentVisitorCanAccess(string $bizType, int $bizId): bool
    {
        $user = User::alloc();
        $userId = $user->hasLogin() ? (int) $user->uid : null;
        $guestTokenHash = GuestToken::hash(GuestToken::get());
        if ($userId !== null && $guestTokenHash !== null) {
            (new GuestClaimService(Db::get()))->claimAll($userId, $guestTokenHash);
        }

        return (new AccessService(Db::get()))->canAccess($bizType, $bizId, $userId, $guestTokenHash);
    }

    /**
     * Check if the current visitor has already purchased this product
     * (based on paid order history, not content access).
     */
    private static function currentVisitorHasPurchased(array $product): bool
    {
        $productId = (int) ($product['product_id'] ?? ($product['id'] ?? ($product['snapshot']['id'] ?? 0)));
        if ($productId <= 0) {
            // Legacy inline products: fall back to content-based check.
            return self::currentVisitorCanAccess((string) $product['biz_type'], (int) $product['biz_id']);
        }

        $user = User::alloc();
        $userId = $user->hasLogin() ? (int) $user->uid : null;
        $guestTokenHash = GuestToken::hash(GuestToken::get());
        if ($userId !== null && $guestTokenHash !== null) {
            (new GuestClaimService(Db::get()))->claimAll($userId, $guestTokenHash);
        }

        return (new PurchasePolicyService(Db::get()))->hasPurchased($productId, $userId, $guestTokenHash);
    }

    private static function archiveReturnTo($archive, Options $options): string
    {
        $permalink = $archive->permalink ?? '';
        return is_string($permalink) && $permalink !== '' ? $permalink : (string) $options->index;
    }

    private static function parseShortcodeAttrs(string $raw): array
    {
        $attrs = [];
        preg_match_all('/([a-zA-Z_][a-zA-Z0-9_-]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s]+))/', $raw, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $attrs[strtolower($match[1])] = $match[3] !== '' ? $match[3] : ($match[4] !== '' ? $match[4] : $match[5]);
        }

        return $attrs;
    }

    private static function normalizeGateways($gateways): array
    {
        $items = is_array($gateways) ? $gateways : explode(',', (string) $gateways);
        $normalized = [];
        foreach ($items as $item) {
            $gateway = strtolower(trim((string) $item));
            if (in_array($gateway, ['wechat', 'alipay'], true)) {
                $normalized[] = $gateway;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function gatewaySupportsCurrency(string $gateway, string $currency): bool
    {
        if (in_array($gateway, ['wechat', 'alipay'], true)) {
            return $currency === 'CNY';
        }

        return false;
    }

    private static function installTables()
    {
        $db = Db::get();
        $adapter = strtolower($db->getAdapterName());
        $prefix = $db->getPrefix();
        $schemaVersion = self::schemaVersion($db);

        foreach (self::schemaSql($adapter, $prefix) as $sql) {
            $db->query($sql, Db::WRITE, '');
        }

        if ($schemaVersion < self::SCHEMA_VERSION) {
            self::migrateExistingTables($db, $adapter, $prefix);
            // Only set the version if the core tables are usable.
            // This prevents a partial migration from being marked as complete.
            if (self::tablesAreUsable($db, $prefix)) {
                self::setSchemaVersion($db, self::SCHEMA_VERSION);
            } else {
                error_log('[TypechoPay] Schema migration incomplete — will retry on next activation.');
            }
        }
    }

    /**
     * Verify that critical tables and columns exist after migration.
     */
    private static function tablesAreUsable(Db $db, string $prefix): bool
    {
        try {
            // Check that pay_orders has the payment_status column (introduced in v2).
            $db->fetchRow(
                $db->select('payment_status')->from('table.pay_orders')->limit(1)
            );
            $db->fetchRow(
                $db->select('return_token_expires_at')->from('table.pay_orders')->limit(1)
            );
            // Check that pay_card_items table is accessible.
            $db->fetchRow(
                $db->select('status')->from('table.pay_card_items')->limit(1)
            );
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function schemaSql(string $adapter, string $prefix): array
    {
        if (strpos($adapter, 'pgsql') !== false) {
            return self::pgsqlSchema($prefix);
        }

        if (strpos($adapter, 'sqlite') !== false) {
            return self::sqliteSchema($prefix);
        }

        return self::mysqlSchema($prefix);
    }

    private static function mysqlSchema(string $prefix): array
    {
        $orders = '`' . $prefix . 'pay_orders`';
        $events = '`' . $prefix . 'pay_events`';
        $entitlements = '`' . $prefix . 'pay_entitlements`';
        $categories = '`' . $prefix . 'pay_product_categories`';
        $products = '`' . $prefix . 'pay_products`';
        $deliverables = '`' . $prefix . 'pay_product_deliverables`';
        $fulfillments = '`' . $prefix . 'pay_fulfillments`';
        $cardBatches = '`' . $prefix . 'pay_card_batches`';
        $cardItems = '`' . $prefix . 'pay_card_items`';
        $nonces = '`' . $prefix . 'pay_nonces`';

        return [
            "CREATE TABLE IF NOT EXISTS {$categories} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `slug` VARCHAR(128) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `description` TEXT DEFAULT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `status` VARCHAR(32) NOT NULL DEFAULT 'active',
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_slug` (`slug`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS {$orders} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `out_trade_no` VARCHAR(64) NOT NULL,
                `gateway` VARCHAR(32) NOT NULL,
                `subject` VARCHAR(255) NOT NULL,
                `amount` BIGINT NOT NULL,
                `currency` VARCHAR(8) NOT NULL DEFAULT 'CNY',
                `biz_type` VARCHAR(32) NOT NULL DEFAULT 'post',
                `biz_id` BIGINT UNSIGNED DEFAULT NULL,
                `product_id` BIGINT UNSIGNED DEFAULT NULL,
                `product_key` VARCHAR(128) DEFAULT NULL,
                `product_version` INT NOT NULL DEFAULT 0,
                `product_snapshot_json` MEDIUMTEXT DEFAULT NULL,
                `user_id` BIGINT UNSIGNED DEFAULT NULL,
                `guest_token_hash` VARCHAR(128) DEFAULT NULL,
                `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
                `payment_status` VARCHAR(32) NOT NULL DEFAULT 'pending',
                `fulfillment_status` VARCHAR(32) NOT NULL DEFAULT 'none',
                `poll_token_hash` VARCHAR(128) DEFAULT NULL,
                `return_token_hash` VARCHAR(128) DEFAULT NULL,
                `return_token_expires_at` DATETIME DEFAULT NULL,
                `delivery_token_hash` VARCHAR(128) DEFAULT NULL,
                `return_token_used` TINYINT(1) NOT NULL DEFAULT 0,
                `platform_trade_no` VARCHAR(128) DEFAULT NULL,
                `pay_url` TEXT DEFAULT NULL,
                `qr_content` TEXT DEFAULT NULL,
                `return_to` TEXT DEFAULT NULL,
                `last_queried_at` DATETIME DEFAULT NULL,
                `query_count` INT NOT NULL DEFAULT 0,
                `paid_at` DATETIME DEFAULT NULL,
                `expired_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_out_trade_no` (`out_trade_no`),
                KEY `idx_biz` (`biz_type`, `biz_id`),
                KEY `idx_product` (`product_id`, `product_key`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS {$events} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `out_trade_no` VARCHAR(64) NOT NULL,
                `gateway` VARCHAR(32) NOT NULL,
                `event_type` VARCHAR(64) NOT NULL,
                `provider_event_id` VARCHAR(128) DEFAULT NULL,
                `provider_event_type` VARCHAR(64) DEFAULT NULL,
                `platform_trade_no` VARCHAR(128) DEFAULT NULL,
                `remote_ip` VARCHAR(64) DEFAULT NULL,
                `headers_json` MEDIUMTEXT DEFAULT NULL,
                `signature_ok` TINYINT(1) NOT NULL DEFAULT 0,
                `payload` MEDIUMTEXT,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_order` (`out_trade_no`),
                UNIQUE KEY `{$prefix}pay_events_uniq_provider_event` (`gateway`, `provider_event_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS {$entitlements} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` BIGINT UNSIGNED NOT NULL,
                `deliverable_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `biz_type` VARCHAR(32) NOT NULL,
                `biz_id` BIGINT UNSIGNED NOT NULL,
                `user_id` BIGINT UNSIGNED DEFAULT NULL,
                `guest_token_hash` VARCHAR(128) DEFAULT NULL,
                `starts_at` DATETIME NOT NULL,
                `expires_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_order_deliverable` (`order_id`, `deliverable_id`),
                KEY `idx_user_access` (`user_id`, `biz_type`, `biz_id`),
                KEY `idx_guest_access` (`guest_token_hash`, `biz_type`, `biz_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS {$products} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_key` VARCHAR(128) NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `content_id` BIGINT UNSIGNED DEFAULT NULL,
                `amount` BIGINT NOT NULL,
                `currency` VARCHAR(8) NOT NULL DEFAULT 'CNY',
                `status` VARCHAR(32) NOT NULL DEFAULT 'active',
                `allow_guest` TINYINT(1) NOT NULL DEFAULT 1,
                `purchase_policy` VARCHAR(32) NOT NULL DEFAULT 'once',
                `max_per_user` INT DEFAULT NULL,
                `duration_seconds` BIGINT DEFAULT NULL,
                `version` INT NOT NULL DEFAULT 1,
                `stock_policy` VARCHAR(32) NOT NULL DEFAULT 'none',
                `category_id` BIGINT UNSIGNED DEFAULT NULL,
                `cover_url` VARCHAR(512) DEFAULT NULL,
                `summary` VARCHAR(512) DEFAULT NULL,
                `description` TEXT DEFAULT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
                `sales_count` INT NOT NULL DEFAULT 0,
                `stock_display_mode` VARCHAR(32) NOT NULL DEFAULT 'exact',
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_product_key` (`product_key`),
                KEY `idx_content` (`content_id`),
                KEY `idx_status` (`status`),
                KEY `idx_category` (`category_id`),
                KEY `idx_featured` (`is_featured`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS {$deliverables} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_id` BIGINT UNSIGNED NOT NULL,
                `handler` VARCHAR(64) NOT NULL,
                `target_type` VARCHAR(64) DEFAULT NULL,
                `target_id` BIGINT UNSIGNED DEFAULT NULL,
                `target_key` VARCHAR(128) DEFAULT NULL,
                `config_json` MEDIUMTEXT DEFAULT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                KEY `idx_product` (`product_id`, `enabled`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS {$fulfillments} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` BIGINT UNSIGNED NOT NULL,
                `deliverable_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `handler` VARCHAR(64) NOT NULL,
                `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
                `attempts` INT NOT NULL DEFAULT 0,
                `card_item_id` BIGINT UNSIGNED DEFAULT NULL,
                `result_json` MEDIUMTEXT DEFAULT NULL,
                `last_error` TEXT DEFAULT NULL,
                `started_at` DATETIME DEFAULT NULL,
                `fulfilled_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_order_deliverable` (`order_id`, `deliverable_id`),
                KEY `idx_status` (`status`),
                KEY `idx_card_item` (`card_item_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS {$cardBatches} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_id` BIGINT UNSIGNED NOT NULL,
                `batch_name` VARCHAR(128) NOT NULL,
                `imported_count` INT NOT NULL DEFAULT 0,
                `imported_by` BIGINT UNSIGNED DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_product` (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS {$cardItems} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_id` BIGINT UNSIGNED NOT NULL,
                `batch_id` BIGINT UNSIGNED DEFAULT NULL,
                `code_ciphertext` MEDIUMTEXT NOT NULL,
                `secret_ciphertext` MEDIUMTEXT DEFAULT NULL,
                `code_mask` VARCHAR(64) DEFAULT NULL,
                `fingerprint` VARCHAR(128) NOT NULL,
                `status` VARCHAR(32) NOT NULL DEFAULT 'available',
                `reserved_order_id` BIGINT UNSIGNED DEFAULT NULL,
                `reserved_until` DATETIME DEFAULT NULL,
                `delivered_order_id` BIGINT UNSIGNED DEFAULT NULL,
                `delivered_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_product_fingerprint` (`product_id`, `fingerprint`),
                UNIQUE KEY `uniq_reserved_order` (`reserved_order_id`),
                KEY `idx_product_status` (`product_id`, `status`),
                KEY `idx_reserved_until` (`reserved_until`),
                KEY `idx_delivered_order` (`delivered_order_id`),
                KEY `idx_product_delivery` (`product_id`, `status`, `delivered_at`),
                KEY `idx_batch_status` (`batch_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS {$nonces} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `nonce_hash` VARCHAR(64) NOT NULL,
                `scope` VARCHAR(64) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_nonce_hash` (`nonce_hash`),
                KEY `idx_expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }

    private static function sqliteSchema(string $prefix): array
    {
        $orders = '"' . $prefix . 'pay_orders"';
        $events = '"' . $prefix . 'pay_events"';
        $entitlements = '"' . $prefix . 'pay_entitlements"';
        $categories = '"' . $prefix . 'pay_product_categories"';
        $products = '"' . $prefix . 'pay_products"';
        $deliverables = '"' . $prefix . 'pay_product_deliverables"';
        $fulfillments = '"' . $prefix . 'pay_fulfillments"';
        $cardBatches = '"' . $prefix . 'pay_card_batches"';
        $cardItems = '"' . $prefix . 'pay_card_items"';
        $nonces = '"' . $prefix . 'pay_nonces"';

        return [
            "CREATE TABLE IF NOT EXISTS {$categories} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                description TEXT DEFAULT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'active',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_product_categories_idx_status\" ON {$categories} (status)",
            "CREATE TABLE IF NOT EXISTS {$orders} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                out_trade_no TEXT NOT NULL UNIQUE,
                gateway TEXT NOT NULL,
                subject TEXT NOT NULL,
                amount INTEGER NOT NULL,
                currency TEXT NOT NULL DEFAULT 'CNY',
                biz_type TEXT NOT NULL DEFAULT 'post',
                biz_id INTEGER DEFAULT NULL,
                product_id INTEGER DEFAULT NULL,
                product_key TEXT DEFAULT NULL,
                product_version INTEGER NOT NULL DEFAULT 0,
                product_snapshot_json TEXT DEFAULT NULL,
                user_id INTEGER DEFAULT NULL,
                guest_token_hash TEXT DEFAULT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                payment_status TEXT NOT NULL DEFAULT 'pending',
                fulfillment_status TEXT NOT NULL DEFAULT 'none',
                poll_token_hash TEXT DEFAULT NULL,
                return_token_hash TEXT DEFAULT NULL,
                return_token_expires_at TEXT DEFAULT NULL,
                delivery_token_hash TEXT DEFAULT NULL,
                return_token_used INTEGER NOT NULL DEFAULT 0,
                platform_trade_no TEXT DEFAULT NULL,
                pay_url TEXT DEFAULT NULL,
                qr_content TEXT DEFAULT NULL,
                return_to TEXT DEFAULT NULL,
                last_queried_at TEXT DEFAULT NULL,
                query_count INTEGER NOT NULL DEFAULT 0,
                paid_at TEXT DEFAULT NULL,
                expired_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_orders_idx_biz\" ON {$orders} (biz_type, biz_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_orders_idx_product\" ON {$orders} (product_id, product_key)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_orders_idx_status\" ON {$orders} (status)",
            "CREATE TABLE IF NOT EXISTS {$events} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                out_trade_no TEXT NOT NULL,
                gateway TEXT NOT NULL,
                event_type TEXT NOT NULL,
                provider_event_id TEXT DEFAULT NULL,
                provider_event_type TEXT DEFAULT NULL,
                platform_trade_no TEXT DEFAULT NULL,
                remote_ip TEXT DEFAULT NULL,
                headers_json TEXT DEFAULT NULL,
                signature_ok INTEGER NOT NULL DEFAULT 0,
                payload TEXT,
                created_at TEXT NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_events_idx_order\" ON {$events} (out_trade_no)",
            "CREATE UNIQUE INDEX IF NOT EXISTS \"{$prefix}pay_events_uniq_provider_event\" ON {$events} (gateway, provider_event_id)",
            "CREATE TABLE IF NOT EXISTS {$entitlements} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL,
                deliverable_id INTEGER NOT NULL DEFAULT 0,
                biz_type TEXT NOT NULL,
                biz_id INTEGER NOT NULL,
                user_id INTEGER DEFAULT NULL,
                guest_token_hash TEXT DEFAULT NULL,
                starts_at TEXT NOT NULL,
                expires_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS \"{$prefix}pay_entitlements_uniq_order_deliverable\" ON {$entitlements} (order_id, deliverable_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_entitlements_idx_user_access\" ON {$entitlements} (user_id, biz_type, biz_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_entitlements_idx_guest_access\" ON {$entitlements} (guest_token_hash, biz_type, biz_id)",
            "CREATE TABLE IF NOT EXISTS {$products} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_key TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                content_id INTEGER DEFAULT NULL,
                amount INTEGER NOT NULL,
                currency TEXT NOT NULL DEFAULT 'CNY',
                status TEXT NOT NULL DEFAULT 'active',
                allow_guest INTEGER NOT NULL DEFAULT 1,
                purchase_policy TEXT NOT NULL DEFAULT 'once',
                max_per_user INTEGER DEFAULT NULL,
                duration_seconds INTEGER DEFAULT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                stock_policy TEXT NOT NULL DEFAULT 'none',
                category_id INTEGER DEFAULT NULL,
                cover_url TEXT DEFAULT NULL,
                summary TEXT DEFAULT NULL,
                description TEXT DEFAULT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                is_featured INTEGER NOT NULL DEFAULT 0,
                sales_count INTEGER NOT NULL DEFAULT 0,
                stock_display_mode TEXT NOT NULL DEFAULT 'exact',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_products_idx_content\" ON {$products} (content_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_products_idx_status\" ON {$products} (status)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_products_idx_category\" ON {$products} (category_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_products_idx_featured\" ON {$products} (is_featured, sort_order)",
            "CREATE TABLE IF NOT EXISTS {$deliverables} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id INTEGER NOT NULL,
                handler TEXT NOT NULL,
                target_type TEXT DEFAULT NULL,
                target_id INTEGER DEFAULT NULL,
                target_key TEXT DEFAULT NULL,
                config_json TEXT DEFAULT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                enabled INTEGER NOT NULL DEFAULT 1
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_product_deliverables_idx_product\" ON {$deliverables} (product_id, enabled, sort_order)",
            "CREATE TABLE IF NOT EXISTS {$fulfillments} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL,
                deliverable_id INTEGER NOT NULL DEFAULT 0,
                handler TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0,
                card_item_id INTEGER DEFAULT NULL,
                result_json TEXT DEFAULT NULL,
                last_error TEXT DEFAULT NULL,
                started_at TEXT DEFAULT NULL,
                fulfilled_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS \"{$prefix}pay_fulfillments_uniq_order_deliverable\" ON {$fulfillments} (order_id, deliverable_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_fulfillments_idx_status\" ON {$fulfillments} (status)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_fulfillments_idx_card_item\" ON {$fulfillments} (card_item_id)",
            "CREATE TABLE IF NOT EXISTS {$cardBatches} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id INTEGER NOT NULL,
                batch_name TEXT NOT NULL,
                imported_count INTEGER NOT NULL DEFAULT 0,
                imported_by INTEGER DEFAULT NULL,
                created_at TEXT NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_card_batches_idx_product\" ON {$cardBatches} (product_id)",
            "CREATE TABLE IF NOT EXISTS {$cardItems} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id INTEGER NOT NULL,
                batch_id INTEGER DEFAULT NULL,
                code_ciphertext TEXT NOT NULL,
                secret_ciphertext TEXT DEFAULT NULL,
                code_mask TEXT DEFAULT NULL,
                fingerprint TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'available',
                reserved_order_id INTEGER DEFAULT NULL,
                reserved_until TEXT DEFAULT NULL,
                delivered_order_id INTEGER DEFAULT NULL,
                delivered_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS \"{$prefix}pay_card_items_uniq_product_fingerprint\" ON {$cardItems} (product_id, fingerprint)",
            "CREATE UNIQUE INDEX IF NOT EXISTS \"{$prefix}pay_card_items_uniq_reserved_order\" ON {$cardItems} (reserved_order_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_card_items_idx_product_status\" ON {$cardItems} (product_id, status)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_card_items_idx_reserved_until\" ON {$cardItems} (reserved_until)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_card_items_idx_delivered_order\" ON {$cardItems} (delivered_order_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_card_items_idx_product_delivery\" ON {$cardItems} (product_id, status, delivered_at)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_card_items_idx_batch_status\" ON {$cardItems} (batch_id, status)",
            "CREATE TABLE IF NOT EXISTS {$nonces} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nonce_hash TEXT NOT NULL UNIQUE,
                scope TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_nonces_idx_expires_at\" ON {$nonces} (expires_at)",
        ];
    }

    private static function pgsqlSchema(string $prefix): array
    {
        $orders = '"' . $prefix . 'pay_orders"';
        $events = '"' . $prefix . 'pay_events"';
        $entitlements = '"' . $prefix . 'pay_entitlements"';
        $categories = '"' . $prefix . 'pay_product_categories"';
        $products = '"' . $prefix . 'pay_products"';
        $deliverables = '"' . $prefix . 'pay_product_deliverables"';
        $fulfillments = '"' . $prefix . 'pay_fulfillments"';
        $cardBatches = '"' . $prefix . 'pay_card_batches"';
        $cardItems = '"' . $prefix . 'pay_card_items"';
        $nonces = '"' . $prefix . 'pay_nonces"';

        return [
            "CREATE TABLE IF NOT EXISTS {$categories} (
                id BIGSERIAL PRIMARY KEY,
                slug VARCHAR(128) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                description TEXT DEFAULT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                status VARCHAR(32) NOT NULL DEFAULT 'active',
                created_at TIMESTAMP NOT NULL,
                updated_at TIMESTAMP NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_product_categories_idx_status\" ON {$categories} (status)",
            "CREATE TABLE IF NOT EXISTS {$orders} (
                id BIGSERIAL PRIMARY KEY,
                out_trade_no VARCHAR(64) NOT NULL UNIQUE,
                gateway VARCHAR(32) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                amount BIGINT NOT NULL,
                currency VARCHAR(8) NOT NULL DEFAULT 'CNY',
                biz_type VARCHAR(32) NOT NULL DEFAULT 'post',
                biz_id BIGINT DEFAULT NULL,
                product_id BIGINT DEFAULT NULL,
                product_key VARCHAR(128) DEFAULT NULL,
                product_version INTEGER NOT NULL DEFAULT 0,
                product_snapshot_json TEXT DEFAULT NULL,
                user_id BIGINT DEFAULT NULL,
                guest_token_hash VARCHAR(128) DEFAULT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                payment_status VARCHAR(32) NOT NULL DEFAULT 'pending',
                fulfillment_status VARCHAR(32) NOT NULL DEFAULT 'none',
                poll_token_hash VARCHAR(128) DEFAULT NULL,
                return_token_hash VARCHAR(128) DEFAULT NULL,
                return_token_expires_at TIMESTAMP DEFAULT NULL,
                delivery_token_hash VARCHAR(128) DEFAULT NULL,
                return_token_used SMALLINT NOT NULL DEFAULT 0,
                platform_trade_no VARCHAR(128) DEFAULT NULL,
                pay_url TEXT DEFAULT NULL,
                qr_content TEXT DEFAULT NULL,
                return_to TEXT DEFAULT NULL,
                last_queried_at TIMESTAMP DEFAULT NULL,
                query_count INTEGER NOT NULL DEFAULT 0,
                paid_at TIMESTAMP DEFAULT NULL,
                expired_at TIMESTAMP DEFAULT NULL,
                created_at TIMESTAMP NOT NULL,
                updated_at TIMESTAMP NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_orders_idx_biz\" ON {$orders} (biz_type, biz_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_orders_idx_product\" ON {$orders} (product_id, product_key)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_orders_idx_status\" ON {$orders} (status)",
            "CREATE TABLE IF NOT EXISTS {$events} (
                id BIGSERIAL PRIMARY KEY,
                out_trade_no VARCHAR(64) NOT NULL,
                gateway VARCHAR(32) NOT NULL,
                event_type VARCHAR(64) NOT NULL,
                provider_event_id VARCHAR(128) DEFAULT NULL,
                provider_event_type VARCHAR(64) DEFAULT NULL,
                platform_trade_no VARCHAR(128) DEFAULT NULL,
                remote_ip VARCHAR(64) DEFAULT NULL,
                headers_json TEXT DEFAULT NULL,
                signature_ok SMALLINT NOT NULL DEFAULT 0,
                payload TEXT,
                created_at TIMESTAMP NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_events_idx_order\" ON {$events} (out_trade_no)",
            "CREATE UNIQUE INDEX IF NOT EXISTS \"{$prefix}pay_events_uniq_provider_event\" ON {$events} (gateway, provider_event_id)",
            "CREATE TABLE IF NOT EXISTS {$entitlements} (
                id BIGSERIAL PRIMARY KEY,
                order_id BIGINT NOT NULL,
                deliverable_id BIGINT NOT NULL DEFAULT 0,
                biz_type VARCHAR(32) NOT NULL,
                biz_id BIGINT NOT NULL,
                user_id BIGINT DEFAULT NULL,
                guest_token_hash VARCHAR(128) DEFAULT NULL,
                starts_at TIMESTAMP NOT NULL,
                expires_at TIMESTAMP DEFAULT NULL,
                created_at TIMESTAMP NOT NULL
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS \"{$prefix}pay_entitlements_uniq_order_deliverable\" ON {$entitlements} (order_id, deliverable_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_entitlements_idx_user_access\" ON {$entitlements} (user_id, biz_type, biz_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_entitlements_idx_guest_access\" ON {$entitlements} (guest_token_hash, biz_type, biz_id)",
            "CREATE TABLE IF NOT EXISTS {$products} (
                id BIGSERIAL PRIMARY KEY,
                product_key VARCHAR(128) NOT NULL UNIQUE,
                title VARCHAR(255) NOT NULL,
                content_id BIGINT DEFAULT NULL,
                amount BIGINT NOT NULL,
                currency VARCHAR(8) NOT NULL DEFAULT 'CNY',
                status VARCHAR(32) NOT NULL DEFAULT 'active',
                allow_guest SMALLINT NOT NULL DEFAULT 1,
                purchase_policy VARCHAR(32) NOT NULL DEFAULT 'once',
                max_per_user INTEGER DEFAULT NULL,
                duration_seconds BIGINT DEFAULT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                stock_policy VARCHAR(32) NOT NULL DEFAULT 'none',
                category_id BIGINT DEFAULT NULL,
                cover_url VARCHAR(512) DEFAULT NULL,
                summary VARCHAR(512) DEFAULT NULL,
                description TEXT DEFAULT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                is_featured SMALLINT NOT NULL DEFAULT 0,
                sales_count INTEGER NOT NULL DEFAULT 0,
                stock_display_mode VARCHAR(32) NOT NULL DEFAULT 'exact',
                created_at TIMESTAMP NOT NULL,
                updated_at TIMESTAMP NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_products_idx_content\" ON {$products} (content_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_products_idx_status\" ON {$products} (status)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_products_idx_category\" ON {$products} (category_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_products_idx_featured\" ON {$products} (is_featured, sort_order)",
            "CREATE TABLE IF NOT EXISTS {$deliverables} (
                id BIGSERIAL PRIMARY KEY,
                product_id BIGINT NOT NULL,
                handler VARCHAR(64) NOT NULL,
                target_type VARCHAR(64) DEFAULT NULL,
                target_id BIGINT DEFAULT NULL,
                target_key VARCHAR(128) DEFAULT NULL,
                config_json TEXT DEFAULT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                enabled SMALLINT NOT NULL DEFAULT 1
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_product_deliverables_idx_product\" ON {$deliverables} (product_id, enabled, sort_order)",
            "CREATE TABLE IF NOT EXISTS {$fulfillments} (
                id BIGSERIAL PRIMARY KEY,
                order_id BIGINT NOT NULL,
                deliverable_id BIGINT NOT NULL DEFAULT 0,
                handler VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0,
                card_item_id BIGINT DEFAULT NULL,
                result_json TEXT DEFAULT NULL,
                last_error TEXT DEFAULT NULL,
                started_at TIMESTAMP DEFAULT NULL,
                fulfilled_at TIMESTAMP DEFAULT NULL,
                created_at TIMESTAMP NOT NULL,
                updated_at TIMESTAMP NOT NULL
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS \"{$prefix}pay_fulfillments_uniq_order_deliverable\" ON {$fulfillments} (order_id, deliverable_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_fulfillments_idx_status\" ON {$fulfillments} (status)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_fulfillments_idx_card_item\" ON {$fulfillments} (card_item_id)",
            "CREATE TABLE IF NOT EXISTS {$cardBatches} (
                id BIGSERIAL PRIMARY KEY,
                product_id BIGINT NOT NULL,
                batch_name VARCHAR(128) NOT NULL,
                imported_count INTEGER NOT NULL DEFAULT 0,
                imported_by BIGINT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_card_batches_idx_product\" ON {$cardBatches} (product_id)",
            "CREATE TABLE IF NOT EXISTS {$cardItems} (
                id BIGSERIAL PRIMARY KEY,
                product_id BIGINT NOT NULL,
                batch_id BIGINT DEFAULT NULL,
                code_ciphertext TEXT NOT NULL,
                secret_ciphertext TEXT DEFAULT NULL,
                code_mask VARCHAR(64) DEFAULT NULL,
                fingerprint VARCHAR(128) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'available',
                reserved_order_id BIGINT DEFAULT NULL,
                reserved_until TIMESTAMP DEFAULT NULL,
                delivered_order_id BIGINT DEFAULT NULL,
                delivered_at TIMESTAMP DEFAULT NULL,
                created_at TIMESTAMP NOT NULL,
                updated_at TIMESTAMP NOT NULL
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS \"{$prefix}pay_card_items_uniq_product_fingerprint\" ON {$cardItems} (product_id, fingerprint)",
            "CREATE UNIQUE INDEX IF NOT EXISTS \"{$prefix}pay_card_items_uniq_reserved_order\" ON {$cardItems} (reserved_order_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_card_items_idx_product_status\" ON {$cardItems} (product_id, status)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_card_items_idx_reserved_until\" ON {$cardItems} (reserved_until)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_card_items_idx_delivered_order\" ON {$cardItems} (delivered_order_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_card_items_idx_product_delivery\" ON {$cardItems} (product_id, status, delivered_at)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_card_items_idx_batch_status\" ON {$cardItems} (batch_id, status)",
            "CREATE TABLE IF NOT EXISTS {$nonces} (
                id BIGSERIAL PRIMARY KEY,
                nonce_hash VARCHAR(64) NOT NULL UNIQUE,
                scope VARCHAR(64) NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_nonces_idx_expires_at\" ON {$nonces} (expires_at)",
        ];
    }

    private static function migrateExistingTables(Db $db, string $adapter, string $prefix): void
    {
        $isMysql = strpos($adapter, 'mysql') !== false || strpos($adapter, 'mysqli') !== false;
        $isPgsql = strpos($adapter, 'pgsql') !== false;
        $ordersTable = $isMysql ? '`' . $prefix . 'pay_orders`' : '"' . $prefix . 'pay_orders"';
        $eventsTable = $isMysql ? '`' . $prefix . 'pay_events`' : '"' . $prefix . 'pay_events"';
        $entitlementsTable = $isMysql ? '`' . $prefix . 'pay_entitlements`' : '"' . $prefix . 'pay_entitlements"';
        $cardItemsTable = $isMysql ? '`' . $prefix . 'pay_card_items`' : '"' . $prefix . 'pay_card_items"';
        $index = $isMysql ? $prefix . 'pay_events_uniq_provider_event' : '"' . $prefix . 'pay_events_uniq_provider_event"';
        $orderProductIndex = $isMysql ? 'idx_product' : '"' . $prefix . 'pay_orders_idx_product"';
        $entitlementUniqueIndex = $isMysql ? 'uniq_order_deliverable' : '"' . $prefix . 'pay_entitlements_uniq_order_deliverable"';
        $reservedOrderIndex = $isMysql ? 'uniq_reserved_order' : '"' . $prefix . 'pay_card_items_uniq_reserved_order"';
        $textType = $isMysql ? 'MEDIUMTEXT' : 'TEXT';
        $string128 = $isMysql || $isPgsql ? 'VARCHAR(128)' : 'TEXT';
        $string64 = $isMysql || $isPgsql ? 'VARCHAR(64)' : 'TEXT';
        $dateType = $isMysql ? 'DATETIME' : ($isPgsql ? 'TIMESTAMP' : 'TEXT');
        $boolDefault = $isMysql ? 'TINYINT(1) NOT NULL DEFAULT 0' : ($isPgsql ? 'SMALLINT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0');

        // v1-v3 columns
        $orderColumns = [
            "return_to {$textType} DEFAULT NULL",
            "last_queried_at {$dateType} DEFAULT NULL",
            "query_count INTEGER NOT NULL DEFAULT 0",
            "poll_token_hash {$string128} DEFAULT NULL",
            "product_id BIGINT DEFAULT NULL",
            "product_key {$string128} DEFAULT NULL",
            "product_version INTEGER NOT NULL DEFAULT 0",
            "product_snapshot_json {$textType} DEFAULT NULL",
            "payment_status {$string64} NOT NULL DEFAULT 'pending'",
            "fulfillment_status {$string64} NOT NULL DEFAULT 'none'",
        ];
        // v5 columns: token separation
        $orderColumns[] = "return_token_hash {$string128} DEFAULT NULL";
        // v6 column: return token expiry.
        $orderColumns[] = "return_token_expires_at {$dateType} DEFAULT NULL";
        $orderColumns[] = "delivery_token_hash {$string128} DEFAULT NULL";
        $orderColumns[] = "return_token_used {$boolDefault}";

        foreach ($orderColumns as $column) {
            self::trySchema($db, "ALTER TABLE {$ordersTable} ADD COLUMN {$column}");
        }

        if ($isMysql) {
            self::trySchema($db, "ALTER TABLE {$ordersTable} ADD KEY `{$orderProductIndex}` (product_id, product_key)");
        } else {
            self::trySchema($db, "CREATE INDEX {$orderProductIndex} ON {$ordersTable} (product_id, product_key)");
        }

        // Backfill old order statuses. Paid orders without the new columns should
        // have correct payment_status and fulfillment_status.
        self::trySchema($db, "UPDATE {$ordersTable} SET payment_status = 'paid', fulfillment_status = 'fulfilled' WHERE status = 'paid' AND (payment_status = 'pending' OR payment_status = 'processing')");
        self::trySchema($db, "UPDATE {$ordersTable} SET payment_status = 'paid', fulfillment_status = 'pending' WHERE status = 'paid_pending_grant' AND (payment_status = 'pending' OR payment_status = 'processing')");
        self::trySchema($db, "UPDATE {$ordersTable} SET payment_status = 'paid', fulfillment_status = 'failed' WHERE status = 'grant_failed' AND (payment_status = 'pending' OR payment_status = 'processing')");

        $eventColumns = [
            "provider_event_id {$string128} DEFAULT NULL",
            "provider_event_type {$string64} DEFAULT NULL",
            "platform_trade_no {$string128} DEFAULT NULL",
            "remote_ip {$string64} DEFAULT NULL",
            "headers_json {$textType} DEFAULT NULL",
        ];

        foreach ($eventColumns as $column) {
            self::trySchema($db, "ALTER TABLE {$eventsTable} ADD COLUMN {$column}");
        }

        if ($isMysql) {
            self::trySchema($db, "ALTER TABLE {$eventsTable} ADD UNIQUE KEY `{$index}` (gateway, provider_event_id)");
        } else {
            self::trySchema($db, "CREATE UNIQUE INDEX {$index} ON {$eventsTable} (gateway, provider_event_id)");
        }

        self::trySchema($db, "ALTER TABLE {$entitlementsTable} ADD COLUMN deliverable_id BIGINT NOT NULL DEFAULT 0");
        if ($isMysql) {
            self::trySchema($db, "ALTER TABLE {$entitlementsTable} DROP INDEX `uniq_order`");
            self::trySchema($db, "ALTER TABLE {$entitlementsTable} ADD UNIQUE KEY `{$entitlementUniqueIndex}` (order_id, deliverable_id)");
        } elseif ($isPgsql) {
            self::trySchema($db, "ALTER TABLE {$entitlementsTable} DROP CONSTRAINT \"{$prefix}pay_entitlements_order_id_key\"");
            self::trySchema($db, "CREATE UNIQUE INDEX {$entitlementUniqueIndex} ON {$entitlementsTable} (order_id, deliverable_id)");
        } else {
            self::trySchema($db, "CREATE UNIQUE INDEX {$entitlementUniqueIndex} ON {$entitlementsTable} (order_id, deliverable_id)");
        }

        // v5: Add unique constraint on reserved_order_id to prevent one order reserving multiple cards.
        if ($isMysql) {
            self::trySchema($db, "ALTER TABLE {$cardItemsTable} ADD UNIQUE KEY `{$reservedOrderIndex}` (reserved_order_id)");
        } else {
            self::trySchema($db, "CREATE UNIQUE INDEX {$reservedOrderIndex} ON {$cardItemsTable} (reserved_order_id)");
        }

        // v7: Add indexes for card sales and delivery queries.
        $deliveredOrderIdx = $isMysql ? 'idx_delivered_order' : '"' . $prefix . 'pay_card_items_idx_delivered_order"';
        $productDeliveryIdx = $isMysql ? 'idx_product_delivery' : '"' . $prefix . 'pay_card_items_idx_product_delivery"';
        $batchStatusIdx = $isMysql ? 'idx_batch_status' : '"' . $prefix . 'pay_card_items_idx_batch_status"';

        if ($isMysql) {
            self::trySchema($db, "ALTER TABLE {$cardItemsTable} ADD KEY `{$deliveredOrderIdx}` (delivered_order_id)");
            self::trySchema($db, "ALTER TABLE {$cardItemsTable} ADD KEY `{$productDeliveryIdx}` (product_id, status, delivered_at)");
            self::trySchema($db, "ALTER TABLE {$cardItemsTable} ADD KEY `{$batchStatusIdx}` (batch_id, status)");
        } else {
            self::trySchema($db, "CREATE INDEX {$deliveredOrderIdx} ON {$cardItemsTable} (delivered_order_id)");
            self::trySchema($db, "CREATE INDEX {$productDeliveryIdx} ON {$cardItemsTable} (product_id, status, delivered_at)");
            self::trySchema($db, "CREATE INDEX {$batchStatusIdx} ON {$cardItemsTable} (batch_id, status)");
        }

        // v8: Add code_mask column for admin display.
        $codeMaskType = $isMysql ? 'VARCHAR(64)' : ($isPgsql ? 'VARCHAR(64)' : 'TEXT');
        self::trySchema($db, "ALTER TABLE {$cardItemsTable} ADD COLUMN code_mask {$codeMaskType} DEFAULT NULL");

        // v9: Create categories table and add product display columns.
        $categoriesTable = $isMysql ? '`' . $prefix . 'pay_product_categories`' : '"' . $prefix . 'pay_product_categories"';
        $catStatusType = $isMysql || $isPgsql ? 'VARCHAR(32)' : 'TEXT';
        $catDateType = $isMysql ? 'DATETIME' : ($isPgsql ? 'TIMESTAMP' : 'TEXT');

        self::trySchema($db, "CREATE TABLE IF NOT EXISTS {$categoriesTable} (
            " . ($isMysql ? "`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT" : ($isPgsql ? "id BIGSERIAL PRIMARY KEY" : "id INTEGER PRIMARY KEY AUTOINCREMENT")) . ",
            " . ($isMysql ? "`slug` VARCHAR(128) NOT NULL" : ($isPgsql ? "slug VARCHAR(128) NOT NULL" : "slug TEXT NOT NULL UNIQUE")) . ",
            " . ($isMysql ? "`name` VARCHAR(255) NOT NULL" : ($isPgsql ? "name VARCHAR(255) NOT NULL" : "name TEXT NOT NULL")) . ",
            " . ($isMysql ? "`description` TEXT DEFAULT NULL" : ($isPgsql ? "description TEXT DEFAULT NULL" : "description TEXT DEFAULT NULL")) . ",
            " . ($isMysql ? "`sort_order` INT NOT NULL DEFAULT 0" : ($isPgsql ? "sort_order INTEGER NOT NULL DEFAULT 0" : "sort_order INTEGER NOT NULL DEFAULT 0")) . ",
            `status` {$catStatusType} NOT NULL DEFAULT 'active',
            `created_at` {$catDateType} NOT NULL,
            `updated_at` {$catDateType} NOT NULL" . ($isMysql ? ",
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_slug` (`slug`),
            KEY `idx_status` (`status`)" : "") . "
        )" . ($isMysql ? " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" : ""));

        // Add new columns to pay_products.
        $productsTable = $isMysql ? '`' . $prefix . 'pay_products`' : '"' . $prefix . 'pay_products"';
        $string512 = $isMysql || $isPgsql ? 'VARCHAR(512)' : 'TEXT';

        $productColumns = [
            "category_id BIGINT DEFAULT NULL",
            "cover_url {$string512} DEFAULT NULL",
            "summary {$string512} DEFAULT NULL",
            "description TEXT DEFAULT NULL",
            "sort_order INTEGER NOT NULL DEFAULT 0",
            "is_featured " . ($isMysql ? 'TINYINT(1) NOT NULL DEFAULT 0' : ($isPgsql ? 'SMALLINT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0')),
            "sales_count INTEGER NOT NULL DEFAULT 0",
            "stock_display_mode {$catStatusType} NOT NULL DEFAULT 'exact'",
        ];

        foreach ($productColumns as $column) {
            self::trySchema($db, "ALTER TABLE {$productsTable} ADD COLUMN {$column}");
        }

        // Add indexes for category and featured queries.
        $categoryIdx = $isMysql ? 'idx_category' : '"' . $prefix . 'pay_products_idx_category"';
        $featuredIdx = $isMysql ? 'idx_featured' : '"' . $prefix . 'pay_products_idx_featured"';

        if ($isMysql) {
            self::trySchema($db, "ALTER TABLE {$productsTable} ADD KEY `{$categoryIdx}` (category_id)");
            self::trySchema($db, "ALTER TABLE {$productsTable} ADD KEY `{$featuredIdx}` (is_featured, sort_order)");
        } else {
            self::trySchema($db, "CREATE INDEX {$categoryIdx} ON {$productsTable} (category_id)");
            self::trySchema($db, "CREATE INDEX {$featuredIdx} ON {$productsTable} (is_featured, sort_order)");
        }
    }

    private static function schemaVersion(Db $db): int
    {
        try {
            $row = $db->fetchRow(
                $db->select('value')->from('table.options')
                    ->where('name = ?', 'typechopay_schema_version')
                    ->where('user = ?', 0)
                    ->limit(1)
            );
            return isset($row['value']) ? (int) $row['value'] : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function setSchemaVersion(Db $db, int $version): void
    {
        try {
            $updated = $db->query($db->update('table.options')->rows([
                'value' => (string) $version,
            ])->where('name = ?', 'typechopay_schema_version')->where('user = ?', 0));

            if ($updated <= 0) {
                $db->query($db->insert('table.options')->rows([
                    'name' => 'typechopay_schema_version',
                    'user' => 0,
                    'value' => (string) $version,
                ]));
            }
        } catch (\Throwable $e) {
            error_log('[TypechoPay] Failed to store schema version: ' . $e->getMessage());
        }
    }

    private static function trySchema(Db $db, string $sql): void
    {
        try {
            $db->query($sql, Db::WRITE, '');
        } catch (\Throwable $e) {
            // Schema upgrades are best-effort because Typecho does not expose portable column introspection here.
        }
    }
}
