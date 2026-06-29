<?php

/**
 * Static regression checks for operational hardening and cleanup.
 */

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function oh_assert(bool $condition, string $label): void
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
$actionSource = file_get_contents($root . '/Action.php');
$cardServiceSource = file_get_contents($root . '/Services/CardCodeService.php');
$guestTokenSource = file_get_contents($root . '/Support/GuestToken.php');
$abstractGatewaySource = file_get_contents($root . '/Gateways/AbstractGateway.php');
$wechatGatewaySource = file_get_contents($root . '/Gateways/WechatNativeGateway.php');
$alipaySdkSource = file_get_contents($root . '/Support/AlipaySdk.php');
$factorySource = file_get_contents($root . '/Gateways/GatewayFactory.php');
$diagnosticsSource = file_get_contents($root . '/manage/diagnostics.php');
$ciSource = file_get_contents($root . '/.github/workflows/ci.yml');

oh_assert(strpos($pluginSource, 'DIAGNOSTICS_PANEL') !== false, 'Plugin registers diagnostics panel constant');
oh_assert(strpos($pluginSource, "Helper::addPanel(\$menuIndex, self::DIAGNOSTICS_PANEL") !== false, 'Plugin adds diagnostics panel');
oh_assert(strpos($pluginSource, "Helper::removePanel(\$menuIndex, self::DIAGNOSTICS_PANEL") !== false, 'Plugin removes diagnostics panel');
oh_assert(strpos($pluginSource, 'DEPRECATED_CONFIG_KEYS') !== false, 'Plugin keeps hidden inputs for deprecated settings during upgrade');
oh_assert(strpos($pluginSource, "'paypayApiSecret'") !== false, 'Deprecated PayPay secret key is safely consumed during upgrade');
oh_assert(strpos($pluginSource, 'unset($settings[$key]);') !== false, 'Deprecated settings are removed on save');
oh_assert(strpos($diagnosticsSource, 'TypechoPay 支付诊断') !== false, 'Diagnostics page has a clear title');
oh_assert(strpos($diagnosticsSource, '不会输出任何密钥明文') !== false, 'Diagnostics page states it does not expose secrets');
oh_assert(strpos($diagnosticsSource, 'AppID / PID 区分') !== false, 'Diagnostics page checks AppID and PID are not mixed');
oh_assert(strpos($diagnosticsSource, 'openapi-sandbox') !== false, 'Diagnostics page identifies sandbox gateway environment');
oh_assert(strpos($diagnosticsSource, 'wechatpay/wechatpay 可加载') !== false, 'Diagnostics page checks WeChat SDK');
oh_assert(strpos($diagnosticsSource, 'alipaysdk/openapi 可加载') !== false, 'Diagnostics page checks Alipay SDK');
oh_assert(strpos($diagnosticsSource, 'openssl_pkey_get_private') !== false, 'Diagnostics page checks Alipay private-key parseability');
oh_assert(strpos($diagnosticsSource, 'openssl_pkey_get_public') !== false, 'Diagnostics page checks Alipay public-key parseability');

oh_assert(strpos($factorySource, "case 'paypay'") === false, 'GatewayFactory no longer exposes PayPay in the main flow');
oh_assert(!is_file($root . '/Gateways/PayPayGateway.php'), 'Legacy PayPay adapter source is removed');
oh_assert(strpos($factorySource, 'WechatNativeGateway') !== false, 'GatewayFactory still exposes WeChat');
oh_assert(strpos($factorySource, 'AlipayGateway') !== false, 'GatewayFactory still exposes Alipay');
oh_assert(strpos($actionSource, 'Legacy endpoint for signed one-time payloads before v0.3') !== false, 'Legacy create endpoint is explicitly documented');
oh_assert(strpos($actionSource, 'New frontend entry points should use do=prepare') !== false, 'New frontend is documented to use prepare');
oh_assert(strpos($actionSource, 'GatewayConfigurationException') !== false, 'Create failure classification uses typed gateway configuration exceptions');
oh_assert(strpos($actionSource, 'strpos($message') === false, 'Create failure classification does not inspect exception message substrings');
oh_assert(strpos($abstractGatewaySource, 'throw new GatewayConfigurationException') !== false, 'Missing gateway config uses a typed exception');
oh_assert(strpos($wechatGatewaySource, 'throw new GatewayConfigurationException') !== false, 'Missing WeChat SDK uses a typed exception');
oh_assert(strpos($alipaySdkSource, 'throw new GatewayConfigurationException') !== false, 'Missing Alipay SDK uses a typed exception');

oh_assert(!is_file($root . '/Contracts/FulfillmentHandlerInterface.php'), 'Unused FulfillmentHandlerInterface contract is removed');
oh_assert(strpos($pluginSource, 'extract(') === false, 'Theme renderer no longer uses extract()');
oh_assert(strpos($pluginSource, 'renderTemplateFile') !== false, 'Theme renderer isolates template variables');
oh_assert(strpos($cardServiceSource, 'SELECT status, COUNT(*) AS count_value') === false, 'Card stock counts do not use raw SQL string assembly');
oh_assert(strpos($cardServiceSource, "select('status', 'COUNT(*) AS count_value')") !== false, 'Card stock counts use Typecho query builder');
oh_assert(strpos($cardServiceSource, "\$secret = \$card['secret'] ?? null;") !== false, 'Card admin display protects missing secret values');
oh_assert(strpos($guestTokenSource, 'HTTP_FORWARDED') !== false, 'Guest token secure detection checks Forwarded proxy header');
oh_assert(strpos($guestTokenSource, 'HTTP_X_FORWARDED_PORT') !== false, 'Guest token secure detection checks forwarded HTTPS port');

oh_assert(strpos($ciSource, "php-version: ['7.4', '8.0', '8.2']") !== false, 'CI runs PHP version matrix');
oh_assert(strpos($ciSource, "path './vendor' -prune") !== false, 'CI lint excludes vendor');

echo "\n\n--- OperationalHardeningTest ---\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
