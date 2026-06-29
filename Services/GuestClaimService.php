<?php

namespace TypechoPlugin\TypechoPay\Services;

use Typecho\Db;
use Typecho\Request;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class GuestClaimService
{
    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function claimAll(int $userId, ?string $guestTokenHash): void
    {
        if ($userId <= 0 || $guestTokenHash === null || $guestTokenHash === '') {
            return;
        }

        $this->claimOrders($userId, $guestTokenHash);
        $this->claimEntitlements($userId, $guestTokenHash);
    }

    private function claimOrders(int $userId, string $guestTokenHash): void
    {
        try {
            $orders = $this->db->fetchAll(
                $this->db->select('out_trade_no')->from('table.pay_orders')
                    ->where('guest_token_hash = ?', $guestTokenHash)
                    ->where('user_id IS NULL')
            );

            $this->db->query($this->db->update('table.pay_orders')->rows([
                'user_id' => $userId,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->where('guest_token_hash = ?', $guestTokenHash)
                ->where('user_id IS NULL'));

            foreach ($orders as $order) {
                $this->recordClaimEvent((string) ($order['out_trade_no'] ?? 'unknown'), $userId);
            }
        } catch (\Throwable $e) {
            error_log('[TypechoPay] Failed to claim guest orders: ' . $e->getMessage());
        }
    }

    private function claimEntitlements(int $userId, string $guestTokenHash): void
    {
        try {
            $this->db->query($this->db->update('table.pay_entitlements')->rows([
                'user_id' => $userId,
            ])->where('guest_token_hash = ?', $guestTokenHash)
                ->where('user_id IS NULL'));
        } catch (\Throwable $e) {
            error_log('[TypechoPay] Failed to claim guest entitlements: ' . $e->getMessage());
        }
    }

    private function recordClaimEvent(string $outTradeNo, int $userId): void
    {
        try {
            $this->db->query($this->db->insert('table.pay_events')->rows([
                'out_trade_no' => $outTradeNo !== '' ? $outTradeNo : 'unknown',
                'gateway' => 'system',
                'event_type' => 'guest_claimed',
                'provider_event_id' => null,
                'provider_event_type' => null,
                'platform_trade_no' => null,
                'remote_ip' => $this->clientIp(),
                'headers_json' => null,
                'signature_ok' => 1,
                'payload' => json_encode(['user_id' => $userId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => date('Y-m-d H:i:s'),
            ]));
        } catch (\Throwable $e) {
            error_log('[TypechoPay] Failed to record guest claim event: ' . $e->getMessage());
        }
    }

    private function clientIp(): string
    {
        try {
            $ip = trim((string) Request::getInstance()->getIp());
        } catch (\Throwable $e) {
            $ip = '';
        }

        return $ip !== '' ? $ip : 'unknown';
    }
}
