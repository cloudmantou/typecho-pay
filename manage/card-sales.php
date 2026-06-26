<?php

use Typecho\Db;
use TypechoPlugin\TypechoPay\Services\CardCodeService;
use TypechoPlugin\TypechoPay\Support\Money;

if (!defined('__TYPECHO_ADMIN__')) {
    exit;
}

$db = Db::get();
$cardService = new CardCodeService($db);
$panelUrl = $options->adminUrl . 'extending.php?panel=TypechoPay%2Fmanage%2Fcard-sales.php';

// Filters.
$filterProductId = filter_var($request->get('product_id'), FILTER_VALIDATE_INT);
$filterProductId = $filterProductId !== false ? (int) $filterProductId : 0;
$page = max(1, filter_var($request->get('p'), FILTER_VALIDATE_INT) ?: 1);

// Load products for filter.
$products = $db->fetchAll($db->select()->from('table.pay_products')->order('product_key', Db::SORT_ASC));

// Get sales data.
$result = $cardService->sales($filterProductId, $page);
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
            &nbsp; <a href="<?php echo htmlspecialchars($options->adminUrl . 'extending.php?panel=TypechoPay%2Fmanage%2Fcard-inventory.php'); ?>">卡密库存</a></p>
        </div>

        <!-- Filters -->
        <form method="get" action="<?php echo htmlspecialchars($options->adminUrl . 'extending.php'); ?>" style="margin:16px 0;padding:12px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;">
            <input type="hidden" name="panel" value="TypechoPay/manage/card-sales.php">
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
            <button class="btn btn-s" type="submit"><?php _e('筛选'); ?></button>
        </form>

        <p style="color:#666;"><?php _e('共 '); echo $total; _e(' 笔已售出'); ?></p>

        <div class="typecho-table-wrap">
            <table class="typecho-list-table">
                <colgroup>
                    <col width="6%">
                    <col width="14%">
                    <col width="10%">
                    <col width="8%">
                    <col width="8%">
                    <col width="10%">
                    <col width="10%">
                    <col width="10%">
                    <col width="8%">
                    <col width="8%">
                    <col width="10%">
                    <col width="8%">
                </colgroup>
                <thead>
                <tr>
                    <th><?php _e('卡密ID'); ?></th>
                    <th><?php _e('订单号'); ?></th>
                    <th><?php _e('金额'); ?></th>
                    <th><?php _e('网关'); ?></th>
                    <th><?php _e('买家'); ?></th>
                    <th><?php _e('支付时间'); ?></th>
                    <th><?php _e('支付状态'); ?></th>
                    <th><?php _e('交付状态'); ?></th>
                    <th><?php _e('交付时间'); ?></th>
                    <th><?php _e('补发次数'); ?></th>
                    <th><?php _e('最后错误'); ?></th>
                    <th><?php _e('操作'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="12"><h6 class="typecho-list-table-title"><?php _e('暂无售出记录'); ?></h6></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo (int) $row['card_id']; ?></td>
                        <td>
                            <?php if (!empty($row['out_trade_no'])): ?>
                                <code><?php echo htmlspecialchars((string) $row['out_trade_no']); ?></code>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row['amount'])): ?>
                                <?php echo htmlspecialchars(Money::formatForDisplay((int) $row['amount'], (string) ($row['currency'] ?? 'CNY'))); ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars((string) ($row['gateway'] ?? '-')); ?></td>
                        <td>
                            <?php
                            if (!empty($row['user_id'])) {
                                echo 'UID:' . (int) $row['user_id'];
                            } elseif (!empty($row['guest_token_hash'])) {
                                echo '访客:' . substr((string) $row['guest_token_hash'], 0, 8) . '...';
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td><small><?php echo !empty($row['paid_at']) ? htmlspecialchars((string) $row['paid_at']) : '-'; ?></small></td>
                        <td>
                            <?php
                            $ps = (string) ($row['payment_status'] ?? '');
                            $psColors = ['paid' => '#10b981', 'pending' => '#f59e0b', 'failed' => '#ef4444', 'processing' => '#3b82f6'];
                            ?>
                            <span style="color:<?php echo $psColors[$ps] ?? '#666'; ?>;font-weight:600;"><?php echo htmlspecialchars($ps); ?></span>
                        </td>
                        <td>
                            <?php
                            $fs = (string) ($row['fulfillment_detail_status'] ?? $row['fulfillment_status'] ?? '');
                            $fsColors = ['fulfilled' => '#10b981', 'failed' => '#ef4444', 'pending' => '#f59e0b', 'processing' => '#3b82f6'];
                            ?>
                            <span style="color:<?php echo $fsColors[$fs] ?? '#666'; ?>;"><?php echo htmlspecialchars($fs); ?></span>
                        </td>
                        <td><small><?php echo !empty($row['delivered_at']) ? htmlspecialchars((string) $row['delivered_at']) : '-'; ?></small></td>
                        <td><?php echo (int) ($row['attempts'] ?? 0); ?></td>
                        <td>
                            <?php if (!empty($row['last_error'])): ?>
                                <span style="color:#ef4444;" title="<?php echo htmlspecialchars((string) $row['last_error']); ?>"><?php echo htmlspecialchars(mb_substr((string) $row['last_error'], 0, 30)); ?>...</span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row['delivered_order_id'])): ?>
                                <a href="<?php echo htmlspecialchars($options->adminUrl . 'extending.php?panel=TypechoPay%2Fmanage%2Forders.php&search=' . rawurlencode((string) $row['out_trade_no'])); ?>">查看订单</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div style="margin-top:16px;">
            <?php $baseParams = 'panel=TypechoPay/manage/card-sales.php&product_id=' . $filterProductId; ?>
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

<?php include 'footer.php'; ?>
