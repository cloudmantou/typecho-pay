<?php

/**
 * Static regression checks for Alipay sandbox gateway configuration.
 */

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function alipay_assert(bool $condition, string $label): void
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
$gatewaySource = file_get_contents($root . '/Gateways/AlipayGateway.php');
$sdkSource = file_get_contents($root . '/Support/AlipaySdk.php');
$settingsHelpSource = file_get_contents($root . '/manage/settings-help.php');

alipay_assert(strpos($pluginSource, 'alipayGatewayUrl') !== false, 'Plugin config exposes Alipay gateway URL');
alipay_assert(strpos($pluginSource, 'normalizeAlipayGatewayUrl') !== false, 'Plugin normalizes Alipay gateway URL');
alipay_assert(strpos($pluginSource, 'openapi-sandbox.dl.alipaydev.com/gateway.do') !== false, 'Plugin mentions Alipay sandbox gateway URL');
alipay_assert(strpos($gatewaySource, '$client->gatewayUrl = $this->gatewayUrl();') !== false, 'Alipay client uses configured gateway URL');
alipay_assert(strpos($gatewaySource, 'function gatewayUrl') !== false, 'Alipay gateway has URL normalization fallback');
alipay_assert(strpos($gatewaySource, "\$client->gatewayUrl = 'https://openapi.alipay.com/gateway.do';") === false, 'Alipay client no longer hardcodes production gateway');
alipay_assert(strpos($sdkSource, "dirname(__DIR__) . '/vendor/alipaysdk/openapi/v2/aop'") !== false, 'Alipay SDK loader resolves vendor from plugin root');
alipay_assert(strpos($sdkSource, "dirname(__DIR__, 2) . '/vendor/alipaysdk") === false, 'Alipay SDK loader does not skip past plugin root');
alipay_assert(strpos($settingsHelpSource, 'openapi-sandbox.dl.alipaydev.com/gateway.do') !== false, 'Settings help documents sandbox gateway URL');

echo "\n\n--- AlipayGatewayTest ---\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
