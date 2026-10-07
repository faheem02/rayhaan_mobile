<?php
session_start();
$page_title = 'New Product';
$base_url = '../../';
require_once '../../includes/functions.php';

// Ensure opening_stock column exists in database
try {
    $pdo->query("SELECT opening_stock FROM products LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE products ADD COLUMN opening_stock INT NOT NULL DEFAULT 0 AFTER sale_price");
    } catch (Exception $ignored) {}
}

$brands = getAll('brands', 'name ASC');
$suppliers = getAll('suppliers', 'name ASC');
$general_categories = $pdo->query("SELECT * FROM categories WHERE product_type = 'general' AND status = 1 ORDER BY name ASC")->fetchAll();

$mobile_category_id = (int)($pdo->query("SELECT id FROM categories WHERE product_type = 'mobile' LIMIT 1")->fetchColumn() ?: 1);
$laptop_category_id = (int)($pdo->query("SELECT id FROM categories WHERE product_type = 'laptop' LIMIT 1")->fetchColumn() ?: 2);

$existing_mobiles = $pdo->query("SELECT id, code, name, brand_id, purchase_price, sale_price, storage, ram, color, product_condition FROM products WHERE product_type='mobile' AND status=1 ORDER BY name ASC")->fetchAll();
$existing_laptops = $pdo->query("SELECT id, code, name, brand_id, purchase_price, sale_price, ram, storage, color, processor, screen_size, product_condition FROM products WHERE product_type='laptop' AND status=1 ORDER BY name ASC")->fetchAll();
$existing_generals = $pdo->query("SELECT id, code, name, category_id, brand_id, purchase_price, sale_price, stock_quantity, unit FROM products WHERE product_type='general' AND status=1 ORDER BY name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_type = in_array($_POST['product_type'] ?? '', ['mobile', 'laptop', 'general'], true) ? $_POST['product_type'] : 'mobile';
    $supplier_id = (int)($_POST['supplier_id'] ?? 0) ?: null;

    if ($product_type === 'mobile') {
        $model_sel = $_POST['mobile_model'] ?? 'new';
        $is_new = ($model_sel === 'new' || empty($model_sel));

        if ($is_new) {
            $name = trim($_POST['new_mobile_name'] ?? '');
            if ($name === '') {
                $_SESSION['error'] = 'Mobile Model Name is required.';
                header("Location: product_create.php?type=mobile");
                exit;
            }
            $brand_id = (int)($_POST['new_mobile_brand_id'] ?? 0) ?: null;
            $condition = $_POST['new_mobile_condition'] ?? 'New';
            $sale_price = max(0, (float)($_POST['new_mobile_sale_price'] ?? 0));
            $code = generateProductCode('mobile');
        } else {
            $product_id = (int)$model_sel;
            $prod = getById('products', $product_id);
            if (!$prod) {
                $_SESSION['error'] = 'Selected mobile model not found.';
                header("Location: product_create.php?type=mobile");
                exit;
            }
            $name = $prod['name'];
            $brand_id = $prod['brand_id'];
            $condition = $_POST['mobile_condition'] ?? $prod['product_condition'] ?? 'New';
            $sale_price = (float)$prod['sale_price'];
            $code = $prod['code'];
        }

        // Parse mobile IMEI rows
        $imei1s = (array)($_POST['mobile_imei1'] ?? []);
        $imei2s = (array)($_POST['mobile_imei2'] ?? []);
        $storages = (array)($_POST['mobile_storage'] ?? []);
        $rams = (array)($_POST['mobile_ram'] ?? []);
        $colors = (array)($_POST['mobile_color'] ?? []);
        $prices = (array)($_POST['mobile_price'] ?? []);

        $valid_units = [];
        $all_imeis = [];

        for ($i = 0; $i < count($imei1s); $i++) {
            $i1 = trim($imei1s[$i] ?? '');
            $i2 = trim($imei2s[$i] ?? '');
            $st = trim($storages[$i] ?? '');
            $ra = trim($rams[$i] ?? '');
            $co = trim($colors[$i] ?? '');
            $pr = max(0, (float)($prices[$i] ?? 0));

            if ($i1 !== '' || $i2 !== '' || $st !== '' || $co !== '' || $pr > 0) {
                $valid_units[] = [
                    'imei1'   => $i1,
                    'imei2'   => $i2,
                    'storage' => $st,
                    'ram'     => $ra,
                    'color'   => $co,
                    'price'   => $pr,
                ];
                if ($i1 !== '') $all_imeis[] = $i1;
                if ($i2 !== '') $all_imeis[] = $i2;
            }
        }

        if (empty($valid_units)) {
            $_SESSION['error'] = 'Please enter at least one mobile unit.';
            header("Location: product_create.php?type=mobile");
            exit;
        }

        // Validate IMEIs format (15 digits)
        foreach ($all_imeis as $im) {
            if (!preg_match('/^\d{15}$/', $im)) {
                $_SESSION['error'] = "IMEI '$im' must be exactly 15 digits.";
                header("Location: product_create.php?type=mobile");
                exit;
            }
        }

        // Reject duplicates within this form submission
        $counts = array_count_values($all_imeis);
        foreach ($counts as $im => $cnt) {
            if ($cnt > 1) {
                $_SESSION['error'] = "Duplicate IMEI '$im' found in entry.";
                header("Location: product_create.php?type=mobile");
                exit;
            }
        }

        // Check if IMEI already exists in database
        if (!empty($all_imeis)) {
            $in_clause = implode(',', array_fill(0, count($all_imeis), '?'));
            $chk = $pdo->prepare("SELECT imei_number, serial_number FROM product_serials WHERE imei_number IN ($in_clause) OR serial_number IN ($in_clause)");
            $chk->execute(array_merge($all_imeis, $all_imeis));
            $found = $chk->fetchAll();
            if (!empty($found)) {
                $dup = $found[0]['imei_number'] ?: $found[0]['serial_number'];
                $_SESSION['error'] = "IMEI '$dup' already exists in the system.";
                header("Location: product_create.php?type=mobile");
                exit;
            }
        }

        $quantity = count($valid_units);
        $total_cost = array_sum(array_column($valid_units, 'price'));
        $avg_price = $quantity > 0 ? ($total_cost / $quantity) : 0;

        // Representative specs from first row
        $first_row = $valid_units[0];
        $rep_color = $first_row['color'] ?: null;
        $rep_storage = $first_row['storage'] ?: null;
        $rep_ram = $first_row['ram'] ?: null;

        if ($is_new) {
            $product_id = insert('products', [
                'name'              => $name,
                'code'              => $code,
                'description'       => 'Opening Stock Entry',
                'category_id'       => $mobile_category_id,
                'brand_id'          => $brand_id,
                'supplier_id'       => $supplier_id,
                'product_type'      => 'mobile',
                'color'             => $rep_color,
                'storage'           => $rep_storage,
                'ram'               => $rep_ram,
                'processor'         => null,
                'screen_size'       => null,
                'graphics'          => null,
                'warranty_months'   => null,
                'product_condition' => $condition,
                'purchase_price'    => $avg_price,
                'sale_price'        => $sale_price,
                'opening_stock'     => $quantity,
                'stock_quantity'    => $quantity,
                'min_stock_level'   => 0,
                'unit'              => 'pcs',
                'has_serial'        => 1,
                'status'            => 1,
                'created_at'        => date('Y-m-d'),
                'updated_at'        => date('Y-m-d'),
            ]);
        } else {
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ?, opening_stock = opening_stock + ?, updated_at = CURDATE() WHERE id = ?")
                ->execute([$quantity, $quantity, $product_id]);
        }

        // Insert serials
        $stmt = $pdo->prepare("INSERT INTO product_serials (product_id, imei_number, serial_number, purchase_price, purchase_id, status, notes, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, 'available', ?, CURDATE(), CURDATE())");
        foreach ($valid_units as $u) {
            $note = trim("Mobile - " . ($u['color'] ?: '') . " - " . ($u['storage'] ?: '') . ($u['ram'] ? "/{$u['ram']}" : '') . " - {$condition} (Opening Stock)", " -");
            $stmt->execute([
                $product_id,
                $u['imei1'] ?: null,
                $u['imei2'] ?: null,
                $u['price'] ?: $avg_price,
                $note
            ]);
        }

        // Activity log
        try {
            $pdo->prepare("INSERT INTO activity_logs (user_id, action, module, reference_id, description, ip_address, created_at) VALUES (?, 'Opening Stock (Mobile)', 'inventory', ?, ?, ?, CURDATE())")
                ->execute([$_SESSION['user_id'] ?? null, $product_id, "Added {$quantity} mobile unit(s) opening stock for '{$name}'", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
        } catch (Exception $ignored) {}

        $_SESSION['success'] = "Added {$quantity} mobile unit(s) with opening stock for '{$name}' successfully!";
        header("Location: products.php");
        exit;

    } elseif ($product_type === 'laptop') {
        $model_sel = $_POST['laptop_model'] ?? 'new';
        $is_new = ($model_sel === 'new' || empty($model_sel));

        if ($is_new) {
            $name = trim($_POST['new_laptop_name'] ?? '');
            if ($name === '') {
                $_SESSION['error'] = 'Laptop Model Name is required.';
                header("Location: product_create.php?type=laptop");
                exit;
            }
            $brand_id = (int)($_POST['new_laptop_brand_id'] ?? 0) ?: null;
            $condition = $_POST['new_laptop_condition'] ?? 'New';
            $sale_price = max(0, (float)($_POST['new_laptop_sale_price'] ?? 0));
            $code = generateProductCode('laptop');
        } else {
            $product_id = (int)$model_sel;
            $prod = getById('products', $product_id);
            if (!$prod) {
                $_SESSION['error'] = 'Selected laptop model not found.';
                header("Location: product_create.php?type=laptop");
                exit;
            }
            $name = $prod['name'];
            $brand_id = $prod['brand_id'];
            $condition = $_POST['laptop_condition'] ?? $prod['product_condition'] ?? 'New';
            $sale_price = (float)$prod['sale_price'];
            $code = $prod['code'];
        }

        // Parse laptop rows
        $colors = (array)($_POST['laptop_color'] ?? []);
        $storages = (array)($_POST['laptop_storage'] ?? []);
        $rams = (array)($_POST['laptop_ram'] ?? []);
        $processors = (array)($_POST['laptop_processor'] ?? []);
        $screens = (array)($_POST['laptop_screen'] ?? []);
        $serials = (array)($_POST['laptop_serial'] ?? []);
        $prices = (array)($_POST['laptop_price'] ?? []);

        $valid_units = [];
        for ($i = 0; $i < count($prices); $i++) {
            $co = trim($colors[$i] ?? '');
            $st = trim($storages[$i] ?? '');
            $ra = trim($rams[$i] ?? '');
            $pr = trim($processors[$i] ?? '');
            $sc = trim($screens[$i] ?? '');
            $sn = trim($serials[$i] ?? '');
            $val = max(0, (float)($prices[$i] ?? 0));

            if ($co !== '' || $st !== '' || $ra !== '' || $pr !== '' || $sn !== '' || $val > 0) {
                $valid_units[] = [
                    'color'     => $co,
                    'storage'   => $st,
                    'ram'       => $ra,
                    'processor' => $pr,
                    'screen'    => $sc,
                    'serial'    => $sn,
                    'price'     => $val,
                ];
            }
        }

        if (empty($valid_units)) {
            $_SESSION['error'] = 'Please enter at least one laptop unit.';
            header("Location: product_create.php?type=laptop");
            exit;
        }

        $quantity = count($valid_units);
        $total_cost = array_sum(array_column($valid_units, 'price'));
        $avg_price = $quantity > 0 ? ($total_cost / $quantity) : 0;

        $first_row = $valid_units[0];

        if ($is_new) {
            $product_id = insert('products', [
                'name'              => $name,
                'code'              => $code,
                'description'       => 'Opening Stock Entry',
                'category_id'       => $laptop_category_id,
                'brand_id'          => $brand_id,
                'supplier_id'       => $supplier_id,
                'product_type'      => 'laptop',
                'color'             => $first_row['color'] ?: null,
                'storage'           => $first_row['storage'] ?: null,
                'ram'               => $first_row['ram'] ?: null,
                'processor'         => $first_row['processor'] ?: null,
                'screen_size'       => $first_row['screen'] ?: null,
                'graphics'          => null,
                'warranty_months'   => null,
                'product_condition' => $condition,
                'purchase_price'    => $avg_price,
                'sale_price'        => $sale_price,
                'opening_stock'     => $quantity,
                'stock_quantity'    => $quantity,
                'min_stock_level'   => 0,
                'unit'              => 'pcs',
                'has_serial'        => 1,
                'status'            => 1,
                'created_at'        => date('Y-m-d'),
                'updated_at'        => date('Y-m-d'),
            ]);
        } else {
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ?, opening_stock = opening_stock + ?, updated_at = CURDATE() WHERE id = ?")
                ->execute([$quantity, $quantity, $product_id]);
        }

        // Insert serials
        $stmt = $pdo->prepare("INSERT INTO product_serials (product_id, imei_number, serial_number, purchase_price, purchase_id, status, notes, created_at, updated_at) VALUES (?, NULL, ?, ?, NULL, 'available', ?, CURDATE(), CURDATE())");
        foreach ($valid_units as $u) {
            $note = trim("Laptop - " . ($u['color'] ?: '') . " - " . ($u['storage'] ?: '') . " - " . ($u['ram'] ?: '') . " - " . ($u['processor'] ?: '') . " - " . ($u['screen'] ?: '') . " - {$condition} (Opening Stock)", " -");
            $stmt->execute([
                $product_id,
                $u['serial'] ?: null,
                $u['price'] ?: $avg_price,
                $note
            ]);
        }

        try {
            $pdo->prepare("INSERT INTO activity_logs (user_id, action, module, reference_id, description, ip_address, created_at) VALUES (?, 'Opening Stock (Laptop)', 'inventory', ?, ?, ?, CURDATE())")
                ->execute([$_SESSION['user_id'] ?? null, $product_id, "Added {$quantity} laptop unit(s) opening stock for '{$name}'", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
        } catch (Exception $ignored) {}

        $_SESSION['success'] = "Added {$quantity} laptop unit(s) with opening stock for '{$name}' successfully!";
        header("Location: products.php");
        exit;

    } elseif ($product_type === 'general') {
        $prod_sel = $_POST['general_product'] ?? 'new';
        $is_new = ($prod_sel === 'new' || empty($prod_sel));
        $quantity = max(1, (int)($_POST['general_quantity'] ?? 1));

        if ($is_new) {
            $name = trim($_POST['new_general_name'] ?? '');
            if ($name === '') {
                $_SESSION['error'] = 'Product Name is required.';
                header("Location: product_create.php?type=general");
                exit;
            }
            $cat_id = (int)($_POST['new_general_category_id'] ?? 0) ?: null;
            $brand_id = (int)($_POST['new_general_brand_id'] ?? 0) ?: null;
            $purchase_price = max(0, (float)($_POST['general_purchase_price'] ?? 0));
            $sale_price = max(0, (float)($_POST['general_sale_price'] ?? 0));
            $unit = trim($_POST['general_unit'] ?? 'pcs') ?: 'pcs';
            $min_stock = max(0, (int)($_POST['general_min_stock'] ?? 2));
            $code = generateProductCode('general');

            $product_id = insert('products', [
                'name'              => $name,
                'code'              => $code,
                'description'       => trim($_POST['general_description'] ?? '') ?: 'Opening Stock Entry',
                'category_id'       => $cat_id,
                'brand_id'          => $brand_id,
                'supplier_id'       => $supplier_id,
                'product_type'      => 'general',
                'purchase_price'    => $purchase_price,
                'sale_price'        => $sale_price,
                'opening_stock'     => $quantity,
                'stock_quantity'    => $quantity,
                'min_stock_level'   => $min_stock,
                'unit'              => $unit,
                'has_serial'        => 0,
                'status'            => 1,
                'created_at'        => date('Y-m-d'),
                'updated_at'        => date('Y-m-d'),
            ]);
        } else {
            $product_id = (int)$prod_sel;
            $prod = getById('products', $product_id);
            if (!$prod) {
                $_SESSION['error'] = 'Selected product not found.';
                header("Location: product_create.php?type=general");
                exit;
            }
            $name = $prod['name'];
            $purchase_price = isset($_POST['general_purchase_price']) && $_POST['general_purchase_price'] !== '' ? max(0, (float)$_POST['general_purchase_price']) : (float)$prod['purchase_price'];
            $sale_price = isset($_POST['general_sale_price']) && $_POST['general_sale_price'] !== '' ? max(0, (float)$_POST['general_sale_price']) : (float)$prod['sale_price'];

            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ?, opening_stock = opening_stock + ?, purchase_price = ?, sale_price = ?, updated_at = CURDATE() WHERE id = ?")
                ->execute([$quantity, $quantity, $purchase_price, $sale_price, $product_id]);
        }

        try {
            $pdo->prepare("INSERT INTO activity_logs (user_id, action, module, reference_id, description, ip_address, created_at) VALUES (?, 'Opening Stock (General)', 'inventory', ?, ?, ?, CURDATE())")
                ->execute([$_SESSION['user_id'] ?? null, $product_id, "Added {$quantity} pcs opening stock for '{$name}'", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
        } catch (Exception $ignored) {}

        $_SESSION['success'] = "Added {$quantity} pcs opening stock for '{$name}' successfully!";
        header("Location: products.php");
        exit;
    }
}

$active_tab = $_GET['type'] ?? 'mobile';
if (!in_array($active_tab, ['mobile', 'laptop', 'general'], true)) {
    $active_tab = 'mobile';
}

require_once '../../includes/header.php';
?>

<style>
.product-card {
    background: #ffffff;
    border: 1px solid #eef2f6;
    border-radius: 12px;
    box-shadow: 0 4px 14px rgba(0,0,0,0.04);
}
.product-card .card-header {
    background: #ffffff;
    border-bottom: 1px solid #f1f5f9;
    padding: 16px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.product-card-title {
    color: #f59e0b;
    font-weight: 700;
    font-size: 1.05rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.form-label-custom {
    font-size: 0.85rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: 4px;
}
.form-control-custom {
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    font-size: 0.88rem;
    color: #0f172a;
}
.form-control-custom:focus {
    border-color: #f59e0b;
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
}
.btn-create-product {
    background: #f59e0b;
    border: 1px solid #f59e0b;
    color: #ffffff;
    font-weight: 700;
    font-size: 1rem;
    padding: 11px 18px;
    border-radius: 8px;
    transition: all 0.2s;
}
.btn-create-product:hover {
    background: #d97706;
    border-color: #d97706;
    color: #ffffff;
}
.btn-cancel-custom {
    background: #64748b;
    border: 1px solid #64748b;
    color: #ffffff;
    font-weight: 600;
    font-size: 0.95rem;
    padding: 10px 16px;
    border-radius: 8px;
    transition: all 0.2s;
}
.btn-cancel-custom:hover {
    background: #475569;
    border-color: #475569;
    color: #ffffff;
    text-decoration: none;
}
.unit-table th {
    background: #f8fafc;
    font-size: 0.82rem;
    font-weight: 600;
    color: #475569;
    vertical-align: middle;
}
.unit-table td {
    padding: 6px;
    vertical-align: middle;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="font-weight-bold mb-0" style="color: #0f172a;"><i class="fas fa-boxes mr-1"></i> Add Product &amp; Opening Stock</h4>
    <small class="text-muted">Enter product specifications, IMEI / serial numbers &amp; initial stock</small>
  </div>
  <div>
    <a href="products.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i> Products List</a>
  </div>
</div>

<div class="row justify-content-center">
  <div class="col-12 col-xl-11">
    <div class="product-card mb-5">
      <form method="post" id="productForm">
        <!-- Card Header -->
        <div class="card-header">
          <div class="product-card-title">
            <i class="fas fa-box" style="color: #f59e0b;"></i>
            <span>New Product</span>
          </div>
          <div class="d-flex align-items-center">
            <label for="product_type" class="mb-0 mr-2 font-weight-bold" style="font-size: 0.9rem; color: #1e293b;">Product Type</label>
            <select name="product_type" id="product_type" class="custom-select custom-select-sm form-control-custom font-weight-bold" style="width: 170px;" onchange="switchProductType(this.value)">
              <option value="mobile" <?= $active_tab === 'mobile' ? 'selected' : '' ?>>Mobile Phone</option>
              <option value="laptop" <?= $active_tab === 'laptop' ? 'selected' : '' ?>>Laptop</option>
              <option value="general" <?= $active_tab === 'general' ? 'selected' : '' ?>>General</option>
            </select>
          </div>
        </div>

        <div class="p-4">
          <!-- Optional Supplier (Applicable to all types) -->
          <div class="row mb-3">
            <div class="col-md-6 form-group mb-0">
              <label class="form-label-custom">Supplier / Vendor (Optional)</label>
              <select name="supplier_id" class="form-control form-control-custom">
                <option value="">Select Supplier</option>
                <?php foreach ($suppliers as $s): ?>
                  <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- ========================================== -->
          <!-- 1. MOBILE PHONE SECTION                     -->
          <!-- ========================================== -->
          <div id="section_mobile" class="type-section" style="<?= $active_tab === 'mobile' ? '' : 'display:none;' ?>">
            <div class="row mb-2">
              <div class="col-md-6 form-group">
                <label class="form-label-custom">Select Model <span class="text-danger">*</span></label>
                <select name="mobile_model" id="mobileModelSelect" class="form-control form-control-custom" onchange="onMobileModelChange(this)">
                  <option value="new">++ Add New Mobile Model</option>
                  <?php foreach ($existing_mobiles as $m): ?>
                    <option value="<?= $m['id'] ?>"
                            data-brand="<?= $m['brand_id'] ?>"
                            data-sale="<?= $m['sale_price'] ?>"
                            data-storage="<?= htmlspecialchars($m['storage'] ?? '') ?>"
                            data-ram="<?= htmlspecialchars($m['ram'] ?? '') ?>"
                            data-color="<?= htmlspecialchars($m['color'] ?? '') ?>"
                            data-condition="<?= htmlspecialchars($m['product_condition'] ?? 'New') ?>"
                            data-price="<?= $m['purchase_price'] ?>">
                      <?= htmlspecialchars($m['name']) ?> [<?= htmlspecialchars($m['code']) ?>]
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6 form-group" id="mobileConditionWrap">
                <label class="form-label-custom">Condition</label>
                <select name="mobile_condition" id="mobileCondition" class="form-control form-control-custom">
                  <option value="New">New</option>
                  <option value="Used">Used</option>
                  <option value="Refurbished">Refurbished</option>
                </select>
              </div>
            </div>

            <!-- New Mobile Fields (NO CATEGORY - Clean & Direct) -->
            <div id="newMobileFieldsWrap" class="p-3 bg-light rounded border mb-3">
              <div class="row">
                <div class="col-md-5 form-group mb-2">
                  <label class="form-label-custom">Product / Model Name <span class="text-danger">*</span></label>
                  <input type="text" name="new_mobile_name" id="newMobileName" class="form-control form-control-custom" placeholder="e.g. Samsung Galaxy A15 / iPhone 14">
                </div>
                <div class="col-md-4 form-group mb-2">
                  <label class="form-label-custom">Company (Brand)</label>
                  <select name="new_mobile_brand_id" id="newMobileBrand" class="form-control form-control-custom">
                    <option value="">Select Brand</option>
                    <?php foreach ($brands as $b): ?>
                      <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3 form-group mb-2">
                  <label class="form-label-custom">Sale Price (PKR) <span class="text-danger">*</span></label>
                  <input type="number" step="0.01" name="new_mobile_sale_price" id="newMobileSale" class="form-control form-control-custom" placeholder="Selling price">
                </div>
              </div>
            </div>

            <!-- Mobile Units / IMEI Table -->
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div>
                <label class="font-weight-bold mb-0 text-dark" style="font-size: 0.92rem;"><i class="fas fa-mobile-alt text-primary mr-1"></i> Enter Mobile Units &amp; IMEIs</label>
                <span class="badge badge-primary ml-2 px-2 py-1" id="mobileCountBadge">Quantity: 1</span>
                <span class="badge badge-success ml-1 px-2 py-1" id="mobileSubtotalBadge">Total: PKR 0.00</span>
              </div>
            </div>

            <div class="table-responsive mb-2">
              <table class="table table-bordered table-sm unit-table mb-1" id="mobileTable">
                <thead>
                  <tr>
                    <th style="width: 40px;" class="text-center">#</th>
                    <th>IMEI No. 1 <span class="text-danger">*</span></th>
                    <th>IMEI No. 2</th>
                    <th style="width: 120px;">Storage</th>
                    <th style="width: 105px;">RAM</th>
                    <th style="width: 125px;">Color</th>
                    <th style="width: 130px;">Purchase Price</th>
                    <th style="width: 45px;"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td class="text-center font-weight-bold">1</td>
                    <td><input type="text" name="mobile_imei1[]" class="form-control form-control-sm form-control-custom mobile-imei1-input" placeholder="IMEI 1 (15 digits)" maxlength="15" oninput="this.value=this.value.replace(/\D/g,'')" required></td>
                    <td><input type="text" name="mobile_imei2[]" class="form-control form-control-sm form-control-custom" placeholder="IMEI 2 (Optional)" maxlength="15" oninput="this.value=this.value.replace(/\D/g,'')"></td>
                    <td>
                      <select name="mobile_storage[]" class="form-control form-control-sm form-control-custom mobileStorageFill">
                        <option value="">Storage</option>
                        <option>32GB</option><option>64GB</option><option selected>128GB</option><option>256GB</option><option>512GB</option><option>1TB</option>
                      </select>
                    </td>
                    <td>
                      <select name="mobile_ram[]" class="form-control form-control-sm form-control-custom mobileRamFill">
                        <option value="">RAM</option>
                        <option>3GB</option><option selected>4GB</option><option>6GB</option><option>8GB</option><option>12GB</option><option>16GB</option>
                      </select>
                    </td>
                    <td>
                      <select name="mobile_color[]" class="form-control form-control-sm form-control-custom mobileColorFill">
                        <option value="">Color</option>
                        <option>Black</option><option>White</option><option>Blue</option><option>Green</option><option>Silver</option><option>Gold</option><option>Titanium</option><option>Grey</option><option>Midnight</option>
                      </select>
                    </td>
                    <td><input type="number" step="0.01" name="mobile_price[]" class="form-control form-control-sm form-control-custom mobilePriceFill" placeholder="0.00" oninput="updateMobileTotal()"></td>
                    <td class="text-center"></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <button type="button" class="btn btn-sm btn-success mb-3" onclick="addMobileRow()"><i class="fas fa-plus mr-1"></i> Add Mobile (IMEI)</button>
          </div>

          <!-- ========================================== -->
          <!-- 2. LAPTOP SECTION                           -->
          <!-- ========================================== -->
          <div id="section_laptop" class="type-section" style="<?= $active_tab === 'laptop' ? '' : 'display:none;' ?>">
            <div class="row mb-2">
              <div class="col-md-6 form-group">
                <label class="form-label-custom">Select Model <span class="text-danger">*</span></label>
                <select name="laptop_model" id="laptopModelSelect" class="form-control form-control-custom" onchange="onLaptopModelChange(this)">
                  <option value="new">++ Add New Laptop Model</option>
                  <?php foreach ($existing_laptops as $l): ?>
                    <option value="<?= $l['id'] ?>"
                            data-brand="<?= $l['brand_id'] ?>"
                            data-sale="<?= $l['sale_price'] ?>"
                            data-ram="<?= htmlspecialchars($l['ram'] ?? '') ?>"
                            data-storage="<?= htmlspecialchars($l['storage'] ?? '') ?>"
                            data-color="<?= htmlspecialchars($l['color'] ?? '') ?>"
                            data-processor="<?= htmlspecialchars($l['processor'] ?? '') ?>"
                            data-screen="<?= htmlspecialchars($l['screen_size'] ?? '') ?>"
                            data-condition="<?= htmlspecialchars($l['product_condition'] ?? 'New') ?>"
                            data-price="<?= $l['purchase_price'] ?>">
                      <?= htmlspecialchars($l['name']) ?> [<?= htmlspecialchars($l['code']) ?>]
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6 form-group" id="laptopConditionWrap">
                <label class="form-label-custom">Condition</label>
                <select name="laptop_condition" id="laptopCondition" class="form-control form-control-custom">
                  <option value="New">New</option>
                  <option value="Used" selected>Used</option>
                  <option value="Refurbished">Refurbished</option>
                </select>
              </div>
            </div>

            <!-- New Laptop Fields (NO CATEGORY - Clean & Direct) -->
            <div id="newLaptopFieldsWrap" class="p-3 bg-light rounded border mb-3">
              <div class="row">
                <div class="col-md-5 form-group mb-2">
                  <label class="form-label-custom">Model / Name <span class="text-danger">*</span></label>
                  <input type="text" name="new_laptop_name" id="newLaptopName" class="form-control form-control-custom" placeholder="e.g. HP EliteBook 840 G5">
                </div>
                <div class="col-md-4 form-group mb-2">
                  <label class="form-label-custom">Company (Brand)</label>
                  <select name="new_laptop_brand_id" id="newLaptopBrand" class="form-control form-control-custom">
                    <option value="">Select Brand</option>
                    <?php foreach ($brands as $b): ?>
                      <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3 form-group mb-2">
                  <label class="form-label-custom">Sale Price (PKR) <span class="text-danger">*</span></label>
                  <input type="number" step="0.01" name="new_laptop_sale_price" id="newLaptopSale" class="form-control form-control-custom" placeholder="Selling price">
                </div>
              </div>
            </div>

            <!-- Laptop Units Table -->
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div>
                <label class="font-weight-bold mb-0 text-dark" style="font-size: 0.92rem;"><i class="fas fa-laptop text-info mr-1"></i> Enter Laptop Units &amp; Details</label>
                <span class="badge badge-primary ml-2 px-2 py-1" id="laptopCountBadge">Quantity: 1</span>
                <span class="badge badge-success ml-1 px-2 py-1" id="laptopSubtotalBadge">Total: PKR 0.00</span>
              </div>
            </div>

            <div class="table-responsive mb-2">
              <table class="table table-bordered table-sm unit-table mb-1" id="laptopTable">
                <thead>
                  <tr>
                    <th style="width: 40px;" class="text-center">#</th>
                    <th style="width: 120px;">Color</th>
                    <th style="width: 140px;">Storage</th>
                    <th style="width: 100px;">RAM</th>
                    <th>Processor</th>
                    <th style="width: 90px;">Screen</th>
                    <th style="width: 140px;">Serial / Notes</th>
                    <th style="width: 125px;">Purchase Price</th>
                    <th style="width: 45px;"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td class="text-center font-weight-bold">1</td>
                    <td>
                      <select name="laptop_color[]" class="form-control form-control-sm form-control-custom laptopColorFill">
                        <option value="">Color</option>
                        <option selected>Silver</option><option>Grey</option><option>Black</option><option>Space Grey</option>
                      </select>
                    </td>
                    <td>
                      <select name="laptop_storage[]" class="form-control form-control-sm form-control-custom laptopStorageFill">
                        <option value="">Storage</option>
                        <option>128GB SSD</option><option selected>256GB SSD</option><option>512GB SSD</option><option>1TB SSD</option><option>500GB HDD</option>
                      </select>
                    </td>
                    <td>
                      <select name="laptop_ram[]" class="form-control form-control-sm form-control-custom laptopRamFill">
                        <option value="">RAM</option>
                        <option>4GB</option><option selected>8GB</option><option>16GB</option><option>32GB</option>
                      </select>
                    </td>
                    <td><input type="text" name="laptop_processor[]" class="form-control form-control-sm form-control-custom laptopProcessorFill" placeholder="e.g. Core i5 8th"></td>
                    <td><input type="text" name="laptop_screen[]" class="form-control form-control-sm form-control-custom laptopScreenFill" placeholder="14.0"></td>
                    <td><input type="text" name="laptop_serial[]" class="form-control form-control-sm form-control-custom" placeholder="Serial No."></td>
                    <td><input type="number" step="0.01" name="laptop_price[]" class="form-control form-control-sm form-control-custom laptopPriceFill" placeholder="0.00" oninput="updateLaptopTotal()"></td>
                    <td class="text-center"></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <button type="button" class="btn btn-sm btn-success mb-3" onclick="addLaptopRow()"><i class="fas fa-plus mr-1"></i> Add Laptop</button>
          </div>

          <!-- ========================================== -->
          <!-- 3. GENERAL ACCESSORY SECTION                -->
          <!-- ========================================== -->
          <div id="section_general" class="type-section" style="<?= $active_tab === 'general' ? '' : 'display:none;' ?>">
            <div class="row mb-2">
              <div class="col-md-6 form-group">
                <label class="form-label-custom">Select Product <span class="text-danger">*</span></label>
                <select name="general_product" id="generalProductSelect" class="form-control form-control-custom" onchange="onGeneralProductChange(this)">
                  <option value="new">++ Add New General Product</option>
                  <?php foreach ($existing_generals as $g): ?>
                    <option value="<?= $g['id'] ?>"
                            data-price="<?= $g['purchase_price'] ?>"
                            data-sale="<?= $g['sale_price'] ?>"
                            data-stock="<?= $g['stock_quantity'] ?>">
                      <?= htmlspecialchars($g['name']) ?> [<?= htmlspecialchars($g['code']) ?>] &mdash; (Stock: <?= $g['stock_quantity'] ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <!-- New General Fields -->
            <div id="newGeneralFieldsWrap" class="p-3 bg-light rounded border mb-3">
              <div class="row">
                <div class="col-md-6 form-group mb-2">
                  <label class="form-label-custom">Product Name <span class="text-danger">*</span></label>
                  <input type="text" name="new_general_name" id="newGeneralName" class="form-control form-control-custom" placeholder="e.g. Type-C Fast Charger / Handsfree">
                </div>
                <div class="col-md-3 form-group mb-2">
                  <label class="form-label-custom">Category (Accessories)</label>
                  <select name="new_general_category_id" id="newGeneralCategory" class="form-control form-control-custom">
                    <option value="">Select Category</option>
                    <?php foreach ($general_categories as $gc): ?>
                      <option value="<?= $gc['id'] ?>"><?= htmlspecialchars($gc['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3 form-group mb-2">
                  <label class="form-label-custom">Brand</label>
                  <select name="new_general_brand_id" id="newGeneralBrand" class="form-control form-control-custom">
                    <option value="">Select Brand</option>
                    <?php foreach ($brands as $b): ?>
                      <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>

            <!-- Quantity & Pricing for General -->
            <div class="row mb-3">
              <div class="col-md-3 form-group mb-2">
                <label class="form-label-custom">Opening Quantity <span class="text-danger">*</span></label>
                <input type="number" name="general_quantity" id="generalQuantity" class="form-control form-control-custom font-weight-bold" min="1" value="1" required>
              </div>
              <div class="col-md-3 form-group mb-2">
                <label class="form-label-custom">Purchase Price (PKR)</label>
                <input type="number" step="0.01" name="general_purchase_price" id="generalPurchasePrice" class="form-control form-control-custom" placeholder="0.00" value="0">
              </div>
              <div class="col-md-3 form-group mb-2">
                <label class="form-label-custom">Sale Price (PKR) <span class="text-danger">*</span></label>
                <input type="number" step="0.01" name="general_sale_price" id="generalSalePrice" class="form-control form-control-custom" placeholder="0.00" value="0">
              </div>
              <div class="col-md-3 form-group mb-2">
                <label class="form-label-custom">Unit</label>
                <input type="text" name="general_unit" id="generalUnit" class="form-control form-control-custom" value="pcs">
              </div>
            </div>
          </div>

          <!-- Bottom Action Buttons -->
          <div class="mt-4 pt-2">
            <button type="submit" class="btn btn-create-product btn-block mb-2 shadow-sm">
              <i class="fas fa-save mr-1"></i> Save &amp; Enter Opening Stock
            </button>
            <a href="products.php" class="btn btn-cancel-custom btn-block text-center">
              Cancel
            </a>
          </div>

        </div>
      </form>
    </div>
  </div>
</div>

<script>
function switchProductType(type) {
    document.querySelectorAll('.type-section').forEach(function(el) {
        el.style.display = 'none';
    });
    var sec = document.getElementById('section_' + type);
    if (sec) sec.style.display = 'block';
}

// Mobile Select Model Change
function onMobileModelChange(sel) {
    var isNew = (sel.value === 'new');
    var wrap = document.getElementById('newMobileFieldsWrap');
    wrap.style.display = isNew ? 'block' : 'none';

    var opt = sel.options[sel.selectedIndex];
    if (!isNew && opt) {
        if (opt.dataset.condition) {
            document.getElementById('mobileCondition').value = opt.dataset.condition;
        }
        var price = opt.dataset.price || '';
        document.querySelectorAll('.mobilePriceFill').forEach(function(el) {
            if (!el.value) el.value = price;
        });
        var st = opt.dataset.storage || '';
        var ra = opt.dataset.ram || '';
        var co = opt.dataset.color || '';
        document.querySelectorAll('.mobileStorageFill').forEach(function(el) { if (!el.value && st) el.value = st; });
        document.querySelectorAll('.mobileRamFill').forEach(function(el) { if (!el.value && ra) el.value = ra; });
        document.querySelectorAll('.mobileColorFill').forEach(function(el) { if (!el.value && co) el.value = co; });
    }
}

// Add Mobile Row
function addMobileRow() {
    var tbody = document.querySelector('#mobileTable tbody');
    var count = tbody.querySelectorAll('tr').length + 1;
    var tr = document.createElement('tr');
    tr.innerHTML = '<td class="text-center font-weight-bold">' + count + '</td>' +
        '<td><input type="text" name="mobile_imei1[]" class="form-control form-control-sm form-control-custom mobile-imei1-input" placeholder="IMEI 1 (15 digits)" maxlength="15" oninput="this.value=this.value.replace(/\\D/g,\'\')" required></td>' +
        '<td><input type="text" name="mobile_imei2[]" class="form-control form-control-sm form-control-custom" placeholder="IMEI 2 (Optional)" maxlength="15" oninput="this.value=this.value.replace(/\\D/g,\'\')"></td>' +
        '<td><select name="mobile_storage[]" class="form-control form-control-sm form-control-custom mobileStorageFill"><option value="">Storage</option><option>32GB</option><option>64GB</option><option selected>128GB</option><option>256GB</option><option>512GB</option><option>1TB</option></select></td>' +
        '<td><select name="mobile_ram[]" class="form-control form-control-sm form-control-custom mobileRamFill"><option value="">RAM</option><option>3GB</option><option selected>4GB</option><option>6GB</option><option>8GB</option><option>12GB</option><option>16GB</option></select></td>' +
        '<td><select name="mobile_color[]" class="form-control form-control-sm form-control-custom mobileColorFill"><option value="">Color</option><option>Black</option><option>White</option><option>Blue</option><option>Green</option><option>Silver</option><option>Gold</option><option>Titanium</option><option>Grey</option><option>Midnight</option></select></td>' +
        '<td><input type="number" step="0.01" name="mobile_price[]" class="form-control form-control-sm form-control-custom mobilePriceFill" placeholder="0.00" oninput="updateMobileTotal()"></td>' +
        '<td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeMobileRow(this)"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);
    updateMobileCount();
}

function removeMobileRow(btn) {
    var tbody = document.querySelector('#mobileTable tbody');
    if (tbody.querySelectorAll('tr').length <= 1) {
        alert('At least one mobile row is required.');
        return;
    }
    btn.closest('tr').remove();
    tbody.querySelectorAll('tr').forEach(function(row, idx) {
        row.cells[0].textContent = idx + 1;
    });
    updateMobileCount();
}

function updateMobileTotal() {
    var total = 0;
    document.querySelectorAll('.mobilePriceFill').forEach(function(el) {
        var v = parseFloat(el.value);
        if (v && v > 0) total += v;
    });
    document.getElementById('mobileSubtotalBadge').textContent = 'Total: PKR ' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function updateMobileCount() {
    var count = document.querySelectorAll('#mobileTable tbody tr').length;
    document.getElementById('mobileCountBadge').textContent = 'Quantity: ' + count;
    updateMobileTotal();
}

// Laptop Model Change
function onLaptopModelChange(sel) {
    var isNew = (sel.value === 'new');
    var wrap = document.getElementById('newLaptopFieldsWrap');
    wrap.style.display = isNew ? 'block' : 'none';

    var opt = sel.options[sel.selectedIndex];
    if (!isNew && opt) {
        if (opt.dataset.condition) {
            document.getElementById('laptopCondition').value = opt.dataset.condition;
        }
        var price = opt.dataset.price || '';
        document.querySelectorAll('.laptopPriceFill').forEach(function(el) {
            if (!el.value) el.value = price;
        });
        var st = opt.dataset.storage || '';
        var ra = opt.dataset.ram || '';
        var co = opt.dataset.color || '';
        var pr = opt.dataset.processor || '';
        var sc = opt.dataset.screen || '';
        document.querySelectorAll('.laptopStorageFill').forEach(function(el) { if (!el.value && st) el.value = st; });
        document.querySelectorAll('.laptopRamFill').forEach(function(el) { if (!el.value && ra) el.value = ra; });
        document.querySelectorAll('.laptopColorFill').forEach(function(el) { if (!el.value && co) el.value = co; });
        document.querySelectorAll('.laptopProcessorFill').forEach(function(el) { if (!el.value && pr) el.value = pr; });
        document.querySelectorAll('.laptopScreenFill').forEach(function(el) { if (!el.value && sc) el.value = sc; });
    }
}

// Add Laptop Row
function addLaptopRow() {
    var tbody = document.querySelector('#laptopTable tbody');
    var count = tbody.querySelectorAll('tr').length + 1;
    var tr = document.createElement('tr');
    tr.innerHTML = '<td class="text-center font-weight-bold">' + count + '</td>' +
        '<td><select name="laptop_color[]" class="form-control form-control-sm form-control-custom laptopColorFill"><option value="">Color</option><option selected>Silver</option><option>Grey</option><option>Black</option><option>Space Grey</option></select></td>' +
        '<td><select name="laptop_storage[]" class="form-control form-control-sm form-control-custom laptopStorageFill"><option value="">Storage</option><option>128GB SSD</option><option selected>256GB SSD</option><option>512GB SSD</option><option>1TB SSD</option><option>500GB HDD</option></select></td>' +
        '<td><select name="laptop_ram[]" class="form-control form-control-sm form-control-custom laptopRamFill"><option value="">RAM</option><option>4GB</option><option selected>8GB</option><option>16GB</option><option>32GB</option></select></td>' +
        '<td><input type="text" name="laptop_processor[]" class="form-control form-control-sm form-control-custom laptopProcessorFill" placeholder="e.g. Core i5 8th"></td>' +
        '<td><input type="text" name="laptop_screen[]" class="form-control form-control-sm form-control-custom laptopScreenFill" placeholder="14.0"></td>' +
        '<td><input type="text" name="laptop_serial[]" class="form-control form-control-sm form-control-custom" placeholder="Serial No."></td>' +
        '<td><input type="number" step="0.01" name="laptop_price[]" class="form-control form-control-sm form-control-custom laptopPriceFill" placeholder="0.00" oninput="updateLaptopTotal()"></td>' +
        '<td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeLaptopRow(this)"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);
    updateLaptopCount();
}

function removeLaptopRow(btn) {
    var tbody = document.querySelector('#laptopTable tbody');
    if (tbody.querySelectorAll('tr').length <= 1) {
        alert('At least one laptop row is required.');
        return;
    }
    btn.closest('tr').remove();
    tbody.querySelectorAll('tr').forEach(function(row, idx) {
        row.cells[0].textContent = idx + 1;
    });
    updateLaptopCount();
}

function updateLaptopTotal() {
    var total = 0;
    document.querySelectorAll('.laptopPriceFill').forEach(function(el) {
        var v = parseFloat(el.value);
        if (v && v > 0) total += v;
    });
    document.getElementById('laptopSubtotalBadge').textContent = 'Total: PKR ' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function updateLaptopCount() {
    var count = document.querySelectorAll('#laptopTable tbody tr').length;
    document.getElementById('laptopCountBadge').textContent = 'Quantity: ' + count;
    updateLaptopTotal();
}

// General Product Change
function onGeneralProductChange(sel) {
    var isNew = (sel.value === 'new');
    var wrap = document.getElementById('newGeneralFieldsWrap');
    wrap.style.display = isNew ? 'block' : 'none';

    var opt = sel.options[sel.selectedIndex];
    if (!isNew && opt) {
        document.getElementById('generalPurchasePrice').value = opt.dataset.price || '0';
        document.getElementById('generalSalePrice').value = opt.dataset.sale || '0';
    }
}

// Form Validation on Submit
document.getElementById('productForm').addEventListener('submit', function(e) {
    var ptype = document.getElementById('product_type').value;

    if (ptype === 'mobile') {
        var isNew = (document.getElementById('mobileModelSelect').value === 'new');
        if (isNew && !document.getElementById('newMobileName').value.trim()) {
            alert('Please enter Mobile Product / Model Name.');
            document.getElementById('newMobileName').focus();
            e.preventDefault();
            return;
        }

        var imeiInputs = document.querySelectorAll('#mobileTable .mobile-imei1-input');
        for (var i = 0; i < imeiInputs.length; i++) {
            var val = imeiInputs[i].value.trim();
            if (!val) {
                alert('IMEI 1 is required for row #' + (i + 1));
                imeiInputs[i].focus();
                e.preventDefault();
                return;
            }
            if (val.length !== 15) {
                alert('IMEI "' + val + '" must be exactly 15 digits.');
                imeiInputs[i].focus();
                e.preventDefault();
                return;
            }
        }
    } else if (ptype === 'laptop') {
        var isNew = (document.getElementById('laptopModelSelect').value === 'new');
        if (isNew && !document.getElementById('newLaptopName').value.trim()) {
            alert('Please enter Laptop Model Name.');
            document.getElementById('newLaptopName').focus();
            e.preventDefault();
            return;
        }
    } else if (ptype === 'general') {
        var isNew = (document.getElementById('generalProductSelect').value === 'new');
        if (isNew && !document.getElementById('newGeneralName').value.trim()) {
            alert('Please enter General Product Name.');
            document.getElementById('newGeneralName').focus();
            e.preventDefault();
            return;
        }
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
