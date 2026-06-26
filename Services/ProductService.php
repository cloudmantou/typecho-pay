<?php

namespace TypechoPlugin\TypechoPay\Services;

use Typecho\Db;
use TypechoPlugin\TypechoPay\Support\Money;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class ProductService
{
    private const PURCHASE_POLICIES = ['once', 'repeatable', 'limited'];

    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function resolve(array $input, array $defaults = []): array
    {
        if ($this->hasStoredProductReference($input)) {
            return $this->resolveStoredProduct($input, $defaults);
        }

        return $this->resolveLegacyInlineProduct($input, $defaults);
    }

    public function entryPayload(array $product, string $returnTo): array
    {
        $payload = ['return_to' => $returnTo];

        if (!empty($product['product_id'])) {
            $payload['product_id'] = (string) $product['product_id'];
            return $payload;
        }

        if (!empty($product['product_key']) && ($product['source'] ?? '') === 'stored') {
            $payload['product'] = (string) $product['product_key'];
            return $payload;
        }

        foreach (['amount', 'currency', 'subject', 'biz_type', 'biz_id', 'purchase_policy'] as $key) {
            if (isset($product[$key])) {
                $payload[$key] = (string) $product[$key];
            }
        }

        return $payload;
    }

    private function resolveStoredProduct(array $input, array $defaults): array
    {
        $product = $this->findStoredProduct($input);
        if (!$product) {
            throw new \InvalidArgumentException('Product is not available.');
        }

        $amount = Money::assertAmount($product['amount'] ?? 0);
        $currency = Money::assertCurrency($product['currency'] ?? ($defaults['currency'] ?? 'CNY'));
        $title = trim((string) ($product['title'] ?? 'TypechoPay Product'));
        $policy = $this->assertPurchasePolicy((string) ($product['purchase_policy'] ?? 'once'));
        $maxPerUser = isset($product['max_per_user']) && (int) $product['max_per_user'] > 0
            ? (int) $product['max_per_user']
            : null;
        $allowGuest = (int) ($product['allow_guest'] ?? 1) === 1 ? 1 : 0;
        $deliverables = $this->findDeliverables((int) $product['id']);

        // Stored products must have at least one enabled deliverable.
        // Otherwise the product is misconfigured and we should not accept payment.
        if (!$deliverables) {
            throw new \InvalidArgumentException('Product has no enabled deliverables. Please configure at least one delivery rule.');
        }

        // Validate deliverable configuration.
        $allowedHandlers = ['post_access', 'content_block', 'cardcode'];
        foreach ($deliverables as $deliverable) {
            $handler = (string) ($deliverable['handler'] ?? '');
            if (!in_array($handler, $allowedHandlers, true)) {
                throw new \InvalidArgumentException('Product has an invalid deliverable handler: ' . $handler);
            }
            if (in_array($handler, ['post_access', 'content_block'], true) && empty($deliverable['target_id'])) {
                throw new \InvalidArgumentException('Product deliverable "' . $handler . '" requires a target post/page.');
            }
            if ($handler === 'cardcode' && ($product['stock_policy'] ?? 'none') === 'none') {
                // Cardcode deliverable without stock policy — warn but allow (stock may be managed externally)
            }
        }

        [$bizType, $bizId] = $this->resolvePrimaryAccessTarget($product, $deliverables, $defaults);

        $snapshot = [
            'source' => 'stored',
            'product_id' => (int) $product['id'],
            'product_key' => (string) ($product['product_key'] ?? ''),
            'product_version' => (int) ($product['version'] ?? 1),
            'subject' => $title,
            'amount' => $amount,
            'currency' => $currency,
            'biz_type' => $bizType,
            'biz_id' => $bizId,
            'purchase_policy' => $policy,
            'max_per_user' => $maxPerUser,
            'allow_guest' => $allowGuest,
            'snapshot' => [
                'id' => (int) $product['id'],
                'product_key' => (string) ($product['product_key'] ?? ''),
                'title' => $title,
                'amount' => $amount,
                'currency' => $currency,
                'purchase_policy' => $policy,
                'max_per_user' => $maxPerUser,
                'allow_guest' => $allowGuest,
                'version' => (int) ($product['version'] ?? 1),
                'deliverables' => $deliverables,
            ],
        ];

        $snapshot['product_snapshot_json'] = json_encode($snapshot['snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $snapshot;
    }

    private function resolveLegacyInlineProduct(array $input, array $defaults): array
    {
        $amount = Money::assertAmount($input['amount'] ?? 0);
        $currency = Money::assertCurrency($input['currency'] ?? ($defaults['currency'] ?? 'CNY'));
        $subject = trim((string) ($input['subject'] ?? ($defaults['subject'] ?? 'TypechoPay Order')));
        $subjectLength = function_exists('mb_strlen') ? mb_strlen($subject) : strlen($subject);
        if ($subject === '' || $subjectLength > 255) {
            throw new \InvalidArgumentException('Invalid payment subject.');
        }

        $bizType = $this->assertBizType((string) ($input['biz_type'] ?? ($defaults['biz_type'] ?? 'post')));
        $bizId = $this->assertBizId($input['biz_id'] ?? ($defaults['biz_id'] ?? null));
        $policy = $this->assertPurchasePolicy((string) ($input['purchase_policy'] ?? 'once'));

        $snapshot = [
            'source' => 'legacy_inline',
            'product_id' => null,
            'product_key' => null,
            'product_version' => 0,
            'subject' => $subject,
            'amount' => $amount,
            'currency' => $currency,
            'biz_type' => $bizType,
            'biz_id' => $bizId,
            'purchase_policy' => $policy,
            'max_per_user' => null,
            'allow_guest' => 1,
            'snapshot' => [
                'mode' => 'legacy_inline',
                'subject' => $subject,
                'amount' => $amount,
                'currency' => $currency,
                'biz_type' => $bizType,
                'biz_id' => $bizId,
                'purchase_policy' => $policy,
                'max_per_user' => null,
                'allow_guest' => 1,
            ],
        ];
        $snapshot['product_snapshot_json'] = json_encode($snapshot['snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $snapshot;
    }

    private function hasStoredProductReference(array $input): bool
    {
        $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
        if ($productId !== false && (int) $productId > 0) {
            return true;
        }

        $productKey = trim((string) ($input['product'] ?? ''));
        return $productKey !== '';
    }

    private function findStoredProduct(array $input): ?array
    {
        $select = $this->db->select()->from('table.pay_products')->where('status = ?', 'active')->limit(1);
        $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);

        if ($productId !== false && (int) $productId > 0) {
            $select->where('id = ?', (int) $productId);
        } else {
            $key = trim((string) ($input['product'] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/', $key)) {
                throw new \InvalidArgumentException('Invalid product key.');
            }
            $select->where('product_key = ?', $key);
        }

        try {
            return $this->db->fetchRow($select) ?: null;
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Product table is not ready. Please reactivate TypechoPay to run migrations.');
        }
    }

    private function findDeliverables(int $productId): array
    {
        $rows = $this->db->fetchAll(
            $this->db->select()->from('table.pay_product_deliverables')
                ->where('product_id = ?', $productId)
                ->where('enabled = ?', 1)
                ->order('sort_order', Db::SORT_ASC)
        );

        $deliverables = [];
        foreach ($rows as $row) {
            $deliverables[] = [
                'id' => (int) ($row['id'] ?? 0),
                'handler' => (string) ($row['handler'] ?? ''),
                'target_type' => (string) ($row['target_type'] ?? ''),
                'target_id' => isset($row['target_id']) ? (int) $row['target_id'] : null,
                'target_key' => isset($row['target_key']) ? (string) $row['target_key'] : null,
                'config' => $this->decodeJson((string) ($row['config_json'] ?? '')),
            ];
        }

        return $deliverables;
    }

    private function resolvePrimaryAccessTarget(array $product, array $deliverables, array $defaults): array
    {
        foreach ($deliverables as $deliverable) {
            if (in_array($deliverable['handler'], ['post_access', 'content_block'], true) && !empty($deliverable['target_id'])) {
                return [
                    $this->assertBizType($deliverable['target_type'] ?: ($deliverable['handler'] === 'post_access' ? 'post' : 'content_block')),
                    $this->assertBizId($deliverable['target_id']),
                ];
            }
        }

        if (!empty($product['content_id'])) {
            return ['post', $this->assertBizId($product['content_id'])];
        }

        if (!empty($defaults['biz_id'])) {
            return [
                $this->assertBizType((string) ($defaults['biz_type'] ?? 'post')),
                $this->assertBizId($defaults['biz_id']),
            ];
        }

        return ['product', $this->assertBizId($product['id'] ?? null)];
    }

    private function assertPurchasePolicy(string $policy): string
    {
        $value = strtolower(trim($policy)) ?: 'once';
        if (!in_array($value, self::PURCHASE_POLICIES, true)) {
            throw new \InvalidArgumentException('Invalid purchase policy.');
        }

        return $value;
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

    private function decodeJson(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
