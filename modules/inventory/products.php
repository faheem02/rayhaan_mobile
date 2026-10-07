<?php
session_start();
$page_title = 'Products';
$base_url = '../../';
require_once '../../includes/functions.php';

// Ensure opening_stock column exists in products table
try {
    $pdo->query("SELECT opening_stock FROM products LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE products ADD COLUMN opening_stock INT NOT NULL DEFAULT 0 AFTER sale_price");
    } catch (Exception $ignored) {}
}

// Handle Opening Balance / Opening Stock submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_opening_stock') {
    $entry_type = $_POST['entry_type'] ?? 'existing';

    if ($entry_type === 'existing') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $prod = getById('products', $product_id);
        if (!$prod) {
            $_SESSION['error'] = 'Selected product not found.';
            header("Location: products.php");
            exit;
        }

        $stock_mode = $_POST['stock_mode'] ?? 'add'; // 'add' or 'set'
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        $purchase_price = isset($_POST['purchase_price']) && $_POST['purchase_price'] !== '' ? max(0, (float)$_POST['purchase_price']) : (float)$prod['purchase_price'];
        $sale_price = isset($_POST['sale_price']) && $_POST['sale_price'] !== '' ? max(0, (float)$_POST['sale_price']) : (float)$prod['sale_price'];
        $notes = trim($_POST['notes'] ?? 'Opening Stock') ?: 'Opening Stock';

        // Serials / IMEIs processing
        $all_imeis = [];
        $collected_pairs = [];

        if ($prod['product_type'] === 'mobile' || (int)$prod['has_serial'] === 1) {
            $row_imei1 = (array)($_POST['mobile_imei1'] ?? []);
            $row_imei2 = (array)($_POST['mobile_imei2'] ?? []);
            $row_prices = (array)($_POST['mobile_price'] ?? []);

            for ($i = 0; $i < count($row_imei1); $i++) {
                $i1 = trim($row_imei1[$i] ?? '');
                $i2 = trim($row_imei2[$i] ?? '');
                $pr = isset($row_prices[$i]) && $row_prices[$i] !== '' ? (float)$row_prices[$i] : $purchase_price;
                if ($i1 !== '' || $i2 !== '') {
                    $collected_pairs[] = ['imei1' => $i1, 'imei2' => $i2, 'price' => $pr, 'serial' => ''];
                    if ($i1 !== '') $all_imeis[] = $i1;
                    if ($i2 !== '') $all_imeis[] = $i2;
                }
            }

            // Bulk textarea
            $bulk_text = trim($_POST['bulk_imeis'] ?? '');
            if ($bulk_text !== '') {
                $lines = preg_split('/[\r\n]+/', $bulk_text);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $parts = array_map('trim', preg_split('/[,;\t]+/', $line));
                    $i1 = $parts[0] ?? '';
                    $i2 = $parts[1] ?? '';
                    if ($i1 !== '' || $i2 !== '') {
                        $collected_pairs[] = ['imei1' => $i1, 'imei2' => $i2, 'price' => $purchase_price, 'serial' => ''];
                        if ($i1 !== '') $all_imeis[] = $i1;
                        if ($i2 !== '') $all_imeis[] = $i2;
                    }
                }
            }

            // Validate IMEI format
            foreach ($all_imeis as $im) {
                if (!preg_match('/^\d{15}$/', $im)) {
                    $_SESSION['error'] = "IMEI '$im' must be exactly 15 digits.";
                    header("Location: products.php");
                    exit;
                }
            }

            // Validate duplicates within entry
            $counts = array_count_values($all_imeis);
            foreach ($counts as $im => $cnt) {
                if ($cnt > 1) {
                    $_SESSION['error'] = "Duplicate IMEI '$im' found in entry.";
                    header("Location: products.php");
                    exit;
                }
            }

            // Check duplicates in database
            if (!empty($all_imeis)) {
                $in_clause = implode(',', array_fill(0, count($all_imeis), '?'));
                $chk = $pdo->prepare("SELECT imei_number, serial_number FROM product_serials WHERE imei_number IN ($in_clause) OR serial_number IN ($in_clause)");
                $chk->execute(array_merge($all_imeis, $all_imeis));
                $found = $chk->fetchAll();
                if (!empty($found)) {
                    $first_dup = $found[0]['imei_number'] ?: $found[0]['serial_number'];
                    $_SESSION['error'] = "IMEI '$first_dup' already exists in the system.";
                    header("Location: products.php");
                    exit;
                }
            }
        } elseif ($prod['product_type'] === 'laptop') {
            $lserials = (array)($_POST['laptop_serial'] ?? []);
            $lnotes = (array)($_POST['laptop_notes'] ?? []);
            for ($i = 0; $i < count($lserials); $i++) {
                $ser = trim($lserials[$i] ?? '');
                $nt = trim($lnotes[$i] ?? '');
                if ($ser !== '' || $nt !== '') {
                    $collected_pairs[] = ['imei1' => '', 'imei2' => '', 'price' => $purchase_price, 'serial' => $ser, 'notes' => $nt];
                }
            }
            $bulk_l = trim($_POST['bulk_laptop_serials'] ?? '');
            if ($bulk_l !== '') {
                $lines = preg_split('/[\r\n]+/', $bulk_l);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $collected_pairs[] = ['imei1' => '', 'imei2' => '', 'price' => $purchase_price, 'serial' => $line, 'notes' => ''];
                    }
                }
            }
        }

        if (!empty($collected_pairs) && count($collected_pairs) > $quantity) {
            $quantity = count($collected_pairs);
        }

        $new_stock = ($stock_mode === 'set') ? $quantity : (int)$prod['stock_quantity'] + $quantity;
        $new_opening = ($stock_mode === 'set') ? $quantity : (int)($prod['opening_stock'] ?? 0) + $quantity;

        // Update product
        $pdo->prepare("UPDATE products SET stock_quantity = ?, opening_stock = ?, purchase_price = ?, sale_price = ?, updated_at = CURDATE() WHERE id = ?")
            ->execute([$new_stock, $new_opening, $purchase_price, $sale_price, $product_id]);

        // Insert serials
        if (!empty($collected_pairs)) {
            $ins_serial = $pdo->prepare("INSERT INTO product_serials (product_id, imei_number, serial_number, purchase_price, purchase_id, status, notes, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, 'available', ?, CURDATE(), CURDATE())");
            foreach ($collected_pairs as $cp) {
                $i1 = !empty($cp['imei1']) ? $cp['imei1'] : null;
                $i2 = !empty($cp['imei2']) ? $cp['imei2'] : null;
                $ser = !empty($cp['serial']) ? $cp['serial'] : ($i2 ?: null);
                $pr = (float)($cp['price'] ?? $purchase_price);
                $note = !empty($cp['notes']) ? $cp['notes'] : $notes;
                $ins_serial->execute([$product_id, $i1, $ser, $pr, $note]);
            }
        }

        // Activity log
        try {
            $pdo->prepare("INSERT INTO activity_logs (user_id, action, module, reference_id, description, ip_address, created_at) VALUES (?, 'Opening Stock', 'inventory', ?, ?, ?, CURDATE())")
                ->execute([$_SESSION['user_id'] ?? null, $product_id, "Opening stock added: {$quantity} units for '{$prod['name']}' (Total stock now: {$new_stock})", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
        } catch (Exception $ignored) {}

        $_SESSION['success'] = "Opening stock of {$quantity} units successfully saved for '{$prod['name']}'. (Total Stock: {$new_stock})";
        header("Location: products.php");
        exit;

    } elseif ($entry_type === 'new') {
        $name = trim($_POST['new_name'] ?? '');
        if ($name === '') {
            $_SESSION['error'] = 'Product name is required.';
            header("Location: products.php");
            exit;
        }

        $product_type = in_array($_POST['new_type'] ?? '', ['mobile', 'laptop', 'general'], true) ? $_POST['new_type'] : 'general';
        $code = trim($_POST['new_code'] ?? '');
        if ($code === '') {
            $code = generateProductCode($product_type);
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM products WHERE code = ?");
            $chk->execute([$code]);
            if ($chk->fetchColumn() > 0) {
                $code = generateProductCode($product_type);
            }
        }

        $quantity = max(0, (int)($_POST['new_quantity'] ?? 0));
        $purchase_price = max(0, (float)($_POST['new_purchase_price'] ?? 0));
        $sale_price = max(0, (float)($_POST['new_sale_price'] ?? 0));
        $category_id = (int)($_POST['new_category_id'] ?? 0) ?: null;
        $brand_id = (int)($_POST['new_brand_id'] ?? 0) ?: null;
        $min_stock = max(0, (int)($_POST['new_min_stock_level'] ?? 0));
        $unit = trim($_POST['new_unit'] ?? 'pcs') ?: 'pcs';
        $condition = in_array($_POST['new_condition'] ?? '', ['New', 'Used', 'Refurbished']) ? $_POST['new_condition'] : 'New';
        $color = trim($_POST['new_color'] ?? '') ?: null;
        $storage = trim($_POST['new_storage'] ?? '') ?: null;
        $ram = trim($_POST['new_ram'] ?? '') ?: null;
        $processor = trim($_POST['new_processor'] ?? '') ?: null;
        $screen_size = trim($_POST['new_screen_size'] ?? '') ?: null;
        $notes = trim($_POST['new_notes'] ?? 'Opening Stock') ?: 'Opening Stock';
        $has_serial = ($product_type === 'mobile' || $product_type === 'laptop') ? 1 : (isset($_POST['new_has_serial']) ? 1 : 0);

        // Serials / IMEIs
        $all_imeis = [];
        $collected_pairs = [];

        if ($product_type === 'mobile') {
            $row_imei1 = (array)($_POST['new_mobile_imei1'] ?? []);
            $row_imei2 = (array)($_POST['new_mobile_imei2'] ?? []);
            $row_prices = (array)($_POST['new_mobile_price'] ?? []);

            for ($i = 0; $i < count($row_imei1); $i++) {
                $i1 = trim($row_imei1[$i] ?? '');
                $i2 = trim($row_imei2[$i] ?? '');
                $pr = isset($row_prices[$i]) && $row_prices[$i] !== '' ? (float)$row_prices[$i] : $purchase_price;
                if ($i1 !== '' || $i2 !== '') {
                    $collected_pairs[] = ['imei1' => $i1, 'imei2' => $i2, 'price' => $pr, 'serial' => ''];
                    if ($i1 !== '') $all_imeis[] = $i1;
                    if ($i2 !== '') $all_imeis[] = $i2;
                }
            }

            $bulk_text = trim($_POST['new_bulk_imeis'] ?? '');
            if ($bulk_text !== '') {
                $lines = preg_split('/[\r\n]+/', $bulk_text);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $parts = array_map('trim', preg_split('/[,;\t]+/', $line));
                    $i1 = $parts[0] ?? '';
                    $i2 = $parts[1] ?? '';
                    if ($i1 !== '' || $i2 !== '') {
                        $collected_pairs[] = ['imei1' => $i1, 'imei2' => $i2, 'price' => $purchase_price, 'serial' => ''];
                        if ($i1 !== '') $all_imeis[] = $i1;
                        if ($i2 !== '') $all_imeis[] = $i2;
                    }
                }
            }

            foreach ($all_imeis as $im) {
                if (!preg_match('/^\d{15}$/', $im)) {
                    $_SESSION['error'] = "IMEI '$im' must be exactly 15 digits.";
                    header("Location: products.php");
                    exit;
                }
            }
            $counts = array_count_values($all_imeis);
            foreach ($counts as $im => $cnt) {
                if ($cnt > 1) {
                    $_SESSION['error'] = "Duplicate IMEI '$im' found in entry.";
                    header("Location: products.php");
                    exit;
                }
            }
            if (!empty($all_imeis)) {
                $in_clause = implode(',', array_fill(0, count($all_imeis), '?'));
                $chk = $pdo->prepare("SELECT imei_number, serial_number FROM product_serials WHERE imei_number IN ($in_clause) OR serial_number IN ($in_clause)");
                $chk->execute(array_merge($all_imeis, $all_imeis));
                $found = $chk->fetchAll();
                if (!empty($found)) {
                    $first_dup = $found[0]['imei_number'] ?: $found[0]['serial_number'];
                    $_SESSION['error'] = "IMEI '$first_dup' already exists in the system.";
                    header("Location: products.php");
                    exit;
                }
            }
        } elseif ($product_type === 'laptop') {
            $lserials = (array)($_POST['new_laptop_serial'] ?? []);
            for ($i = 0; $i < count($lserials); $i++) {
                $ser = trim($lserials[$i] ?? '');
                if ($ser !== '') {
                    $collected_pairs[] = ['imei1' => '', 'imei2' => '', 'price' => $purchase_price, 'serial' => $ser];
                }
            }
            $bulk_l = trim($_POST['new_bulk_laptop_serials'] ?? '');
            if ($bulk_l !== '') {
                $lines = preg_split('/[\r\n]+/', $bulk_l);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $collected_pairs[] = ['imei1' => '', 'imei2' => '', 'price' => $purchase_price, 'serial' => $line];
                    }
                }
            }
        }

        if (!empty($collected_pairs) && count($collected_pairs) > $quantity) {
            $quantity = count($collected_pairs);
        }

        $new_product_id = insert('products', [
            'name' => $name,
            'code' => $code,
            'description' => $notes ?: null,
            'category_id' => $category_id,
            'brand_id' => $brand_id,
            'supplier_id' => null,
            'color' => $color,
            'storage' => $storage,
            'ram' => $ram,
            'processor' => $processor,
            'screen_size' => $screen_size,
            'graphics' => null,
            'warranty_months' => null,
            'product_condition' => $condition,
            'purchase_price' => $purchase_price,
            'sale_price' => $sale_price,
            'opening_stock' => $quantity,
            'stock_quantity' => $quantity,
            'min_stock_level' => $min_stock,
            'unit' => $unit,
            'product_type' => $product_type,
            'has_serial' => $has_serial,
            'status' => 1,
            'created_at' => date('Y-m-d'),
            'updated_at' => date('Y-m-d'),
        ]);

        if (!empty($collected_pairs)) {
            $ins_serial = $pdo->prepare("INSERT INTO product_serials (product_id, imei_number, serial_number, purchase_price, purchase_id, status, notes, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, 'available', ?, CURDATE(), CURDATE())");
            foreach ($collected_pairs as $cp) {
                $i1 = !empty($cp['imei1']) ? $cp['imei1'] : null;
                $i2 = !empty($cp['imei2']) ? $cp['imei2'] : null;
                $ser = !empty($cp['serial']) ? $cp['serial'] : ($i2 ?: null);
                $pr = (float)($cp['price'] ?? $purchase_price);
                $ins_serial->execute([$new_product_id, $i1, $ser, $pr, $notes]);
            }
        }

        try {
            $pdo->prepare("INSERT INTO activity_logs (user_id, action, module, reference_id, description, ip_address, created_at) VALUES (?, 'New Product (Opening Stock)', 'inventory', ?, ?, ?, CURDATE())")
                ->execute([$_SESSION['user_id'] ?? null, $new_product_id, "Created product '{$name}' ({$code}) with opening stock {$quantity} units", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
        } catch (Exception $ignored) {}

        $_SESSION['success'] = "Product '{$name}' ({$code}) created with opening stock of {$quantity} units.";
        header("Location: products.php");
        exit;
    }
}

// Handle delete (safe: deactivate if product has sales/purchase history)
if (isset($_GET['delete'])) {
    $pid = (int)$_GET['delete'];
    $prod = getById('products', $pid);
    if (!$prod) {
        $_SESSION['error'] = 'Product not found.';
        header("Location: products.php");
        exit;
    }
    $has_history = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM sale_items WHERE product_id = $pid)
        + (SELECT COUNT(*) FROM purchase_items WHERE product_id = $pid)
        + (SELECT COUNT(*) FROM product_serials WHERE product_id = $pid)")->fetchColumn();
    if ($has_history > 0) {
        $pdo->prepare("UPDATE products SET status = 0, updated_at = CURDATE() WHERE id = ?")->execute([$pid]);
        $_SESSION['success'] = "Product '{$prod['name']}' has sales/purchase history, so it was deactivated instead of deleted.";
    } else {
        $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$pid]);
        $_SESSION['success'] = "Product '{$prod['name']}' deleted.";
    }
    header("Location: products.php");
    exit;
}

$where = ['1=1'];
$params = [];

if (!empty($_GET['search'])) {
    $where[] = "(p.name LIKE ? OR p.code LIKE ?)";
    $term = '%' . trim($_GET['search']) . '%';
    $params[] = $term; $params[] = $term;
}
if (!empty($_GET['type']) && in_array($_GET['type'], ['mobile', 'laptop', 'general'], true)) {
    $where[] = "p.product_type = ?";
    $params[] = $_GET['type'];
}
if (!empty($_GET['category'])) {
    $where[] = "p.category_id = ?";
    $params[] = (int)$_GET['category'];
}
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $where[] = "p.status = ?";
    $params[] = (int)$_GET['status'];
} else {
    $where[] = "p.status = 1";
}

if (!empty($_GET['type']) && in_array($_GET['type'], ['mobile', 'laptop', 'general'], true)) {
    $cat_stmt = $pdo->prepare("SELECT * FROM categories WHERE status = 1 AND product_type = ? ORDER BY name");
    $cat_stmt->execute([$_GET['type']]);
} else {
    $cat_stmt = $pdo->query("SELECT * FROM categories WHERE status = 1 ORDER BY product_type, name");
}
$categories = $cat_stmt->fetchAll();

// Queries for Opening Balance / Opening Stock modal
$all_active_products = $pdo->query("SELECT id, code, name, product_type, stock_quantity, opening_stock, purchase_price, sale_price, has_serial FROM products WHERE status = 1 ORDER BY name ASC")->fetchAll();
$all_categories = $pdo->query("SELECT * FROM categories WHERE status = 1 ORDER BY product_type, name ASC")->fetchAll();
$brands = $pdo->query("SELECT * FROM brands WHERE status = 1 ORDER BY name ASC")->fetchAll();

$next_code_mobile = generateProductCode('mobile');
$next_code_laptop = generateProductCode('laptop');
$next_code_general = generateProductCode('general');

$stmt = $pdo->prepare("
    SELECT p.*, c.name AS category_name, b.name AS brand_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN brands b ON b.id = p.brand_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY p.product_type, p.name
");
$stmt->execute($params);
$products = $stmt->fetchAll();

$out_of_stock = $pdo->query("
    SELECT p.*, c.name AS category_name, b.name AS brand_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN brands b ON b.id = p.brand_id
    WHERE p.stock_quantity <= 0 AND p.status = 1
    ORDER BY p.product_type, p.name
")->fetchAll();

$total_product_count = count($products);

$stock_ok = 0; $stock_low = 0; $stock_out = 0;
$stock_value = 0;
foreach ($products as $p) {
    if ((int)$p['status'] !== 1) continue;
    $s = (int)$p['stock_quantity'];
    if ($s <= 0) $stock_out++;
    elseif ((int)$p['min_stock_level'] > 0 && $s <= (int)$p['min_stock_level']) $stock_low++;
    else $stock_ok++;

    if ((int)$p['has_serial'] !== 1) {
        $stock_value += (float)$p['purchase_price'] * $s;
    }
}
if (!empty($products)) {
    $ids = implode(',', array_map('intval', array_column($products, 'id')));
    $stock_value += (float)$pdo->query("SELECT COALESCE(SUM(ps.purchase_price), 0) FROM product_serials ps JOIN products p ON p.id = ps.product_id WHERE ps.status = 'available' AND p.status = 1 AND ps.product_id IN ($ids)")->fetchColumn();
}

$products = array_values(array_filter($products, fn($p) => (int)$p['stock_quantity'] > 0));

require_once '../../includes/header.php';
?>

<style>
.pv-grid .pv-box {
    background: #f8f9fc;
    border: 1px solid #eef0f7;
    border-radius: 8px;
    padding: 8px 10px;
    margin-bottom: 10px;
}
.pv-label { font-size: .68rem; text-transform: uppercase; letter-spacing: .3px; color: #858796; margin-bottom: 2px; }
.pv-value { font-size: .9rem; font-weight: 600; color: #0f172a; word-break: break-word; }
.pv-section-title { font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: #4e73df; border-left: 3px solid #4e73df; padding-left: 8px; margin: 14px 0 8px; }
.pv-count { font-size: .75rem; padding: 5px 10px; }
.pv-serial-table td { font-size: .82rem; }
.pv-serial-table code { font-size: .78rem; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h5 class="m-0 font-weight-bold" style="color:#0f172a;"><i class="fas fa-boxes"></i> Products</h5>
    <small class="text-muted">Product-wise stock &amp; pricing</small>
  </div>
  <div class="d-flex align-items-center">
    <a href="product_create.php" class="btn btn-sm mr-1 shadow-sm font-weight-bold text-white" style="background-color: #f59e0b; border-color: #f59e0b;">
      <i class="fas fa-plus mr-1"></i> Add Product
    </a>
    <a href="products_print.php?<?= http_build_query(array_filter($_GET)) ?>" target="_blank" class="btn btn-sm btn-outline-secondary mr-1"><i class="fas fa-print"></i> Print</a>
    <button type="button" class="btn btn-sm btn-danger" data-toggle="modal" data-target="#outOfStockModal">
      <i class="fas fa-times-circle"></i> Out of Stock
      <?php if (count($out_of_stock) > 0): ?><span class="badge badge-light ml-1"><?= count($out_of_stock) ?></span><?php endif; ?>
    </button>
  </div>
</div>

<!-- Summary -->
<div class="row mb-3">
  <div class="col-6 col-md-4 mb-2">
    <div class="card shadow-sm"><div class="card-body py-2 d-flex align-items-center">
      <div class="mr-3"><i class="fas fa-cubes fa-2x text-primary"></i></div>
      <div><div class="text-xs font-weight-bold text-uppercase mb-1">Total Products</div><div class="h5 mb-0 font-weight-bold" style="color:#0f172a;"><?= $total_product_count ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-4 mb-2">
    <div class="card shadow-sm"><div class="card-body py-2 d-flex align-items-center">
      <div class="mr-3"><i class="fas fa-check-circle fa-2x text-success"></i></div>
      <div><div class="text-xs font-weight-bold text-uppercase mb-1">In Stock</div><div class="h5 mb-0 font-weight-bold text-success"><?= $stock_ok ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-4 mb-2">
    <div class="card shadow-sm"><div class="card-body py-2 d-flex align-items-center">
      <div class="mr-3"><i class="fas fa-exclamation-triangle fa-2x text-warning"></i></div>
      <div><div class="text-xs font-weight-bold text-uppercase mb-1">Low Stock</div><div class="h5 mb-0 font-weight-bold text-warning"><?= $stock_low ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-4 mb-2">
    <div class="card shadow-sm"><div class="card-body py-2 d-flex align-items-center">
      <div class="mr-3"><i class="fas fa-times-circle fa-2x text-danger"></i></div>
      <div><div class="text-xs font-weight-bold text-uppercase mb-1">Out of Stock</div><div class="h5 mb-0 font-weight-bold text-danger"><?= $stock_out ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-4 mb-2">
    <div class="card shadow-sm"><div class="card-body py-2 d-flex align-items-center">
      <div class="mr-3"><i class="fas fa-coins fa-2x text-info"></i></div>
      <div><div class="text-xs font-weight-bold text-uppercase mb-1">Stock Value</div><div class="h5 mb-0 font-weight-bold" style="color:#0f172a;">PKR <?= formatCurrency($stock_value) ?></div></div>
    </div></div>
  </div>
</div>

<div class="card shadow mb-4">
  <div class="card-header py-3">
    <form method="get" class="form-inline">
      <input type="text" name="search" class="form-control form-control-sm mr-2 mb-1" style="min-width:220px;" placeholder="Search name / code..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
      <select name="type" class="form-control form-control-sm mr-2 mb-1">
        <option value="">All Types</option>
        <?php foreach (['mobile' => 'Mobile', 'laptop' => 'Laptop', 'general' => 'General'] as $tk => $tv): ?>
          <option value="<?= $tk ?>" <?= (($_GET['type'] ?? '') === $tk) ? 'selected' : '' ?>><?= $tv ?></option>
        <?php endforeach; ?>
      </select>
      <select name="category" class="form-control form-control-sm mr-2 mb-1">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>" <?= ((int)($_GET['category'] ?? 0) === (int)$cat['id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" class="form-control form-control-sm mr-2 mb-1">
        <option value="">All Status</option>
        <option value="1" <?= (($_GET['status'] ?? '') === '1') ? 'selected' : '' ?>>Active</option>
        <option value="0" <?= (($_GET['status'] ?? '') === '0') ? 'selected' : '' ?>>Inactive</option>
      </select>
      <button class="btn btn-sm btn-primary mb-1 mr-1"><i class="fas fa-search"></i> Filter</button>
      <a href="products.php" class="btn btn-sm btn-secondary mb-1"><i class="fas fa-undo"></i> Reset</a>
    </form>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered table-hover table-sm">
        <thead class="thead-light">
          <tr>
            <th>#</th>
            <th>Code</th>
            <th>Product</th>
            <th>Type</th>
            <th>Category</th>
            <th>Brand</th>
            <th class="text-center">Stock</th>
            <th class="text-right">Purchase Price</th>
            <th class="text-right">Sale Price</th>
            <th class="text-center">Status</th>
            <th class="text-center">History</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($products)): ?>
            <tr><td colspan="11" class="text-center text-muted">No products found</td></tr>
          <?php else: foreach ($products as $i => $p): ?>
            <?php
              $stock = (int)$p['stock_quantity'];
              $min_stock = (int)$p['min_stock_level'];
              if ($stock <= 0) { $stockBadge = 'danger'; $stockTxt = 'Out of Stock'; }
              elseif ($min_stock > 0 && $stock <= $min_stock) { $stockBadge = 'warning'; $stockTxt = 'Low - ' . $stock; }
              else { $stockBadge = 'success'; $stockTxt = (string)$stock; }
              $specs = array_filter([$p['color'], $p['storage'], $p['ram'], $p['processor'], $p['screen_size']]);
              $spec_txt = implode(' / ', $specs);
              $typeLabel = ['mobile' => 'Mobile', 'laptop' => 'Laptop', 'general' => 'General'][$p['product_type']] ?? ucfirst($p['product_type']);
              $typeIcon = $p['product_type'] === 'mobile' ? 'fa-mobile-alt' : ($p['product_type'] === 'laptop' ? 'fa-laptop' : 'fa-box');
            ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td><code><?= htmlspecialchars($p['code']) ?></code></td>
              <td>
                <div class="font-weight-bold" style="color:#0f172a;"><?= htmlspecialchars($p['name']) ?></div>
                <?php if ($spec_txt): ?><small class="text-muted"><?= htmlspecialchars($spec_txt) ?></small><?php endif; ?>
              </td>
              <td><i class="fas <?= $typeIcon ?> text-primary mr-1"></i><?= $typeLabel ?></td>
              <td><?= htmlspecialchars($p['category_name'] ?? '-') ?></td>
              <td><?= htmlspecialchars($p['brand_name'] ?? '-') ?></td>
              <td class="text-center">
                <span class="badge badge-<?= $stockBadge ?>"><?= $stockTxt ?></span>
                <?php if (!empty($p['opening_stock']) && (int)$p['opening_stock'] > 0): ?>
                  <div class="mt-1" title="Opening Balance"><span class="badge badge-light border text-muted" style="font-size:0.68rem;">Op: <?= (int)$p['opening_stock'] ?></span></div>
                <?php endif; ?>
              </td>
              <td class="text-right"><?= formatCurrency($p['purchase_price']) ?></td>
              <td class="text-right"><?= formatCurrency($p['sale_price']) ?></td>
              <td class="text-center"><span class="badge badge-<?= $p['status'] ? 'success' : 'secondary' ?>"><?= $p['status'] ? 'Active' : 'Inactive' ?></span></td>
              <td class="text-center" style="white-space:nowrap;">
                <button type="button" class="btn btn-sm btn-outline-primary btn-product-view" data-product-id="<?= $p['id'] ?>" data-product-name="<?= htmlspecialchars($p['name']) ?>"><i class="fas fa-eye"></i></button>
                <button type="button" class="btn btn-sm btn-outline-info btn-purchase-history" data-product-id="<?= $p['id'] ?>" data-product-name="<?= htmlspecialchars($p['name']) ?>"><i class="fas fa-history"></i></button>
                <a href="product_edit.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-pen"></i></a>
                <a href="products.php?delete=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Delete product <?= htmlspecialchars($p['name'], ENT_QUOTES) ?>? Products with sales/purchase history will be deactivated instead.')"><i class="fas fa-trash"></i></a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Product View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="viewModalTitle"><i class="fas fa-eye"></i> Product Details</h6>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body" id="viewModalBody">
        <div class="text-center text-muted py-4">Loading...</div>
      </div>
      <div class="modal-footer py-1">
        <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Purchase History Modal -->
<div class="modal fade" id="historyModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="historyModalTitle"><i class="fas fa-history"></i> Purchase History</h6>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <div class="table-responsive">
          <table class="table table-sm table-bordered mb-0">
            <thead class="thead-light">
              <tr><th>Date</th><th>Supplier / Person</th><th>Invoice</th><th class="text-center">Qty</th><th class="text-right">Price</th></tr>
            </thead>
            <tbody id="historyBody">
              <tr><td colspan="5" class="text-center text-muted py-3">Loading...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer py-1">
        <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Out of Stock Modal -->
<div class="modal fade" id="outOfStockModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="fas fa-times-circle text-danger mr-1"></i> Out of Stock Products (<?= count($out_of_stock) ?>)</h6>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <?php if (empty($out_of_stock)): ?>
          <p class="text-center text-muted py-3 mb-0"><i class="fas fa-check-circle text-success fa-2x d-block mb-2"></i>No out of stock products</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
              <thead class="thead-light">
                <tr>
                  <th style="width:40px;">#</th>
                  <th>Code</th>
                  <th>Product</th>
                  <th>Type</th>
                  <th>Category</th>
                  <th>Brand</th>
                  <th class="text-right">Purchase Price</th>
                  <th class="text-right">Sale Price</th>
                  <th class="text-center">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php $oi = 1; foreach ($out_of_stock as $o): ?>
                  <tr>
                    <td><?= $oi++ ?></td>
                    <td><code><?= htmlspecialchars($o['code']) ?></code></td>
                    <td>
                      <div class="font-weight-bold" style="color:#0f172a;"><?= htmlspecialchars($o['name']) ?></div>
                    </td>
                    <td><?= ['mobile' => 'Mobile', 'laptop' => 'Laptop', 'general' => 'General'][$o['product_type']] ?? ucfirst($o['product_type']) ?></td>
                    <td><?= htmlspecialchars($o['category_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($o['brand_name'] ?? '-') ?></td>
                    <td class="text-right"><?= formatCurrency($o['purchase_price']) ?></td>
                    <td class="text-right"><?= formatCurrency($o['sale_price']) ?></td>
                    <td class="text-center">
                      <span class="badge badge-<?= $o['status'] ? 'danger' : 'secondary' ?>"><?= $o['status'] ? 'Out of Stock' : 'Inactive' ?></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer py-1">
        <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Opening Stock / Balance Modal -->
<div class="modal fade" id="openingStockModal" tabindex="-1" role="dialog" aria-labelledby="openingStockModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content border-0 shadow">
      <div class="modal-header text-white py-2" style="background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%);">
        <h6 class="modal-title font-weight-bold" id="openingStockModalTitle">
          <i class="fas fa-boxes mr-2"></i> Opening Balance / Opening Stock
        </h6>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">&times;</button>
      </div>
      <div class="modal-body p-3">
        <!-- Nav pills to switch between Existing and New Product -->
        <ul class="nav nav-pills nav-justified mb-3" id="openingStockTabs" role="tablist">
          <li class="nav-item">
            <a class="nav-link active font-weight-bold" id="tab-existing-link" data-toggle="pill" href="#tab-existing" role="tab">
              <i class="fas fa-cubes mr-1"></i> Existing Product Stock
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link font-weight-bold" id="tab-new-link" data-toggle="pill" href="#tab-new" role="tab">
              <i class="fas fa-plus-circle mr-1"></i> New Product + Opening Stock
            </a>
          </li>
        </ul>

        <div class="tab-content" id="openingStockTabsContent">
          <!-- TAB 1: EXISTING PRODUCT -->
          <div class="tab-pane fade show active" id="tab-existing" role="tabpanel">
            <form method="post" id="formExistingOpeningStock">
              <input type="hidden" name="action" value="save_opening_stock">
              <input type="hidden" name="entry_type" value="existing">

              <div class="form-group mb-2">
                <label class="font-weight-bold text-dark small mb-1">Select Product <span class="text-danger">*</span></label>
                <div class="input-group input-group-sm mb-1">
                  <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
                  <input type="text" id="ob_product_filter" class="form-control" placeholder="Quick search by product name or code...">
                </div>
                <select name="product_id" id="ob_product_id" class="form-control" required size="5" style="height: auto; max-height: 140px;">
                  <option value="" disabled selected>-- Click a product below to select --</option>
                  <?php foreach ($all_active_products as $ap): ?>
                    <option value="<?= $ap['id'] ?>"
                            data-type="<?= $ap['product_type'] ?>"
                            data-code="<?= htmlspecialchars($ap['code']) ?>"
                            data-name="<?= htmlspecialchars($ap['name']) ?>"
                            data-stock="<?= (int)$ap['stock_quantity'] ?>"
                            data-opening="<?= (int)($ap['opening_stock'] ?? 0) ?>"
                            data-purchase="<?= (float)$ap['purchase_price'] ?>"
                            data-sale="<?= (float)$ap['sale_price'] ?>"
                            data-serial="<?= (int)$ap['has_serial'] ?>">
                      <?= htmlspecialchars($ap['name']) ?> [<?= htmlspecialchars($ap['code']) ?>] &mdash; (Stock: <?= (int)$ap['stock_quantity'] ?> | <?= ucfirst($ap['product_type']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <small class="form-text text-muted">Click any product in the list to select it.</small>
              </div>

              <!-- Product Info Box (shown upon selection) -->
              <div id="ob_product_card" class="card bg-light border mb-2" style="display:none;">
                <div class="card-body py-2 px-3">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <strong id="ob_card_name" class="text-dark"></strong>
                      <span id="ob_card_code" class="badge badge-secondary ml-1"></span>
                      <span id="ob_card_type" class="badge badge-info ml-1"></span>
                    </div>
                    <div>
                      <span class="text-muted small mr-1">Current Stock:</span>
                      <span id="ob_card_stock" class="badge badge-primary px-2 py-1 font-weight-bold">0</span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Mode & Quantity -->
              <div class="row">
                <div class="col-md-6 form-group mb-2">
                  <label class="font-weight-bold text-dark small mb-1">Adjustment Mode</label>
                  <div class="d-flex align-items-center mt-1">
                    <div class="custom-control custom-radio mr-3">
                      <input type="radio" id="mode_add" name="stock_mode" value="add" class="custom-control-input" checked>
                      <label class="custom-control-label small" for="mode_add"><strong class="text-success">+ Add to Current Stock</strong></label>
                    </div>
                    <div class="custom-control custom-radio">
                      <input type="radio" id="mode_set" name="stock_mode" value="set" class="custom-control-input">
                      <label class="custom-control-label small" for="mode_set"><strong class="text-primary">= Set Exact Stock</strong></label>
                    </div>
                  </div>
                </div>
                <div class="col-md-6 form-group mb-2">
                  <label class="font-weight-bold text-dark small mb-1">Opening Stock Quantity <span class="text-danger">*</span></label>
                  <input type="number" name="quantity" id="ob_qty" class="form-control form-control-sm font-weight-bold" min="1" value="1" required>
                </div>
              </div>

              <!-- Prices -->
              <div class="row">
                <div class="col-md-6 form-group mb-2">
                  <label class="small font-weight-bold text-dark mb-1">Purchase / Cost Price (PKR)</label>
                  <input type="number" step="0.01" name="purchase_price" id="ob_purchase_price" class="form-control form-control-sm" placeholder="0.00">
                </div>
                <div class="col-md-6 form-group mb-2">
                  <label class="small font-weight-bold text-dark mb-1">Sale Price (PKR)</label>
                  <input type="number" step="0.01" name="sale_price" id="ob_sale_price" class="form-control form-control-sm" placeholder="0.00">
                </div>
              </div>

              <!-- Mobile IMEI Section -->
              <div id="ob_mobile_section" style="display:none;" class="border rounded p-2 bg-light mb-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <label class="font-weight-bold text-primary small mb-0"><i class="fas fa-mobile-alt mr-1"></i> Mobile IMEIs (15 Digits)</label>
                  <div>
                    <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2" id="ob_add_imei_row_btn" style="font-size:11px;">
                      <i class="fas fa-plus mr-1"></i> Add Row
                    </button>
                  </div>
                </div>
                <small class="text-muted d-block mb-2">Optionally enter IMEI 1 (and optional IMEI 2) for tracking individual units.</small>

                <div id="ob_imei_rows">
                  <div class="row imei-row mb-1">
                    <div class="col-6">
                      <input type="text" name="mobile_imei1[]" class="form-control form-control-sm ob-imei1-input" placeholder="IMEI 1 (15 digits)" maxlength="15">
                    </div>
                    <div class="col-5">
                      <input type="text" name="mobile_imei2[]" class="form-control form-control-sm" placeholder="IMEI 2 (Optional)" maxlength="15">
                    </div>
                    <div class="col-1 p-0 text-center">
                      <button type="button" class="btn btn-sm btn-link text-danger p-0 ob-remove-imei" title="Remove"><i class="fas fa-times"></i></button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Laptop Serial Section -->
              <div id="ob_laptop_section" style="display:none;" class="border rounded p-2 bg-light mb-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <label class="font-weight-bold text-info small mb-0"><i class="fas fa-laptop mr-1"></i> Laptop Serials / Specifications</label>
                  <button type="button" class="btn btn-xs btn-outline-info py-0 px-2" id="ob_add_laptop_row_btn" style="font-size:11px;">
                    <i class="fas fa-plus mr-1"></i> Add Row
                  </button>
                </div>
                <div id="ob_laptop_rows">
                  <div class="row laptop-row mb-1">
                    <div class="col-6">
                      <input type="text" name="laptop_serial[]" class="form-control form-control-sm" placeholder="Serial Number">
                    </div>
                    <div class="col-5">
                      <input type="text" name="laptop_notes[]" class="form-control form-control-sm" placeholder="Details (e.g. 8GB/256GB SSD)">
                    </div>
                    <div class="col-1 p-0 text-center">
                      <button type="button" class="btn btn-sm btn-link text-danger p-0 ob-remove-laptop" title="Remove"><i class="fas fa-times"></i></button>
                    </div>
                  </div>
                </div>
              </div>

              <div class="form-group mb-3">
                <label class="small font-weight-bold text-dark mb-1">Notes / Remarks</label>
                <input type="text" name="notes" class="form-control form-control-sm" value="Opening Stock" placeholder="e.g. Initial inventory balance">
              </div>

              <div class="text-right">
                <button type="button" class="btn btn-sm btn-secondary mr-1" data-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-success font-weight-bold px-3">
                  <i class="fas fa-check-circle mr-1"></i> Save Opening Stock
                </button>
              </div>
            </form>
          </div>

          <!-- TAB 2: NEW PRODUCT -->
          <div class="tab-pane fade" id="tab-new" role="tabpanel">
            <form method="post" id="formNewOpeningStock">
              <input type="hidden" name="action" value="save_opening_stock">
              <input type="hidden" name="entry_type" value="new">

              <!-- Product Type -->
              <div class="form-group mb-2">
                <label class="font-weight-bold text-dark small mb-1">Product Type <span class="text-danger">*</span></label>
                <div class="d-flex">
                  <div class="custom-control custom-radio mr-3">
                    <input type="radio" id="np_type_gen" name="new_type" value="general" class="custom-control-input np-type-radio" checked>
                    <label class="custom-control-label small" for="np_type_gen"><i class="fas fa-box mr-1"></i> General Accessory</label>
                  </div>
                  <div class="custom-control custom-radio mr-3">
                    <input type="radio" id="np_type_mob" name="new_type" value="mobile" class="custom-control-input np-type-radio">
                    <label class="custom-control-label small" for="np_type_mob"><i class="fas fa-mobile-alt mr-1"></i> Mobile Phone</label>
                  </div>
                  <div class="custom-control custom-radio">
                    <input type="radio" id="np_type_lap" name="new_type" value="laptop" class="custom-control-input np-type-radio">
                    <label class="custom-control-label small" for="np_type_lap"><i class="fas fa-laptop mr-1"></i> Laptop</label>
                  </div>
                </div>
              </div>

              <!-- Name & Code -->
              <div class="row">
                <div class="col-md-7 form-group mb-2">
                  <label class="font-weight-bold text-dark small mb-1">Product Name <span class="text-danger">*</span></label>
                  <input type="text" name="new_name" class="form-control form-control-sm" placeholder="e.g. Type-C Fast Charger / iPhone 13" required>
                </div>
                <div class="col-md-5 form-group mb-2">
                  <label class="font-weight-bold text-dark small mb-1">Item Code</label>
                  <input type="text" name="new_code" id="np_code" class="form-control form-control-sm" value="<?= $next_code_general ?>">
                </div>
              </div>

              <!-- Category & Brand -->
              <div class="row">
                <div class="col-md-6 form-group mb-2">
                  <label class="small font-weight-bold text-dark mb-1">Category</label>
                  <select name="new_category_id" class="form-control form-control-sm">
                    <option value="">-- None --</option>
                    <?php foreach ($all_categories as $c): ?>
                      <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= ucfirst($c['product_type']) ?>)</option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6 form-group mb-2">
                  <label class="small font-weight-bold text-dark mb-1">Brand</label>
                  <select name="new_brand_id" class="form-control form-control-sm">
                    <option value="">-- None --</option>
                    <?php foreach ($brands as $b): ?>
                      <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <!-- Quantity & Prices -->
              <div class="row">
                <div class="col-md-3 form-group mb-2">
                  <label class="font-weight-bold text-dark small mb-1">Opening Qty <span class="text-danger">*</span></label>
                  <input type="number" name="new_quantity" id="np_quantity" class="form-control form-control-sm font-weight-bold" min="0" value="1" required>
                </div>
                <div class="col-md-3 form-group mb-2">
                  <label class="font-weight-bold text-dark small mb-1">Purchase Price</label>
                  <input type="number" step="0.01" name="new_purchase_price" class="form-control form-control-sm" value="0.00">
                </div>
                <div class="col-md-3 form-group mb-2">
                  <label class="font-weight-bold text-dark small mb-1">Sale Price</label>
                  <input type="number" step="0.01" name="new_sale_price" class="form-control form-control-sm" value="0.00">
                </div>
                <div class="col-md-3 form-group mb-2">
                  <label class="font-weight-bold text-dark small mb-1">Low Stock Alert</label>
                  <input type="number" name="new_min_stock_level" class="form-control form-control-sm" value="2">
                </div>
              </div>

              <!-- Device Specs (Mobile / Laptop) -->
              <div id="np_specs_section" style="display:none;" class="border rounded p-2 bg-light mb-2">
                <h6 class="small font-weight-bold text-primary mb-2"><i class="fas fa-sliders-h mr-1"></i> Specifications</h6>
                <div class="row">
                  <div class="col-4 form-group mb-1">
                    <label class="small text-muted mb-0">Condition</label>
                    <select name="new_condition" class="form-control form-control-sm">
                      <option value="New">New</option>
                      <option value="Used">Used</option>
                      <option value="Refurbished">Refurbished</option>
                    </select>
                  </div>
                  <div class="col-4 form-group mb-1">
                    <label class="small text-muted mb-0">Color</label>
                    <input type="text" name="new_color" class="form-control form-control-sm" placeholder="Black / Blue">
                  </div>
                  <div class="col-4 form-group mb-1">
                    <label class="small text-muted mb-0">Storage</label>
                    <input type="text" name="new_storage" class="form-control form-control-sm" placeholder="128GB / 256GB">
                  </div>
                  <div class="col-4 form-group mb-1">
                    <label class="small text-muted mb-0">RAM</label>
                    <input type="text" name="new_ram" class="form-control form-control-sm" placeholder="4GB / 8GB">
                  </div>
                  <div class="col-4 form-group mb-1">
                    <label class="small text-muted mb-0">Processor</label>
                    <input type="text" name="new_processor" class="form-control form-control-sm" placeholder="i5 / Snapdragon">
                  </div>
                  <div class="col-4 form-group mb-1">
                    <label class="small text-muted mb-0">Screen Size</label>
                    <input type="text" name="new_screen_size" class="form-control form-control-sm" placeholder="6.5&quot; / 15.6&quot;">
                  </div>
                </div>
              </div>

              <!-- Mobile IMEIs for New Product -->
              <div id="np_mobile_section" style="display:none;" class="border rounded p-2 bg-light mb-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <label class="font-weight-bold text-primary small mb-0"><i class="fas fa-mobile-alt mr-1"></i> Mobile IMEIs (15 Digits)</label>
                  <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2" id="np_add_imei_row_btn" style="font-size:11px;">
                    <i class="fas fa-plus mr-1"></i> Add Row
                  </button>
                </div>
                <div id="np_imei_rows">
                  <div class="row imei-row mb-1">
                    <div class="col-6">
                      <input type="text" name="new_mobile_imei1[]" class="form-control form-control-sm np-imei1-input" placeholder="IMEI 1 (15 digits)" maxlength="15">
                    </div>
                    <div class="col-5">
                      <input type="text" name="new_mobile_imei2[]" class="form-control form-control-sm" placeholder="IMEI 2 (Optional)" maxlength="15">
                    </div>
                    <div class="col-1 p-0 text-center">
                      <button type="button" class="btn btn-sm btn-link text-danger p-0 ob-remove-imei" title="Remove"><i class="fas fa-times"></i></button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Laptop Serials for New Product -->
              <div id="np_laptop_section" style="display:none;" class="border rounded p-2 bg-light mb-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <label class="font-weight-bold text-info small mb-0"><i class="fas fa-laptop mr-1"></i> Laptop Serials</label>
                  <button type="button" class="btn btn-xs btn-outline-info py-0 px-2" id="np_add_laptop_row_btn" style="font-size:11px;">
                    <i class="fas fa-plus mr-1"></i> Add Row
                  </button>
                </div>
                <div id="np_laptop_rows">
                  <div class="row laptop-row mb-1">
                    <div class="col-11">
                      <input type="text" name="new_laptop_serial[]" class="form-control form-control-sm" placeholder="Serial Number">
                    </div>
                    <div class="col-1 p-0 text-center">
                      <button type="button" class="btn btn-sm btn-link text-danger p-0 ob-remove-laptop" title="Remove"><i class="fas fa-times"></i></button>
                    </div>
                  </div>
                </div>
              </div>

              <div class="form-group mb-3">
                <label class="small font-weight-bold text-dark mb-1">Notes / Description</label>
                <input type="text" name="new_notes" class="form-control form-control-sm" value="Opening Stock" placeholder="e.g. Initial stock">
              </div>

              <div class="text-right">
                <button type="button" class="btn btn-sm btn-secondary mr-1" data-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary font-weight-bold px-3">
                  <i class="fas fa-plus-circle mr-1"></i> Create Product &amp; Save Stock
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function typeBadge(t) {
    var map = { mobile: ['primary','fa-mobile-alt'], laptop: ['info','fa-laptop'], general: ['secondary','fa-box'] };
    var m = map[t] || ['secondary','fa-box'];
    return '<span class="badge badge-' + m[0] + '"><i class="fas ' + m[1] + ' mr-1"></i>' + esc(t.charAt(0).toUpperCase() + t.slice(1)) + '</span>';
}
function serialBadge(st) {
    if (st === 'sold') return '<span class="badge badge-secondary">Sold</span>';
    if (st === 'returned') return '<span class="badge badge-warning">Returned</span>';
    return '<span class="badge badge-success">Available</span>';
}
function renderViewModal(d) {
    var p = d.product;
    if (!p) { $('#viewModalBody').html('<div class="alert alert-danger mb-0">' + esc(d.error || 'Data not found') + '</div>'); return; }
    var rows = [];
    var info = {
        'Category': p.category_name, 'Brand': p.brand_name, 'Supplier': p.supplier_name,
        'Condition': p.product_condition, 'Color': p.color, 'Storage': p.storage, 'RAM': p.ram,
        'Processor': p.processor, 'Screen Size': p.screen_size, 'Graphics': p.graphics,
        'Warranty': p.warranty_months ? p.warranty_months + ' months' : null,
        'Unit': p.unit
    };
    Object.keys(info).forEach(function(k) {
        if (info[k] !== null && info[k] !== undefined && String(info[k]).trim() !== '' && String(info[k]) !== '0') {
            rows.push('<div class="col-6 col-md-4"><div class="pv-box"><div class="pv-label">' + k + '</div><div class="pv-value">' + esc(info[k]) + '</div></div></div>');
        }
    });
    var pricing = {
        'Purchase Price': p.purchase_price, 'Sale Price': p.sale_price,
        'Stock': p.stock_quantity, 'Opening Stock': p.opening_stock || 0, 'Min Stock': p.min_stock_level
    };
    if (p.product_type === 'mobile' || p.product_type === 'laptop') delete pricing['Purchase Price'];
    Object.keys(pricing).forEach(function(k) {
        var v = pricing[k];
        rows.push('<div class="col-6 col-md-4"><div class="pv-box"><div class="pv-label">' + k + '</div><div class="pv-value">' + (k === 'Stock' || k === 'Opening Stock' || k === 'Min Stock' ? esc(v) : esc(parseFloat(v).toFixed(2))) + '</div></div></div>');
    });

    var html = '';
    html += '<div class="d-flex align-items-center justify-content-between mb-3">';
    html += '<div><span class="h5 font-weight-bold" style="color:#0f172a;">' + esc(p.name) + '</span><div><code>' + esc(p.code) + '</code> ' + typeBadge(p.product_type) + ' <span class="badge badge-' + (p.status ? 'success' : 'secondary') + '">' + (p.status ? 'Active' : 'Inactive') + '</span></div></div>';
    html += '<span class="badge badge-primary pv-count"><i class="fas fa-cubes mr-1"></i>' + esc(p.stock_quantity) + ' in stock</span>';
    html += '</div>';

    html += '<div class="row pv-grid mb-3">' + rows.join('') + '</div>';

    if (p.product_type === 'mobile') {
        var units = d.serials || [];
        html += '<div class="pv-section-title"><i class="fas fa-mobile-alt mr-1"></i> Units / IMEI (' + units.length + ')</div>';
        if (units.length === 0) {
            html += '<div class="alert alert-info small mb-0">No unit / IMEI records found. Please add IMEI during purchase entry.</div>';
        } else {
            html += '<div class="table-responsive"><table class="table table-sm table-bordered pv-serial-table mb-0"><thead class="thead-light"><tr><th>#</th><th>IMEI 1</th><th>IMEI 2</th><th>Purchase Price</th><th>Status</th><th>Purchase Inv</th><th>Sale Inv</th></tr></thead><tbody>';
            units.forEach(function(u, i) {
                html += '<tr><td>' + (i + 1) + '</td>' +
                    '<td><code>' + esc(u.imei_number || '-') + '</code>' +
                    (u.imei_number && u.imei_number !== "-" ?
                        '<div class="mt-1"><svg class="imei-barcode" data-barcode="' + esc(u.imei_number) + '" data-width="0.9" data-height="22" data-font-size="8"></svg></div>' +
                        '<button type="button" class="btn btn-xs btn-outline-primary py-0 px-1 mt-1" style="font-size:10px;" onclick="printImeiBarcodeSticker(\x27' + esc(u.imei_number) + '\x27, \x27' + esc(p.name) + '\x27, \x27IMEI 1\x27)"><i class="fas fa-barcode"></i> Sticker</button>' : "") +
                    '</td>' +
                    '<td><code>' + esc(u.serial_number || '-') + '</code>' +
                    (u.serial_number && u.serial_number !== "-" ?
                        '<div class="mt-1"><svg class="imei-barcode" data-barcode="' + esc(u.serial_number) + '" data-width="0.9" data-height="22" data-font-size="8"></svg></div>' : "") +
                    '</td>' +
                    '<td class="text-right">' + esc(parseFloat(u.purchase_price || 0).toFixed(2)) + '</td>' +
                    '<td>' + serialBadge(u.status) + '</td>' +
                    '<td>' + esc(u.purchase_invoice || '-') + '</td>' +
                    '<td>' + esc(u.sale_invoice || '-') + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }
    } else if (p.product_type === 'laptop') {
        html += '<div class="pv-section-title"><i class="fas fa-laptop mr-1"></i> Specifications</div>';
        var lap = { 'Storage': p.storage, 'RAM': p.ram, 'Processor': p.processor, 'Screen Size': p.screen_size, 'Graphics': p.graphics, 'Color': p.color, 'Condition': p.product_condition };
        var any = Object.keys(lap).some(function(k) { return lap[k] !== null && String(lap[k]).trim() !== ''; });
        if (any) {
            html += '<div class="row pv-grid mb-2">';
            Object.keys(lap).forEach(function(k) {
                if (lap[k] !== null && String(lap[k]).trim() !== '') {
                    html += '<div class="col-6 col-md-4"><div class="pv-box"><div class="pv-label">' + k + '</div><div class="pv-value">' + esc(lap[k]) + '</div></div></div>';
                }
            });
            html += '</div>';
        } else {
            html += '<div class="alert alert-info small mb-0">No detailed specifications recorded for this laptop.</div>';
        }
        var units = d.serials || [];
        html += '<div class="pv-section-title"><i class="fas fa-laptop mr-1"></i> Units (' + units.length + ')</div>';
        if (units.length === 0) {
            html += '<div class="alert alert-info small mb-0">No unit records found.</div>';
        } else {
            html += '<div class="table-responsive"><table class="table table-sm table-bordered pv-serial-table mb-0"><thead class="thead-light"><tr><th>#</th><th>Serial / Details</th><th>Purchase Price</th><th>Status</th><th>Purchase Inv</th><th>Sale Inv</th></tr></thead><tbody>';
            units.forEach(function(u, i) {
                var det = u.notes || u.serial_number || '-';
                html += '<tr><td>' + (i + 1) + '</td>' +
                    '<td><small>' + esc(det) + '</small></td>' +
                    '<td class="text-right">' + esc(parseFloat(u.purchase_price || 0).toFixed(2)) + '</td>' +
                    '<td>' + serialBadge(u.status) + '</td>' +
                    '<td>' + esc(u.purchase_invoice || '-') + '</td>' +
                    '<td>' + esc(u.sale_invoice || '-') + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }
    }

    $('#viewModalBody').html(html);
}

$(document).on('click', '.btn-product-view', function() {
    var pid = $(this).data('product-id');
    var pname = $(this).data('product-name');
    $('#viewModalTitle').text('Product Details - ' + pname);
    $('#viewModalBody').html('<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin fa-2x d-block mb-2"></i>Loading...</div>');
    $('#viewModal').modal('show');
    $.getJSON('product_view.php', { product_id: pid }, function(data) {
        renderViewModal(data);
    }).fail(function() {
        $('#viewModalBody').html('<div class="alert alert-danger mb-0">Failed to load product details</div>');
    });
});

$(document).on('click', '.btn-purchase-history', function() {
    var pid = $(this).data('product-id');
    var pname = $(this).data('product-name');
    $('#historyModalTitle').text('Purchase History - ' + pname);
    $('#historyBody').html('<tr><td colspan="5" class="text-center text-muted py-3">Loading...</td></tr>');
    $('#historyModal').modal('show');
    $.getJSON('product_purchases.php', { product_id: pid }, function(data) {
        if (!data || data.length === 0) {
            $('#historyBody').html('<tr><td colspan="5" class="text-center text-muted py-3">No purchase records found</td></tr>');
            return;
        }
        var html = '';
        data.forEach(function(r) {
            var who = r.supplier_name || r.supplier_contact || r.person_name || '-';
            html += '<tr>' +
                '<td>' + (r.purchase_date || '-') + '</td>' +
                '<td>' + who + '</td>' +
                '<td>' + (r.invoice_no || '-') + '</td>' +
                '<td class="text-center">' + (r.quantity || 0) + '</td>' +
                '<td class="text-right">' + parseFloat(r.purchase_price || 0).toFixed(2) + '</td>' +
                '</tr>';
        });
        $('#historyBody').html(html);
    }).fail(function() {
        $('#historyBody').html('<tr><td colspan="5" class="text-center text-muted py-3">Failed to load history</td></tr>');
    });
});

// Opening Stock Modal Logic
var nextProductCodes = {
    general: '<?= $next_code_general ?>',
    mobile: '<?= $next_code_mobile ?>',
    laptop: '<?= $next_code_laptop ?>'
};

// Filter products in Existing tab
$('#ob_product_filter').on('input', function() {
    var term = $(this).val().toLowerCase().trim();
    $('#ob_product_id option').each(function() {
        if (!$(this).val()) return;
        var text = $(this).text().toLowerCase();
        $(this).toggle(text.indexOf(term) > -1);
    });
});

// Select product in Existing tab
$('#ob_product_id').on('change', function() {
    var opt = $(this).find(':selected');
    if (!opt.val()) {
        $('#ob_product_card').hide();
        $('#ob_mobile_section, #ob_laptop_section').hide();
        return;
    }
    var ptype = opt.data('type') || 'general';
    var pcode = opt.data('code') || '';
    var pname = opt.data('name') || '';
    var pstock = opt.data('stock') || 0;
    var ppurchase = parseFloat(opt.data('purchase') || 0);
    var psale = parseFloat(opt.data('sale') || 0);

    $('#ob_card_name').text(pname);
    $('#ob_card_code').text(pcode);
    $('#ob_card_type').html(typeBadge(ptype));
    $('#ob_card_stock').text(pstock);
    $('#ob_product_card').slideDown(150);

    $('#ob_purchase_price').val(ppurchase > 0 ? ppurchase.toFixed(2) : '');
    $('#ob_sale_price').val(psale > 0 ? psale.toFixed(2) : '');

    if (ptype === 'mobile') {
        $('#ob_mobile_section').slideDown(150);
        $('#ob_laptop_section').hide();
    } else if (ptype === 'laptop') {
        $('#ob_laptop_section').slideDown(150);
        $('#ob_mobile_section').hide();
    } else {
        $('#ob_mobile_section, #ob_laptop_section').slideUp(150);
    }
});

// Add IMEI row in Existing tab
$('#ob_add_imei_row_btn').on('click', function() {
    var row = '<div class="row imei-row mb-1">' +
        '<div class="col-6"><input type="text" name="mobile_imei1[]" class="form-control form-control-sm ob-imei1-input" placeholder="IMEI 1 (15 digits)" maxlength="15"></div>' +
        '<div class="col-5"><input type="text" name="mobile_imei2[]" class="form-control form-control-sm" placeholder="IMEI 2 (Optional)" maxlength="15"></div>' +
        '<div class="col-1 p-0 text-center"><button type="button" class="btn btn-sm btn-link text-danger p-0 ob-remove-imei" title="Remove"><i class="fas fa-times"></i></button></div>' +
        '</div>';
    $('#ob_imei_rows').append(row);
});

// Remove IMEI row
$(document).on('click', '.ob-remove-imei', function() {
    var container = $(this).closest('#ob_imei_rows, #np_imei_rows');
    var rows = container.find('.imei-row');
    if (rows.length > 1) {
        $(this).closest('.imei-row').remove();
    } else {
        $(this).closest('.imei-row').find('input').val('');
    }
});

// Add Laptop row in Existing tab
$('#ob_add_laptop_row_btn').on('click', function() {
    var row = '<div class="row laptop-row mb-1">' +
        '<div class="col-6"><input type="text" name="laptop_serial[]" class="form-control form-control-sm" placeholder="Serial Number"></div>' +
        '<div class="col-5"><input type="text" name="laptop_notes[]" class="form-control form-control-sm" placeholder="Details (e.g. 8GB/256GB SSD)"></div>' +
        '<div class="col-1 p-0 text-center"><button type="button" class="btn btn-sm btn-link text-danger p-0 ob-remove-laptop" title="Remove"><i class="fas fa-times"></i></button></div>' +
        '</div>';
    $('#ob_laptop_rows').append(row);
});

// Remove Laptop row
$(document).on('click', '.ob-remove-laptop', function() {
    var container = $(this).closest('#ob_laptop_rows, #np_laptop_rows');
    var rows = container.find('.laptop-row');
    if (rows.length > 1) {
        $(this).closest('.laptop-row').remove();
    } else {
        $(this).closest('.laptop-row').find('input').val('');
    }
});

// New Product tab - Type Radio change
$('.np-type-radio').on('change', function() {
    var type = $(this).val();
    $('#np_code').val(nextProductCodes[type] || '');

    if (type === 'mobile') {
        $('#np_specs_section').slideDown(150);
        $('#np_mobile_section').slideDown(150);
        $('#np_laptop_section').hide();
    } else if (type === 'laptop') {
        $('#np_specs_section').slideDown(150);
        $('#np_laptop_section').slideDown(150);
        $('#np_mobile_section').hide();
    } else {
        $('#np_specs_section').slideUp(150);
        $('#np_mobile_section').slideUp(150);
        $('#np_laptop_section').slideUp(150);
    }
});

// Add IMEI row in New Product tab
$('#np_add_imei_row_btn').on('click', function() {
    var row = '<div class="row imei-row mb-1">' +
        '<div class="col-6"><input type="text" name="new_mobile_imei1[]" class="form-control form-control-sm np-imei1-input" placeholder="IMEI 1 (15 digits)" maxlength="15"></div>' +
        '<div class="col-5"><input type="text" name="new_mobile_imei2[]" class="form-control form-control-sm" placeholder="IMEI 2 (Optional)" maxlength="15"></div>' +
        '<div class="col-1 p-0 text-center"><button type="button" class="btn btn-sm btn-link text-danger p-0 ob-remove-imei" title="Remove"><i class="fas fa-times"></i></button></div>' +
        '</div>';
    $('#np_imei_rows').append(row);
});

// Add Laptop row in New Product tab
$('#np_add_laptop_row_btn').on('click', function() {
    var row = '<div class="row laptop-row mb-1">' +
        '<div class="col-11"><input type="text" name="new_laptop_serial[]" class="form-control form-control-sm" placeholder="Serial Number"></div>' +
        '<div class="col-1 p-0 text-center"><button type="button" class="btn btn-sm btn-link text-danger p-0 ob-remove-laptop" title="Remove"><i class="fas fa-times"></i></button></div>' +
        '</div>';
    $('#np_laptop_rows').append(row);
});

// Form submission validation for IMEI length
$('#formExistingOpeningStock').on('submit', function(e) {
    var invalid = false;
    $(this).find('.ob-imei1-input').each(function() {
        var v = $(this).val().trim();
        if (v !== '' && !/^\d{15}$/.test(v)) {
            alert('IMEI "' + v + '" must be exactly 15 digits.');
            $(this).focus();
            invalid = true;
            return false;
        }
    });
    if (invalid) e.preventDefault();
});

$('#formNewOpeningStock').on('submit', function(e) {
    var invalid = false;
    $(this).find('.np-imei1-input').each(function() {
        var v = $(this).val().trim();
        if (v !== '' && !/^\d{15}$/.test(v)) {
            alert('IMEI "' + v + '" must be exactly 15 digits.');
            $(this).focus();
            invalid = true;
            return false;
        }
    });
    if (invalid) e.preventDefault();
});
</script>

<?php require_once '../../includes/footer.php'; ?>
