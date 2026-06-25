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
use TypechoPlugin\TypechoPay\Support\GuestToken;
use Utils\Helper;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
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
 * @version 0.1.0
 * @link https://github.com/
 */
class Plugin implements PluginInterface
{
    private const ACTION = 'typechopay';
    private const MENU = 'TypechoPay';
    private const ORDERS_PANEL = 'TypechoPay/manage/orders.php';

    /**
     * 启用插件。
     */
    public static function activate()
    {
        self::installTables();

        Helper::addAction(self::ACTION, '\\' . __NAMESPACE__ . '\\Action');
        $menuIndex = Helper::addMenu(self::MENU);
        Helper::addPanel($menuIndex, self::ORDERS_PANEL, _t('支付订单'), _t('TypechoPay'), 'administrator');

        \Typecho\Plugin::factory('Widget\Base\Contents')->contentEx = __CLASS__ . '::renderPayShortcodes';

        return _t('TypechoPay 已启用，支付订单表已准备完成。');
    }

    /**
     * 禁用插件。
     */
    public static function deactivate()
    {
        Helper::removeAction(self::ACTION);
        $menuIndex = Helper::removeMenu(self::MENU);
        Helper::removePanel($menuIndex, self::ORDERS_PANEL);

        return _t('TypechoPay 已禁用，订单表会保留以便审计和恢复。');
    }

    /**
     * 插件配置。
     *
     * @param Form $form
     */
    public static function config(Form $form)
    {
        $enabledGateways = new Checkbox(
            'enabledGateways',
            [
                'paypay' => 'PayPay Dynamic QR',
                'wechat' => '微信支付 Native',
                'alipay' => '支付宝 Page/Precreate',
            ],
            ['paypay'],
            _t('启用网关'),
            _t('只会展示并允许创建已启用网关的订单。')
        );
        $form->addInput($enabledGateways);

        $defaultCurrency = new Select(
            'defaultCurrency',
            ['JPY' => 'JPY - 日元', 'CNY' => 'CNY - 人民币'],
            'JPY',
            _t('默认币种'),
            _t('PayPay 使用 JPY；微信和支付宝通常使用 CNY。')
        );
        $form->addInput($defaultCurrency);

        $endpointSecret = new Password(
            'endpointSecret',
            null,
            '',
            _t('入口签名密钥'),
            _t('用于文章付款入口金额防篡改。留空时回退到 Typecho 站点 secret，生产环境建议单独设置。')
        );
        $form->addInput($endpointSecret);

        $paypayEnvironment = new Select(
            'paypayEnvironment',
            ['sandbox' => 'Sandbox', 'staging' => 'Staging', 'production' => 'Production'],
            'sandbox',
            _t('PayPay 环境')
        );
        $form->addInput($paypayEnvironment);

        $form->addInput(new Text('paypayApiKey', null, '', _t('PayPay API Key')));
        $form->addInput(new Password('paypayApiSecret', null, '', _t('PayPay API Secret')));
        $form->addInput(new Text('paypayMerchantId', null, '', _t('PayPay Merchant ID')));

        $form->addInput(new Text('wechatAppId', null, '', _t('微信 AppID')));
        $form->addInput(new Text('wechatMchId', null, '', _t('微信商户号')));
        $form->addInput(new Text('wechatMerchantSerial', null, '', _t('微信商户 API 证书序列号')));
        $form->addInput(new Text('wechatPrivateKeyPath', null, '', _t('微信商户私钥路径')));
        $form->addInput(new Text('wechatPlatformPublicKeyPath', null, '', _t('微信平台公钥/证书路径')));
        $form->addInput(new Text('wechatPlatformSerial', null, '', _t('微信平台证书序列号/公钥 ID')));
        $form->addInput(new Password('wechatApiV3Key', null, '', _t('微信 APIv3 Key')));

        $alipayMode = new Select(
            'alipayMode',
            ['page' => '电脑网站支付 Page Pay', 'precreate' => '当面付预创建 Precreate'],
            'page',
            _t('支付宝模式')
        );
        $form->addInput($alipayMode);
        $form->addInput(new Text('alipayAppId', null, '', _t('支付宝 AppID')));
        $form->addInput(new Textarea('alipayPrivateKey', null, '', _t('支付宝应用私钥')));
        $form->addInput(new Textarea('alipayPublicKey', null, '', _t('支付宝公钥')));
        $form->addInput(new Text('alipaySellerId', null, '', _t('支付宝 Seller ID')));
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
            $amount = isset($attrs['amount']) ? (int) $attrs['amount'] : 0;
            if ($amount <= 0) {
                return '<p class="typechopay-error">' . htmlspecialchars(_t('支付入口金额无效')) . '</p>';
            }

            $options = Options::alloc();
            $config = self::pluginConfig($options);
            $currency = strtoupper($attrs['currency'] ?? ($config['defaultCurrency'] ?: 'JPY'));
            $subject = trim($attrs['subject'] ?? ($archive->title ?? 'TypechoPay Order'));
            $gateways = self::normalizeGateways($attrs['gateways'] ?? implode(',', $config['enabledGateways']));
            $gateways = array_values(array_intersect($gateways, $config['enabledGateways']));
            if (!$gateways) {
                return '<p class="typechopay-error">' . htmlspecialchars(_t('没有可用支付方式')) . '</p>';
            }

            [$bizType, $bizId] = self::resolveAccessTarget($attrs, $archive);
            if (self::currentVisitorCanAccess($bizType, $bizId)) {
                return '<div class="typechopay-owned">' . htmlspecialchars(_t('已购买')) . '</div>';
            }

            $payload = [
                'amount' => (string) $amount,
                'currency' => $currency,
                'subject' => $subject,
                'biz_type' => $bizType,
                'biz_id' => (string) $bizId,
                'return_to' => self::archiveReturnTo($archive, $options),
            ];

            return self::renderPayBox($payload, $gateways, $options, $config);
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

    private static function renderPayBox(array $payload, array $gateways, Options $options, array $config): string
    {
        $action = Common::url('/action/' . self::ACTION . '?do=create', $options->index);
        $labels = [
            'paypay' => 'PayPay',
            'wechat' => '微信支付',
            'alipay' => '支付宝',
        ];

        $buttons = [];
        foreach ($gateways as $gateway) {
            $signedPayload = $payload + [
                'gateway' => $gateway,
                'ts' => (string) time(),
                'nonce' => bin2hex(random_bytes(8)),
            ];
            $signedPayload['signature'] = Support\Signer::sign($signedPayload, self::signingSecret($options, $config));

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
            . '<strong>' . htmlspecialchars($payload['subject']) . '</strong>'
            . '<span class="typechopay-amount">' . htmlspecialchars($payload['currency'] . ' ' . $payload['amount']) . '</span>'
            . implode('', $buttons)
            . '</div>';
    }

    private static function renderProtectedContent(string $content, $archive): string
    {
        if (strpos($content, '[typechopay_content]') === false) {
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

        return (new AccessService(Db::get()))->canAccess($bizType, $bizId, $userId, $guestTokenHash);
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

    private static function installTables()
    {
        $db = Db::get();
        $adapter = strtolower($db->getAdapterName());
        $prefix = $db->getPrefix();

        foreach (self::schemaSql($adapter, $prefix) as $sql) {
            $db->query($sql, Db::WRITE, '');
        }

        self::migrateExistingTables($db, $adapter, $prefix);
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
                `user_id` BIGINT UNSIGNED DEFAULT NULL,
                `guest_token_hash` VARCHAR(128) DEFAULT NULL,
                `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
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
                UNIQUE KEY `uniq_provider_event` (`gateway`, `provider_event_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS {$entitlements} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` BIGINT UNSIGNED NOT NULL,
                `biz_type` VARCHAR(32) NOT NULL,
                `biz_id` BIGINT UNSIGNED NOT NULL,
                `user_id` BIGINT UNSIGNED DEFAULT NULL,
                `guest_token_hash` VARCHAR(128) DEFAULT NULL,
                `starts_at` DATETIME NOT NULL,
                `expires_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_order` (`order_id`),
                KEY `idx_user_access` (`user_id`, `biz_type`, `biz_id`),
                KEY `idx_guest_access` (`guest_token_hash`, `biz_type`, `biz_id`)
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
                user_id INTEGER DEFAULT NULL,
                guest_token_hash TEXT DEFAULT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
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
                order_id INTEGER NOT NULL UNIQUE,
                biz_type TEXT NOT NULL,
                biz_id INTEGER NOT NULL,
                user_id INTEGER DEFAULT NULL,
                guest_token_hash TEXT DEFAULT NULL,
                starts_at TEXT NOT NULL,
                expires_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_entitlements_idx_user_access\" ON {$entitlements} (user_id, biz_type, biz_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_entitlements_idx_guest_access\" ON {$entitlements} (guest_token_hash, biz_type, biz_id)",
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
                user_id BIGINT DEFAULT NULL,
                guest_token_hash VARCHAR(128) DEFAULT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
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
                order_id BIGINT NOT NULL UNIQUE,
                biz_type VARCHAR(32) NOT NULL,
                biz_id BIGINT NOT NULL,
                user_id BIGINT DEFAULT NULL,
                guest_token_hash VARCHAR(128) DEFAULT NULL,
                starts_at TIMESTAMP NOT NULL,
                expires_at TIMESTAMP DEFAULT NULL,
                created_at TIMESTAMP NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_entitlements_idx_user_access\" ON {$entitlements} (user_id, biz_type, biz_id)",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}pay_entitlements_idx_guest_access\" ON {$entitlements} (guest_token_hash, biz_type, biz_id)",
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
        $index = $isMysql ? $prefix . 'pay_events_uniq_provider_event' : '"' . $prefix . 'pay_events_uniq_provider_event"';
        $textType = $isMysql ? 'MEDIUMTEXT' : 'TEXT';
        $string128 = $isMysql || $isPgsql ? 'VARCHAR(128)' : 'TEXT';
        $string64 = $isMysql || $isPgsql ? 'VARCHAR(64)' : 'TEXT';
        $dateType = $isMysql ? 'DATETIME' : ($isPgsql ? 'TIMESTAMP' : 'TEXT');

        $orderColumns = [
            "return_to {$textType} DEFAULT NULL",
            "last_queried_at {$dateType} DEFAULT NULL",
            "query_count INTEGER NOT NULL DEFAULT 0",
        ];
        foreach ($orderColumns as $column) {
            self::trySchema($db, "ALTER TABLE {$ordersTable} ADD COLUMN {$column}");
        }

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
