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
    private const RATE_LIMIT_WINDOW = 60;
    private const RATE_LIMIT_MAX_PREPARES = 10;
    private const ORDER_TTL = 1800;
    private const FINAL_PROVIDER_STATUSES = [
        'expired' => 'expired',
        'cancelled' => 'cancelled',
        'canceled' => 'cancelled',
        'failed' => 'failed',
        'closed' => 'closed',
        'revoked' => 'cancelled',
        'payerror' => 'failed',
        'trade_closed' => 'closed',
    ];

    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * Create or reuse an order. When a pending/processing order already exists
     * for the same buyer + product + gateway, reuse it instead of creating a new one.
     */
    public function create(array $input, ?int $userId = null, ?string $guestTokenHash = null): array
    {
        $productId = isset($input['product_id']) ? (int) $input['product_id'] : null;
        $gateway = (string) ($input['gateway'] ?? '');

        if ($productId !== null && $productId > 0 && $gateway !== '') {
            $existing = $this->findActiveOrderForBuyer($userId, $guestTokenHash, $input, $gateway);
            if ($existing) {
                return $this->prepareReusableOrder($existing);
            }
        }

        return $this->createFresh($input, $userId, $guestTokenHash);
    }

    /**
     * Rate-limit prepare requests by IP address. Throws on excess.
     */
    public function assertRateLimit(string $ip): void
    {
        $this->cleanupRateLimits();
        $scope = $this->rateLimitScope($ip);
        $now = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + self::RATE_LIMIT_WINDOW);
        $nonceHash = hash('sha256', $scope . ':' . bin2hex(random_bytes(16)));

        try {
            $this->db->query($this->db->insert('table.pay_nonces')->rows([
                'nonce_hash' => $nonceHash,
                'scope' => $scope,
                'expires_at' => $expires,
                'created_at' => $now,
            ]));
        } catch (\Throwable $e) {
            // Ignore insert failure for rate limiting
        }

        $cutoff = date('Y-m-d H:i:s', time() - self::RATE_LIMIT_WINDOW);
        $count = 0;
        try {
            $row = $this->db->fetchRow(
                $this->db->select('COUNT(*) AS cnt')->from('table.pay_nonces')
                    ->where('scope = ?', $scope)
                    ->where('created_at >= ?', $cutoff)
            );
            $count = (int) ($row['cnt'] ?? 0);
        } catch (\Throwable $e) {
            return;
        }

        if ($count > self::RATE_LIMIT_MAX_PREPARES) {
            throw new \InvalidArgumentException('Too many requests. Please try again later.');
        }
    }

    private function rateLimitScope(string $ip): string
    {
        $normalized = strtolower(trim($ip));
        if ($normalized === '') {
            $normalized = 'unknown';
        }

        if (strlen($normalized) > 56) {
            $normalized = 'hash:' . substr(hash('sha256', $normalized), 0, 51);
        }

        return 'prepare:' . $normalized;
    }

    /**
     * Clean up expired rate-limit records.
     */
    public function cleanupRateLimits(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::RATE_LIMIT_WINDOW * 2);
        try {
            $this->db->query($this->db->delete('table.pay_nonces')->where('scope LIKE ?', 'prepare:%')->where('created_at < ?', $cutoff));
        } catch (\Throwable $e) {
            // Best effort
        }
    }

    public function findActiveOrderForBuyer(?int $userId, ?string $guestTokenHash, array $input, string $gateway): ?array
    {
        if ($userId === null && ($guestTokenHash === null || $guestTokenHash === '')) {
            return null;
        }

        $productId = isset($input['product_id']) ? (int) $input['product_id'] : 0;
        if ($productId <= 0) {
            return null;
        }

        $select = $this->db->select()->from('table.pay_orders')
            ->where('product_id = ?', $productId)
            ->where('gateway = ?', $gateway)
            ->where('status IN ?', self::PAYABLE_STATUSES)
            ->where('expired_at > ?', date('Y-m-d H:i:s'))
            ->where('amount = ?', Money::assertAmount($input['amount'] ?? 0))
            ->where('currency = ?', Money::assertCurrency($input['currency'] ?? 'CNY'))
            ->where('product_version = ?', isset($input['product_version']) ? (int) $input['product_version'] : 0)
            ->order('id', Db::SORT_DESC)
            ->limit(1);

        if ($userId !== null) {
            $select->where('user_id = ?', $userId);
        } else {
            $select->where('guest_token_hash = ?', $guestTokenHash)
                ->where('user_id IS NULL');
        }

        return $this->db->fetchRow($select) ?: null;
    }

    private function prepareReusableOrder(array $existing): array
    {
        $pollToken = $this->makeToken();
        $hasReusablePaymentEntry = !empty($existing['pay_url']) || !empty($existing['qr_content']);
        $rows = [
            'poll_token_hash' => hash('sha256', $pollToken),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($hasReusablePaymentEntry) {
            $deliveryToken = $this->makeToken();
            $rows['delivery_token_hash'] = hash('sha256', $deliveryToken);
            $this->db->query($this->db->update('table.pay_orders')->rows($rows)->where('id = ?', (int) $existing['id']));
            $existing['poll_token'] = $pollToken;
            $existing['delivery_token'] = $deliveryToken;
            $existing['reused'] = true;
            $existing['skip_gateway_create'] = true;
            return $existing;
        }

        $returnToken = $this->makeToken();
        $rows['return_token_hash'] = hash('sha256', $returnToken);
        $rows['return_token_expires_at'] = date('Y-m-d H:i:s', time() + self::ORDER_TTL);
        $rows['return_token_used'] = 0;
        $this->db->query($this->db->update('table.pay_orders')->rows($rows)->where('id = ?', (int) $existing['id']));
        $existing['poll_token'] = $pollToken;
        $existing['delivery_token'] = null;
        $existing['return_token'] = $returnToken;
        $existing['return_token_hash'] = $rows['return_token_hash'];
        $existing['return_token_expires_at'] = $rows['return_token_expires_at'];
        $existing['return_token_used'] = 0;
        $existing['reused'] = true;
        $existing['create_in_progress'] = true;
        return $existing;
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
        $bizType = $this->assertBizType((string) ($input['biz_type'] ?? 'post'));
        $bizId = $this->assertBizId($input['biz_id'] ?? null);
        $pollToken = $this->makeToken();
        $returnToken = $this->makeToken();
        $deliveryToken = $this->makeToken();
        $expiresAt = date('Y-m-d H:i:s', time() + self::ORDER_TTL);

        $order = [
            'out_trade_no' => $this->makeTradeNo(),
            'gateway' => (string) $input['gateway'],
            'subject' => $subject,
            'amount' => $amount,
            'currency' => $currency,
            'biz_type' => $bizType,
            'biz_id' => $bizId,
            'product_id' => isset($input['product_id']) ? (int) $input['product_id'] : null,
            'product_key' => isset($input['product_key']) ? (string) $input['product_key'] : null,
            'product_version' => isset($input['product_version']) ? (int) $input['product_version'] : 0,
            'product_snapshot_json' => isset($input['product_snapshot_json']) ? (string) $input['product_snapshot_json'] : null,
            'user_id' => $userId,
            'guest_token_hash' => $guestTokenHash,
            'status' => 'pending',
            'payment_status' => 'pending',
            'fulfillment_status' => 'none',
            'poll_token_hash' => hash('sha256', $pollToken),
            'return_token_hash' => hash('sha256', $returnToken),
            'return_token_expires_at' => $expiresAt,
            'delivery_token_hash' => hash('sha256', $deliveryToken),
            'return_token_used' => 0,
            'platform_trade_no' => null,
            'pay_url' => null,
            'qr_content' => null,
            'return_to' => isset($input['return_to']) ? (string) $input['return_to'] : null,
            'last_queried_at' => null,
            'query_count' => 0,
            'paid_at' => null,
            'expired_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $id = $this->db->query($this->db->insert('table.pay_orders')->rows($order));
        $order['id'] = $id;
        $order['poll_token'] = $pollToken;
        $order['return_token'] = $returnToken;
        $order['delivery_token'] = $deliveryToken;

        return $order;
    }

    public function attachCreateResult(string $outTradeNo, PayCreateResult $result): void
    {
        $this->db->query($this->db->update('table.pay_orders')->rows([
            'status' => 'pending',
            'payment_status' => 'pending',
            'pay_url' => $result->payUrl,
            'qr_content' => $result->qrContent,
            'updated_at' => date('Y-m-d H:i:s'),
        ])->where('out_trade_no = ?', $outTradeNo)
            ->where('status IN ?', self::PAYABLE_STATUSES));
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
        return $this->fulfillPaidOrder($this->confirmPayment($result));
    }

    public function confirmPayment(NotifyResult $result): array
    {
        $order = $this->findByOutTradeNo($result->outTradeNo);
        if (!$order) {
            throw new \RuntimeException('Order not found.');
        }

        $this->assertResultMatchesOrder($order, $result);

        if ($order['status'] === 'paid') {
            return $order;
        }

        if (in_array($order['status'], self::GRANTABLE_STATUSES, true)) {
            return $order;
        }

        if (!in_array($order['status'], self::PAYABLE_STATUSES, true)) {
            throw new \RuntimeException('Order status is not payable.');
        }

        $paidAt = date('Y-m-d H:i:s');
        $updated = $this->db->query($this->db->update('table.pay_orders')->rows([
            'status' => 'paid_pending_grant',
            'payment_status' => 'paid',
            'fulfillment_status' => 'pending',
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
            'payment_status' => 'paid',
            'fulfillment_status' => 'pending',
            'platform_trade_no' => $result->platformTradeNo,
            'paid_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);
        return $pendingOrder;
    }

    public function fulfillPaidOrder(array $order): array
    {
        if ((string) ($order['payment_status'] ?? '') !== 'paid'
            && !in_array((string) ($order['status'] ?? ''), ['paid', 'paid_pending_grant', 'grant_failed'], true)) {
            throw new \RuntimeException('Order payment is not confirmed.');
        }

        return $this->grantPaidOrder($order);
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
        $fulfillmentStatus = (new FulfillmentManager($this->db))->fulfillOrder($order);
        if (in_array($fulfillmentStatus, ['failed', 'none'], true)) {
            $this->db->query($this->db->update('table.pay_orders')->rows([
                'status' => 'grant_failed',
                'payment_status' => 'paid',
                'fulfillment_status' => 'failed',
                'updated_at' => date('Y-m-d H:i:s'),
            ])->where('out_trade_no = ?', $order['out_trade_no'])
                ->where('status IN ?', array_merge(['paid'], self::GRANTABLE_STATUSES)));

            $this->recordEvent($order['out_trade_no'], 'system', 'grant_failed', false, [
                'fulfillment_status' => $fulfillmentStatus,
            ]);

            throw new \RuntimeException('Payment was confirmed but entitlement grant failed.');
        }

        $this->db->query($this->db->update('table.pay_orders')->rows([
            'status' => 'paid',
            'payment_status' => 'paid',
            'fulfillment_status' => $fulfillmentStatus,
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

        $order = $this->findByOutTradeNo($outTradeNo);
        if ($order) {
            (new FulfillmentManager($this->db))->releaseOrder($order);
        }

        $this->db->query($this->db->update('table.pay_orders')->rows([
            'status' => 'failed',
            'payment_status' => 'failed',
            'fulfillment_status' => 'none',
            'updated_at' => date('Y-m-d H:i:s'),
        ])->where('out_trade_no = ?', $outTradeNo)->where('status IN ?', self::PAYABLE_STATUSES));

        $this->recordEvent($outTradeNo, 'system', 'failed', false, ['reason' => $reason]);
    }

    public function markProcessing(string $outTradeNo): void
    {
        if (!$this->isValidTradeNo($outTradeNo)) {
            return;
        }

        $this->db->query($this->db->update('table.pay_orders')->rows([
            'status' => 'processing',
            'payment_status' => 'processing',
            'updated_at' => date('Y-m-d H:i:s'),
        ])->where('out_trade_no = ?', $outTradeNo)->where('status = ?', 'pending'));
    }

    public function markCreateUnknown(string $outTradeNo, string $reason): void
    {
        if (!$this->isValidTradeNo($outTradeNo)) {
            return;
        }

        $this->db->query($this->db->update('table.pay_orders')->rows([
            'status' => 'processing',
            'payment_status' => 'processing',
            'updated_at' => date('Y-m-d H:i:s'),
        ])->where('out_trade_no = ?', $outTradeNo)->where('status IN ?', self::PAYABLE_STATUSES));

        $this->recordEvent($outTradeNo, 'system', 'create_unknown', false, ['reason' => $reason]);
    }

    public function syncProviderStatus(NotifyResult $result): ?array
    {
        $normalized = self::FINAL_PROVIDER_STATUSES[strtolower($result->status)] ?? null;
        if ($normalized === null) {
            return null;
        }

        $order = $this->findByOutTradeNo($result->outTradeNo);
        if (!$order) {
            throw new \RuntimeException('Order not found.');
        }

        $this->assertResultMatchesOrder($order, $result);

        // First, try to transition the order to the terminal state.
        // Only release card reservations if the transition succeeds.
        $updated = $this->db->query($this->db->update('table.pay_orders')->rows([
            'status' => $normalized,
            'payment_status' => $normalized,
            'fulfillment_status' => 'none',
            'platform_trade_no' => $result->platformTradeNo,
            'updated_at' => date('Y-m-d H:i:s'),
        ])->where('out_trade_no = ?', $result->outTradeNo)
            ->where('status IN ?', self::PAYABLE_STATUSES));

        if ($updated > 0) {
            // Status was still pending/processing — safe to release.
            (new FulfillmentManager($this->db))->releaseOrder($order);
        }

        return $this->findByOutTradeNo($result->outTradeNo) ?: array_merge($order, ['status' => $normalized]);
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
                'payment_status' => $order['payment_status'] ?? $order['status'],
                'fulfillment_status' => $order['fulfillment_status'] ?? null,
                'amount' => (int) $order['amount'],
                'currency' => $order['currency'],
                'amount_display' => Money::formatForDisplay((int) $order['amount'], (string) $order['currency']),
                'paid_at' => $order['paid_at'],
                'terminal' => $this->isTerminalStatus((string) $order['status']),
                'has_card_delivery' => (new FulfillmentManager($this->db))->orderHasHandler($order, 'cardcode'),
            ],
        ];
    }

    public function verifyPollToken(array $order, string $pollToken): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $pollToken)) {
            return false;
        }

        $hash = (string) ($order['poll_token_hash'] ?? '');
        return $hash !== '' && hash_equals($hash, hash('sha256', $pollToken));
    }

    /**
     * Atomically consume a one-time return token.
     */
    public function consumeReturnToken(string $outTradeNo, string $returnToken): bool
    {
        if (!$this->isValidTradeNo($outTradeNo) || !preg_match('/^[a-f0-9]{64}$/', $returnToken)) {
            return false;
        }

        $updated = $this->db->query($this->db->update('table.pay_orders')->rows([
            'return_token_used' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ])->where('out_trade_no = ?', $outTradeNo)
            ->where('return_token_hash = ?', hash('sha256', $returnToken))
            ->where('return_token_used = ?', 0)
            ->where('return_token_expires_at > ?', date('Y-m-d H:i:s')));

        return (int) $updated === 1;
    }

    /**
     * Mark a return token as used so it cannot be reused.
     *
     * @deprecated Use consumeReturnToken() so validation and consumption are atomic.
     */
    public function markReturnTokenUsed(string $outTradeNo): void
    {
        if (!$this->isValidTradeNo($outTradeNo)) {
            return;
        }

        $this->db->query($this->db->update('table.pay_orders')->rows([
            'return_token_used' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ])->where('out_trade_no = ?', $outTradeNo)
            ->where('return_token_used = ?', 0));
    }

    public function rotateDeliveryToken(string $outTradeNo, string $deliveryToken): bool
    {
        if (!$this->isValidTradeNo($outTradeNo) || !preg_match('/^[a-f0-9]{64}$/', $deliveryToken)) {
            return false;
        }

        $updated = $this->db->query($this->db->update('table.pay_orders')->rows([
            'delivery_token_hash' => hash('sha256', $deliveryToken),
            'updated_at' => date('Y-m-d H:i:s'),
        ])->where('out_trade_no = ?', $outTradeNo));

        return (int) $updated === 1;
    }

    /**
     * Verify a delivery token (long-lived, for revisiting card delivery page).
     */
    public function verifyDeliveryToken(array $order, string $deliveryToken): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $deliveryToken)) {
            return false;
        }

        $hash = (string) ($order['delivery_token_hash'] ?? '');
        return $hash !== '' && hash_equals($hash, hash('sha256', $deliveryToken));
    }

    public function belongsToOwner(array $order, ?int $userId, ?string $guestTokenHash): bool
    {
        if ($userId !== null && isset($order['user_id']) && (int) $order['user_id'] === $userId) {
            return true;
        }

        return $guestTokenHash !== null
            && isset($order['guest_token_hash'])
            && is_string($order['guest_token_hash'])
            && hash_equals($order['guest_token_hash'], $guestTokenHash);
    }

    private function makeTradeNo(): string
    {
        return 'TP' . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
    }

    private function isValidTradeNo(string $outTradeNo): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{8,64}$/', $outTradeNo);
    }

    private function makeToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function quotedTable(string $table): string
    {
        $prefix = $this->db->getPrefix();
        $adapter = strtolower($this->db->getAdapterName());
        if (strpos($adapter, 'mysql') !== false || strpos($adapter, 'mysqli') !== false) {
            return '`' . str_replace('`', '``', $prefix . $table) . '`';
        }

        return '"' . str_replace('"', '""', $prefix . $table) . '"';
    }

    private function assertBizType(string $bizType): string
    {
        $value = trim($bizType) ?: 'post';
        if (!preg_match('/^[a-z0-9_.-]{1,32}$/', $value)) {
            throw new \InvalidArgumentException('Invalid business type.');
        }

        return $value;
    }

    private function assertBizId($bizId): int
    {
        $value = filter_var($bizId, FILTER_VALIDATE_INT);
        if ($value === false || (int) $value <= 0) {
            throw new \InvalidArgumentException('Invalid business id.');
        }

        return (int) $value;
    }

    private function assertResultMatchesOrder(array $order, NotifyResult $result): void
    {
        if ($result->amount !== null && (int) $order['amount'] !== $result->amount) {
            throw new \RuntimeException('Payment amount mismatch.');
        }

        if ($result->currency !== null && strtoupper((string) $order['currency']) !== strtoupper($result->currency)) {
            throw new \RuntimeException('Payment currency mismatch.');
        }
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, ['paid', 'grant_failed', 'expired', 'cancelled', 'failed', 'closed'], true);
    }
}
