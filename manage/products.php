<?php

use Typecho\Db;
use TypechoPlugin\TypechoPay\Services\CardCodeService;
use TypechoPlugin\TypechoPay\Support\Money;
use Widget\Notice;

if (!defined('__TYPECHO_ADMIN__')) {
    exit;
}

$db = Db::get();
$cardService = new CardCodeService($db);
$panelUrl = $options->adminUrl . 'extending.php?panel=TypechoPay%2Fmanage%2Fproducts.php';
$formAction = $security->getTokenUrl($request->getRequestUrl());

if ($request->isPost()) {
    $security->protect();

    try {
        $action = (string) $request->get('action');
        if ($action === 'edit_product') {
            $productId = filter_var($request->get('product_id'), FILTER_VALIDATE_INT);
            if ($productId === false || (int) $productId <= 0) {
                throw new InvalidArgumentException('无效商品 ID。');
            }
            $product = $db->fetchRow($db->select()->from('table.pay_products')->where('id = ?', (int) $productId)->limit(1));
            if (!$product) {
                throw new InvalidArgumentException('商品不存在。');
            }

            $title = trim((string) $request->get('title'));
            $titleLen = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
            if ($title === '' || $titleLen > 255) {
                throw new InvalidArgumentException('请填写 1-255 字的商品标题。');
            }

            $amount = Money::assertAmount($request->get('amount'));
            $currency = Money::assertCurrency($request->get('currency'));
            $policy = strtolower(trim((string) $request->get('purchase_policy'))) ?: 'repeatable';
            if (!in_array($policy, ['once', 'repeatable', 'limited'], true)) {
                throw new InvalidArgumentException('购买策略无效。');
            }
            $maxPerUser = filter_var($request->get('max_per_user'), FILTER_VALIDATE_INT);
            $maxPerUser = ($policy === 'limited' && $maxPerUser !== false && (int) $maxPerUser > 0) ? (int) $maxPerUser : null;
            $status = in_array((string) $request->get('status'), ['active', 'paused'], true) ? (string) $request->get('status') : 'active';
            $allowGuest = (string) $request->get('allow_guest') === '1' ? 1 : 0;
            $contentId = filter_var($request->get('content_id'), FILTER_VALIDATE_INT);
            $contentId = $contentId !== false && (int) $contentId > 0 ? (int) $contentId : null;
            $enablePostAccess = (string) $request->get('enable_post_access') === '1';
            $enableCardcode = (string) $request->get('enable_cardcode') === '1';
            if (!$enablePostAccess && !$enableCardcode) {
                throw new InvalidArgumentException('请至少选择一种交付内容。');
            }

            $now = date('Y-m-d H:i:s');
            $db->query('START TRANSACTION', Db::WRITE, '');
            try {
                // Update product; increment version on price/policy change.
                $versionBump = ((int) $product['amount'] !== $amount || (string) $product['currency'] !== $currency || (string) $product['purchase_policy'] !== $policy) ? 1 : 0;
                $db->query($db->update('table.pay_products')->rows([
                    'title' => $title,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => $status,
                    'allow_guest' => $allowGuest,
                    'purchase_policy' => $policy,
                    'max_per_user' => $maxPerUser,
                    'content_id' => $contentId,
                    'stock_policy' => $enableCardcode ? 'reserve_on_order' : 'none',
                    'version' => (int) $product['version'] + $versionBump,
                    'updated_at' => $now,
                ])->where('id = ?', (int) $productId));

                // Sync deliverables: remove old, add new.
                $db->query($db->delete('table.pay_product_deliverables')->where('product_id = ?', (int) $productId));
                if ($enablePostAccess && $contentId !== null) {
                    $db->query($db->insert('table.pay_product_deliverables')->rows([
                        'product_id' => (int) $productId,
                        'handler' => 'post_access',
                        'target_type' => 'post',
                        'target_id' => $contentId,
                        'target_key' => null,
                        'config_json' => null,
                        'sort_order' => 10,
                        'enabled' => 1,
                    ]));
                }
                if ($enableCardcode) {
                    $db->query($db->insert('table.pay_product_deliverables')->rows([
                        'product_id' => (int) $productId,
                        'handler' => 'cardcode',
                        'target_type' => 'cardcode',
                        'target_id' => null,
                        'target_key' => 'default',
                        'config_json' => null,
                        'sort_order' => 20,
                        'enabled' => 1,
                    ]));
                }
                $db->query('COMMIT', Db::WRITE, '');
            } catch (\Throwable $e) {
                try { $db->query('ROLLBACK', Db::WRITE, ''); } catch (\Throwable $rb) {}
                throw $e;
            }

            Notice::alloc()->set(_t('商品已更新。'), 'success');
        } elseif ($action === 'create_product') {
            $productKey = trim((string) $request->get('product_key'));
            if (!preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/', $productKey)) {
                throw new InvalidArgumentException('商品标识只允许字母、数字、点、横线、下划线和冒号。');
            }

            $title = trim((string) $request->get('title'));
            $titleLength = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
            if ($title === '' || $titleLength > 255) {
                throw new InvalidArgumentException('请填写 1-255 字的商品标题。');
            }

            $amount = Money::assertAmount($request->get('amount'));
            $currency = Money::assertCurrency($request->get('currency'));
            $policy = strtolower(trim((string) $request->get('purchase_policy'))) ?: 'repeatable';
            if (!in_array($policy, ['once', 'repeatable', 'limited'], true)) {
                throw new InvalidArgumentException('购买策略无效。');
            }

            $contentId = filter_var($request->get('content_id'), FILTER_VALIDATE_INT);
            $contentId = $contentId !== false && (int) $contentId > 0 ? (int) $contentId : null;
            $maxPerUser = filter_var($request->get('max_per_user'), FILTER_VALIDATE_INT);
            $maxPerUser = ($policy === 'limited' && $maxPerUser !== false && (int) $maxPerUser > 0) ? (int) $maxPerUser : null;

            $enablePostAccess = (string) $request->get('enable_post_access') === '1';
            $enableCardcode = (string) $request->get('enable_cardcode') === '1';
            if (!$enablePostAccess && !$enableCardcode) {
                throw new InvalidArgumentException('请至少选择一种交付内容。');
            }

            if ($enablePostAccess && $contentId === null) {
                throw new InvalidArgumentException('解锁文章需要填写文章 cid。');
            }

            $now = date('Y-m-d H:i:s');
            $db->query('START TRANSACTION', Db::WRITE, '');
            try {
                $productId = $db->query($db->insert('table.pay_products')->rows([
                    'product_key' => $productKey,
                    'title' => $title,
                    'content_id' => $contentId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => 'active',
                    'allow_guest' => 1,
                    'purchase_policy' => $policy,
                    'max_per_user' => $maxPerUser,
                    'duration_seconds' => null,
                    'version' => 1,
                    'stock_policy' => $enableCardcode ? 'reserve_on_order' : 'none',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));

                if ($enablePostAccess) {
                    $db->query($db->insert('table.pay_product_deliverables')->rows([
                        'product_id' => (int) $productId,
                        'handler' => 'post_access',
                        'target_type' => 'post',
                        'target_id' => $contentId,
                        'target_key' => null,
                        'config_json' => null,
                        'sort_order' => 10,
                        'enabled' => 1,
                    ]));
                }

                if ($enableCardcode) {
                    $db->query($db->insert('table.pay_product_deliverables')->rows([
                        'product_id' => (int) $productId,
                        'handler' => 'cardcode',
                        'target_type' => 'cardcode',
                        'target_id' => null,
                        'target_key' => 'default',
                        'config_json' => null,
                        'sort_order' => 20,
                        'enabled' => 1,
                    ]));
                }

                $db->query('COMMIT', Db::WRITE, '');
            } catch (\Throwable $e) {
                try { $db->query('ROLLBACK', Db::WRITE, ''); } catch (\Throwable $rb) {}
                throw $e;
            }

            Notice::alloc()->set(_t('商品已创建，可使用短代码 [typechopay product="%s"]。', $productKey), 'success');
        } elseif ($action === 'import_cards') {
            $productId = filter_var($request->get('product_id'), FILTER_VALIDATE_INT);
            if ($productId === false || (int) $productId <= 0) {
                throw new InvalidArgumentException('请选择卡密商品。');
            }

            // Verify the product exists and has a cardcode deliverable.
            $product = $db->fetchRow(
                $db->select()->from('table.pay_products')->where('id = ?', (int) $productId)->limit(1)
            );
            if (!$product) {
                throw new InvalidArgumentException('商品不存在。');
            }
            $hasCardcode = $db->fetchRow(
                $db->select('id')->from('table.pay_product_deliverables')
                    ->where('product_id = ?', (int) $productId)
                    ->where('handler = ?', 'cardcode')
                    ->where('enabled = ?', 1)
                    ->limit(1)
            );
            if (!$hasCardcode) {
                throw new InvalidArgumentException('该商品未启用卡密交付，请先创建卡密交付规则。');
            }

            // Support file upload or textarea input.
            $rawLines = '';
            $batchName = trim((string) $request->get('batch_name'));
            if (!empty($_FILES['card_file']) && $_FILES['card_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['card_file'];
                if ($file['size'] > 5 * 1024 * 1024) {
                    throw new InvalidArgumentException('上传文件过大（最大 5MB）。');
                }
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['txt', 'csv', 'tsv'], true)) {
                    throw new InvalidArgumentException('仅支持 .txt、.csv、.tsv 文件。');
                }
                $rawLines = file_get_contents($file['tmp_name']);
                if ($rawLines === false) {
                    throw new InvalidArgumentException('无法读取上传文件。');
                }
                // Remove UTF-8 BOM.
                $rawLines = preg_replace('/^\xEF\xBB\xBF/', '', $rawLines);
                if ($batchName === '') {
                    $batchName = 'file-' . ($file['name'] ?? date('YmdHis'));
                }
            } else {
                $rawLines = (string) $request->get('card_lines');
            }

            if (trim($rawLines) === '') {
                throw new InvalidArgumentException('请输入卡密内容或上传文件。');
            }

            $result = $cardService->importBatch(
                (int) $productId,
                $batchName,
                $rawLines,
                $user->hasLogin() ? (int) $user->uid : null
            );
            Notice::alloc()->set(
                _t('卡密导入完成：原始 %d 条，文件内重复 %d 条，成功 %d 条，数据库重复 %d 条。',
                    $result['raw_count'], $result['duplicate_in_file'], $result['imported'], $result['duplicates']),
                $result['imported'] > 0 ? 'success' : 'notice'
            );
        }
    } catch (Throwable $e) {
        Notice::alloc()->set($e->getMessage(), 'error');
    }

    $response->redirect($panelUrl);
    return;
}

$products = $db->fetchAll($db->select()->from('table.pay_products')->order('created_at', Db::SORT_DESC));
$productHandlers = [];
$productDeliverables = [];
if ($products) {
    $deliverables = $db->fetchAll($db->select()->from('table.pay_product_deliverables')->order('sort_order', Db::SORT_ASC));
    foreach ($deliverables as $deliverable) {
        $pid = (int) $deliverable['product_id'];
        $productHandlers[$pid][] = (string) $deliverable['handler'];
        $productDeliverables[$pid][] = $deliverable;
    }
}

// Load product for editing if requested.
$editProduct = null;
$editDeliverables = [];
$editId = filter_var($request->get('edit'), FILTER_VALIDATE_INT);
if ($editId !== false && (int) $editId > 0) {
    $editProduct = $db->fetchRow($db->select()->from('table.pay_products')->where('id = ?', (int) $editId)->limit(1));
    $editDeliverables = $productDeliverables[(int) $editId] ?? [];
}

include 'header.php';
include 'menu.php';
?>

<div class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>

        <div class="typecho-list-operate clearfix">
            <p>管理商品、卡密库存和销售。正式卡密商品请使用 <code>[typechopay product="商品标识"]</code>。
            &nbsp; <a href="<?php echo htmlspecialchars($options->adminUrl . 'extending.php?panel=TypechoPay%2Fmanage%2Fcard-inventory.php'); ?>">卡密库存</a>
            &nbsp; <a href="<?php echo htmlspecialchars($options->adminUrl . 'extending.php?panel=TypechoPay%2Fmanage%2Fcard-sales.php'); ?>">卡密销售</a></p>
        </div>

        <?php if ($editProduct): ?>
        <div class="table-description" style="margin-top:20px;border:1px solid #3b82f6;padding:16px;border-radius:6px;">
            <h3><?php _e('编辑商品'); ?>: <?php echo htmlspecialchars($editProduct['product_key']); ?></h3>
            <form method="post" action="<?php echo htmlspecialchars($formAction); ?>">
                <input type="hidden" name="action" value="edit_product">
                <input type="hidden" name="product_id" value="<?php echo (int) $editProduct['id']; ?>">
                <p>
                    <label><?php _e('商品标题'); ?></label><br>
                    <input type="text" name="title" value="<?php echo htmlspecialchars($editProduct['title']); ?>" style="width:360px;" required>
                </p>
                <p>
                    <label><?php _e('金额'); ?></label><br>
                    <input type="number" name="amount" min="1" value="<?php echo (int) $editProduct['amount']; ?>" style="width:180px;" required>
                    <select name="currency">
                        <option value="CNY" <?php if ($editProduct['currency'] === 'CNY') echo 'selected'; ?>>CNY</option>
                        <option value="JPY" <?php if ($editProduct['currency'] === 'JPY') echo 'selected'; ?>>JPY</option>
                    </select>
                </p>
                <p>
                    <label><?php _e('状态'); ?></label><br>
                    <select name="status">
                        <option value="active" <?php if ($editProduct['status'] === 'active') echo 'selected'; ?>><?php _e('active - 上架'); ?></option>
                        <option value="paused" <?php if ($editProduct['status'] === 'paused') echo 'selected'; ?>><?php _e('paused - 下架'); ?></option>
                    </select>
                </p>
                <p>
                    <label><?php _e('购买策略'); ?></label><br>
                    <select name="purchase_policy">
                        <option value="repeatable" <?php if ($editProduct['purchase_policy'] === 'repeatable') echo 'selected'; ?>><?php _e('repeatable'); ?></option>
                        <option value="once" <?php if ($editProduct['purchase_policy'] === 'once') echo 'selected'; ?>><?php _e('once'); ?></option>
                        <option value="limited" <?php if ($editProduct['purchase_policy'] === 'limited') echo 'selected'; ?>><?php _e('limited'); ?></option>
                    </select>
                    &nbsp; <label>max_per_user: <input type="number" name="max_per_user" min="1" value="<?php echo (int) ($editProduct['max_per_user'] ?? 0); ?>" style="width:80px;" placeholder="N"></label>
                </p>
                <p>
                    <label><?php _e('文章 cid'); ?></label><br>
                    <input type="number" name="content_id" min="1" value="<?php echo (int) ($editProduct['content_id'] ?? 0); ?>" style="width:220px;" placeholder="留空则不解锁文章">
                </p>
                <?php
                $hasPostAccess = false;
                $hasCardcode = false;
                foreach ($editDeliverables as $d) {
                    if ($d['handler'] === 'post_access') $hasPostAccess = true;
                    if ($d['handler'] === 'cardcode') $hasCardcode = true;
                }
                ?>
                <p>
                    <label><input type="checkbox" name="enable_cardcode" value="1" <?php if ($hasCardcode) echo 'checked'; ?>> <?php _e('交付卡密'); ?></label>
                    <label style="margin-left:18px;"><input type="checkbox" name="enable_post_access" value="1" <?php if ($hasPostAccess) echo 'checked'; ?>> <?php _e('解锁文章'); ?></label>
                </p>
                <p>
                    <button class="btn primary" type="submit"><?php _e('保存修改'); ?></button>
                    <a href="<?php echo htmlspecialchars($panelUrl); ?>" class="btn"><?php _e('取消'); ?></a>
                </p>
            </form>
        </div>
        <?php endif; ?>

        <div class="table-description" style="margin-top:20px;">
            <h3><?php _e($editProduct ? '创建新商品' : '创建商品'); ?></h3>
            <form method="post" action="<?php echo htmlspecialchars($formAction); ?>">
                <input type="hidden" name="action" value="create_product">
                <p>
                    <label><?php _e('商品标识'); ?></label><br>
                    <input type="text" name="product_key" placeholder="recharge-card-100" style="width:260px;" required>
                </p>
                <p>
                    <label><?php _e('商品标题'); ?></label><br>
                    <input type="text" name="title" placeholder="100 元充值卡" style="width:360px;" required>
                </p>
                <p>
                    <label><?php _e('金额'); ?></label><br>
                    <input type="number" name="amount" min="1" placeholder="CNY 用分，JPY 用日元" style="width:180px;" required>
                    <select name="currency">
                        <option value="CNY">CNY</option>
                        <option value="JPY">JPY</option>
                    </select>
                </p>
                <p>
                    <label><?php _e('购买策略'); ?></label><br>
                    <select name="purchase_policy">
                        <option value="repeatable"><?php _e('repeatable - 可重复购买，适合卡密'); ?></option>
                        <option value="once"><?php _e('once - 已购买后不再显示付款按钮'); ?></option>
                        <option value="limited"><?php _e('limited - 限制每用户购买次数'); ?></option>
                    </select>
                    &nbsp; <label>max_per_user: <input type="number" name="max_per_user" min="1" style="width:80px;" placeholder="N"></label>
                </p>
                <p>
                    <label><?php _e('文章 cid（可选）'); ?></label><br>
                    <input type="number" name="content_id" min="1" placeholder="组合商品解锁文章时填写" style="width:220px;">
                </p>
                <p>
                    <label><input type="checkbox" name="enable_cardcode" value="1" checked> <?php _e('交付卡密'); ?></label>
                    <label style="margin-left:18px;"><input type="checkbox" name="enable_post_access" value="1"> <?php _e('同时解锁文章'); ?></label>
                </p>
                <p><button class="btn primary" type="submit"><?php _e('创建商品'); ?></button></p>
            </form>
        </div>

        <div class="table-description" style="margin-top:30px;">
            <h3><?php _e('导入卡密'); ?></h3>
            <form method="post" action="<?php echo htmlspecialchars($formAction); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_cards">
                <p>
                    <label><?php _e('选择商品'); ?></label><br>
                    <select name="product_id" required>
                        <option value=""><?php _e('-- 请选择 --'); ?></option>
                        <?php foreach ($products as $product): ?>
                            <?php $handlers = $productHandlers[(int) $product['id']] ?? []; ?>
                            <?php if (in_array('cardcode', $handlers, true)): ?>
                                <option value="<?php echo (int) $product['id']; ?>">
                                    <?php echo htmlspecialchars($product['product_key'] . ' - ' . $product['title']); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label><?php _e('批次名称'); ?></label><br>
                    <input type="text" name="batch_name" placeholder="2026-06-26 首批导入" style="width:320px;">
                </p>
                <p>
                    <label><?php _e('上传文件'); ?></label><br>
                    <input type="file" name="card_file" accept=".txt,.csv,.tsv,text/plain,text/csv,text/tab-separated-values">
                    <small>支持 .txt / .csv / .tsv，最大 5MB</small>
                </p>
                <p>
                    <label><?php _e('或直接粘贴卡密'); ?></label><br>
                    <textarea name="card_lines" rows="8" style="width:100%;" placeholder="每行一张。支持：卡号----卡密、卡号|卡密、卡号,卡密、Tab 分隔或单独兑换码。"></textarea>
                </p>
                <p><button class="btn primary" type="submit"><?php _e('导入卡密'); ?></button></p>
            </form>
        </div>

        <div class="typecho-table-wrap" style="margin-top:30px;">
            <table class="typecho-list-table">
                <colgroup>
                    <col width="14%">
                    <col width="18%">
                    <col width="8%">
                    <col width="8%">
                    <col width="10%">
                    <col width="22%">
                    <col width="10%">
                    <col width="10%">
                </colgroup>
                <thead>
                <tr>
                    <th><?php _e('商品标识'); ?></th>
                    <th><?php _e('标题'); ?></th>
                    <th><?php _e('金额'); ?></th>
                    <th><?php _e('状态'); ?></th>
                    <th><?php _e('策略'); ?></th>
                    <th><?php _e('库存'); ?></th>
                    <th><?php _e('短代码'); ?></th>
                    <th><?php _e('操作'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$products): ?>
                    <tr><td colspan="8"><h6 class="typecho-list-table-title"><?php _e('暂无商品'); ?></h6></td></tr>
                <?php endif; ?>
                <?php foreach ($products as $product): ?>
                    <?php
                    $pid = (int) $product['id'];
                    $handlers = $productHandlers[$pid] ?? [];
                    $isCardcode = in_array('cardcode', $handlers, true);
                    $counts = $isCardcode
                        ? $cardService->stockCounts($pid)
                        : ['available' => 0, 'reserved' => 0, 'delivered' => 0, 'void' => 0, 'compromised' => 0, 'total' => 0];
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($product['product_key']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($product['title']); ?>
                            <br><small><?php echo htmlspecialchars(implode(', ', $handlers)); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($product['currency'] . ' ' . $product['amount']); ?></td>
                        <td><?php echo htmlspecialchars($product['status']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($product['purchase_policy']); ?>
                            <?php if ($product['purchase_policy'] === 'limited' && !empty($product['max_per_user'])): ?>
                                <br><small>max: <?php echo (int) $product['max_per_user']; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isCardcode): ?>
                                可用 <?php echo $counts['available']; ?>
                                / 预留 <?php echo $counts['reserved']; ?>
                                / 已发 <?php echo $counts['delivered']; ?>
                                <?php if ($counts['void'] > 0 || $counts['compromised'] > 0): ?>
                                    / 作废 <?php echo $counts['void']; ?>
                                    / 泄露 <?php echo $counts['compromised']; ?>
                                <?php endif; ?>
                                <br><small>总计 <?php echo $counts['total']; ?></small>
                            <?php else: ?>
                                <?php _e('无卡密'); ?>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo htmlspecialchars('[typechopay product="' . $product['product_key'] . '"]'); ?></code></td>
                        <td>
                            <a href="<?php echo htmlspecialchars($panelUrl . '&edit=' . $pid); ?>"><?php _e('编辑'); ?></a>
                            <?php if ($isCardcode): ?>
                                | <a href="<?php echo htmlspecialchars($options->adminUrl . 'extending.php?panel=TypechoPay%2Fmanage%2Fcard-inventory.php&product_id=' . $pid); ?>"><?php _e('库存'); ?></a>
                                | <a href="<?php echo htmlspecialchars($options->adminUrl . 'extending.php?panel=TypechoPay%2Fmanage%2Fcard-sales.php&product_id=' . $pid); ?>"><?php _e('销售'); ?></a>
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
