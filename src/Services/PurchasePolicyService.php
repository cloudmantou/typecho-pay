<?php

namespace TypechoPlugin\TypechoPay\Services;

use Typecho\Db;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Purchase policy enforcement based on product purchase records.
 *
 * Unlike AccessService (which checks content entitlements), this service
 * checks whether a buyer has already purchased a specific product.
 */
final class PurchasePolicyService
{
    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * Assert that the buyer is allowed to purchase this product.
     * Throws InvalidArgumentException if the purchase policy disallows it.
     *
     * @param array $product The resolved product (must have product_id, purchase_policy, max_per_user)
     * @param int|null $userId The logged-in user ID, or null for guests
     * @param string|null $guestTokenHash The guest token hash, or null for logged-in users
     */
    public function assertCanPurchase(array $product, ?int $userId, ?string $guestTokenHash): void
    {
        $productId = (int) ($product['product_id'] ?? ($product['snapshot']['id'] ?? 0));
        if ($productId <= 0) {
            // Legacy inline products: fall back to content-based check
            return;
        }

        $policy = (string) ($product['purchase_policy'] ?? 'once');
        $paidCount = $this->countPaidOrders($productId, $userId, $guestTokenHash);

        if ($policy === 'once' && $paidCount > 0) {
            throw new \InvalidArgumentException('This product has already been purchased.');
        }

        if ($policy === 'limited') {
            $maxPerUser = (int) ($product['max_per_user'] ?? ($product['snapshot']['max_per_user'] ?? 1));
            if ($maxPerUser > 0 && $paidCount >= $maxPerUser) {
                throw new \InvalidArgumentException('Purchase limit reached for this product.');
            }
        }
        // 'repeatable' — no limit
    }

    /**
     * Check if the buyer can access this product (has a paid order).
     * Used for display purposes (e.g., showing "already purchased" in shortcodes).
     */
    public function hasPurchased(int $productId, ?int $userId, ?string $guestTokenHash): bool
    {
        if ($productId <= 0) {
            return false;
        }

        return $this->countPaidOrders($productId, $userId, $guestTokenHash) > 0;
    }

    private function countPaidOrders(int $productId, ?int $userId, ?string $guestTokenHash): int
    {
        if ($userId === null && ($guestTokenHash === null || $guestTokenHash === '')) {
            return 0;
        }

        $select = $this->db->select('COUNT(*) AS cnt')->from('table.pay_orders')
            ->where('product_id = ?', $productId)
            ->where('payment_status = ?', 'paid');

        if ($userId !== null) {
            $select->where('user_id = ?', $userId);
        } else {
            $select->where('guest_token_hash = ?', $guestTokenHash)
                ->where('user_id IS NULL');
        }

        $row = $this->db->fetchRow($select);
        return (int) ($row['cnt'] ?? 0);
    }
}
