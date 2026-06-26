<?php

namespace TypechoPlugin\TypechoPay\Services;

use Typecho\Db;
use TypechoPlugin\TypechoPay\Support\CardCodeCipher;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class CardCodeService
{
    private const RESERVATION_TTL = 1800;

    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * Import card codes for a product.
     *
     * @param int $productId
     * @param string $batchName
     * @param string $rawLines Raw text (from textarea or file content)
     * @param int|null $importedBy
     * @return array{batch_id:int, imported:int, duplicates:int, duplicate_in_file:int, raw_count:int, total:int}
     */
    public function importBatch(int $productId, string $batchName, string $rawLines, ?int $importedBy = null): array
    {
        if ($productId <= 0) {
            throw new \InvalidArgumentException('Invalid product id.');
        }

        $batchName = trim($batchName);
        $nameLen = function_exists('mb_strlen') ? mb_strlen($batchName) : strlen($batchName);
        if ($batchName !== '' && $nameLen > 128) {
            throw new \InvalidArgumentException('Batch name is too long (max 128 characters).');
        }

        $parsed = $this->parseLines($rawLines);
        $items = $parsed['items'];
        if (!$items) {
            throw new \InvalidArgumentException('No card codes to import.');
        }

        if (count($items) > 10000) {
            throw new \InvalidArgumentException('Too many card codes in a single import (max 10,000).');
        }

        $now = date('Y-m-d H:i:s');
        $keyMaterial = $this->keyMaterial();
        $imported = 0;
        $dbDuplicates = 0;

        $this->db->query('START TRANSACTION', Db::WRITE, '');

        try {
            $batchId = $this->db->query($this->db->insert('table.pay_card_batches')->rows([
                'product_id' => $productId,
                'batch_name' => $batchName !== '' ? $batchName : 'batch-' . date('YmdHis'),
                'imported_count' => 0,
                'imported_by' => $importedBy,
                'created_at' => $now,
            ]));

            // Pre-compute all fingerprints and encrypt in chunks to avoid timeout.
            $chunkSize = 500;
            $chunks = array_chunk($items, $chunkSize);

            foreach ($chunks as $chunk) {
                // Query existing fingerprints for this chunk to avoid DB unique violations.
                $fingerprints = [];
                foreach ($chunk as $item) {
                    $fingerprints[] = $this->fingerprint($productId, $item['code'], $item['secret']);
                }

                $existing = $this->findExistingFingerprints($productId, $fingerprints);
                $existingSet = [];
                foreach ($existing as $fp) {
                    $existingSet[$fp] = true;
                }

                foreach ($chunk as $item) {
                    $fp = $this->fingerprint($productId, $item['code'], $item['secret']);
                    if (isset($existingSet[$fp])) {
                        $dbDuplicates++;
                        continue;
                    }

                    $codeCiphertext = CardCodeCipher::encrypt($item['code'], $keyMaterial);
                    $secretCiphertext = $item['secret'] !== null
                        ? CardCodeCipher::encrypt($item['secret'], $keyMaterial)
                        : null;
                    try {
                        $this->db->query($this->db->insert('table.pay_card_items')->rows([
                            'product_id' => $productId,
                            'batch_id' => (int) $batchId,
                            'code_ciphertext' => $codeCiphertext,
                            'secret_ciphertext' => $secretCiphertext,
                            'fingerprint' => $fp,
                            'status' => 'available',
                            'reserved_order_id' => null,
                            'reserved_until' => null,
                            'delivered_order_id' => null,
                            'delivered_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]));
                        $imported++;
                    } catch (\Throwable $e) {
                        $msg = strtolower($e->getMessage());
                        if (strpos($msg, '1062') !== false
                            || strpos($msg, 'unique') !== false
                            || strpos($msg, 'duplicate') !== false) {
                            $dbDuplicates++;
                            continue;
                        }
                        throw $e;
                    }
                }
            }

            $this->db->query($this->db->update('table.pay_card_batches')->rows([
                'imported_count' => $imported,
            ])->where('id = ?', (int) $batchId));

            $this->db->query('COMMIT', Db::WRITE, '');
        } catch (\Throwable $e) {
            try {
                $this->db->query('ROLLBACK', Db::WRITE, '');
            } catch (\Throwable $rb) {
                error_log('[TypechoPay] Import rollback failed: ' . $rb->getMessage());
            }
            throw $e;
        }

        return [
            'batch_id' => (int) $batchId,
            'imported' => $imported,
            'duplicates' => $dbDuplicates,
            'duplicate_in_file' => $parsed['duplicate_in_file'],
            'raw_count' => $parsed['raw_count'],
            'total' => count($items),
        ];
    }

    /**
     * Mark card items as void (admin action).
     */
    public function markVoid(array $ids): int
    {
        if (!$ids) {
            return 0;
        }

        return $this->db->query($this->db->update('table.pay_card_items')->rows([
            'status' => 'void',
            'updated_at' => date('Y-m-d H:i:s'),
        ])->where('id IN ?', $ids)
            ->where('status = ?', 'available'));
    }

    /**
     * Mark card items as compromised (admin action).
     */
    public function markCompromised(array $ids): int
    {
        if (!$ids) {
            return 0;
        }

        return $this->db->query($this->db->update('table.pay_card_items')->rows([
            'status' => 'compromised',
            'updated_at' => date('Y-m-d H:i:s'),
        ])->where('id IN ?', $ids)
            ->where('status IN ?', ['available', 'reserved', 'delivered']));
    }

    /**
     * Paginated card inventory for admin listing.
     */
    public function inventory(int $productId, ?string $status = null, ?int $batchId = null, int $page = 1, int $perPage = 50): array
    {
        $select = $this->db->select()->from('table.pay_card_items');
        if ($productId > 0) {
            $select->where('product_id = ?', $productId);
        }
        if ($status !== null && $status !== '') {
            $select->where('status = ?', $status);
        }
        if ($batchId !== null && $batchId > 0) {
            $select->where('batch_id = ?', $batchId);
        }

        // Count total.
        $countSelect = $this->db->select('COUNT(*) AS cnt')->from('table.pay_card_items');
        if ($productId > 0) {
            $countSelect->where('product_id = ?', $productId);
        }
        if ($status !== null && $status !== '') {
            $countSelect->where('status = ?', $status);
        }
        if ($batchId !== null && $batchId > 0) {
            $countSelect->where('batch_id = ?', $batchId);
        }
        $total = (int) (($this->db->fetchRow($countSelect))['cnt'] ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->fetchAll(
            $select->order('id', Db::SORT_DESC)->limit($perPage)->offset($offset)
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Paginated card sales (delivered items with order info).
     */
    public function sales(int $productId = 0, int $page = 1, int $perPage = 50): array
    {
        $cardTable = $this->quotedTable('pay_card_items');
        $orderTable = $this->quotedTable('pay_orders');
        $fulfillmentTable = $this->quotedTable('pay_fulfillments');

        $where = "ci.status = 'delivered'";
        if ($productId > 0) {
            $where .= " AND ci.product_id = " . (int) $productId;
        }

        $countRow = $this->db->fetchRow(
            "SELECT COUNT(*) AS cnt FROM {$cardTable} ci WHERE {$where}"
        );
        $total = (int) ($countRow['cnt'] ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->fetchAll(
            "SELECT ci.id AS card_id, ci.product_id, ci.batch_id, ci.delivered_order_id, ci.delivered_at,
                    o.out_trade_no, o.amount, o.currency, o.gateway, o.user_id, o.guest_token_hash,
                    o.payment_status, o.fulfillment_status, o.paid_at,
                    f.attempts, f.last_error, f.status AS fulfillment_detail_status
             FROM {$cardTable} ci
             LEFT JOIN {$orderTable} o ON ci.delivered_order_id = o.id
             LEFT JOIN {$fulfillmentTable} f ON f.order_id = o.id AND f.card_item_id = ci.id
             WHERE {$where}
             ORDER BY ci.delivered_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function reserveForOrder(array $order): ?array
    {
        $productId = (int) ($order['product_id'] ?? 0);
        $orderId = (int) ($order['id'] ?? 0);
        if ($productId <= 0 || $orderId <= 0) {
            return null;
        }

        $this->releaseExpiredReservations($productId);

        $reservedUntil = date('Y-m-d H:i:s', time() + self::RESERVATION_TTL);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            // Re-check on every attempt: another concurrent request may have
            // already reserved a card for this order.
            $existing = $this->findOrderCard($productId, $orderId, ['reserved', 'delivered']);
            if ($existing) {
                return $existing;
            }

            $candidate = $this->db->fetchRow(
                $this->db->select()->from('table.pay_card_items')
                    ->where('product_id = ?', $productId)
                    ->where('status = ?', 'available')
                    ->order('id', Db::SORT_ASC)
                    ->limit(1)
            );

            if (!$candidate) {
                // Before declaring out of stock, check if another request just reserved for us.
                $existing = $this->findOrderCard($productId, $orderId, ['reserved', 'delivered']);
                if ($existing) {
                    return $existing;
                }
                throw new \InvalidArgumentException('Card code is out of stock.');
            }

            $updated = $this->db->query($this->db->update('table.pay_card_items')->rows([
                'status' => 'reserved',
                'reserved_order_id' => $orderId,
                'reserved_until' => $reservedUntil,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->where('id = ?', (int) $candidate['id'])
                ->where('status = ?', 'available'));

            if ($updated > 0) {
                return $this->findById((int) $candidate['id']);
            }
        }

        // Final check before giving up.
        $existing = $this->findOrderCard($productId, $orderId, ['reserved', 'delivered']);
        if ($existing) {
            return $existing;
        }

        throw new \RuntimeException('Failed to reserve card code.');
    }

    public function deliverForOrder(array $order): array
    {
        $productId = (int) ($order['product_id'] ?? 0);
        $orderId = (int) ($order['id'] ?? 0);
        if ($productId <= 0 || $orderId <= 0) {
            throw new \RuntimeException('Invalid card-code order.');
        }

        $delivered = $this->findOrderCard($productId, $orderId, ['delivered']);
        if ($delivered) {
            return $delivered;
        }

        $this->releaseExpiredReservations($productId);
        $reserved = $this->findOrderCard($productId, $orderId, ['reserved']);
        if (!$reserved) {
            $reserved = $this->reserveForOrder($order);
        }

        if (!$reserved) {
            throw new \RuntimeException('Card-code reservation failed.');
        }

        $updated = $this->db->query($this->db->update('table.pay_card_items')->rows([
            'status' => 'delivered',
            'delivered_order_id' => $orderId,
            'delivered_at' => date('Y-m-d H:i:s'),
            'reserved_until' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ])->where('id = ?', (int) $reserved['id'])
            ->where('status = ?', 'reserved')
            ->where('reserved_order_id = ?', $orderId));

        if ($updated <= 0) {
            $delivered = $this->findOrderCard($productId, $orderId, ['delivered']);
            if ($delivered) {
                return $delivered;
            }

            throw new \RuntimeException('Failed to deliver card code.');
        }

        return $this->findById((int) $reserved['id']) ?: $reserved;
    }

    public function releaseOrderReservations(array $order): void
    {
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId <= 0) {
            return;
        }

        $this->db->query($this->db->update('table.pay_card_items')->rows([
            'status' => 'available',
            'reserved_order_id' => null,
            'reserved_until' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ])->where('reserved_order_id = ?', $orderId)
            ->where('status = ?', 'reserved'));
    }

    public function deliveredCardsForOrder(array $order): array
    {
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId <= 0) {
            return [];
        }

        $rows = $this->db->fetchAll(
            $this->db->select()->from('table.pay_card_items')
                ->where('delivered_order_id = ?', $orderId)
                ->where('status = ?', 'delivered')
                ->order('delivered_at', Db::SORT_ASC)
        );

        $cards = [];
        foreach ($rows as $row) {
            $cards[] = $this->decryptRow($row);
        }

        return $cards;
    }

    public function stockCounts(int $productId): array
    {
        $counts = [
            'available' => 0,
            'reserved' => 0,
            'delivered' => 0,
            'void' => 0,
            'compromised' => 0,
            'total' => 0,
        ];

        if ($productId <= 0) {
            return $counts;
        }

        $this->releaseExpiredReservations($productId);
        $table = $this->quotedTable('pay_card_items');
        $rows = $this->db->fetchAll(
            "SELECT status, COUNT(*) AS count_value FROM {$table} WHERE product_id = " . (int) $productId . " GROUP BY status"
        );

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $count = (int) ($row['count_value'] ?? 0);
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $count;
            }
            $counts['total'] += $count;
        }

        return $counts;
    }

    public function releaseExpiredReservations(?int $productId = null): void
    {
        // Only release cards whose associated orders are NOT paid.
        // Paid orders must keep their reservation so delivery can proceed.
        $now = date('Y-m-d H:i:s');
        $cardTable = $this->quotedTable('pay_card_items');
        $orderTable = $this->quotedTable('pay_orders');

        $sql = "UPDATE {$cardTable} ci
            LEFT JOIN {$orderTable} o ON ci.reserved_order_id = o.id
            SET ci.status = 'available',
                ci.reserved_order_id = NULL,
                ci.reserved_until = NULL,
                ci.updated_at = '{$now}'
            WHERE ci.status = 'reserved'
              AND ci.reserved_until IS NOT NULL
              AND ci.reserved_until < '{$now}'
              AND (o.id IS NULL OR o.payment_status NOT IN ('paid', 'processing'))";

        if ($productId !== null && $productId > 0) {
            $sql .= " AND ci.product_id = " . (int) $productId;
        }

        try {
            $this->db->query($sql, Db::WRITE, '');
        } catch (\Throwable $e) {
            // Fallback for SQLite/PostgreSQL: use subquery approach.
            // First find expired reservations with unpaid orders, then release them.
            $adapter = strtolower($this->db->getAdapterName());
            if (strpos($adapter, 'sqlite') !== false) {
                $this->releaseExpiredReservationsSqlite($productId, $now);
                return;
            }
            // PostgreSQL: use EXISTS subquery.
            $this->releaseExpiredReservationsSubquery($productId, $now);
        }
    }

    private function releaseExpiredReservationsSqlite(?int $productId, string $now): void
    {
        $cardTable = $this->quotedTable('pay_card_items');
        $orderTable = $this->quotedTable('pay_orders');

        // SQLite: collect IDs first, then update.
        $sql = "SELECT ci.id FROM {$cardTable} ci
            LEFT JOIN {$orderTable} o ON ci.reserved_order_id = o.id
            WHERE ci.status = 'reserved'
              AND ci.reserved_until IS NOT NULL
              AND ci.reserved_until < '{$now}'
              AND (o.id IS NULL OR o.payment_status NOT IN ('paid', 'processing'))";

        if ($productId !== null && $productId > 0) {
            $sql .= " AND ci.product_id = " . (int) $productId;
        }

        $rows = $this->db->fetchAll($sql);
        if (!$rows) {
            return;
        }

        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        foreach (array_chunk($ids, 500) as $chunk) {
            $this->db->query($this->db->update('table.pay_card_items')->rows([
                'status' => 'available',
                'reserved_order_id' => null,
                'reserved_until' => null,
                'updated_at' => $now,
            ])->where('id IN ?', $chunk));
        }
    }

    private function releaseExpiredReservationsSubquery(?int $productId, string $now): void
    {
        $cardTable = $this->quotedTable('pay_card_items');
        $orderTable = $this->quotedTable('pay_orders');

        $sql = "UPDATE {$cardTable}
            SET status = 'available',
                reserved_order_id = NULL,
                reserved_until = NULL,
                updated_at = '{$now}'
            WHERE status = 'reserved'
              AND reserved_until IS NOT NULL
              AND reserved_until < '{$now}'
              AND (
                reserved_order_id IS NULL
                OR NOT EXISTS (
                    SELECT 1 FROM {$orderTable}
                    WHERE {$orderTable}.id = {$cardTable}.reserved_order_id
                      AND {$orderTable}.payment_status IN ('paid', 'processing')
                )
              )";

        if ($productId !== null && $productId > 0) {
            $sql .= " AND product_id = " . (int) $productId;
        }

        $this->db->query($sql, Db::WRITE, '');
    }

    private function findOrderCard(int $productId, int $orderId, array $statuses): ?array
    {
        return $this->db->fetchRow(
            $this->db->select()->from('table.pay_card_items')
                ->where('product_id = ?', $productId)
                ->where('(reserved_order_id = ? OR delivered_order_id = ?)', $orderId, $orderId)
                ->where('status IN ?', $statuses)
                ->order('id', Db::SORT_ASC)
                ->limit(1)
        ) ?: null;
    }

    private function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return $this->db->fetchRow(
            $this->db->select()->from('table.pay_card_items')->where('id = ?', $id)->limit(1)
        ) ?: null;
    }

    private function parseLines(string $rawLines): array
    {
        $items = [];
        $seen = [];
        $rawCount = 0;
        $duplicateInFile = 0;

        foreach (preg_split('/\R/u', $rawLines) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $rawCount++;
            [$code, $secret] = $this->parseLine($line);
            $key = hash('sha256', $code . "\0" . (string) $secret);
            if (isset($seen[$key])) {
                $duplicateInFile++;
                continue;
            }

            $seen[$key] = true;
            $items[] = ['code' => $code, 'secret' => $secret];
        }

        return [
            'items' => $items,
            'raw_count' => $rawCount,
            'duplicate_in_file' => $duplicateInFile,
        ];
    }

    /**
     * Find which fingerprints already exist in the database for a given product.
     */
    private function findExistingFingerprints(int $productId, array $fingerprints): array
    {
        if (!$fingerprints) {
            return [];
        }

        $existing = [];
        // Query in chunks to avoid overly large IN clauses.
        foreach (array_chunk($fingerprints, 500) as $chunk) {
            $rows = $this->db->fetchAll(
                $this->db->select('fingerprint')->from('table.pay_card_items')
                    ->where('product_id = ?', $productId)
                    ->where('fingerprint IN ?', $chunk)
            );
            foreach ($rows as $row) {
                $existing[] = (string) $row['fingerprint'];
            }
        }

        return $existing;
    }

    private function parseLine(string $line): array
    {
        foreach (["\t", '----', '---', '|', ','] as $separator) {
            if (strpos($line, $separator) !== false) {
                [$code, $secret] = array_map('trim', explode($separator, $line, 2));
                if ($code === '') {
                    throw new \InvalidArgumentException('Card code line contains empty code.');
                }

                $this->assertCodeLength($code, $secret);
                return [$code, $secret !== '' ? $secret : null];
            }
        }

        $this->assertCodeLength($line, null);
        return [$line, null];
    }

    private function assertCodeLength(string $code, ?string $secret): void
    {
        $codeLen = function_exists('mb_strlen') ? mb_strlen($code) : strlen($code);
        if ($codeLen > 4096) {
            throw new \InvalidArgumentException('Card code is too long (max 4096 characters).');
        }

        if ($secret !== null && $secret !== '') {
            $secretLen = function_exists('mb_strlen') ? mb_strlen($secret) : strlen($secret);
            if ($secretLen > 4096) {
                throw new \InvalidArgumentException('Card secret is too long (max 4096 characters).');
            }
        }
    }

    private function decryptRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => CardCodeCipher::decrypt((string) $row['code_ciphertext'], $this->keyMaterial()),
            'secret' => !empty($row['secret_ciphertext'])
                ? CardCodeCipher::decrypt((string) $row['secret_ciphertext'], $this->keyMaterial())
                : null,
            'delivered_at' => $row['delivered_at'] ?? null,
        ];
    }

    private function fingerprint(int $productId, string $code, ?string $secret): string
    {
        return hash_hmac('sha256', $productId . "\0" . $code . "\0" . (string) $secret, $this->keyMaterial());
    }

    private function keyMaterial(): string
    {
        return (string) Options::alloc()->secret;
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
}
