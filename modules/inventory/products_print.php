<?php
session_start();
require_once '../../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
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
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $where[] = "p.status = ?";
    $params[] = (int)$_GET['status'];
} else {
    $where[] = "p.status = 1";
}

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

$typeLabelMap = ['mobile' => 'Mobile', 'laptop' => 'Laptop', 'general' => 'General'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Products | RAYHAAN MOBILE KAHUTA</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
<style>
  @media print {
    @page { size: A4 portrait; margin: 10mm; }
    body { background:#fff; font-size:11px; }
    .no-print { display:none !important; }
    .wrap { box-shadow:none !important; border:1px solid #000 !important; max-width:100%; margin:0; }
    td, th { padding: 4px 6px !important; font-size: 10.5px !important; }
  }
  body { background: #f1f5f9; font-family: 'Segoe UI', Arial, sans-serif; font-size:12px; }
  .wrap { max-width: 1000px; margin: 20px auto; background: #fff; border: 2px solid #1f2937; border-radius: 4px; padding: 0; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
  .r-header { display:flex; align-items:center; gap:14px; padding: 14px 18px 8px 18px; border-bottom: 2px solid #1f2937; }
  .r-logo { width:60px; height:60px; border:3px solid #4b5563; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:1.4rem; color:#374151; flex-shrink:0; }
  .r-company-name { font-size:1.5rem; font-weight:700; margin:0; color:#1f2937; }
  .r-company-sub { font-size:.85rem; font-weight:600; letter-spacing:.03em; margin:0; color:#1f2937; }
  .r-company-contact { font-size:.7rem; color:#374151; margin:0; }
  .r-section-title { font-size:.95rem; font-weight:700; padding: 8px 18px; border-bottom: 1px solid #1f2937; }
  .r-content { padding: 14px 18px; }
  .r-summary { width:100%; border-collapse: collapse; margin-bottom: 12px; }
  .r-summary td { border: 1px solid #1f2937; text-align:center; padding: 8px 6px; }
  .r-summary .lbl { font-size:.62rem; text-transform:uppercase; letter-spacing:.03em; color:#475569; font-weight:700; }
  .r-summary .val { font-size:1.1rem; font-weight:700; color:#0f172a; }
  table.list { width:100%; border-collapse: collapse; }
  table.list th, table.list td { border: 1px solid #1f2937; padding: 5px 6px; text-align:left; font-size:11px; }
  table.list th { background: #f8fafc; color: #475569; font-weight:700; }
  table.list .num { text-align:right; }
  table.list .c { text-align:center; }
  .r-software { text-align:left; font-size:.7rem; color:#9ca3af; padding: 6px 18px 14px 18px; }
</style>
</head>
<body>

<div class="no-print text-center mt-3 mb-3">
  <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
  <button class="btn btn-secondary" onclick="window.close()"><i class="fas fa-times"></i> Close</button>
</div>

<div class="wrap">
  <div class="r-header">
    <div class="r-logo">RMK</div>
    <div>
      <p class="r-company-name">RAYHAAN MOBILE KAHUTA</p>
      <p class="r-company-sub">Mobile Phone Sales</p>
      <p class="r-company-contact">Near Ameen Plaza, Kallar Road, Kahuta | Cell: 0336-5389945</p>
    </div>
  </div>

  <div class="r-section-title">
    Products / Stock List
    <?php
    $flt = [];
    if (!empty($_GET['search'])) $flt[] = 'Search: ' . htmlspecialchars($_GET['search']);
    if (!empty($_GET['type'])) $flt[] = 'Type: ' . $typeLabelMap[$_GET['type']];
    if (isset($_GET['status']) && $_GET['status'] !== '') $flt[] = 'Status: ' . ($_GET['status'] ? 'Active' : 'Inactive');
    if ($flt) echo ' &mdash; ' . implode(' | ', $flt);
    ?>
  </div>

  <div class="r-content">
    <table class="r-summary">
      <tr>
        <td><div class="lbl">Total Products</div><div class="val"><?= count($products) ?></div></td>
        <td><div class="lbl">In Stock</div><div class="val"><?= $stock_ok ?></div></td>
        <td><div class="lbl">Low Stock</div><div class="val"><?= $stock_low ?></div></td>
        <td><div class="lbl">Out of Stock</div><div class="val"><?= $stock_out ?></div></td>
        <td><div class="lbl">Stock Value</div><div class="val">PKR <?= formatCurrency($stock_value) ?></div></td>
      </tr>
    </table>

    <table class="list">
      <thead>
        <tr>
          <th style="width:28px;">#</th>
          <th>Code</th>
          <th>Product</th>
          <th>Type</th>
          <th>Category</th>
          <th>Brand</th>
          <th class="c">Stock</th>
          <th class="num">Purchase</th>
          <th class="num">Sale</th>
          <th class="c">Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($products)): ?>
          <tr><td colspan="10" class="text-center text-muted">No products found</td></tr>
        <?php else: foreach ($products as $i => $p): ?>
          <?php
            $stock = (int)$p['stock_quantity'];
            $min_stock = (int)$p['min_stock_level'];
            if ($stock <= 0) { $stockTxt = 'Out'; }
            elseif ($min_stock > 0 && $stock <= $min_stock) { $stockTxt = 'Low - ' . $stock; }
            else { $stockTxt = (string)$stock; }
            $specs = array_filter([$p['color'], $p['storage'], $p['ram'], $p['processor'], $p['screen_size']]);
            $spec_txt = implode(' / ', $specs);
          ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><code><?= htmlspecialchars($p['code']) ?></code></td>
            <td><?= htmlspecialchars($p['name']) ?><?php if ($spec_txt): ?> <small style="color:#64748b;">(<?= htmlspecialchars($spec_txt) ?>)</small><?php endif; ?></td>
            <td><?= $typeLabelMap[$p['product_type']] ?? ucfirst($p['product_type']) ?></td>
            <td><?= htmlspecialchars($p['category_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($p['brand_name'] ?? '-') ?></td>
            <td class="c"><?= $stockTxt ?></td>
            <td class="num"><?= formatCurrency($p['purchase_price']) ?></td>
            <td class="num"><?= formatCurrency($p['sale_price']) ?></td>
            <td class="c"><?= $p['status'] ? 'Active' : 'Inactive' ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="r-software">[Software By @ ATR]</div>
</div>

<script>window.print()</script>
</body>
</html>
