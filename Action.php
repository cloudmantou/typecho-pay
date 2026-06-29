<?php

namespace TypechoPlugin\TypechoPay;

use Typecho\Db;
use Typecho\Common;
use TypechoPlugin\TypechoPay\Contracts\PayCreateResult;
use TypechoPlugin\TypechoPay\Gateways\GatewayFactory;
use TypechoPlugin\TypechoPay\Services\CardCodeService;
use TypechoPlugin\TypechoPay\Services\FulfillmentManager;
use TypechoPlugin\TypechoPay\Services\GuestClaimService;
use TypechoPlugin\TypechoPay\Services\NonceService;
use TypechoPlugin\TypechoPay\Services\OrderService;
use TypechoPlugin\TypechoPay\Services\ProductService;
use TypechoPlugin\TypechoPay\Services\PurchasePolicyService;
use TypechoPlugin\TypechoPay\Support\GatewayConfigurationException;
use TypechoPlugin\TypechoPay\Support\GuestToken;
use TypechoPlugin\TypechoPay\Support\HttpHeaders;
use TypechoPlugin\TypechoPay\Support\Signer;
use Widget\ActionInterface;
use Widget\Base\Options as BaseOptions;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Action extends BaseOptions implements ActionInterface
{
    public function action()
    {
        $do = (string) $this->request->get('do');

        try {
            if ($do === 'prepare') {
                $this->prepare();
                return;
            }

            if ($do === 'create') {
                $this->create();
                return;
            }

            if ($do === 'notify') {
                $this->notify();
                return;
            }

            if ($do === 'query') {
                $this->query();
                return;
            }

            if ($do === 'return') {
                $this->paymentReturn();
                return;
            }

            if ($do === 'delivery') {
                $this->delivery();
                return;
            }

            if ($do === 'grant') {
                $this->grant();
                return;
            }

            $this->json(['success' => false, 'error' => 'Unknown action.'], 404);
        } catch (\InvalidArgumentException $e) {
            if (in_array($do, ['prepare', 'create'], true)) {
                $this->renderPaymentError($e->getMessage());
                return;
            }
            $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            error_log('[TypechoPay] ' . $e->getMessage());
            if (in_array($do, ['prepare', 'create'], true)) {
                $this->renderPaymentError('支付服务暂时不可用，请稍后重试。');
                return;
            }
            $this->json(['success' => false, 'error' => 'Payment service is unavailable.'], 500);
        }
    }

    private function create(): void
    {
        // Legacy endpoint for signed one-time payloads before v0.3.
        // New frontend entry points should use do=prepare.
        if (!$this->request->isPost()) {
            throw new \InvalidArgumentException('Payment order must be created by POST.');
        }

        $config = Plugin::pluginConfig($this->options);
        $payload = $this->entryPayloadFromRequest() + [
            'ts' => (string) $this->request->get('ts'),
            'nonce' => (string) $this->request->get('nonce'),
        ];

        $this->assertFreshPayload($payload);
        if (!Signer::verify($payload, Plugin::signingSecret($this->options, $config), (string) $this->request->get('signature'))) {
            throw new \InvalidArgumentException('Invalid payment entry signature.');
        }
        (new NonceService(Db::get()))->consume('create', $payload['nonce']);

        $this->createFromPayload($payload, $config);
    }

    private function prepare(): void
    {
        if (!$this->request->isPost()) {
            throw new \InvalidArgumentException('Payment entry must be prepared by POST.');
        }

        $config = Plugin::pluginConfig($this->options);
        $payload = $this->entryPayloadFromRequest();

        if (!Signer::verify($payload, Plugin::signingSecret($this->options, $config), (string) $this->request->get('entry_signature'))) {
            throw new \InvalidArgumentException('Invalid payment entry signature.');
        }

        $this->createFromPayload($payload, $config);
    }

    private function createFromPayload(array $payload, array $config): void
    {
        $gateway = strtolower((string) ($payload['gateway'] ?? ''));
        if (!in_array($gateway, $config['enabledGateways'], true)) {
            throw new \InvalidArgumentException('Payment gateway is disabled.');
        }

        // Rate-limit by IP to prevent inventory exhaustion.
        $orderService = new OrderService(Db::get());
        $ip = $this->clientIp();
        $orderService->assertRateLimit($ip);
        $orderService->cleanupRateLimits();

        $payload['return_to'] = $this->safeReturnTo($payload['return_to']);
        $product = (new ProductService(Db::get()))->resolve($payload);
        $this->assertGatewayCurrency($gateway, (string) $product['currency']);
        $this->assertBizTarget((string) $product['biz_type'], (string) $product['biz_id']);

        $orderInput = array_merge($payload, [
            'amount' => (int) $product['amount'],
            'currency' => (string) $product['currency'],
            'subject' => (string) $product['subject'],
            'biz_type' => (string) $product['biz_type'],
            'biz_id' => (int) $product['biz_id'],
            'product_id' => $product['product_id'],
            'product_key' => $product['product_key'],
            'product_version' => (int) $product['product_version'],
            'product_snapshot_json' => (string) $product['product_snapshot_json'],
            'purchase_policy' => (string) $product['purchase_policy'],
        ]);

        $userId = $this->user->hasLogin() ? (int) $this->user->uid : null;
        if ($userId === null && (int) ($product['allow_guest'] ?? 1) !== 1) {
            throw new \InvalidArgumentException('Please log in before purchasing this product.');
        }

        $guestToken = $userId === null ? GuestToken::getOrCreate() : GuestToken::get();
        $guestTokenHash = GuestToken::hash($guestToken);
        if ($userId !== null) {
            (new GuestClaimService(Db::get()))->claimAll($userId, $guestTokenHash);
        }

        // Check purchase policy based on paid order history, not content access.
        (new PurchasePolicyService(Db::get()))->assertCanPurchase($product, $userId, $guestTokenHash);

        $order = $orderService->create($orderInput + ['gateway' => $gateway], $userId, $guestTokenHash);

        if (!empty($order['skip_gateway_create'])) {
            if (!empty($order['pay_url']) || !empty($order['qr_content'])) {
                $this->renderPayment($order, $this->payCreateResultFromOrder($order));
                return;
            }

            $this->renderCreateInProgress($order);
            return;
        }

        try {
            (new FulfillmentManager(Db::get()))->reserveOrder($order);
            $adapter = GatewayFactory::make($gateway, $config, $this->options);
            $orderService->markProcessing($order['out_trade_no']);
            $result = $adapter->create($order);
        } catch (\Throwable $e) {
            if ($this->isDefiniteCreateFailure($e)) {
                $orderService->markFailed($order['out_trade_no'], $e->getMessage());
            } else {
                $orderService->markCreateUnknown($order['out_trade_no'], $e->getMessage());
            }
            throw $e;
        }

        $orderService->attachCreateResult($order['out_trade_no'], $result);
        $this->renderPayment($order, $result);
    }

    private function entryPayloadFromRequest(): array
    {
        $payload = [
            'gateway' => strtolower((string) $this->request->get('gateway')),
            'return_to' => (string) $this->request->get('return_to'),
        ];

        foreach (['product_id', 'product', 'amount', 'currency', 'subject', 'biz_type', 'biz_id', 'purchase_policy'] as $key) {
            $value = $this->request->get($key);
            if ($value !== null && (string) $value !== '') {
                $payload[$key] = $key === 'currency' ? strtoupper((string) $value) : (string) $value;
            }
        }

        return $payload;
    }

    private function notify(): void
    {
        $config = Plugin::pluginConfig($this->options);
        $gateway = strtolower((string) $this->request->get('gateway'));
        if (!in_array($gateway, ['wechat', 'alipay'], true)) {
            $this->providerResponse($gateway, false);
            return;
        }

        $rawBody = file_get_contents('php://input') ?: '';
        $post = $_POST;
        $headers = HttpHeaders::fromServer();
        $orderService = new OrderService(Db::get());

        try {
            $result = GatewayFactory::make($gateway, $config, $this->options)
                ->notify($headers, $rawBody, $_GET, $post);
            $orderService->recordEvent($result->outTradeNo, $gateway, $result->status, $result->signatureOk, $result->raw, [
                'provider_event_id' => $result->providerEventId,
                'provider_event_type' => $result->providerEventType,
                'platform_trade_no' => $result->platformTradeNo,
                'remote_ip' => $this->clientIp(),
                'headers' => $headers,
            ]);

            if ($result->isPaid() && $result->signatureOk) {
                $order = $orderService->confirmPayment($result);
                try {
                    $orderService->fulfillPaidOrder($order);
                } catch (\Throwable $e) {
                    error_log('[TypechoPay] Fulfillment after notify failed: ' . $e->getMessage());
                }
            } else {
                $orderService->syncProviderStatus($result);
            }

            $this->providerResponse($gateway, $result->signatureOk);
        } catch (\Throwable $e) {
            $orderService->recordEvent('unknown', $gateway, 'notify_error', false, ['error' => $e->getMessage()]);
            $this->providerResponse($gateway, false);
        }
    }

    private function query(): void
    {
        $outTradeNo = (string) $this->request->get('out_trade_no');
        $config = Plugin::pluginConfig($this->options);
        $orderService = new OrderService(Db::get());
        $order = $orderService->findByOutTradeNo($outTradeNo);
        if (!$order) {
            $this->json(['success' => false, 'error' => 'Order not found.'], 404);
            return;
        }

        if (!$this->canReadOrder($orderService, $order, (string) $this->request->get('poll_token'))) {
            $this->json(['success' => false, 'error' => 'Order access denied.'], 403);
            return;
        }

        if (in_array($order['status'], ['pending', 'processing'], true)) {
            try {
                $order = $this->activeQuery($orderService, $order, $config, false);
            } catch (\Throwable $e) {
                error_log('[TypechoPay] Active query failed: ' . $e->getMessage());
            }
        }

        $this->json($orderService->publicOrderStatus($orderService->findByOutTradeNo($outTradeNo) ?: $order));
    }

    private function paymentReturn(): void
    {
        $outTradeNo = (string) $this->request->get('out_trade_no');
        $config = Plugin::pluginConfig($this->options);
        $orderService = new OrderService(Db::get());
        $order = $orderService->findByOutTradeNo($outTradeNo);

        $returnToken = (string) $this->request->get('return_token');
        $returnTokenConsumed = $order ? $orderService->consumeReturnToken($outTradeNo, $returnToken) : false;
        $deliveryToken = '';
        if ($returnTokenConsumed) {
            $deliveryToken = bin2hex(random_bytes(32));
            if ($orderService->rotateDeliveryToken($outTradeNo, $deliveryToken)) {
                $this->setDeliveryCookie($outTradeNo, $deliveryToken);
            }
        }

        if (!$returnTokenConsumed) {
            if (!$order || (!$this->canReadOrder($orderService, $order, '') && !$this->hasDeliveryCookieAccess($orderService, $order))) {
                $this->response->setStatus(403);
                $this->response->throwContent(
                    '<!doctype html><meta charset="utf-8"><title>Payment Return</title><p>订单访问凭证无效。</p>',
                    'text/html'
                );
                return;
            }
        }

        $order = $orderService->findByOutTradeNo($outTradeNo) ?: $order;
        if (in_array($order['status'], ['pending', 'processing'], true)) {
            try {
                $order = $this->activeQuery($orderService, $order, $config, false);
            } catch (\Throwable $e) {
                error_log('[TypechoPay] Return active query failed: ' . $e->getMessage());
            }
        }

        $returnTo = $this->safeReturnTo((string) ($order['return_to'] ?? $this->request->get('return_to')));
        $fulfillmentStatus = (string) ($order['fulfillment_status'] ?? '');

        if ($order['status'] === 'paid'
            && (new FulfillmentManager(Db::get()))->orderHasHandler($order, 'cardcode')) {
            $deliveryUrl = Common::url(
                '/action/typechopay?do=delivery&out_trade_no=' . rawurlencode($outTradeNo),
                $this->options->index
            );
            $this->redirectSeeOther($deliveryUrl);
            return;
        }

        if ($order['status'] === 'paid' && !in_array($fulfillmentStatus, ['failed', 'partial'], true)) {
            $this->response->redirect($returnTo);
            return;
        }

        $safeOutTradeNo = htmlspecialchars($outTradeNo);
        $safeReturnTo = htmlspecialchars($returnTo);
        $status = htmlspecialchars((string) ($order['status'] ?? 'unknown'));
        $fulfillment = htmlspecialchars($fulfillmentStatus);
        $this->response->throwContent(
            '<!doctype html><meta charset="utf-8"><title>Payment Return</title>'
            . '<p>订单状态：' . $status . '</p>'
            . ($fulfillment !== '' ? '<p>交付状态：' . $fulfillment . '</p>' : '')
            . ($safeOutTradeNo !== '' ? '<p>订单号：' . $safeOutTradeNo . '</p>' : '')
            . '<p><a href="' . $safeReturnTo . '">返回查看内容</a></p>',
            'text/html'
        );
    }

    private function grant(): void
    {
        $this->user->pass('administrator');
        $this->security->protect();
        if (!$this->request->isPost()) {
            throw new \InvalidArgumentException('Grant must be retried by POST.');
        }

        $outTradeNo = (string) $this->request->get('out_trade_no');
        (new OrderService(Db::get()))->regrant($outTradeNo);

        $this->response->redirect($this->request->getReferer() ?: (string) $this->options->adminUrl);
    }

    private function delivery(): void
    {
        $outTradeNo = (string) $this->request->get('out_trade_no');
        $orderService = new OrderService(Db::get());
        $order = $orderService->findByOutTradeNo($outTradeNo);
        if (!$order) {
            $this->response->setStatus(404);
            $this->response->throwContent('<!doctype html><meta charset="utf-8"><title>Card Delivery</title><p>订单不存在。</p>', 'text/html');
            return;
        }

        $deliveryToken = (string) $this->request->get('delivery_token');
        $hasAccess = false;

        // 1. Check delivery_token from URL.
        if ($deliveryToken !== '' && $orderService->verifyDeliveryToken($order, $deliveryToken)) {
            $hasAccess = true;
        }

        // 2. Check HttpOnly delivery cookie.
        if (!$hasAccess) {
            $cookieToken = $this->getDeliveryCookieToken($outTradeNo);
            if ($cookieToken !== '' && $orderService->verifyDeliveryToken($order, $cookieToken)) {
                $hasAccess = true;
            }
        }

        // 3. Fallback: owner check (logged-in user or guest cookie).
        if (!$hasAccess && $this->canReadOrder($orderService, $order, '')) {
            $hasAccess = true;
        }

        if (!$hasAccess) {
            $this->response->setStatus(403);
            $this->response->throwContent('<!doctype html><meta charset="utf-8"><title>Card Delivery</title><p>订单访问凭证无效。</p>', 'text/html');
            return;
        }

        $returnTo = $this->safeReturnTo((string) ($order['return_to'] ?? ''));
        $this->renderDeliveryPage($order, $deliveryToken, $returnTo);
    }

    private function renderPayment(array $order, $result): void
    {
        $this->setNoStoreHeaders();

        if ($result->type === 'html' && $result->html !== null) {
            $this->response->throwContent($result->html, 'text/html');
            return;
        }

        if ($result->type === 'redirect' && $result->payUrl !== null) {
            $this->response->redirect($result->payUrl);
            return;
        }

        // poll_token is used ONLY for internal frontend polling — never sent to payment platforms.
        $pollUrl = Common::url(
            '/action/typechopay?do=query&out_trade_no=' . rawurlencode($order['out_trade_no'])
            . '&poll_token=' . rawurlencode((string) ($order['poll_token'] ?? '')),
            $this->options->index
        );
        if (!empty($order['delivery_token'])) {
            $this->setDeliveryCookie((string) $order['out_trade_no'], (string) $order['delivery_token']);
        }

        $deliveryUrl = Common::url(
            '/action/typechopay?do=delivery&out_trade_no=' . rawurlencode($order['out_trade_no']),
            $this->options->index
        );
        $returnTo = $this->safeReturnTo((string) ($order['return_to'] ?? ''));
        $payUrl = $result->payUrl;
        $qrContent = $result->qrContent ?: $payUrl;
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>支付订单</title>'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:40px;line-height:1.6}'
            . '.box{max-width:640px}.code{word-break:break-all;padding:12px;background:#f6f7f8;border:1px solid #ddd}'
            . '#typechopay-qrcode{width:240px;height:240px;margin:16px 0}.status{color:#555}</style>'
            . '</head><body><main class="box">'
            . '<h1>支付订单</h1>'
            . '<p>订单号：' . htmlspecialchars($order['out_trade_no']) . '</p>'
            . '<p>金额：' . htmlspecialchars(Support\Money::formatForDisplay((int) $order['amount'], (string) $order['currency'])) . '</p>';

        if ($result->type === 'qr' && $qrContent) {
            $html .= '<div id="typechopay-qrcode" data-text="' . htmlspecialchars($qrContent) . '"></div>'
                . ($payUrl ? '<p><a href="' . htmlspecialchars($payUrl) . '" rel="nofollow">打开支付 App</a></p>' : '')
                . '<p class="code">' . htmlspecialchars($qrContent) . '</p>'
                . '<p class="status" id="typechopay-status">等待支付...</p>'
                . '<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU" crossorigin="anonymous"></script>'
                . '<script>(function(){var box=document.getElementById("typechopay-qrcode");'
                . 'if(window.QRCode&&box){new QRCode(box,{text:box.getAttribute("data-text"),width:240,height:240,correctLevel:QRCode.CorrectLevel.M});}'
                . 'var status=document.getElementById("typechopay-status");'
                . 'var returnTo=' . json_encode($returnTo) . ';'
                . 'var deliveryUrl=' . json_encode($deliveryUrl) . ';'
                . 'var timer=setInterval(function(){fetch(' . json_encode($pollUrl) . ',{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){if(j&&j.data){status.textContent="订单状态："+j.data.status;if(j.data.status==="paid"&&j.data.fulfillment_status!=="failed"&&j.data.fulfillment_status!=="partial"){clearInterval(timer);location.href=j.data.has_card_delivery?deliveryUrl:returnTo;}else if(j.data.status==="grant_failed"||j.data.fulfillment_status==="failed"||j.data.fulfillment_status==="partial"){clearInterval(timer);status.textContent="支付成功，交付未完全完成，请联系站点管理员。";}else if(j.data.terminal){clearInterval(timer);status.textContent="订单状态："+j.data.status+"，请重新发起支付。";}}}).catch(function(){});},3000);'
                . '}());</script>';
        } elseif ($payUrl) {
            $html .= '<p><a href="' . htmlspecialchars($payUrl) . '" rel="nofollow">打开支付链接</a></p>';
        } else {
            $html .= '<p>支付网关未返回可展示的支付入口。</p>';
        }

        $html .= '</main></body></html>';
        $this->response->throwContent($html, 'text/html');
    }

    private function renderCreateInProgress(array $order): void
    {
        $this->setNoStoreHeaders();
        $pollUrl = Common::url(
            '/action/typechopay?do=query&out_trade_no=' . rawurlencode((string) $order['out_trade_no'])
            . '&poll_token=' . rawurlencode((string) ($order['poll_token'] ?? '')),
            $this->options->index
        );
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>支付订单创建中</title>'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:40px;line-height:1.6}.box{max-width:640px}</style>'
            . '</head><body><main class="box">'
            . '<h1>支付订单创建中</h1>'
            . '<p>订单号：' . htmlspecialchars((string) $order['out_trade_no']) . '</p>'
            . '<p id="typechopay-status">支付平台订单正在创建或确认中，请稍后刷新。</p>'
            . '<p><a href="' . htmlspecialchars($pollUrl) . '">查看订单状态</a></p>'
            . '</main></body></html>';
        $this->response->throwContent($html, 'text/html');
    }

    private function renderPaymentError(string $message): void
    {
        $this->setNoStoreHeaders();
        $returnTo = $this->safeReturnTo((string) $this->request->get('return_to'));
        $backHtml = $returnTo !== ''
            ? '<p><a href="' . htmlspecialchars($returnTo) . '">返回文章页面</a></p>'
            : '<p><button type="button" onclick="history.back()">返回上一页</button></p>';
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>支付失败</title>'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:40px;line-height:1.6}'
            . '.box{max-width:640px;padding:24px;border:1px solid #e5e5e5;border-radius:6px;background:#fff}'
            . '.error{color:#b94a48;padding:12px;background:#fef2f2;border:1px solid #fecaca;border-radius:4px}'
            . '</style></head><body><main class="box">'
            . '<h1>支付失败</h1>'
            . '<div class="error">' . htmlspecialchars($message) . '</div>'
            . $backHtml
            . '</main></body></html>';
        $this->response->throwContent($html, 'text/html');
    }

    private function renderDeliveryPage(array $order, string $deliveryToken, string $returnTo): void
    {
        $this->setNoStoreHeaders();
        $this->setSecurityHeaders();
        $cards = [];
        if ((string) ($order['status'] ?? '') === 'paid') {
            $cards = (new CardCodeService(Db::get()))->deliveredCardsForOrder($order);
        }

        $html = '<!doctype html><html><head><meta charset="utf-8"><title>卡密交付</title>'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:40px;line-height:1.6}'
            . '.box{max-width:760px}.card{border:1px solid #ddd;background:#fafafa;padding:14px;margin:12px 0}'
            . '.value{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;word-break:break-all;background:#fff;padding:8px;border:1px solid #e5e5e5}</style>'
            . '</head><body><main class="box">'
            . '<h1>卡密交付</h1>'
            . '<p>订单号：' . htmlspecialchars((string) $order['out_trade_no']) . '</p>'
            . '<p>订单状态：' . htmlspecialchars((string) ($order['status'] ?? 'unknown')) . '</p>'
            . '<p>交付状态：' . htmlspecialchars((string) ($order['fulfillment_status'] ?? '')) . '</p>';

        if ($cards) {
            foreach ($cards as $card) {
                $html .= '<section class="card">'
                    . '<p><strong>卡号 / 兑换码</strong></p>'
                    . '<p class="value">' . htmlspecialchars((string) $card['code']) . '</p>';
                $secret = $card['secret'] ?? null;
                if ($secret !== null && $secret !== '') {
                    $html .= '<p><strong>卡密 / 密钥</strong></p>'
                        . '<p class="value">' . htmlspecialchars((string) $secret) . '</p>';
                }
                $html .= '<p>交付时间：' . htmlspecialchars((string) ($card['delivered_at'] ?? '')) . '</p>'
                    . '</section>';
            }
        } elseif ((string) ($order['status'] ?? '') === 'paid') {
            $html .= '<p>支付已完成，但卡密暂未交付完成。请联系站点管理员处理。</p>';
        } else {
            $html .= '<p>订单尚未完成支付，暂不能查看卡密。</p>';
        }

        $deliveryUrl = Common::url(
            '/action/typechopay?do=delivery&out_trade_no=' . rawurlencode((string) $order['out_trade_no']),
            $this->options->index
        );
        $html .= '<p><a href="' . htmlspecialchars($deliveryUrl) . '">刷新交付状态</a></p>';
        if ($returnTo !== '') {
            $html .= '<p><a href="' . htmlspecialchars($returnTo) . '">返回原页面</a></p>';
        }

        $html .= '</main></body></html>';
        $this->response->throwContent($html, 'text/html');
    }

    private function providerResponse(string $gateway, bool $success): void
    {
        if ($gateway === 'alipay') {
            $this->response->throwContent($success ? 'success' : 'failure', 'text/plain');
            return;
        }

        if ($gateway === 'wechat') {
            $this->response->setStatus($success ? 200 : 400);
            $this->response->throwJson($success
                ? ['code' => 'SUCCESS', 'message' => '成功']
                : ['code' => 'FAIL', 'message' => '失败']);
            return;
        }

        $this->response->setStatus($success ? 200 : 400);
        $this->response->throwContent($success ? 'OK' : 'FAIL', 'text/plain');
    }

    private function json(array $payload, int $status = 200): void
    {
        $this->setNoStoreHeaders();
        $this->response->setStatus($status);
        $this->response->throwJson($payload);
    }

    private function assertGatewayCurrency(string $gateway, string $currency): void
    {
        if ($currency !== 'CNY') {
            throw new \InvalidArgumentException('Currency does not match payment gateway.');
        }
    }

    private function activeQuery(OrderService $orderService, array $order, array $config, bool $force): array
    {
        if (!$orderService->shouldQueryUpstream($order, $force)) {
            return $order;
        }

        $order = $orderService->markQueryAttempt($order);
        $result = GatewayFactory::make($order['gateway'], $config, $this->options)->query($order);
        $orderService->recordEvent($result->outTradeNo, $order['gateway'], 'active_query:' . $result->status, $result->signatureOk, $result->raw, [
            'provider_event_id' => $result->providerEventId,
            'provider_event_type' => $result->providerEventType,
            'platform_trade_no' => $result->platformTradeNo,
            'remote_ip' => $this->clientIp(),
        ]);

        if ($result->isPaid()) {
            $confirmed = $orderService->confirmPayment($result);
            try {
                return $orderService->fulfillPaidOrder($confirmed);
            } catch (\Throwable $e) {
                error_log('[TypechoPay] Fulfillment after active query failed: ' . $e->getMessage());
                return $orderService->findByOutTradeNo($confirmed['out_trade_no']) ?: $confirmed;
            }
        }

        $synced = $orderService->syncProviderStatus($result);
        if ($synced !== null) {
            return $synced;
        }

        return $orderService->findByOutTradeNo($order['out_trade_no']) ?: $order;
    }

    private function safeReturnTo(string $returnTo): string
    {
        $fallback = (string) $this->options->index;
        $returnTo = trim($returnTo);
        if ($returnTo === '' || preg_match('/[\r\n]/', $returnTo)) {
            return $fallback;
        }

        if (strpos($returnTo, '/') === 0 && strpos($returnTo, '//') !== 0) {
            return Common::url($returnTo, $this->options->index);
        }

        $target = parse_url($returnTo);
        $site = parse_url((string) $this->options->index);
        if (!$target || !$site || empty($target['scheme']) || empty($target['host']) || empty($site['host'])) {
            return $fallback;
        }

        if (!in_array(strtolower($target['scheme']), ['http', 'https'], true)) {
            return $fallback;
        }

        return strtolower($target['host']) === strtolower($site['host']) ? $returnTo : $fallback;
    }

    private function clientIp(): string
    {
        try {
            $ip = trim((string) $this->request->getIp());
        } catch (\Throwable $e) {
            $ip = '';
        }

        return $ip !== '' ? $ip : 'unknown';
    }

    private function assertFreshPayload(array $payload): void
    {
        $ts = filter_var($payload['ts'] ?? null, FILTER_VALIDATE_INT);
        if ($ts === false || abs(time() - (int) $ts) > 600) {
            throw new \InvalidArgumentException('Payment payload expired.');
        }

        if (!preg_match('/^[a-f0-9]{16}$/', (string) ($payload['nonce'] ?? ''))) {
            throw new \InvalidArgumentException('Invalid payment payload nonce.');
        }
    }

    private function assertBizTarget(string $bizType, string $bizId): void
    {
        if (!preg_match('/^[a-z0-9_.-]{1,32}$/', $bizType)) {
            throw new \InvalidArgumentException('Invalid business type.');
        }

        $id = filter_var($bizId, FILTER_VALIDATE_INT);
        if ($id === false || (int) $id <= 0) {
            throw new \InvalidArgumentException('Invalid business id.');
        }
    }

    private function canReadOrder(OrderService $orderService, array $order, string $pollToken): bool
    {
        if ($pollToken !== '' && $orderService->verifyPollToken($order, $pollToken)) {
            return true;
        }

        $userId = $this->user->hasLogin() ? (int) $this->user->uid : null;
        $guestTokenHash = GuestToken::hash(GuestToken::get());
        if ($userId !== null && $guestTokenHash !== null) {
            (new GuestClaimService(Db::get()))->claimAll($userId, $guestTokenHash);
        }

        return $orderService->belongsToOwner($order, $userId, $guestTokenHash);
    }

    private function isDefiniteCreateFailure(\Throwable $e): bool
    {
        return $e instanceof \InvalidArgumentException
            || $e instanceof GatewayConfigurationException;
    }

    private function setNoStoreHeaders(): void
    {
        $this->response->setHeader('Cache-Control', 'private, no-store, max-age=0');
        $this->response->setHeader('Pragma', 'no-cache');
        $this->response->setHeader('Expires', '0');
    }

    private function setSecurityHeaders(): void
    {
        $this->response->setHeader('Referrer-Policy', 'no-referrer');
        $this->response->setHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->response->setHeader('X-Frame-Options', 'DENY');
        $this->response->setHeader('X-Content-Type-Options', 'nosniff');
    }

    private function payCreateResultFromOrder(array $order): PayCreateResult
    {
        $payUrl = isset($order['pay_url']) ? (string) $order['pay_url'] : '';
        $qrContent = isset($order['qr_content']) ? (string) $order['qr_content'] : '';
        return new PayCreateResult(
            $qrContent !== '' ? 'qr' : 'redirect',
            $payUrl !== '' ? $payUrl : null,
            $qrContent !== '' ? $qrContent : ($payUrl !== '' ? $payUrl : null),
            null,
            ['reused' => true]
        );
    }

    private function hasDeliveryCookieAccess(OrderService $orderService, array $order): bool
    {
        $outTradeNo = (string) ($order['out_trade_no'] ?? '');
        $cookieToken = $outTradeNo !== '' ? $this->getDeliveryCookieToken($outTradeNo) : '';
        return $cookieToken !== '' && $orderService->verifyDeliveryToken($order, $cookieToken);
    }

    private function redirectSeeOther(string $location): void
    {
        $this->response->setStatus(303);
        $this->response->setHeader('Location', $location);
        $this->response->throwContent('', 'text/html');
    }

    /**
     * Set an HttpOnly cookie containing the delivery token so the user
     * can revisit the card delivery page without the token in the URL.
     */
    private function setDeliveryCookie(string $outTradeNo, string $deliveryToken): void
    {
        $value = $outTradeNo . ':' . $deliveryToken;
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(
            '__typechopay_delivery',
            $value,
            [
                'expires' => time() + 86400 * 7,
                'path' => '/',
                'httponly' => true,
                'secure' => $secure,
                'samesite' => 'Lax',
            ]
        );
    }

    /**
     * Read the delivery token from the HttpOnly cookie, if it matches the order.
     */
    private function getDeliveryCookieToken(string $outTradeNo): string
    {
        $cookie = (string) ($_COOKIE['__typechopay_delivery'] ?? '');
        if ($cookie === '') {
            return '';
        }

        $parts = explode(':', $cookie, 2);
        if (count($parts) !== 2 || $parts[0] !== $outTradeNo) {
            return '';
        }

        if (!preg_match('/^[a-f0-9]{64}$/', $parts[1])) {
            return '';
        }

        return $parts[1];
    }
}
