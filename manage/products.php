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

// Load categories.
$categories = $db->fetchAll($db->select()->from('table.pay_product_categories')->order('sort_order', Db::SORT_ASC)->order('id', Db::SORT_ASC));
$categoriesById = [];
foreach ($categories as $cat) {
    $categoriesById[(int) $cat['id']] = $cat;
}


if ($request->isPost()) {
    $security->protect();

    try {
        $action = (string) $request->get('action');

        // ============================================================
        // Category CRUD
        // ============================================================
        if ($action === 'create_category') {
            $slug = trim((string) $request->get('cat_slug'));
            $name = trim((string) $request->get('cat_name'));
            $description = trim((string) $request->get('cat_description'));
            $sortOrder = filter_var($request->get('cat_sort_order'), FILTER_VALIDATE_INT) ?: 0;

            if ($slug === '' || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $slug)) {
                throw new InvalidArgumentException('分类标识只允许字母、数字、横线和下划线。');
            }
            $nameLen = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
            if ($name === '' || $nameLen > 255) {
                throw new InvalidArgumentException('请填写 1-255 字的分类名称。');
            }

            $now = date('Y-m-d H:i:s');
            $db->query($db->insert('table.pay_product_categories')->rows([
                'slug' => $slug,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'sort_order' => $sortOrder,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            Notice::alloc()->set(_t('分类 "%s" 已创建。', $name), 'success');
        } elseif ($action === 'edit_category') {
            $catId = filter_var($request->get('cat_id'), FILTER_VALIDATE_INT);
            if ($catId === false || (int) $catId <= 0) {
                throw new InvalidArgumentException('无效分类 ID。');
            }
            $cat = $db->fetchRow($db->select()->from('table.pay_product_categories')->where('id = ?', (int) $catId)->limit(1));
            if (!$cat) {
                throw new InvalidArgumentException('分类不存在。');
            }

            $name = trim((string) $request->get('cat_name'));
            $description = trim((string) $request->get('cat_description'));
            $sortOrder = filter_var($request->get('cat_sort_order'), FILTER_VALIDATE_INT) ?: 0;
            $status = in_array((string) $request->get('cat_status'), ['active', 'paused'], true) ? (string) $request->get('cat_status') : 'active';

            $nameLen = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
            if ($name === '' || $nameLen > 255) {
                throw new InvalidArgumentException('请填写 1-255 字的分类名称。');
            }

            $db->query($db->update('table.pay_product_categories')->rows([
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'sort_order' => $sortOrder,
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->where('id = ?', (int) $catId));

            Notice::alloc()->set(_t('分类已更新。'), 'success');
        } elseif ($action === 'delete_category') {
            $catId = filter_var($request->get('cat_id'), FILTER_VALIDATE_INT);
            if ($catId === false || (int) $catId <= 0) {
                throw new InvalidArgumentException('无效分类 ID。');
            }
            $db->query($db->update('table.pay_products')->rows([
                'category_id' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->where('category_id = ?', (int) $catId));
            $db->query($db->delete('table.pay_product_categories')->where('id = ?', (int) $catId));
            Notice::alloc()->set(_t('分类已删除，关联商品已取消分类。'), 'success');
        } elseif ($action === 'edit_product') {
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

            // Display fields.
            $categoryId = filter_var($request->get('category_id'), FILTER_VALIDATE_INT);
            $categoryId = ($categoryId !== false && (int) $categoryId > 0) ? (int) $categoryId : null;
            $coverUrl = trim((string) $request->get('cover_url'));
            $coverUrl = $coverUrl !== '' ? $coverUrl : null;
            $summary = trim((string) $request->get('summary'));
            $summary = $summary !== '' ? $summary : null;
            $description = trim((string) $request->get('description'));
            $description = $description !== '' ? $description : null;
            $sortOrder = filter_var($request->get('sort_order'), FILTER_VALIDATE_INT) ?: 0;
            $isFeatured = (string) $request->get('is_featured') === '1' ? 1 : 0;
            $stockDisplayMode = in_array((string) $request->get('stock_display_mode'), ['exact', 'range', 'hidden'], true)
                ? (string) $request->get('stock_display_mode') : 'exact';

            // Resolve old deliverables for version bump check.
            $oldDeliverables = $productDeliverables[(int) $productId] ?? [];
            $hasPostAccess = false;
            $hasCardcode = false;
            foreach ($oldDeliverables as $d) {
                if ($d['handler'] === 'post_access') $hasPostAccess = true;
                if ($d['handler'] === 'cardcode') $hasCardcode = true;
            }

            $now = date('Y-m-d H:i:s');
            $db->query('START TRANSACTION', Db::WRITE, '');
            try {
                $oldContentId = (int) ($product['content_id'] ?? 0);
                $oldMaxPerUser = isset($product['max_per_user']) && (int) $product['max_per_user'] > 0
                    ? (int) $product['max_per_user']
                    : null;
                $oldStatus = (string) ($product['status'] ?? 'active');
                $oldStockPolicy = (string) ($product['stock_policy'] ?? 'none');
                $oldAllowGuest = (int) ($product['allow_guest'] ?? 1);
                $newStockPolicy = $enableCardcode ? 'reserve_on_order' : 'none';
                $versionBump = ((int) $product['amount'] !== $amount
                    || (string) $product['currency'] !== $currency
                    || $oldStatus !== $status
                    || $oldAllowGuest !== $allowGuest
                    || (string) $product['purchase_policy'] !== $policy
                    || $oldMaxPerUser !== $maxPerUser
                    || $oldContentId !== (int) ($contentId ?? 0)
                    || $oldStockPolicy !== $newStockPolicy
                    || $hasPostAccess !== $enablePostAccess
                    || $hasCardcode !== $enableCardcode) ? 1 : 0;

                $db->query($db->update('table.pay_products')->rows([
                    'title' => $title,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => $status,
                    'allow_guest' => $allowGuest,
                    'purchase_policy' => $policy,
                    'max_per_user' => $maxPerUser,
                    'content_id' => $contentId,
                    'stock_policy' => $newStockPolicy,
                    'category_id' => $categoryId,
                    'cover_url' => $coverUrl,
                    'summary' => $summary,
                    'description' => $description,
                    'sort_order' => $sortOrder,
                    'is_featured' => $isFeatured,
                    'stock_display_mode' => $stockDisplayMode,
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
            $allowGuest = (string) $request->get('allow_guest') === '1' ? 1 : 0;
            if (!$enablePostAccess && !$enableCardcode) {
                throw new InvalidArgumentException('请至少选择一种交付内容。');
            }

            if ($enablePostAccess && $contentId === null) {
                throw new InvalidArgumentException('解锁文章需要填写文章 cid。');
            }

            // Display fields.
            $categoryId = filter_var($request->get('category_id'), FILTER_VALIDATE_INT);
            $categoryId = ($categoryId !== false && (int) $categoryId > 0) ? (int) $categoryId : null;
            $coverUrl = trim((string) $request->get('cover_url'));
            $coverUrl = $coverUrl !== '' ? $coverUrl : null;
            $summary = trim((string) $request->get('summary'));
            $summary = $summary !== '' ? $summary : null;
            $sortOrder = filter_var($request->get('sort_order'), FILTER_VALIDATE_INT) ?: 0;
            $isFeatured = (string) $request->get('is_featured') === '1' ? 1 : 0;
            $stockDisplayMode = in_array((string) $request->get('stock_display_mode'), ['exact', 'range', 'hidden'], true)
                ? (string) $request->get('stock_display_mode') : 'exact';

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
                    'allow_guest' => $allowGuest,
                    'purchase_policy' => $policy,
                    'max_per_user' => $maxPerUser,
                    'duration_seconds' => null,
                    'version' => 1,
                    'stock_policy' => $enableCardcode ? 'reserve_on_order' : 'none',
                    'category_id' => $categoryId,
                    'cover_url' => $coverUrl,
                    'summary' => $summary,
                    'sort_order' => $sortOrder,
                    'is_featured' => $isFeatured,
                    'stock_display_mode' => $stockDisplayMode,
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

        // ============================================================
        // Card Import
        // ============================================================
        } elseif ($action === 'preview_cards') {
            $productId = filter_var($request->get('product_id'), FILTER_VALIDATE_INT);
            if ($productId === false || (int) $productId <= 0) {
                throw new InvalidArgumentException('请选择卡密商品。');
            }

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
                throw new InvalidArgumentException('该商品未启用卡密交付。');
            }

            $rawLines = '';
            $filenameHint = '';
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
                $rawLines = preg_replace('/^\xEF\xBB\xBF/', '', $rawLines);
                $filenameHint = $file['name'] ?? '';
                if ($batchName === '') {
                    $batchName = 'file-' . ($file['name'] ?? date('YmdHis'));
                }
            } else {
                $rawLines = (string) $request->get('card_lines');
            }

            if (trim($rawLines) === '') {
                throw new InvalidArgumentException('请输入卡密内容或上传文件。');
            }

            $parsed = $cardService->parseForPreview($rawLines, $filenameHint);
            $previewData = [
                'product_id' => (int) $productId,
                'product_key' => $product['product_key'],
                'product_title' => $product['title'],
                'batch_name' => $batchName,
                'raw_count' => $parsed['raw_count'],
                'valid_count' => count($parsed['items']),
                'duplicate_in_file' => $parsed['duplicate_in_file'],
                'raw_lines' => $rawLines,
                'filename_hint' => $filenameHint,
            ];
        } elseif ($action === 'import_cards') {
            $productId = filter_var($request->get('product_id'), FILTER_VALIDATE_INT);
            if ($productId === false || (int) $productId <= 0) {
                throw new InvalidArgumentException('请选择卡密商品。');
            }

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

            $filenameHint = !empty($_FILES['card_file']) && $_FILES['card_file']['error'] === UPLOAD_ERR_OK
                ? ($_FILES['card_file']['name'] ?? '')
                : trim((string) $request->get('filename_hint'));
            $result = $cardService->importBatch(
                (int) $productId,
                $batchName,
                $rawLines,
                $user->hasLogin() ? (int) $user->uid : null,
                $filenameHint
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

$products = $db->fetchAll($db->select()->from('table.pay_products')->order('sort_order', Db::SORT_ASC)->order('created_at', Db::SORT_DESC));
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

// Category editing.
$editCategory = null;
$editCatId = filter_var($request->get('edit_cat'), FILTER_VALIDATE_INT);
if ($editCatId !== false && (int) $editCatId > 0) {
    $editCategory = $categoriesById[(int) $editCatId] ?? null;
}

include 'header.php';
include 'menu.php';
?>

<div class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>

        <div class="typecho-list-operate clearfix">
            <p>管理商品、分类、卡密库存和销售。
            商城短代码: <code>[typechopay_shop]</code> <code>[typechopay_product product="标识"]</code> <code>[typechopay_product]</code>
            绑定文章后可在插件设置中开启自动插入商品卡。
            &nbsp; <a href="<?php echo htmlspecialchars($options->adminUrl . 'extending.php?panel=TypechoPay%2Fmanage%2Fcard-inventory.php'); ?>">卡密库存</a>
            &nbsp; <a href="<?php echo htmlspecialchars($options->adminUrl . 'extending.php?panel=TypechoPay%2Fmanage%2Fcard-sales.php'); ?>">卡密销售</a></p>
        </div>

        <!-- ============================================================ -->
        <!-- Category Management -->
        <!-- ============================================================ -->
        <div class="table-description" style="margin-top:20px;">
            <h3><?php _e('商品分类'); ?></h3>

            <?php if ($editCategory): ?>
            <div style="padding:12px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;margin-bottom:12px;">
                <h4><?php _e('编辑分类'); ?>: <?php echo htmlspecialchars($editCategory['slug']); ?></h4>
                <form method="post" action="<?php echo htmlspecialchars($formAction); ?>">
                    <input type="hidden" name="action" value="edit_category">
                    <input type="hidden" name="cat_id" value="<?php echo (int) $editCategory['id']; ?>">
                    <p><label><?php _e('分类名称'); ?></label><br><input type="text" name="cat_name" value="<?php echo htmlspecialchars($editCategory['name']); ?>" style="width:260px;" required></p>
                    <p><label><?php _e('描述'); ?></label><br><input type="text" name="cat_description" value="<?php echo htmlspecialchars((string) ($editCategory['description'] ?? '')); ?>" style="width:400px;"></p>
                    <p><label><?php _e('排序'); ?></label><br><input type="number" name="cat_sort_order" value="<?php echo (int) $editCategory['sort_order']; ?>" style="width:100px;"> <small>越小越靠前</small></p>
                    <p><label><?php _e('状态'); ?></label><br>
                        <select name="cat_status">
                            <option value="active" <?php if ($editCategory['status'] === 'active') echo 'selected'; ?>><?php _e('active'); ?></option>
                            <option value="paused" <?php if ($editCategory['status'] === 'paused') echo 'selected'; ?>><?php _e('paused'); ?></option>
                        </select>
                    </p>
                    <p><button class="btn primary" type="submit"><?php _e('保存'); ?></button> <a href="<?php echo htmlspecialchars($panelUrl); ?>" class="btn"><?php _e('取消'); ?></a></p>
                </form>
            </div>
            <?php endif; ?>

            <form method="post" action="<?php echo htmlspecialchars($formAction); ?>" style="margin-bottom:12px;">
                <input type="hidden" name="action" value="create_category">
                <input type="text" name="cat_slug" placeholder="分类标识 如 vip" style="width:140px;" required>
                <input type="text" name="cat_name" placeholder="分类名称" style="width:200px;" required>
                <input type="text" name="cat_description" placeholder="描述（可选）" style="width:200px;">
                <input type="number" name="cat_sort_order" value="0" style="width:80px;" title="排序">
                <button class="btn btn-s" type="submit"><?php _e('新增分类'); ?></button>
            </form>

            <?php if ($categories): ?>
            <table class="typecho-list-table" style="max-width:700px;">
                <thead><tr><th>标识</th><th>名称</th><th>描述</th><th>排序</th><th>状态</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($categories as $cat): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($cat['slug']); ?></code></td>
                        <td><?php echo htmlspecialchars($cat['name']); ?></td>
                        <td><small><?php echo htmlspecialchars((string) ($cat['description'] ?? '')); ?></small></td>
                        <td><?php echo (int) $cat['sort_order']; ?></td>
                        <td><?php echo htmlspecialchars($cat['status']); ?></td>
                        <td>
                            <a href="<?php echo htmlspecialchars($panelUrl . '&edit_cat=' . (int) $cat['id']); ?>"><?php _e('编辑'); ?></a>
                            | <form method="post" action="<?php echo htmlspecialchars($formAction); ?>" style="display:inline;">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="cat_id" value="<?php echo (int) $cat['id']; ?>">
                                <button type="submit" style="background:none;border:none;color:#3b82f6;cursor:pointer;padding:0;" onclick="return confirm('确定删除此分类？关联商品不会被删除。');"><?php _e('删除'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p style="color:#999;"><?php _e('暂无分类。创建分类后可在创建/编辑商品时选择。'); ?></p>
            <?php endif; ?>
        </div>

        <!-- ============================================================ -->
        <!-- Product Edit Form -->
        <!-- ============================================================ -->
        <?php if ($editProduct): ?>
        <div class="table-description" style="margin-top:30px;border:1px solid #3b82f6;padding:16px;border-radius:6px;">
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
                    <label>
                        <input type="checkbox" name="allow_guest" value="1" <?php if (!empty($editProduct['allow_guest'])) echo 'checked'; ?>>
                        <?php _e('允许游客购买'); ?>
                    </label>
                    <br><small><?php _e('卡密商品建议关闭，要求用户登录后购买，便于售后查询和卡密找回。'); ?></small>
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
                <hr style="margin:16px 0;border:none;border-top:1px solid #e5e7eb;">
                <h4><?php _e('前台展示'); ?></h4>
                <p>
                    <label><?php _e('分类'); ?></label><br>
                    <select name="category_id">
                        <option value="0"><?php _e('-- 无分类 --'); ?></option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo (int) $cat['id']; ?>" <?php if ((int) ($editProduct['category_id'] ?? 0) === (int) $cat['id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label><?php _e('封面图 URL'); ?></label><br>
                    <input type="text" name="cover_url" value="<?php echo htmlspecialchars((string) ($editProduct['cover_url'] ?? '')); ?>" style="width:400px;" placeholder="https://example.com/cover.jpg">
                </p>
                <p>
                    <label><?php _e('摘要'); ?></label><br>
                    <input type="text" name="summary" value="<?php echo htmlspecialchars((string) ($editProduct['summary'] ?? '')); ?>" style="width:400px;" placeholder="商品简介，显示在商品卡片上">
                </p>
                <p>
                    <label><?php _e('排序'); ?></label><br>
                    <input type="number" name="sort_order" value="<?php echo (int) ($editProduct['sort_order'] ?? 0); ?>" style="width:100px;"> <small>越小越靠前</small>
                    <label style="margin-left:18px;"><input type="checkbox" name="is_featured" value="1" <?php if (!empty($editProduct['is_featured'])) echo 'checked'; ?>> <?php _e('推荐商品'); ?></label>
                </p>
                <p>
                    <label><?php _e('库存显示'); ?></label><br>
                    <select name="stock_display_mode">
                        <option value="exact" <?php if (($editProduct['stock_display_mode'] ?? 'exact') === 'exact') echo 'selected'; ?>><?php _e('exact - 显示精确数量'); ?></option>
                        <option value="range" <?php if (($editProduct['stock_display_mode'] ?? '') === 'range') echo 'selected'; ?>><?php _e('range - 显示充足/少量/售罄'); ?></option>
                        <option value="hidden" <?php if (($editProduct['stock_display_mode'] ?? '') === 'hidden') echo 'selected'; ?>><?php _e('hidden - 不显示库存'); ?></option>
                    </select>
                </p>
                <p>
                    <button class="btn primary" type="submit"><?php _e('保存修改'); ?></button>
                    <a href="<?php echo htmlspecialchars($panelUrl); ?>" class="btn"><?php _e('取消'); ?></a>
                </p>
            </form>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- Create Product -->
        <!-- ============================================================ -->
        <div class="table-description" style="margin-top:30px;">
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
                    <label>
                        <input type="checkbox" name="allow_guest" value="1">
                        <?php _e('允许游客购买'); ?>
                    </label>
                    <br><small><?php _e('默认关闭。卡密商品建议要求登录购买，避免用户丢失卡密后无法找回。'); ?></small>
                </p>
                <p>
                    <label><?php _e('文章 cid（可选）'); ?></label><br>
                    <input type="number" name="content_id" min="1" placeholder="组合商品解锁文章时填写" style="width:220px;">
                </p>
                <p>
                    <label><input type="checkbox" name="enable_cardcode" value="1" checked> <?php _e('交付卡密'); ?></label>
                    <label style="margin-left:18px;"><input type="checkbox" name="enable_post_access" value="1"> <?php _e('同时解锁文章'); ?></label>
                </p>
                <hr style="margin:16px 0;border:none;border-top:1px solid #e5e7eb;">
                <h4><?php _e('前台展示'); ?></h4>
                <p>
                    <label><?php _e('分类'); ?></label><br>
                    <select name="category_id">
                        <option value="0"><?php _e('-- 无分类 --'); ?></option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo (int) $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label><?php _e('封面图 URL'); ?></label><br>
                    <input type="text" name="cover_url" style="width:400px;" placeholder="https://example.com/cover.jpg">
                </p>
                <p>
                    <label><?php _e('摘要'); ?></label><br>
                    <input type="text" name="summary" style="width:400px;" placeholder="商品简介，显示在商品卡片上">
                </p>
                <p>
                    <label><?php _e('排序'); ?></label><br>
                    <input type="number" name="sort_order" value="0" style="width:100px;"> <small>越小越靠前</small>
                    <label style="margin-left:18px;"><input type="checkbox" name="is_featured" value="1"> <?php _e('推荐商品'); ?></label>
                </p>
                <p>
                    <label><?php _e('库存显示'); ?></label><br>
                    <select name="stock_display_mode">
                        <option value="exact"><?php _e('exact - 显示精确数量'); ?></option>
                        <option value="range"><?php _e('range - 显示充足/少量/售罄'); ?></option>
                        <option value="hidden"><?php _e('hidden - 不显示库存'); ?></option>
                    </select>
                </p>
                <p><button class="btn primary" type="submit"><?php _e('创建商品'); ?></button></p>
            </form>
        </div>

        <!-- ============================================================ -->
        <!-- Import Cards -->
        <!-- ============================================================ -->
        <div class="table-description" style="margin-top:30px;">
            <h3><?php _e('导入卡密'); ?></h3>
            <?php if (!empty($previewData)): ?>
                <div style="padding:16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;margin-bottom:16px;">
                    <h4><?php _e('预览结果'); ?></h4>
                    <table style="margin:12px 0;">
                        <tr><td style="padding:4px 16px 4px 0;color:#666;"><?php _e('商品'); ?></td><td><strong><?php echo htmlspecialchars($previewData['product_key'] . ' - ' . $previewData['product_title']); ?></strong></td></tr>
                        <tr><td style="padding:4px 16px 4px 0;color:#666;"><?php _e('批次名称'); ?></td><td><?php echo htmlspecialchars($previewData['batch_name']); ?></td></tr>
                        <tr><td style="padding:4px 16px 4px 0;color:#666;"><?php _e('原始行数'); ?></td><td><?php echo $previewData['raw_count']; ?></td></tr>
                        <tr><td style="padding:4px 16px 4px 0;color:#666;"><?php _e('有效条数'); ?></td><td style="color:#10b981;font-weight:600;"><?php echo $previewData['valid_count']; ?></td></tr>
                        <tr><td style="padding:4px 16px 4px 0;color:#666;"><?php _e('文件内重复'); ?></td><td style="color:<?php echo $previewData['duplicate_in_file'] > 0 ? '#f59e0b' : '#666'; ?>;"><?php echo $previewData['duplicate_in_file']; ?></td></tr>
                    </table>
                    <?php if ($previewData['valid_count'] > 0): ?>
                        <form method="post" action="<?php echo htmlspecialchars($formAction); ?>" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="import_cards">
                            <input type="hidden" name="product_id" value="<?php echo $previewData['product_id']; ?>">
                            <input type="hidden" name="batch_name" value="<?php echo htmlspecialchars($previewData['batch_name']); ?>">
                            <input type="hidden" name="filename_hint" value="<?php echo htmlspecialchars($previewData['filename_hint']); ?>">
                            <textarea name="card_lines" style="display:none;"><?php echo htmlspecialchars($previewData['raw_lines']); ?></textarea>
                            <button class="btn primary" type="submit" onclick="return confirm('确认导入 <?php echo $previewData['valid_count']; ?> 条卡密？');"><?php _e('确认导入'); ?></button>
                            <a href="<?php echo htmlspecialchars($panelUrl); ?>" class="btn"><?php _e('取消'); ?></a>
                        </form>
                    <?php else: ?>
                        <p style="color:#666;"><?php _e('没有可导入的有效卡密。'); ?></p>
                        <a href="<?php echo htmlspecialchars($panelUrl); ?>" class="btn"><?php _e('返回'); ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($previewData)): ?>
            <form method="post" action="<?php echo htmlspecialchars($formAction); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="preview_cards">
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
                    <small>支持 .txt / .csv / .tsv，最大 5MB。CSV 文件使用标准引号转义解析。</small>
                </p>
                <p>
                    <label><?php _e('或直接粘贴卡密'); ?></label><br>
                    <textarea name="card_lines" rows="8" style="width:100%;" placeholder="每行一张。支持：卡号----卡密、卡号|卡密、Tab 分隔或单独兑换码。CSV 请用逗号分隔。"></textarea>
                </p>
                <p><button class="btn primary" type="submit"><?php _e('解析预览'); ?></button></p>
            </form>
            <?php endif; ?>
        </div>

        <!-- ============================================================ -->
        <!-- Product List -->
        <!-- ============================================================ -->
        <div class="typecho-table-wrap" style="margin-top:30px;">
            <table class="typecho-list-table">
                <colgroup>
                    <col width="12%">
                    <col width="16%">
                    <col width="7%">
                    <col width="7%">
                    <col width="8%">
                    <col width="8%">
                    <col width="18%">
                    <col width="14%">
                    <col width="10%">
                </colgroup>
                <thead>
                <tr>
                    <th><?php _e('商品标识'); ?></th>
                    <th><?php _e('标题'); ?></th>
                    <th><?php _e('金额'); ?></th>
                    <th><?php _e('状态'); ?></th>
                    <th><?php _e('分类'); ?></th>
                    <th><?php _e('策略'); ?></th>
                    <th><?php _e('库存'); ?></th>
                    <th><?php _e('短代码'); ?></th>
                    <th><?php _e('操作'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$products): ?>
                    <tr><td colspan="9"><h6 class="typecho-list-table-title"><?php _e('暂无商品'); ?></h6></td></tr>
                <?php endif; ?>
                <?php foreach ($products as $product): ?>
                    <?php
                    $pid = (int) $product['id'];
                    $handlers = $productHandlers[$pid] ?? [];
                    $isCardcode = in_array('cardcode', $handlers, true);
                    $counts = $isCardcode
                        ? $cardService->stockCounts($pid)
                        : ['available' => 0, 'reserved' => 0, 'delivered' => 0, 'void' => 0, 'compromised' => 0, 'total' => 0];
                    $catName = '';
                    if (!empty($product['category_id']) && isset($categoriesById[(int) $product['category_id']])) {
                        $catName = $categoriesById[(int) $product['category_id']]['name'];
                    }
                    ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($product['product_key']); ?>
                            <?php if (!empty($product['is_featured'])): ?>
                                <br><small style="color:#f59e0b;">★ 推荐</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($product['title']); ?>
                            <?php if (!empty($product['summary'])): ?>
                                <br><small style="color:#999;"><?php echo htmlspecialchars(function_exists('mb_substr') ? mb_substr($product['summary'], 0, 40) : substr($product['summary'], 0, 40)); ?></small>
                            <?php endif; ?>
                            <br><small><?php echo htmlspecialchars(implode(', ', $handlers)); ?></small>
                            <?php if (!empty($product['content_id'])): ?>
                                <br><small><?php _e('绑定文章 cid'); ?>: <?php echo (int) $product['content_id']; ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($product['currency'] . ' ' . $product['amount']); ?></td>
                        <td><?php echo htmlspecialchars($product['status']); ?></td>
                        <td><small><?php echo $catName !== '' ? htmlspecialchars($catName) : '-'; ?></small></td>
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
                        <td>
                            <code style="font-size:0.85em;"><?php echo htmlspecialchars('[typechopay product="' . $product['product_key'] . '"]'); ?></code>
                            <?php if (!empty($product['cover_url'])): ?>
                                <br><code style="font-size:0.85em;"><?php echo htmlspecialchars('[typechopay_product product="' . $product['product_key'] . '"]'); ?></code>
                            <?php endif; ?>
                        </td>
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
