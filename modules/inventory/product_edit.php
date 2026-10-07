<?php
session_start();
$page_title = 'Edit Product';
$base_url = '../../';
require_once '../../includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$item = getById('products', $id);
if (!$item) redirect('products.php', 'Product not found', 'error');

$categories = getAll('categories', 'name ASC');
$brands = getAll('brands', 'name ASC');
$suppliers = getAll('suppliers', 'name ASC');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') redirect("product_edit.php?id=$id", 'Enter the product name', 'error');

    $data = [
        'name' => $name,
        'code' => trim($_POST['code'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'category_id' => (int)($_POST['category_id'] ?? 0) ?: null,
        'brand_id' => (int)($_POST['brand_id'] ?? 0) ?: null,
        'supplier_id' => (int)($_POST['supplier_id'] ?? 0) ?: null,
        'product_type' => $_POST['product_type'] ?? 'general',
        'color' => trim($_POST['color'] ?? '') ?: null,
        'imei_no_1' => trim($_POST['imei_no_1'] ?? '') ?: null,
        'imei_no_2' => trim($_POST['imei_no_2'] ?? '') ?: null,
        'storage' => trim($_POST['storage'] ?? '') ?: null,
        'ram' => trim($_POST['ram'] ?? '') ?: null,
        'processor' => trim($_POST['processor'] ?? '') ?: null,
        'screen_size' => trim($_POST['screen_size'] ?? '') ?: null,
        'graphics' => trim($_POST['graphics'] ?? '') ?: null,
        'warranty_months' => (int)($_POST['warranty_months'] ?? 0) ?: null,
        'product_condition' => $_POST['product_condition'] ?? 'New',
        'purchase_price' => (float)($_POST['purchase_price'] ?? 0),
        'sale_price' => (float)($_POST['sale_price'] ?? 0),
        'opening_stock' => max(0, (int)($_POST['opening_stock'] ?? 0)),
        'stock_quantity' => max(0, (int)($_POST['stock_quantity'] ?? 0)),
        'min_stock_level' => max(0, (int)($_POST['min_stock_level'] ?? 0)),
        'unit' => trim($_POST['unit'] ?? 'pcs') ?: 'pcs',
        'has_serial' => isset($_POST['has_serial']) ? 1 : 0,
        'status' => isset($_POST['status']) ? 1 : 0,
        'updated_at' => date('Y-m-d'),
    ];

    // Unique code check (allow keeping the existing code)
    $code = $data['code'];
    if ($code !== $item['code']) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM products WHERE code = ?");
        $chk->execute([$code]);
        if ($chk->fetchColumn() > 0) {
            redirect("product_edit.php?id=$id", "Product code '$code' already exists", 'error');
        }
    }

    update('products', $data, $id);
    redirect('products.php', "Product '{$data['name']}' updated");
}

require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="m-0 font-weight-bold" style="color:#0f172a;"><i class="fas fa-edit"></i> Edit Product</h5>
  <a href="products.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left"></i> Products</a>
</div>

<div class="row justify-content-center">
  <div class="col-lg-8">
    <div class="card shadow mb-4">
      <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-box-open"></i> Product Details</h6></div>
      <div class="card-body">
        <form method="post">
          <div class="row">
            <div class="col-md-6 form-group">
              <label class="form-label">Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($item['name']) ?>" required>
            </div>
            <div class="col-md-3 form-group">
              <label class="form-label">Code</label>
              <input type="text" name="code" class="form-control" value="<?= htmlspecialchars($item['code']) ?>">
            </div>
            <div class="col-md-3 form-group">
              <label class="form-label">Type</label>
              <select name="product_type" class="form-control">
                <?php foreach (['general' => 'General', 'mobile' => 'Mobile', 'laptop' => 'Laptop'] as $tk => $tv): ?>
                  <option value="<?= $tk ?>" <?= $item['product_type'] === $tk ? 'selected' : '' ?>><?= $tv ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="row">
            <div class="col-md-4 form-group">
              <label class="form-label">Category</label>
              <select name="category_id" class="form-control">
                <option value="">None</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= $c['id'] ?>" <?= (int)$item['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 form-group">
              <label class="form-label">Brand</label>
              <select name="brand_id" class="form-control">
                <option value="">None</option>
                <?php foreach ($brands as $b): ?>
                  <option value="<?= $b['id'] ?>" <?= (int)$item['brand_id'] === (int)$b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 form-group">
              <label class="form-label">Supplier</label>
              <select name="supplier_id" class="form-control">
                <option value="">None</option>
                <?php foreach ($suppliers as $s): ?>
                  <option value="<?= $s['id'] ?>" <?= (int)$item['supplier_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($item['description'] ?? '') ?></textarea>
          </div>

          <hr>
          <h6 class="font-weight-bold text-primary"><i class="fas fa-microchip"></i> Specifications</h6>
          <div class="row">
            <div class="col-md-4 form-group"><label class="form-label">Color</label><input type="text" name="color" class="form-control" value="<?= htmlspecialchars($item['color'] ?? '') ?>"></div>
            <div class="col-md-4 form-group"><label class="form-label">Storage</label><input type="text" name="storage" class="form-control" value="<?= htmlspecialchars($item['storage'] ?? '') ?>" placeholder="e.g. 128GB"></div>
            <div class="col-md-4 form-group"><label class="form-label">RAM</label><input type="text" name="ram" class="form-control" value="<?= htmlspecialchars($item['ram'] ?? '') ?>" placeholder="e.g. 8GB"></div>
            <div class="col-md-4 form-group"><label class="form-label">Processor</label><input type="text" name="processor" class="form-control" value="<?= htmlspecialchars($item['processor'] ?? '') ?>"></div>
            <div class="col-md-4 form-group"><label class="form-label">Screen Size</label><input type="text" name="screen_size" class="form-control" value="<?= htmlspecialchars($item['screen_size'] ?? '') ?>" placeholder="e.g. 15.6"></div>
            <div class="col-md-4 form-group"><label class="form-label">Graphics</label><input type="text" name="graphics" class="form-control" value="<?= htmlspecialchars($item['graphics'] ?? '') ?>"></div>
            <div class="col-md-4 form-group"><label class="form-label">IMEI 1</label><input type="text" name="imei_no_1" class="form-control" value="<?= htmlspecialchars($item['imei_no_1'] ?? '') ?>"></div>
            <div class="col-md-4 form-group"><label class="form-label">IMEI 2</label><input type="text" name="imei_no_2" class="form-control" value="<?= htmlspecialchars($item['imei_no_2'] ?? '') ?>"></div>
            <div class="col-md-4 form-group">
              <label class="form-label">Condition</label>
              <select name="product_condition" class="form-control">
                <?php foreach (['New', 'Used', 'Refurbished'] as $cond): ?>
                  <option value="<?= $cond ?>" <?= $item['product_condition'] === $cond ? 'selected' : '' ?>><?= $cond ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 form-group"><label class="form-label">Warranty (months)</label><input type="number" name="warranty_months" class="form-control" min="0" value="<?= (int)$item['warranty_months'] ?>"></div>
          </div>

          <hr>
          <h6 class="font-weight-bold text-primary"><i class="fas fa-coins"></i> Pricing &amp; Stock</h6>
          <div class="row">
            <div class="col-md-2 form-group"><label class="form-label">Purchase Price</label><input type="number" name="purchase_price" class="form-control" step="0.01" min="0" value="<?= $item['purchase_price'] ?>"></div>
            <div class="col-md-2 form-group"><label class="form-label">Sale Price</label><input type="number" name="sale_price" class="form-control" step="0.01" min="0" value="<?= $item['sale_price'] ?>"></div>
            <div class="col-md-2 form-group"><label class="form-label">Opening Stock</label><input type="number" name="opening_stock" class="form-control" min="0" value="<?= (int)($item['opening_stock'] ?? 0) ?>"></div>
            <div class="col-md-2 form-group"><label class="form-label">Stock Qty</label><input type="number" name="stock_quantity" class="form-control" min="0" value="<?= (int)$item['stock_quantity'] ?>"></div>
            <div class="col-md-2 form-group"><label class="form-label">Min Stock Level</label><input type="number" name="min_stock_level" class="form-control" min="0" value="<?= (int)$item['min_stock_level'] ?>"></div>
            <div class="col-md-2 form-group"><label class="form-label">Unit</label><input type="text" name="unit" class="form-control" value="<?= htmlspecialchars($item['unit'] ?? 'pcs') ?>"></div>
          </div>

          <div class="form-group">
            <div class="custom-control custom-switch">
              <input type="checkbox" class="custom-control-input" id="serialSwitch" name="has_serial" <?= $item['has_serial'] ? 'checked' : '' ?>>
              <label class="custom-control-label" for="serialSwitch">Track serial / IMEI</label>
            </div>
            <div class="custom-control custom-switch">
              <input type="checkbox" class="custom-control-input" id="statusSwitch" name="status" <?= $item['status'] ? 'checked' : '' ?>>
              <label class="custom-control-label" for="statusSwitch">Active</label>
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-block py-2"><i class="fas fa-save"></i> Update Product</button>
          <a href="products.php" class="btn btn-secondary btn-block">Cancel</a>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
