<?php
session_start();
$id = (int)($_GET['id'] ?? 0);
$is_print = isset($_GET['print']);
require_once '../../includes/functions.php';

$party = getById('general_parties', $id);
if (!$party) { echo 'Party not found'; exit; }

$transactions = $pdo->prepare("SELECT * FROM general_transactions WHERE party_id = ? ORDER BY transaction_date ASC, id ASC");
$transactions->execute([$id]);
$transactions = $transactions->fetchAll();

$opening = (float)$party['opening_balance'];
$total_receipts = array_sum(array_map(fn($t) => $t['type'] === 'receipt' ? (float)$t['amount'] : 0, $transactions));
$total_payments = array_sum(array_map(fn($t) => $t['type'] === 'payment' ? (float)$t['amount'] : 0, $transactions));
$closing = $opening + $total_receipts - $total_payments;

$title = 'General Ledger - ' . htmlspecialchars($party['name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $title ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css">
<style>
  @media print {
    @page { size: A4; margin: 10mm; }
    body{ background:#fff; font-size:11px; }
    .no-print { display:none !important; }
    .wrap { box-shadow:none !important; border:1px solid #000 !important; max-width:100%; margin:0; }
  }
  body { background:#f1f5f9; font-family:'Segoe UI',Arial,sans-serif; font-size:14px; }
  .wrap { max-width:900px; margin:30px auto; background:#fff; border:2px solid #1f2937; border-radius:4px; padding:0; box-shadow:0 4px 24px rgba(0,0,0,.08); }
  .r-header { display:flex; align-items:center; gap:14px; padding:14px 18px 8px 18px; border-bottom:2px solid #1f2937; }
  .r-logo { width:60px; height:60px; border:3px solid #4b5563; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:1.4rem; color:#374151; flex-shrink:0; }
  .r-company-name { font-size:1.6rem; font-weight:700; margin:0; color:#1f2937; }
  .r-company-sub { font-size:.85rem; font-weight:600; letter-spacing:.03em; margin:0; color:#1f2937; }
  .r-company-contact { font-size:.7rem; color:#374151; margin:0; }
  .r-section-title { font-size:.95rem; font-weight:700; padding:8px 18px; border-bottom:1px solid #1f2937; text-decoration:underline; text-underline-offset:3px; }
  .r-row { display:flex; flex-wrap:wrap; padding:8px 18px; border-bottom:1px solid #1f2937; font-size:.95rem; gap:6px 24px; }
  .r-row .r-field { display:flex; gap:6px; }
  .r-row .r-field .lbl { font-weight:700; }
  .r-row .r-field .val { font-weight:400; }
  table { width:100%; border-collapse:collapse; }
  th, td { border:1px solid #e2e8f0; padding:6px 10px; text-align:center; }
  th { background:#f8fafc; color:#475569; font-size:12px; }
  td.text-right { text-align:right; }
  td.text-left { text-align:left; }
  .r-software { text-align:left; font-size:.7rem; color:#9ca3af; padding:6px 18px 14px 18px; }
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
      <p class="r-company-sub">Near Ameen Plaza, Kallar Road, Kahuta</p>
      <p class="r-company-contact">Cell: 0336-5389945</p>
    </div>
  </div>

  <div class="r-section-title">General Ledger</div>

  <div class="r-row">
    <div class="r-field" style="width:100%;"><span class="lbl">Party:</span><span class="val"><?=htmlspecialchars($party['name'])?> (<?=htmlspecialchars($party['phone'] ?? '-')?>)</span></div>
    <div class="r-field"><span class="lbl">Opening Balance:</span><span class="val"><?=formatCurrency($opening)?></span></div>
    <div class="r-field"><span class="lbl">Closing Balance:</span><span class="val" style="color:#dc2626;font-weight:700;"><?=formatCurrency($closing)?></span></div>
  </div>

  <div class="r-section-title">Transactions</div>
  <div style="padding:8px 18px;">
    <table>
      <thead>
        <tr><th style="width:100px;">Date</th><th class="text-left">Description</th><th style="width:100px;">Receipt</th><th style="width:100px;">Payment</th><th style="width:100px;">Balance</th></tr>
      </thead>
      <tbody>
        <tr style="background:#f8f9fc;"><td colspan="4" class="text-right"><strong>Opening Balance</strong></td><td><strong><?=formatCurrency($opening)?></strong></td></tr>
        <?php $bal = $opening; foreach ($transactions as $t):
          $bal += $t['type'] === 'receipt' ? (float)$t['amount'] : -(float)$t['amount'];
          $type_label = $t['type'] === 'receipt' ? 'Receipt' : 'Payment';
        ?>
          <tr>
            <td><?=formatDate($t['transaction_date'])?></td>
            <td class="text-left small"><?=htmlspecialchars($t['description'] ?? '-')?> <span class="badge badge-<?=$t['type']==='receipt'?'success':'danger'?>"><?=$type_label?></span></td>
            <td><?=$t['type']==='receipt'?formatCurrency($t['amount']):'-'?></td>
            <td><?=$t['type']==='payment'?formatCurrency($t['amount']):'-'?></td>
            <td><strong><?=formatCurrency($bal)?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:#f8f9fc;font-weight:bold;">
          <td colspan="2" class="text-right">Totals</td>
          <td><?=formatCurrency($total_receipts)?></td>
          <td><?=formatCurrency($total_payments)?></td>
          <td><?=formatCurrency($closing)?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="r-software">[Software By @ ATR ]</div>

</div>

<?php if ($is_print): ?><script>window.print()</script><?php endif; ?>
</body>
</html>
