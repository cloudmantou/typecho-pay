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
use TypechoPlugin\TypechoPay\Services\GuestClaimService;
use TypechoPlugin\TypechoPay\Services\ProductService;
use TypechoPlugin\TypechoPay\Services\PurchasePolicyService;
use TypechoPlugin\TypechoPay\Support\GuestToken;
use Utils\Helper;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

spl_autoload_register(function ($class) {
    $prefix = __NAMESPACE__ . '\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $relativePath = str_replace('\\', '/', $relative) . '.php';
    foreach ([__DIR__ . '/' . $relativePath, __DIR__ . '/src/' . $relativePath] as $path) {
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
});

/**
 * Typecho Pay
 *
 * 订单中心与多支付网关适配器，支持 PayPay、微信支付、支付宝的统一接入骨架。
 *
 * @package TypechoPay
 * @author mantou
 * @version 0.3.1
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
    private const SCHEMA_VERSION = 7;

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
                'paypay' => 'PayPay（日本，JPY）',
                'wechat' => '微信支付（中国，CNY）',
                'alipay' => '支付宝（中国，CNY）',
            ],
            ['paypay'],
            _t('启用支付方式'),
            _t('勾选需要启用的支付网关。未勾选的支付方式不会在前端显示。')
        );
        $form->addInput($enabledGateways);

        $defaultCurrency = new Select(
            'defaultCurrency',
            ['JPY' => 'JPY - 日元（PayPay 使用）', 'CNY' => 'CNY - 人民币（微信/支付宝使用）'],
            'JPY',
            _t('默认币种'),
            _t('短代码未指定 currency 时使用此默认值。PayPay 只支持 JPY，微信和支付宝只支持 CNY。')
        );
        $form->addInput($defaultCurrency);

        $endpointSecret = new Password(
            'endpointSecret',
            null,
            '',
            _t('入口签名密钥'),
            _t('用于文章付款入口金额防篡改。留空时回退到 Typecho 站点 secret。<br><strong>生产环境强烈建议单独设置</strong>，避免与其他功能共用密钥。')
        );
        $form->addInput($endpointSecret);

        // ============================================================
        // PayPay 配置
        // ============================================================

        $paypayEnvironment = new Select(
            'paypayEnvironment',
            [
                'sandbox' => 'Sandbox（测试环境，用于开发调试）',
                'staging' => 'Staging（预发布环境）',
                'production' => 'Production（生产环境，真实交易）',
            ],
            'sandbox',
            _t('PayPay 环境'),
            _t('当前使用 PayPay Open Payment API 的 Dynamic QR。开发时请使用 Sandbox 环境，生产环境切换到 Production。详细申请和回调配置请查看左侧 TypechoPay → 支付设置说明。')
        );
        $form->addInput($paypayEnvironment);

        $form->addInput(new Text('paypayApiKey', null, '', _t('PayPay API Key'), _t('通过 PayPay 官方加盟店/开发者流程开通 OPA 权限后获取。格式类似：<code>xxxxxxxxxxxx</code>')));
        $form->addInput(new Password('paypayApiSecret', null, '', _t('PayPay API Secret'), _t('通过 PayPay 官方加盟店/开发者流程获取，用于请求签名。<strong>请妥善保管，不要泄露。</strong>')));
        $form->addInput(new Text('paypayMerchantId', null, '', _t('PayPay Merchant ID'), _t('PayPay 商户 ID，格式类似：<code>70xxxx</code>。PayPay 仅支持 JPY（日元）。')));

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
     * 渲染文章中的 [typechopay ...] 付款入口。
     *
     * @param string|null $content
     * @param object $archive
     * @return string|null
     */
    public static function renderPayShortcodes($content, $archive)
    {
        if (!is_string($content) || strpos($content, '[typechopay') === false) {
            return $content;
        }

        $content = self::renderProtectedContent($content, $archive);

        return preg_replace_callback('/\[typechopay\s+([^\]]+)\]/i', function ($matches) use ($archive) {
            $attrs = self::parseShortcodeAttrs($matches[1]);
            $options = Options::alloc();
            $config = self::pluginConfig($options);
            [$bizType, $bizId] = self::resolveAccessTarget($attrs, $archive);
            $productService = new ProductService(Db::get());

            try {
                $product = $productService->resolve($attrs, [
                    'currency' => $config['defaultCurrency'] ?: 'JPY',
                    'subject' => $archive->title ?? 'TypechoPay Order',
                    'biz_type' => $bizType,
                    'biz_id' => $bizId,
                ]);
            } catch (\Throwable $e) {
                return '<p class="typechopay-error">' . htmlspecialchars($e->getMessage()) . '</p>';
            }

            $currency = (string) $product['currency'];
            $gateways = self::normalizeGateways($attrs['gateways'] ?? implode(',', $config['enabledGateways']));
            $gateways = array_values(array_intersect($gateways, $config['enabledGateways']));
            if (!$gateways) {
                return '<p class="typechopay-error">' . htmlspecialchars(_t('没有可用支付方式')) . '</p>';
            }
            $gateways = array_values(array_filter($gateways, function ($gateway) use ($currency) {
                return self::gatewaySupportsCurrency($gateway, $currency);
            }));
            if (!$gateways) {
                return '<p class="typechopay-error">' . htmlspecialchars(_t('当前币种没有可用支付方式')) . '</p>';
            }

            if (($product['purchase_policy'] ?? 'once') === 'once'
                && self::currentVisitorHasPurchased($product)) {
                return '<div class="typechopay-owned">' . htmlspecialchars(_t('已购买')) . '</div>';
            }

            $returnTo = self::archiveReturnTo($archive, $options);
            $entryPayload = $productService->entryPayload($product, $returnTo);

            return self::renderPayBox($product, $entryPayload, $gateways, $options, $config);
        }, $content);
    }

    /**
     * @param Options $options
     * @return array
     */
    public static function pluginConfig(Options $options): array
    {
        $plugin = $options->plugin('TypechoPay');

        return [
            'enabledGateways' => self::normalizeGateways($plugin->enabledGateways ?? ['paypay']),
            'defaultCurrency' => strtoupper((string) ($plugin->defaultCurrency ?? 'JPY')),
            'endpointSecret' => (string) ($plugin->endpointSecret ?? ''),
            'paypayEnvironment' => (string) ($plugin->paypayEnvironment ?? 'sandbox'),
            'paypayApiKey' => (string) ($plugin->paypayApiKey ?? ''),
            'paypayApiSecret' => (string) ($plugin->paypayApiSecret ?? ''),
            'paypayMerchantId' => (string) ($plugin->paypayMerchantId ?? ''),
            'wechatAppId' => (string) ($plugin->wechatAppId ?? ''),
            'wechatMchId' => (string) ($plugin->wechatMchId ?? ''),
            'wechatMerchantSerial' => (string) ($plugin->wechatMerchantSerial ?? ''),
            'wechatPrivateKeyPath' => (string) ($plugin->wechatPrivateKeyPath ?? ''),
            'wechatPlatformPublicKeyPath' => (string) ($plugin->wechatPlatformPublicKeyPath ?? ''),
            'wechatPlatformSerial' => (string) ($plugin->wechatPlatformSerial ?? ''),
            'wechatApiV3Key' => (string) ($plugin->wechatApiV3Key ?? ''),
            'alipayMode' => (string) ($plugin->alipayMode ?? 'page'),
            'alipayAppId' => (string) ($plugin->alipayAppId ?? ''),
            'alipayPrivateKey' => (string) ($plugin->alipayPrivateKey ?? ''),
            'alipayPublicKey' => (string) ($plugin->alipayPublicKey ?? ''),
            'alipaySellerId' => (string) ($plugin->alipaySellerId ?? ''),
        ];
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

    private static function renderPayBox(array $display, array $entryPayload, array $gateways, Options $options, array $config): string
    {
        $action = Common::url('/action/' . self::ACTION . '?do=prepare', $options->index);
        $labels = [
            'paypay' => 'PayPay',
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

        return '<div class="typechopay-box" data-typechopay="1">'
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

            return '<div class="typechopay-locked">' . htmlspecialchars(_t('此内容需要购买后查看。')) . '</div>';
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
        $productId = (int) ($product['product_id'] ?? ($product['snapshot']['id'] ?? 0));
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
            if (in_array($gateway, ['paypay', 'wechat', 'alipay'], true)) {
                $normalized[] = $gateway;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function gatewaySupportsCurrency(string $gateway, string $currency): bool
    {
        if ($gateway === 'paypay') {
            return $currency === 'JPY';
        }

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
        $products = '`' . $prefix . 'pay_products`';
        $deliverables = '`' . $prefix . 'pay_product_deliverables`';
        $fulfillments = '`' . $prefix . 'pay_fulfillments`';
        $cardBatches = '`' . $prefix . 'pay_card_batches`';
        $cardItems = '`' . $prefix . 'pay_card_items`';
        $nonces = '`' . $prefix . 'pay_nonces`';

        return [
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
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_product_key` (`product_key`),
                KEY `idx_content` (`content_id`),
                KEY `idx_status` (`status`)
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
        $products = '"' . $prefix . 'pay_products"';
        $deliverables = '"' . $prefix . 'pay_product_deliverables"';
        $fulfillments = '"' . $prefix . 'pay_fulfillments"';
        $cardBatches = '"' . $prefix . 'pay_card_batches"';
        $cardItems = '"' . $prefix . 'pay_card_items"';
        $nonces = '"' . $prefix . 'pay_nonces"';

        return [
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
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_products_idx_content\" ON {$products} (content_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_products_idx_status\" ON {$products} (status)",
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
        $products = '"' . $prefix . 'pay_products"';
        $deliverables = '"' . $prefix . 'pay_product_deliverables"';
        $fulfillments = '"' . $prefix . 'pay_fulfillments"';
        $cardBatches = '"' . $prefix . 'pay_card_batches"';
        $cardItems = '"' . $prefix . 'pay_card_items"';
        $nonces = '"' . $prefix . 'pay_nonces"';

        return [
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
                created_at TIMESTAMP NOT NULL,
                updated_at TIMESTAMP NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_products_idx_content\" ON {$products} (content_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_products_idx_status\" ON {$products} (status)",
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
        try {
            if ($isMysql) {
                $db->query("ALTER TABLE {$cardItemsTable} ADD UNIQUE KEY `{$reservedOrderIndex}` (reserved_order_id)", Db::WRITE, '');
            } else {
                $db->query("CREATE UNIQUE INDEX {$reservedOrderIndex} ON {$cardItemsTable} (reserved_order_id)", Db::WRITE, '');
            }
        } catch (\Throwable $e) {
            error_log('[TypechoPay] Could not add unique constraint on reserved_order_id: ' . $e->getMessage());
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
