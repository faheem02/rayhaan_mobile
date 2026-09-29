<?php
session_start();
$id = (int)($_GET['id'] ?? 0);
$is_print = isset($_GET['print']);
require_once '../../includes/functions.php';

$supplier = getById('suppliers', $id);
if (!$supplier) { $_SESSION['error'] = 'Supplier not found.'; header("Location: suppliers.php"); exit; }

$purchases_data = $pdo->prepare("SELECT * FROM purchases WHERE supplier_id = ? AND status != 'cancelled' ORDER BY purchase_date ASC");
$purchases_data->execute([$id]); $purchases_data = $purchases_data->fetchAll();

$payments_data = $pdo->prepare("SELECT * FROM supplier_payments WHERE supplier_id = ? ORDER BY payment_date ASC");
$payments_data->execute([$id]); $payments_data = $payments_data->fetchAll();

$purchase_items_data = $pdo->prepare("SELECT pi.*, p.name AS product_name, p.code AS product_code, pu.id as purchase_id FROM purchase_items pi JOIN purchases pu ON pu.id = pi.purchase_id LEFT JOIN products p ON p.id = pi.product_id WHERE pu.supplier_id = ?");
$purchase_items_data->execute([$id]); $purchase_items_data = $purchase_items_data->fetchAll();

$purchase_products = [];
foreach ($purchase_items_data as $pi) {
    $pid = $pi['purchase_id'];
    $pname = $pi['product_name'] ?? $pi['product_code'] ?? 'Item';
    if (!isset($purchase_products[$pid])) $purchase_products[$pid] = [];
    if (!in_array($pname, $purchase_products[$pid])) $purchase_products[$pid][] = $pname;
}

$serials_stmt = $pdo->prepare("SELECT purchase_id, imei_number, serial_number FROM product_serials WHERE purchase_id IN (SELECT id FROM purchases WHERE supplier_id = ?) ORDER BY purchase_id, id");
$serials_stmt->execute([$id]);
$purchase_serials = [];
foreach ($serials_stmt->fetchAll() as $sr) {
    $purchase_serials[$sr['purchase_id']][] = $sr;
}

$ledger = [];
foreach ($purchases_data as $p) {
    $products = $purchase_products[$p['id']] ?? [];
    $desc = !empty($products) ? implode(', ', array_slice($products, 0, 3)) : 'Products supplied';
    if (count($products) > 3) $desc .= ' +' . (count($products) - 3) . ' more';
    $imeis = [];
    foreach (($purchase_serials[$p['id']] ?? []) as $sr) {
        $unit = $sr['imei_number'] ?? '';
        if (!empty($sr['serial_number'])) $unit .= ' / ' . $sr['serial_number'];
        if ($unit) $imeis[] = $unit;
    }
    $ledger[] = ['date' => $p['purchase_date'], 'type' => 'purchase', 'ref' => $p['invoice_no'] ?? '#' . $p['id'], 'debit' => (float)$p['total_amount'], 'credit' => 0, 'desc' => $desc, 'imeis' => $imeis];
}
foreach ($payments_data as $p) {
    $ledger[] = ['date' => $p['payment_date'], 'type' => 'payment', 'ref' => '#' . $p['id'], 'debit' => 0, 'credit' => (float)$p['amount'], 'desc' => $p['description'] ?? 'Cash paid'];
}
usort($ledger, function($a, $b) { return strcmp($a['date'], $b['date']); });

$opening = (float)$supplier['opening_balance'] + (float)$supplier['adjustment'];
$debit_total = array_sum(array_column($ledger, 'debit'));
$credit_total = array_sum(array_column($ledger, 'credit'));
$closing = $opening + $debit_total - $credit_total;

$title = 'Supplier Ledger - ' . htmlspecialchars($supplier['contact_person'] ?? $supplier['name']);
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

  <div class="r-section-title">Supplier Ledger</div>

  <div class="r-row">
    <div class="r-field" style="width:100%;"><span class="lbl">Supplier:</span><span class="val"><?= htmlspecialchars(($supplier['contact_person'] ?? '') . ' (' . ($supplier['name'] ?? '') . ')') ?> (<?= htmlspecialchars($supplier['phone'] ?? '') ?>)</span></div>
    <div class="r-field"><span class="lbl">Opening Balance:</span><span class="val"><?= formatCurrency($opening) ?></span></div>
    <div class="r-field"><span class="lbl">Closing Balance:</span><span class="val" style="color:#dc2626;font-weight:700;"><?= formatCurrency($closing) ?></span></div>
  </div>

  <div class="r-section-title">Ledger Entries</div>
  <div style="padding:8px 18px;">
    <table>
      <thead>
        <tr><th style="width:100px;">Date</th><th style="width:90px;">Ref</th><th class="text-left">Description</th><th style="width:100px;">Debit</th><th style="width:100px;">Credit</th><th style="width:100px;">Balance</th></tr>
      </thead>
      <tbody>
        <tr style="background:#f8f9fc;"><td colspan="5" class="text-right"><strong>Opening Balance</strong></td><td><strong><?= formatCurrency($opening) ?></strong></td></tr>
        <?php $bal = $opening; foreach ($ledger as $l):
          $bal += $l['debit'] - $l['credit'];
          $bg = $l['type'] === 'payment' ? 'style="background:#f0fff4;"' : '';
        ?>
          <tr <?= $bg ?>>
            <td><?= formatDate($l['date']) ?></td>
            <td><span class="badge badge-secondary"><?= htmlspecialchars($l['ref']) ?></span></td>
            <td class="text-left small"><?= htmlspecialchars($l['desc']) ?>
              <?php if (!empty($l['imeis'])): ?>
                <?php foreach ($l['imeis'] as $u): ?><div class="text-muted">IMEI: <?= htmlspecialchars($u) ?></div><?php endforeach; ?>
              <?php endif; ?>
            </td>
            <td class="text-right"><?= $l['debit'] ? formatCurrency($l['debit']) : '-' ?></td>
            <td class="text-right"><?= $l['credit'] ? formatCurrency($l['credit']) : '-' ?></td>
            <td class="text-right"><strong><?= formatCurrency($bal) ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:#f8f9fc;font-weight:bold;">
          <td colspan="3" class="text-right">Totals</td>
          <td class="text-right"><?= formatCurrency($debit_total) ?></td>
          <td class="text-right"><?= formatCurrency($credit_total) ?></td>
          <td class="text-right"><?= formatCurrency($closing) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="r-software">[Thanks For Visiting:::RAYHAAN MOBILE KAHUTA]</div>
   
</div>

<?php if ($is_print): ?><script>window.print()</script><?php endif; ?>
</body>
</html>
