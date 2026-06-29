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
     * Parse raw input lines without importing — used for preview.
     *
     * @param string $rawLines
     * @param string $filenameHint For CSV detection
     * @return array{items:array, raw_count:int, duplicate_in_file:int}
     */
    public function parseForPreview(string $rawLines, string $filenameHint = ''): array
    {
        $ext = strtolower(pathinfo($filenameHint, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            return $this->parseCsvForPreview($rawLines);
        }
        return $this->parseLines($rawLines);
    }

    /**
     * Import card codes for a product.
     *
     * @param int $productId
     * @param string $batchName
     * @param string $rawLines Raw text (from textarea or file content)
     * @param int|null $importedBy
     * @param string $filenameHint For CSV detection
     * @return array{batch_id:int, imported:int, duplicates:int, duplicate_in_file:int, raw_count:int, total:int}
     */
    public function importBatch(int $productId, string $batchName, string $rawLines, ?int $importedBy = null, string $filenameHint = ''): array
    {
        if ($productId <= 0) {
            throw new \InvalidArgumentException('Invalid product id.');
        }

        $batchName = trim($batchName);
        $nameLen = function_exists('mb_strlen') ? mb_strlen($batchName) : strlen($batchName);
        if ($batchName !== '' && $nameLen > 128) {
            throw new \InvalidArgumentException('Batch name is too long (max 128 characters).');
        }

        $ext = strtolower(pathinfo($filenameHint, PATHINFO_EXTENSION));
        $parsed = ($ext === 'csv') ? $this->parseCsvForPreview($rawLines) : $this->parseLines($rawLines);
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
                            'code_mask' => null,
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
        $rows = $this->withAdminDisplayFields($rows);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Paginated abnormal orders — paid but delivery failed/partial/pending.
     * These are orders where the user paid but card delivery did not complete.
     */
    public function abnormalOrders(int $productId = 0, int $page = 1, int $perPage = 50): array
    {
        // Count orders with payment_status = 'paid' and fulfillment_status in problem states.
        $countSelect = $this->db->select('COUNT(*) AS cnt')->from('table.pay_orders')
            ->where('payment_status = ?', 'paid')
            ->where('fulfillment_status IN ?', ['failed', 'partial', 'pending']);
        if ($productId > 0) {
            $countSelect->where('product_id = ?', $productId);
        }
        $countRow = $this->db->fetchRow($countSelect);
        $total = (int) ($countRow['cnt'] ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $select = $this->db->select()->from('table.pay_orders')
            ->where('payment_status = ?', 'paid')
            ->where('fulfillment_status IN ?', ['failed', 'partial', 'pending'])
            ->order('created_at', Db::SORT_DESC)
            ->limit($perPage)
            ->offset($offset);
        if ($productId > 0) {
            $select->where('product_id = ?', $productId);
        }
        $orders = $this->db->fetchAll($select);

        $rows = [];
        foreach ($orders as $order) {
            $fulfillments = $this->db->fetchAll(
                $this->db->select()->from('table.pay_fulfillments')
                    ->where('order_id = ?', (int) $order['id'])
                    ->order('id', Db::SORT_ASC)
            );
            $rows[] = [
                'order' => $order,
                'fulfillments' => $fulfillments,
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Paginated card sales (delivered items with order info).
     * Uses Typecho query builder instead of raw JOINs for compatibility.
     */
    public function sales(int $productId = 0, int $page = 1, int $perPage = 50): array
    {
        // Count delivered cards.
        $countSelect = $this->db->select('COUNT(*) AS cnt')->from('table.pay_card_items')
            ->where('status = ?', 'delivered');
        if ($productId > 0) {
            $countSelect->where('product_id = ?', $productId);
        }
        $countRow = $this->db->fetchRow($countSelect);
        $total = (int) ($countRow['cnt'] ?? 0);

        // Fetch delivered cards with pagination.
        $offset = max(0, ($page - 1) * $perPage);
        $cardSelect = $this->db->select()->from('table.pay_card_items')
            ->where('status = ?', 'delivered')
            ->order('delivered_at', Db::SORT_DESC)
            ->limit($perPage)
            ->offset($offset);
        if ($productId > 0) {
            $cardSelect->where('product_id = ?', $productId);
        }
        $cards = $this->db->fetchAll($cardSelect);
        $cards = $this->withAdminDisplayFields($cards);

        // Enrich each card with order and fulfillment data.
        $rows = [];
        foreach ($cards as $card) {
            $row = [
                'card_id' => (int) $card['id'],
                'card_display' => (string) ($card['card_display'] ?? ''),
                'code_display' => (string) ($card['code_display'] ?? ''),
                'secret_display' => isset($card['secret_display']) ? (string) $card['secret_display'] : null,
                'product_id' => (int) $card['product_id'],
                'batch_id' => isset($card['batch_id']) ? (int) $card['batch_id'] : 0,
                'delivered_order_id' => isset($card['delivered_order_id']) ? (int) $card['delivered_order_id'] : null,
                'delivered_at' => $card['delivered_at'] ?? null,
                'out_trade_no' => null,
                'amount' => null,
                'currency' => null,
                'gateway' => null,
                'user_id' => null,
                'guest_token_hash' => null,
                'payment_status' => null,
                'fulfillment_status' => null,
                'paid_at' => null,
                'attempts' => 0,
                'last_error' => null,
                'fulfillment_detail_status' => null,
            ];

            // Load order info.
            $orderId = (int) ($card['delivered_order_id'] ?? 0);
            if ($orderId > 0) {
                $order = $this->db->fetchRow(
                    $this->db->select()->from('table.pay_orders')->where('id = ?', $orderId)->limit(1)
                );
                if ($order) {
                    $row['out_trade_no'] = $order['out_trade_no'];
                    $row['amount'] = (int) $order['amount'];
                    $row['currency'] = $order['currency'];
                    $row['gateway'] = $order['gateway'];
                    $row['user_id'] = $order['user_id'] ?? null;
                    $row['guest_token_hash'] = $order['guest_token_hash'] ?? null;
                    $row['payment_status'] = $order['payment_status'] ?? null;
                    $row['fulfillment_status'] = $order['fulfillment_status'] ?? null;
                    $row['paid_at'] = $order['paid_at'] ?? null;

                    // Load fulfillment info.
                    $fulfillment = $this->db->fetchRow(
                        $this->db->select()->from('table.pay_fulfillments')
                            ->where('order_id = ?', $orderId)
                            ->where('card_item_id = ?', (int) $card['id'])
                            ->limit(1)
                    );
                    if ($fulfillment) {
                        $row['attempts'] = (int) ($fulfillment['attempts'] ?? 0);
                        $row['last_error'] = $fulfillment['last_error'] ?? null;
                        $row['fulfillment_detail_status'] = $fulfillment['status'] ?? null;
                    }
                }
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function recentForAdmin(int $productId, int $limit): array
    {
        if ($productId <= 0) {
            return [];
        }

        $rows = $this->db->fetchAll(
            $this->db->select()->from('table.pay_card_items')
                ->where('product_id = ?', $productId)
                ->order('id', Db::SORT_DESC)
                ->limit(max(1, min(20, $limit)))
        );

        return $this->withAdminDisplayFields($rows);
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
        $rows = $this->db->fetchAll(
            $this->db->select('status', 'COUNT(*) AS count_value')->from('table.pay_card_items')
                ->where('product_id = ?', $productId)
                ->group('status')
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
        // Only release cards whose associated orders are NOT paid/processing.
        // Use query builder: find expired card IDs, check order status, then update.
        $now = date('Y-m-d H:i:s');

        $select = $this->db->select('id', 'reserved_order_id')->from('table.pay_card_items')
            ->where('status = ?', 'reserved')
            ->where('reserved_until IS NOT NULL')
            ->where('reserved_until < ?', $now);
        if ($productId !== null && $productId > 0) {
            $select->where('product_id = ?', $productId);
        }

        $expiredCards = $this->db->fetchAll($select);
        if (!$expiredCards) {
            return;
        }

        // Collect order IDs to check payment status.
        $orderIds = [];
        foreach ($expiredCards as $card) {
            $oid = (int) ($card['reserved_order_id'] ?? 0);
            if ($oid > 0) {
                $orderIds[$oid] = true;
            }
        }

        // Fetch paid/processing order IDs.
        $paidOrderIds = [];
        if ($orderIds) {
            $orders = $this->db->fetchAll(
                $this->db->select('id')->from('table.pay_orders')
                    ->where('id IN ?', array_keys($orderIds))
                    ->where('payment_status IN ?', ['paid', 'processing'])
            );
            foreach ($orders as $o) {
                $paidOrderIds[(int) $o['id']] = true;
            }
        }

        // Release only cards whose orders are NOT paid/processing.
        $releaseIds = [];
        foreach ($expiredCards as $card) {
            $oid = (int) ($card['reserved_order_id'] ?? 0);
            if ($oid <= 0 || !isset($paidOrderIds[$oid])) {
                $releaseIds[] = (int) $card['id'];
            }
        }

        if (!$releaseIds) {
            return;
        }

        foreach (array_chunk($releaseIds, 500) as $chunk) {
            $this->db->query($this->db->update('table.pay_card_items')->rows([
                'status' => 'available',
                'reserved_order_id' => null,
                'reserved_until' => null,
                'updated_at' => $now,
            ])->where('id IN ?', $chunk));
        }
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

    /**
     * Parse CSV content using fgetcsv for proper quoting/escaping support.
     * Each row: code[,secret]
     */
    private function parseCsvForPreview(string $rawLines): array
    {
        $items = [];
        $seen = [];
        $rawCount = 0;
        $duplicateInFile = 0;

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $rawLines);
        rewind($stream);

        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            $rawCount++;
            $row = array_map('trim', $row);
            $row = array_filter($row, fn($v) => $v !== '');
            if (!$row) {
                continue;
            }

            $code = $row[0] ?? '';
            $secret = $row[1] ?? null;
            if ($code === '') {
                continue;
            }

            $this->assertCodeLength($code, $secret);
            $key = hash('sha256', $code . "\0" . (string) $secret);
            if (isset($seen[$key])) {
                $duplicateInFile++;
                continue;
            }

            $seen[$key] = true;
            $items[] = ['code' => $code, 'secret' => $secret];
        }

        fclose($stream);

        return [
            'items' => $items,
            'raw_count' => $rawCount,
            'duplicate_in_file' => $duplicateInFile,
        ];
    }

    private function parseLine(string $line): array
    {
        foreach (["\t", '----', '---', '|'] as $separator) {
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

    private function withAdminDisplayFields(array $rows): array
    {
        $visible = [];
        foreach ($rows as $row) {
            $visible[] = $this->withAdminDisplayField($row);
        }

        return $visible;
    }

    private function withAdminDisplayField(array $row): array
    {
        try {
            $card = $this->decryptRow($row);
            $row['code_display'] = $card['code'];
            $secret = $card['secret'] ?? null;
            $row['secret_display'] = $secret;
            $row['card_display'] = $secret !== null && $secret !== ''
                ? $card['code'] . ' ---- ' . $secret
                : $card['code'];
        } catch (\Throwable $e) {
            $row['code_display'] = '[解密失败]';
            $row['secret_display'] = null;
            $row['card_display'] = '[解密失败]';
        }

        return $row;
    }

    private function fingerprint(int $productId, string $code, ?string $secret): string
    {
        return hash_hmac('sha256', $productId . "\0" . $code . "\0" . (string) $secret, $this->keyMaterial());
    }

    private function keyMaterial(): string
    {
        return (string) Options::alloc()->secret;
    }
}
