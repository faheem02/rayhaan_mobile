<?php
session_start();
$id = (int)($_GET['id'] ?? 0);
$is_print = isset($_GET['print']);
require_once '../../includes/functions.php';

$customer = getById('customers', $id);
if (!$customer) { $_SESSION['error'] = 'Customer not found.'; header("Location: view.php?id=$id"); exit; }

$sales_data = $pdo->prepare("SELECT * FROM sales WHERE customer_id = ? AND status != 'cancelled' ORDER BY sale_date ASC");
$sales_data->execute([$id]); $sales_data = $sales_data->fetchAll();

$payments_data = $pdo->prepare("SELECT p.* FROM payments p WHERE p.sale_id IN (SELECT id FROM sales WHERE customer_id = ?) ORDER BY p.payment_date ASC");
$payments_data->execute([$id]); $payments_data = $payments_data->fetchAll();

$returns_data = $pdo->prepare("SELECT * FROM sale_returns WHERE customer_id = ? ORDER BY return_date ASC");
$returns_data->execute([$id]); $returns_data = $returns_data->fetchAll();

// Build sale_id → products map for ledger
$sale_products_map = [];
$sale_ids = array_unique(array_filter(array_column($payments_data, 'sale_id')));
if (!empty($sale_ids)) {
    $ids = implode(',', array_map('intval', $sale_ids));
    $prod_rows = $pdo->query("SELECT si.sale_id, GROUP_CONCAT(DISTINCT COALESCE(p.name, si.item_description) SEPARATOR ', ') AS products FROM sale_items si LEFT JOIN products p ON si.product_id = p.id WHERE si.sale_id IN ($ids) GROUP BY si.sale_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($prod_rows as $row) { $sale_products_map[$row['sale_id']] = $row['products']; }
}

$opening_due = (float)$customer['opening_due'];
$opening_paid = (float)$customer['opening_paid'];
$net_opening = $opening_due - $opening_paid;

$customer_ledger = [];
foreach ($sales_data as $s) {
    $total = (float)$s['total_amount'];
    $customer_ledger[] = ['date' => $s['sale_date'], 'type' => 'sale', 'ref' => $s['invoice_no'] ?? '#' . $s['id'], 'debit' => $total, 'credit' => 0, 'desc' => 'Sale'];
}
foreach ($payments_data as $p) {
    $products = $sale_products_map[$p['sale_id']] ?? '';
    $desc = 'Payment received' . ($products ? ' - ' . $products : '');
    if ($p['notes']) $desc .= ' (' . $p['notes'] . ')';
    $customer_ledger[] = ['date' => $p['payment_date'], 'type' => 'payment', 'ref' => '#' . $p['id'], 'debit' => 0, 'credit' => (float)$p['amount'], 'desc' => $desc];
}
foreach ($returns_data as $r) {
    $customer_ledger[] = ['date' => $r['return_date'], 'type' => 'return', 'ref' => $r['invoice_no'] ?? '#' . $r['id'], 'debit' => 0, 'credit' => (float)$r['amount'], 'desc' => $r['notes'] ?? 'Return'];
}
usort($customer_ledger, function($a, $b) { return strcmp($a['date'], $b['date']); });

$credit_total = array_sum(array_column($customer_ledger, 'debit'));
$debit_total = array_sum(array_column($customer_ledger, 'credit'));
$closing = $net_opening + $credit_total - $debit_total;

$title = 'Customer Ledger - ' . htmlspecialchars($customer['full_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?=$title?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css">
<style>
  @media print {
    @page { size: A4; margin: 10mm; }
    body{ background:#fff; font-size:11px; }
    .no-print { display:none !important; }
    .wrap { box-shadow:none !important; border:1px solid #000 !important; max-width:100%; margin:0; }
  }
  body { background: #f1f5f9; font-family: 'Segoe UI', Arial, sans-serif; font-size:14px; }
  .wrap { max-width: 900px; margin: 30px auto; background: #fff; border: 2px solid #1f2937; border-radius: 4px; padding: 0; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
  .r-header { display:flex; align-items:center; gap:14px; padding: 14px 18px 8px 18px; border-bottom: 2px solid #1f2937; }
  .r-logo { width:60px; height:60px; border:3px solid #4b5563; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:1.4rem; color:#374151; flex-shrink:0; }
  .r-company-name { font-size:1.6rem; font-weight:700; margin:0; color:#1f2937; }
  .r-company-sub { font-size:.85rem; font-weight:600; letter-spacing:.03em; margin:0; color:#1f2937; }
  .r-company-contact { font-size:.7rem; color:#374151; margin:0; }
  .r-section-title { font-size:.95rem; font-weight:700; padding: 8px 18px; border-bottom: 1px solid #1f2937; text-decoration: underline; text-underline-offset: 3px; }
  .r-content { padding: 8px 18px; }
  .r-row { display:flex; flex-wrap:wrap; padding: 8px 18px; border-bottom: 1px solid #1f2937; font-size:.95rem; gap: 6px 24px; }
  .r-row .r-field { display:flex; gap:6px; }
  .r-row .r-field .lbl { font-weight:700; }
  .r-row .r-field .val { font-weight:400; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #e2e8f0; padding: 6px 10px; text-align: center; }
  th { background: #f8fafc; color: #475569; font-size: 12px; }
  td.text-right { text-align: right; }
  td.text-left { text-align: left; }
  .r-software { text-align:left; font-size:.7rem; color:#9ca3af; padding: 6px 18px 14px 18px; }
</style>
</head>
<body>

<div class="no-print text-center mt-3 mb-3">
  <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-download"></i> Download PDF</button>
  <button class="btn btn-secondary" onclick="window.close()"><i class="fas fa-times"></i> Close</button>
</div>

<div class="wrap">

  <div class="r-header">
    <div class="r-logo">RMK</div>
    <div>
      <p class="r-company-name">RAYHAAN MOBILE KAHUTA</p>
      <p class="r-company-sub">Near Ameen Plaza, Kallar Road, Kahuta</p>
      <p class="r-company-contact">Cell: 0336-5389945</p>
    </div>
  </div>

  <div class="r-section-title">Customer Ledger</div>

  <div class="r-row">
    <div class="r-field" style="width:100%;"><span class="lbl">Customer:</span><span class="val"><?= htmlspecialchars($customer['full_name']) ?> (<?= htmlspecialchars($customer['phone'] ?? '') ?>)</span></div>
    <div class="r-field"><span class="lbl">Opening Balance:</span><span class="val"><?= formatCurrency($net_opening) ?></span></div>
    <div class="r-field"><span class="lbl">Closing Balance:</span><span class="val" style="color:#dc2626;font-weight:700;"><?= formatCurrency($closing) ?></span></div>
  </div>

  <div class="r-section-title">Ledger Entries</div>
  <div style="padding:8px 18px;">
    <table>
      <thead>
        <tr><th style="width:100px;">Date</th><th style="width:90px;">Ref</th><th class="text-left">Description</th><th style="width:100px;">Debit</th><th style="width:100px;">Credit</th><th style="width:100px;">Balance</th></tr>
      </thead>
      <tbody>
        <tr style="background:#f8f9fc;"><td colspan="5" class="text-right"><strong>Opening Balance</strong></td><td><strong><?= formatCurrency($net_opening) ?></strong></td></tr>
        <?php $bal = $net_opening; foreach ($customer_ledger as $l):
          $bal += $l['debit'] - $l['credit'];
          $bg = $l['type'] === 'payment' || $l['type'] === 'return' ? 'style="background:#f0fff4;"' : '';
        ?>
          <tr <?= $bg ?>>
            <td><?= formatDate($l['date']) ?></td>
            <td><span class="badge badge-secondary"><?= htmlspecialchars($l['ref']) ?></span></td>
            <td class="text-left small"><?= htmlspecialchars($l['desc']) ?></td>
            <td class="text-right"><?= $l['debit'] ? formatCurrency($l['debit']) : '-' ?></td>
            <td class="text-right"><?= $l['credit'] ? formatCurrency($l['credit']) : '-' ?></td>
            <td class="text-right"><strong><?= formatCurrency($bal) ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:#f8f9fc;font-weight:bold;">
          <td colspan="3" class="text-right">Totals</td>
          <td class="text-right"><?= formatCurrency($credit_total) ?></td>
          <td class="text-right"><?= formatCurrency($debit_total) ?></td>
          <td class="text-right"><?= formatCurrency($closing) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="r-software">[Software By @ ATR ]</div>

</div>

<?php if ($is_print): ?><script>window.print()</script><?php endif; ?>
</body>
</html>
