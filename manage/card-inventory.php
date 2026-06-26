<?php

use Typecho\Db;
use TypechoPlugin\TypechoPay\Services\CardCodeService;
use Widget\Notice;

if (!defined('__TYPECHO_ADMIN__')) {
    exit;
}

$db = Db::get();
$cardService = new CardCodeService($db);
$panelUrl = $options->adminUrl . 'extending.php?panel=TypechoPay%2Fmanage%2Fcard-inventory.php';
$formAction = $security->getTokenUrl($request->getRequestUrl());

// Handle POST actions (void/compromised).
if ($request->isPost()) {
    $security->protect();
    try {
        $action = (string) $request->get('action');
        $ids = $request->get('ids');
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
        $ids = array_filter($ids, fn($id) => $id > 0);

        if (!$ids) {
            throw new InvalidArgumentException('请选择卡密。');
        }

        if ($action === 'mark_void') {
            $count = $cardService->markVoid($ids);
            Notice::alloc()->set(_t('已作废 %d 张卡密。', $count), 'success');
        } elseif ($action === 'mark_compromised') {
            $count = $cardService->markCompromised($ids);
            Notice::alloc()->set(_t('已标记 %d 张卡密为泄露。', $count), 'success');
        }
    } catch (Throwable $e) {
        Notice::alloc()->set($e->getMessage(), 'error');
    }
    $response->redirect($panelUrl . ($_GET['product_id'] ? '&product_id=' . (int) $_GET['product_id'] : ''));
    return;
}

// Filters.
$filterProductId = filter_var($request->get('product_id'), FILTER_VALIDATE_INT);
$filterProductId = $filterProductId !== false ? (int) $filterProductId : 0;
$filterStatus = trim((string) $request->get('status'));
$filterBatchId = filter_var($request->get('batch_id'), FILTER_VALIDATE_INT);
$filterBatchId = $filterBatchId !== false ? (int) $filterBatchId : 0;
$page = max(1, filter_var($request->get('p'), FILTER_VALIDATE_INT) ?: 1);

// Load products for filter dropdown.
$products = $db->fetchAll($db->select()->from('table.pay_products')->order('product_key', Db::SORT_ASC));

// Load batches for filter dropdown.
$batches = [];
if ($filterProductId > 0) {
    $batches = $db->fetchAll(
        $db->select()->from('table.pay_card_batches')
            ->where('product_id = ?', $filterProductId)
            ->order('created_at', Db::SORT_DESC)
    );
}

// Get inventory data.
$result = $cardService->inventory($filterProductId, $filterStatus ?: null, $filterBatchId > 0 ? $filterBatchId : null, $page);
$rows = $result['rows'];
$total = $result['total'];
$perPage = $result['per_page'];
$totalPages = max(1, (int) ceil($total / $perPage));

include 'header.php';
include 'menu.php';
?>

<div class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>

        <div class="typecho-list-operate clearfix">
            <p><a href="<?php echo htmlspecialchars($options->adminUrl . 'extending.php?panel=TypechoPay%2Fmanage%2Fproducts.php'); ?>">返回商品管理</a>
            &nbsp; <a href="<?php echo htmlspecialchars($options->adminUrl . 'extending.php?panel=TypechoPay%2Fmanage%2Fcard-sales.php'); ?>">卡密销售</a></p>
        </div>

        <!-- Filters -->
        <form method="get" action="<?php echo htmlspecialchars($options->adminUrl . 'extending.php'); ?>" style="margin:16px 0;padding:12px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;">
            <input type="hidden" name="panel" value="TypechoPay/manage/card-inventory.php">
            <label>商品：
                <select name="product_id">
                    <option value="0"><?php _e('全部商品'); ?></option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php if ($filterProductId === (int) $p['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($p['product_key'] . ' - ' . $p['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            &nbsp;
            <label>状态：
                <select name="status">
                    <option value=""><?php _e('全部状态'); ?></option>
                    <?php foreach (['available', 'reserved', 'delivered', 'void', 'compromised'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php if ($filterStatus === $s) echo 'selected'; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($batches): ?>
            &nbsp;
            <label>批次：
                <select name="batch_id">
                    <option value="0"><?php _e('全部批次'); ?></option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?php echo (int) $b['id']; ?>" <?php if ($filterBatchId === (int) $b['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($b['batch_name'] . ' (#' . $b['id'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            &nbsp;
            <button class="btn btn-s" type="submit"><?php _e('筛选'); ?></button>
        </form>

        <p style="color:#666;"><?php _e('共 '); echo $total; _e(' 条记录'); ?></p>

        <form method="post" action="<?php echo htmlspecialchars($formAction . '&product_id=' . $filterProductId); ?>">
            <input type="hidden" name="panel" value="TypechoPay/manage/card-inventory.php">

            <div class="typecho-table-wrap">
                <table class="typecho-list-table">
                    <colgroup>
                        <col width="3%">
                        <col width="6%">
                        <col width="12%">
                        <col width="12%">
                        <col width="18%">
                        <col width="8%">
                        <th width="12%">
                        <col width="12%">
                        <col width="10%">
                        <col width="7%">
                    </colgroup>
                    <thead>
                    <tr>
                        <th><input type="checkbox" class="typechopay-check-all"></th>
                        <th><?php _e('ID'); ?></th>
                        <th><?php _e('商品'); ?></th>
                        <th><?php _e('批次'); ?></th>
                        <th><?php _e('卡号掩码'); ?></th>
                        <th><?php _e('状态'); ?></th>
                        <th><?php _e('预留订单'); ?></th>
                        <th><?php _e('售出订单'); ?></th>
                        <th><?php _e('交付时间'); ?></th>
                        <th><?php _e('创建时间'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="10"><h6 class="typecho-list-table-title"><?php _e('暂无卡密'); ?></h6></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        // Mask the card code for display.
                        $code = (string) ($row['code_ciphertext'] ?? '');
                        $masked = $code !== '' ? mb_substr($code, 0, 8) . '****' : '****';
                        ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?php echo (int) $row['id']; ?>"></td>
                            <td><?php echo (int) $row['id']; ?></td>
                            <td><?php echo (int) $row['product_id']; ?></td>
                            <td><?php echo (int) ($row['batch_id'] ?? 0); ?></td>
                            <td><code><?php echo htmlspecialchars($masked); ?></code></td>
                            <td>
                                <?php
                                $statusColors = [
                                    'available' => '#10b981',
                                    'reserved' => '#f59e0b',
                                    'delivered' => '#3b82f6',
                                    'void' => '#6b7280',
                                    'compromised' => '#ef4444',
                                ];
                                $st = (string) $row['status'];
                                $color = $statusColors[$st] ?? '#666';
                                ?>
                                <span style="color:<?php echo $color; ?>;font-weight:600;"><?php echo htmlspecialchars($st); ?></span>
                            </td>
                            <td><?php echo !empty($row['reserved_order_id']) ? '#' . (int) $row['reserved_order_id'] : '-'; ?></td>
                            <td><?php echo !empty($row['delivered_order_id']) ? '#' . (int) $row['delivered_order_id'] : '-'; ?></td>
                            <td><?php echo !empty($row['delivered_at']) ? htmlspecialchars((string) $row['delivered_at']) : '-'; ?></td>
                            <td><small><?php echo htmlspecialchars((string) ($row['created_at'] ?? '')); ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($rows): ?>
            <div style="margin-top:12px;">
                <select name="action" style="width:auto;">
                    <option value="mark_void"><?php _e('标记作废'); ?></option>
                    <option value="mark_compromised"><?php _e('标记泄露'); ?></option>
                </select>
                <button class="btn btn-s" type="submit" onclick="return confirm('确定执行批量操作？');"><?php _e('执行'); ?></button>
            </div>
            <?php endif; ?>
        </form>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div style="margin-top:16px;">
            <?php
            $baseParams = 'panel=TypechoPay/manage/card-inventory.php&product_id=' . $filterProductId
                . ($filterStatus !== '' ? '&status=' . urlencode($filterStatus) : '')
                . ($filterBatchId > 0 ? '&batch_id=' . $filterBatchId : '');
            ?>
            <?php if ($page > 1): ?>
                <a href="<?php echo htmlspecialchars($options->adminUrl . 'extending.php?' . $baseParams . '&p=' . ($page - 1)); ?>">&laquo; <?php _e('上一页'); ?></a>
            <?php endif; ?>
            &nbsp; <?php echo $page; ?> / <?php echo $totalPages; ?> &nbsp;
            <?php if ($page < $totalPages): ?>
                <a href="<?php echo htmlspecialchars($options->adminUrl . 'extending.php?' . $baseParams . '&p=' . ($page + 1)); ?>"><?php _e('下一页'); ?> &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.querySelector('.typechopay-check-all')?.addEventListener('change', function(e) {
    document.querySelectorAll('input[name="ids[]"]').forEach(function(cb) {
        cb.checked = e.target.checked;
    });
});
</script>

<?php include 'footer.php'; ?>
