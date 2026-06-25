<?php

namespace TypechoPlugin\TypechoPay\Services;

use Typecho\Db;
use TypechoPlugin\TypechoPay\Contracts\NotifyResult;
use TypechoPlugin\TypechoPay\Contracts\PayCreateResult;
use TypechoPlugin\TypechoPay\Support\Money;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class OrderService
{
    private const PAYABLE_STATUSES = ['pending', 'processing'];
    private const GRANTABLE_STATUSES = ['paid_pending_grant', 'grant_failed'];
    private const ACTIVE_QUERY_INTERVAL = 8;

    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function create(array $input, ?int $userId = null, ?string $guestTokenHash = null): array
    {
        $existing = $this->findReusablePending($input, $userId, $guestTokenHash);
        if ($existing) {
            return $this->refreshReusableOrder($existing, (string) ($input['return_to'] ?? '')) + ['reused' => true];
        }

        return $this->createFresh($input, $userId, $guestTokenHash) + ['reused' => false];
    }

    private function createFresh(array $input, ?int $userId, ?string $guestTokenHash): array
    {
        $now = date('Y-m-d H:i:s');
        $amount = Money::assertAmount($input['amount'] ?? 0);
        $currency = Money::assertCurrency($input['currency'] ?? 'CNY');
        $subject = trim((string) ($input['subject'] ?? 'TypechoPay Order'));
        $subjectLength = function_exists('mb_strlen') ? mb_strlen($subject) : strlen($subject);
        if ($subject === '' || $subjectLength > 255) {
            throw new \InvalidArgumentException('Invalid payment subject.');
        }

        $order = [
            'out_trade_no' => $this->makeTradeNo(),
            'gateway' => (string) $input['gateway'],
            'subject' => $subject,
            'amount' => $amount,
            'currency' => $currency,
            'biz_type' => trim((string) ($input['biz_type'] ?? 'post')) ?: 'post',
            'biz_id' => isset($input['biz_id']) ? (int) $input['biz_id'] : null,
            'user_id' => $userId,
            'guest_token_hash' => $guestTokenHash,
            'status' => 'pending',
            'platform_trade_no' => null,
            'pay_url' => null,
            'qr_content' => null,
            'return_to' => isset($input['return_to']) ? (string) $input['return_to'] : null,
            'last_queried_at' => null,
            'query_count' => 0,
            'paid_at' => null,
            'expired_at' => date('Y-m-d H:i:s', time() + 1800),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $id = $this->db->query($this->db->insert('table.pay_orders')->rows($order));
        $order['id'] = $id;

        return $order;
    }

    private function refreshReusableOrder(array $order, string $returnTo): array
    {
        if ($returnTo === '' || (string) ($order['return_to'] ?? '') === $returnTo) {
            return $order;
        }

        $this->db->query($this->db->update('table.pay_orders')->rows([
            'return_to' => $returnTo,
            'updated_at' => date('Y-m-d H:i:s'),
        ])->where('out_trade_no = ?', $order['out_trade_no']));

        $order['return_to'] = $returnTo;

        return $order;
    }

    public function findReusablePending(array $input, ?int $userId, ?string $guestTokenHash): ?array
    {
        $amount = Money::assertAmount($input['amount'] ?? 0);
        $currency = Money::assertCurrency($input['currency'] ?? 'CNY');
        $bizType = trim((string) ($input['biz_type'] ?? 'post')) ?: 'post';
        $bizId = isset($input['biz_id']) ? (int) $input['biz_id'] : null;
        $gateway = (string) ($input['gateway'] ?? '');

        $select = $this->db->select()->from('table.pay_orders')
            ->where('gateway = ?', $gateway)
            ->where('biz_type = ?', $bizType)
            ->where('amount = ?', $amount)
            ->where('currency = ?', $currency)
            ->where('status = ?', 'pending')
            ->where('expired_at > ?', date('Y-m-d H:i:s'))
            ->order('created_at', Db::SORT_DESC)
            ->limit(1);

        if ($bizId !== null) {
            $select->where('biz_id = ?', $bizId);
        }

        if ($userId !== null) {
            $select->where('user_id = ?', $userId);
        } elseif ($guestTokenHash !== null) {
            $select->where('guest_token_hash = ?', $guestTokenHash);
        } else {
            return null;
        }

        return $this->db->fetchRow($select);
    }

    public function attachCreateResult(string $outTradeNo, PayCreateResult $result): void
    {
        $this->db->query($this->db->update('table.pay_orders')->rows([
            'pay_url' => $result->payUrl,
            'qr_content' => $result->qrContent,
            'updated_at' => date('Y-m-d H:i:s'),
        ])->where('out_trade_no = ?', $outTradeNo));
    }

    public function findByOutTradeNo(string $outTradeNo): ?array
    {
        if (!$this->isValidTradeNo($outTradeNo)) {
            return null;
        }

        return $this->db->fetchRow(
            $this->db->select()->from('table.pay_orders')->where('out_trade_no = ?', $outTradeNo)->limit(1)
        );
    }

    public function markPaid(NotifyResult $result): array
    {
        $order = $this->findByOutTradeNo($result->outTradeNo);
        if (!$order) {
            throw new \RuntimeException('Order not found.');
        }

        if ($result->amount !== null && (int) $order['amount'] !== $result->amount) {
            throw new \RuntimeException('Payment amount mismatch.');
        }

        if ($result->currency !== null && strtoupper((string) $order['currency']) !== strtoupper($result->currency)) {
            throw new \RuntimeException('Payment currency mismatch.');
        }

        if ($order['status'] === 'paid') {
            return $order;
        }

        if (in_array($order['status'], self::GRANTABLE_STATUSES, true)) {
            return $this->grantPaidOrder($order);
        }

        if (!in_array($order['status'], self::PAYABLE_STATUSES, true)) {
            throw new \RuntimeException('Order status is not payable.');
        }

        $paidAt = date('Y-m-d H:i:s');
        $updated = $this->db->query($this->db->update('table.pay_orders')->rows([
            'status' => 'paid_pending_grant',
            'platform_trade_no' => $result->platformTradeNo,
            'paid_at' => $paidAt,
            'updated_at' => $paidAt,
        ])->where('out_trade_no = ?', $result->outTradeNo)
            ->where('status IN ?', self::PAYABLE_STATUSES));

        if ($updated <= 0) {
            return $this->findByOutTradeNo($result->outTradeNo) ?: $order;
        }

        $pendingOrder = array_merge($order, [
            'status' => 'paid_pending_grant',
            'platform_trade_no' => $result->platformTradeNo,
            'paid_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);

        return $this->grantPaidOrder($pendingOrder);
    }

    public function regrant(string $outTradeNo): array
    {
        $order = $this->findByOutTradeNo($outTradeNo);
        if (!$order) {
            throw new \RuntimeException('Order not found.');
        }

        if (!in_array($order['status'], ['paid', 'paid_pending_grant', 'grant_failed'], true)) {
            throw new \RuntimeException('Order is not paid.');
        }

        return $this->grantPaidOrder($order);
    }

    private function grantPaidOrder(array $order): array
    {
        try {
            (new AccessService($this->db))->grant($order);
        } catch (\Throwable $e) {
            $this->db->query($this->db->update('table.pay_orders')->rows([
                'status' => 'grant_failed',
                'updated_at' => date('Y-m-d H:i:s'),
            ])->where('out_trade_no = ?', $order['out_trade_no'])
                ->where('status IN ?', array_merge(['paid'], self::GRANTABLE_STATUSES)));

            $this->recordEvent($order['out_trade_no'], 'system', 'grant_failed', false, [
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Payment was confirmed but entitlement grant failed.');
        }

        $this->db->query($this->db->update('table.pay_orders')->rows([
            'status' => 'paid',
            'updated_at' => date('Y-m-d H:i:s'),
        ])->where('out_trade_no = ?', $order['out_trade_no'])
            ->where('status IN ?', array_merge(['paid'], self::GRANTABLE_STATUSES)));

        $paidOrder = $this->findByOutTradeNo($order['out_trade_no']) ?: array_merge($order, ['status' => 'paid']);
        $this->recordEvent($order['out_trade_no'], 'system', 'grant_succeeded', true, [
            'order_id' => (int) $order['id'],
        ]);

        return $paidOrder;
    }

    public function markFailed(string $outTradeNo, string $reason): void
    {
        if (!$this->isValidTradeNo($outTradeNo)) {
            return;
        }

        $this->db->query($this->db->update('table.pay_orders')->rows([
            'status' => 'failed',
            'updated_at' => date('Y-m-d H:i:s'),
        ])->where('out_trade_no = ?', $outTradeNo)->where('status = ?', 'pending'));

        $this->recordEvent($outTradeNo, 'system', 'failed', false, ['reason' => $reason]);
    }

    public function recordEvent(
        string $outTradeNo,
        string $gateway,
        string $eventType,
        bool $signatureOk,
        array $payload,
        array $context = []
    ): void
    {
        try {
            $this->db->query($this->db->insert('table.pay_events')->rows([
                'out_trade_no' => $outTradeNo !== '' ? $outTradeNo : 'unknown',
                'gateway' => $gateway,
                'event_type' => $eventType,
                'provider_event_id' => $context['provider_event_id'] ?? null,
                'provider_event_type' => $context['provider_event_type'] ?? null,
                'platform_trade_no' => $context['platform_trade_no'] ?? null,
                'remote_ip' => $context['remote_ip'] ?? null,
                'headers_json' => isset($context['headers']) ? json_encode($context['headers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'signature_ok' => $signatureOk ? 1 : 0,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => date('Y-m-d H:i:s'),
            ]));
        } catch (\Throwable $e) {
            error_log('[TypechoPay] Failed to record event: ' . $e->getMessage());
        }
    }

    public function shouldQueryUpstream(array $order, bool $force = false): bool
    {
        if ($force) {
            return true;
        }

        if (!empty($order['last_queried_at'])) {
            return strtotime((string) $order['last_queried_at']) <= time() - self::ACTIVE_QUERY_INTERVAL;
        }

        return true;
    }

    public function markQueryAttempt(array $order): array
    {
        $now = date('Y-m-d H:i:s');
        $count = (int) ($order['query_count'] ?? 0) + 1;

        $this->db->query($this->db->update('table.pay_orders')->rows([
            'last_queried_at' => $now,
            'query_count' => $count,
            'updated_at' => $now,
        ])->where('out_trade_no = ?', $order['out_trade_no']));

        $order['last_queried_at'] = $now;
        $order['query_count'] = $count;

        return $order;
    }

    public function publicOrderStatus(?array $order): array
    {
        if (!$order) {
            return ['success' => false, 'error' => 'Order not found.'];
        }

        return [
            'success' => true,
            'data' => [
                'out_trade_no' => $order['out_trade_no'],
                'gateway' => $order['gateway'],
                'status' => $order['status'],
                'amount' => (int) $order['amount'],
                'currency' => $order['currency'],
                'paid_at' => $order['paid_at'],
                'return_to' => $order['return_to'] ?? null,
            ],
        ];
    }

    private function makeTradeNo(): string
    {
        return 'TP' . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
    }

    private function isValidTradeNo(string $outTradeNo): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{8,64}$/', $outTradeNo);
    }
}
