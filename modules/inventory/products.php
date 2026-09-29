<?php
session_start();
$page_title = 'Products';
$base_url = '../../';
require_once '../../includes/functions.php';

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
              <td class="text-center"><span class="badge badge-<?= $stockBadge ?>"><?= $stockTxt ?></span></td>
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
        'Stock': p.stock_quantity, 'Min Stock': p.min_stock_level
    };
    if (p.product_type === 'mobile' || p.product_type === 'laptop') delete pricing['Purchase Price'];
    Object.keys(pricing).forEach(function(k) {
        var v = pricing[k];
        rows.push('<div class="col-6 col-md-4"><div class="pv-box"><div class="pv-label">' + k + '</div><div class="pv-value">' + (k === 'Stock' || k === 'Min Stock' ? esc(v) : esc(parseFloat(v).toFixed(2))) + '</div></div></div>');
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
</script>

<?php require_once '../../includes/footer.php'; ?>
