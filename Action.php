<?php

namespace TypechoPlugin\TypechoPay;

use Typecho\Db;
use TypechoPlugin\TypechoPay\Gateways\GatewayFactory;
use TypechoPlugin\TypechoPay\Services\OrderService;
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

            $this->json(['success' => false, 'error' => 'Unknown action.'], 404);
        } catch (\InvalidArgumentException $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            error_log('[TypechoPay] ' . $e->getMessage());
            $this->json(['success' => false, 'error' => 'Payment service is unavailable.'], 500);
        }
    }

    private function create(): void
    {
        if (!$this->request->isPost()) {
            throw new \InvalidArgumentException('Payment order must be created by POST.');
        }

        $config = Plugin::pluginConfig($this->options);
        $gateway = strtolower((string) $this->request->get('gateway'));
        if (!in_array($gateway, $config['enabledGateways'], true)) {
            throw new \InvalidArgumentException('Payment gateway is disabled.');
        }

        $payload = [
            'amount' => (string) $this->request->get('amount'),
            'currency' => strtoupper((string) $this->request->get('currency')),
            'subject' => (string) $this->request->get('subject'),
            'biz_type' => (string) $this->request->get('biz_type'),
            'biz_id' => (string) $this->request->get('biz_id'),
        ];

        if (!Signer::verify($payload, Plugin::signingSecret($this->options, $config), (string) $this->request->get('signature'))) {
            throw new \InvalidArgumentException('Invalid payment entry signature.');
        }

        $this->assertGatewayCurrency($gateway, $payload['currency']);

        $orderService = new OrderService(Db::get());
        $order = $orderService->create($payload + ['gateway' => $gateway], $this->user->hasLogin() ? (int) $this->user->uid : null);
        $adapter = GatewayFactory::make($gateway, $config, $this->options);

        try {
            $result = $adapter->create($order);
            $orderService->attachCreateResult($order['out_trade_no'], $result);
            $this->renderPayment($order, $result);
        } catch (\Throwable $e) {
            $orderService->markFailed($order['out_trade_no'], $e->getMessage());
            throw $e;
        }
    }

    private function notify(): void
    {
        $config = Plugin::pluginConfig($this->options);
        $gateway = strtolower((string) $this->request->get('gateway'));
        if (!in_array($gateway, ['paypay', 'wechat', 'alipay'], true)) {
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
            $orderService->recordEvent($result->outTradeNo, $gateway, $result->status, $result->signatureOk, $result->raw);

            if ($result->isPaid()) {
                $orderService->markPaid($result);
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
        $order = (new OrderService(Db::get()))->findByOutTradeNo($outTradeNo);
        if (!$order) {
            $this->json(['success' => false, 'error' => 'Order not found.'], 404);
            return;
        }

        $this->json([
            'success' => true,
            'data' => [
                'out_trade_no' => $order['out_trade_no'],
                'gateway' => $order['gateway'],
                'status' => $order['status'],
                'amount' => (int) $order['amount'],
                'currency' => $order['currency'],
                'paid_at' => $order['paid_at'],
            ],
        ]);
    }

    private function paymentReturn(): void
    {
        $outTradeNo = htmlspecialchars((string) $this->request->get('out_trade_no'));
        $this->response->throwContent(
            '<!doctype html><meta charset="utf-8"><title>Payment Return</title>'
            . '<p>支付完成后订单状态会通过异步通知更新。</p>'
            . ($outTradeNo !== '' ? '<p>订单号：' . $outTradeNo . '</p>' : ''),
            'text/html'
        );
    }

    private function renderPayment(array $order, $result): void
    {
        if ($result->html !== null) {
            $this->response->throwContent($result->html, 'text/html');
            return;
        }

        $payUrl = $result->payUrl ?: $result->qrContent;
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>支付订单</title>'
            . '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:40px;line-height:1.6}'
            . '.box{max-width:640px}.code{word-break:break-all;padding:12px;background:#f6f7f8;border:1px solid #ddd}</style>'
            . '</head><body><main class="box">'
            . '<h1>支付订单</h1>'
            . '<p>订单号：' . htmlspecialchars($order['out_trade_no']) . '</p>'
            . '<p>金额：' . htmlspecialchars($order['currency'] . ' ' . $order['amount']) . '</p>';

        if ($payUrl) {
            $html .= '<p><a href="' . htmlspecialchars($payUrl) . '" rel="nofollow">打开支付链接</a></p>'
                . '<p class="code">' . htmlspecialchars($payUrl) . '</p>';
        } elseif ($result->qrContent) {
            $html .= '<p class="code">' . htmlspecialchars($result->qrContent) . '</p>';
        } else {
            $html .= '<p>支付网关未返回可展示的支付入口。</p>';
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
        $this->response->setStatus($status);
        $this->response->throwJson($payload);
    }

    private function assertGatewayCurrency(string $gateway, string $currency): void
    {
        $expected = $gateway === 'paypay' ? 'JPY' : 'CNY';
        if ($currency !== $expected) {
            throw new \InvalidArgumentException('Currency does not match payment gateway.');
        }
    }
}
