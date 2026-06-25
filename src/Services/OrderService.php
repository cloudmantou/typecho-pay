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
    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function create(array $input, ?int $userId = null): array
    {
        $now = date('Y-m-d H:i:s');
        $amount = Money::assertAmount($input['amount'] ?? 0);
        $currency = Money::assertCurrency($input['currency'] ?? 'CNY');
        $subject = trim((string) ($input['subject'] ?? 'TypechoPay Order'));
        if ($subject === '' || mb_strlen($subject) > 255) {
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
            'guest_token_hash' => null,
            'status' => 'pending',
            'platform_trade_no' => null,
            'pay_url' => null,
            'qr_content' => null,
            'paid_at' => null,
            'expired_at' => date('Y-m-d H:i:s', time() + 1800),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $id = $this->db->query($this->db->insert('table.pay_orders')->rows($order));
        $order['id'] = $id;

        return $order;
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

        if ($order['status'] !== 'paid') {
            $this->db->query($this->db->update('table.pay_orders')->rows([
                'status' => 'paid',
                'platform_trade_no' => $result->platformTradeNo,
                'paid_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ])->where('out_trade_no = ?', $result->outTradeNo));
        }

        return $this->findByOutTradeNo($result->outTradeNo) ?: $order;
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

    public function recordEvent(string $outTradeNo, string $gateway, string $eventType, bool $signatureOk, array $payload): void
    {
        $this->db->query($this->db->insert('table.pay_events')->rows([
            'out_trade_no' => $outTradeNo !== '' ? $outTradeNo : 'unknown',
            'gateway' => $gateway,
            'event_type' => $eventType,
            'signature_ok' => $signatureOk ? 1 : 0,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]));
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
