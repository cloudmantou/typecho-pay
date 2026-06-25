<?php

namespace TypechoPlugin\TypechoPay\Services;

use Typecho\Db;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class AccessService
{
    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function grant(array $order): void
    {
        if (empty($order['id']) || empty($order['biz_type']) || empty($order['biz_id'])) {
            throw new \RuntimeException('Invalid entitlement target.');
        }

        if (empty($order['user_id']) && empty($order['guest_token_hash'])) {
            throw new \RuntimeException('Missing entitlement owner.');
        }

        if ($this->hasOrderGrant((int) $order['id'])) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        try {
            $this->db->query($this->db->insert('table.pay_entitlements')->rows([
                'order_id' => (int) $order['id'],
                'biz_type' => (string) $order['biz_type'],
                'biz_id' => (int) $order['biz_id'],
                'user_id' => isset($order['user_id']) ? (int) $order['user_id'] : null,
                'guest_token_hash' => $order['guest_token_hash'] ?? null,
                'starts_at' => $now,
                'expires_at' => null,
                'created_at' => $now,
            ]));
        } catch (\Throwable $e) {
            if ($this->hasOrderGrant((int) $order['id'])) {
                return;
            }

            throw $e;
        }
    }

    public function hasOrderGrant(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        return (bool) $this->db->fetchRow(
            $this->db->select('id')->from('table.pay_entitlements')->where('order_id = ?', $orderId)->limit(1)
        );
    }

    public function canAccess(string $bizType, int $bizId, ?int $userId, ?string $guestTokenHash): bool
    {
        if ($bizId <= 0) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $select = $this->db->select('id')->from('table.pay_entitlements')
            ->where('biz_type = ?', $bizType)
            ->where('biz_id = ?', $bizId)
            ->where('starts_at <= ?', $now)
            ->where('(expires_at IS NULL OR expires_at > ?)', $now)
            ->limit(1);

        if ($userId !== null) {
            $select->where('user_id = ?', $userId);
        } elseif ($guestTokenHash !== null) {
            $select->where('guest_token_hash = ?', $guestTokenHash);
        } else {
            return false;
        }

        return (bool) $this->db->fetchRow($select);
    }
}
