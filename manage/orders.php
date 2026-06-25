<?php

use Typecho\Db;

if (!defined('__TYPECHO_ADMIN__')) {
    exit;
}

include 'header.php';
include 'menu.php';

$db = Db::get();
$page = max(1, (int) $request->get('page', 1));
$outTradeNo = trim((string) $request->get('out_trade_no'));
$select = $db->select()->from('table.pay_orders')->order('created_at', Db::SORT_DESC)->page($page, 20);
if ($outTradeNo !== '') {
    $select->where('out_trade_no = ?', $outTradeNo);
}

$orders = $db->fetchAll($select);
?>

<div class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>
        <div class="typecho-list-operate clearfix">
            <form method="get">
                <input type="hidden" name="panel" value="TypechoPay/manage/orders.php">
                <input type="text" name="out_trade_no" value="<?php echo htmlspecialchars((string) $request->get('out_trade_no')); ?>" placeholder="订单号">
                <button class="btn btn-s" type="submit"><?php _e('筛选'); ?></button>
            </form>
        </div>

        <div class="typecho-table-wrap">
            <table class="typecho-list-table">
                <colgroup>
                    <col width="17%">
                    <col width="10%">
                    <col width="20%">
                    <col width="10%">
                    <col width="10%">
                    <col width="13%">
                    <col width="13%">
                    <col width="7%">
                </colgroup>
                <thead>
                <tr>
                    <th><?php _e('订单号'); ?></th>
                    <th><?php _e('网关'); ?></th>
                    <th><?php _e('标题'); ?></th>
                    <th><?php _e('金额'); ?></th>
                    <th><?php _e('状态'); ?></th>
                    <th><?php _e('创建时间'); ?></th>
                    <th><?php _e('支付时间'); ?></th>
                    <th><?php _e('操作'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$orders): ?>
                    <tr>
                        <td colspan="8"><h6 class="typecho-list-table-title"><?php _e('暂无订单'); ?></h6></td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($order['out_trade_no']); ?></td>
                        <td><?php echo htmlspecialchars($order['gateway']); ?></td>
                        <td><?php echo htmlspecialchars($order['subject']); ?></td>
                        <td><?php echo htmlspecialchars($order['currency'] . ' ' . $order['amount']); ?></td>
                        <td><?php echo htmlspecialchars($order['status']); ?></td>
                        <td><?php echo htmlspecialchars($order['created_at']); ?></td>
                        <td><?php echo htmlspecialchars((string) $order['paid_at']); ?></td>
                        <td>
                            <?php if (in_array($order['status'], ['paid', 'paid_pending_grant', 'grant_failed'], true)): ?>
                                <form method="post" action="<?php echo htmlspecialchars($security->getIndex('/action/typechopay?do=grant')); ?>">
                                    <input type="hidden" name="out_trade_no" value="<?php echo htmlspecialchars($order['out_trade_no']); ?>">
                                    <button class="btn btn-xs" type="submit"><?php _e('重发权益'); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
