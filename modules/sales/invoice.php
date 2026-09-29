<?php
session_start();
$id = (int)($_GET['id'] ?? 0);
$is_print = isset($_GET['print']);
require_once '../../includes/functions.php';

$sale = getById('sales', $id);
if (!$sale) {
    $_SESSION['error'] = 'Invoice not found.';
    header("Location: invoices.php");
    exit;
}

$customer = getById('customers', $sale['customer_id']);
$items    = getWhere('sale_items', 'sale_id', $id);
$discount = $sale['discount_id'] ? getById('discounts', $sale['discount_id']) : null;

$serials = $pdo->prepare("SELECT s.*, p.product_type FROM product_serials s JOIN products p ON p.id = s.product_id WHERE s.sale_id = ? ORDER BY s.id");
$serials->execute([$id]);
$serials = $serials->fetchAll();
$serials_by_product = [];
foreach ($serials as $sn) {
    $serials_by_product[$sn['product_id']][] = $sn;
}

$pay_stmt = $pdo->prepare("SELECT * FROM payments WHERE sale_id = ? ORDER BY payment_date ASC");
$pay_stmt->execute([$id]);
$payments = $pay_stmt->fetchAll();

$badge_map = ['paid'=>'success', 'partial'=>'warning', 'pending'=>'danger'];
$badge = $badge_map[$sale['payment_status']] ?? 'secondary';

$total_paid = array_sum(array_map(fn($p) => (float)$p['amount'], $payments));

// Customer total outstanding across all sales
$customer_data = $pdo->query("SELECT opening_due, opening_paid FROM customers WHERE id = " . (int)$sale['customer_id'])->fetch();
$opening_balance = (float)($customer_data['opening_due'] ?? 0) - (float)($customer_data['opening_paid'] ?? 0);
$total_sales_all = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE customer_id = " . (int)$sale['customer_id'] . " AND status != 'cancelled'")->fetchColumn();
$total_payments_all = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE sale_id IN (SELECT id FROM sales WHERE customer_id = " . (int)$sale['customer_id'] . ")")->fetchColumn();
$total_returns = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM sale_returns WHERE customer_id = " . (int)$sale['customer_id'])->fetchColumn();
$remaining = $opening_balance + $total_sales_all - $total_payments_all - $total_returns;

$title = 'Invoice #' . htmlspecialchars($sale['invoice_no']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $title ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
<style>
  @media print {
      @page { size: A4; margin: 10mm; }
      body { background:#fff; font-size:11px; }
      .no-print { display:none !important; }
      .receipt-box { box-shadow:none !important; border:1px solid #000 !important; max-width:100%; margin:0; }
  }
  body {
      background:#f1f5f9;
      font-family: 'Segoe UI', Arial, sans-serif;
      font-size:14px;
  }
  .receipt-box {
      max-width: 720px;
      margin: 30px auto;
      background:#fff;
      border: 2px solid #1f2937;
      border-radius: 4px;
      padding: 0;
      box-shadow: 0 4px 24px rgba(0,0,0,.08);
  }
  .r-header {
      display:flex; align-items:center; gap:14px;
      padding: 14px 18px 8px 18px;
      border-bottom: 2px solid #1f2937;
  }
  .r-logo {
      width:60px; height:60px;
      border:3px solid #4b5563; border-radius:50%;
      display:flex; align-items:center; justify-content:center;
      font-weight:bold; font-size:1.4rem; color:#374151;
      flex-shrink:0;
  }
  .r-company-name { font-size:1.6rem; font-weight:700; margin:0; color:#1f2937; }
  .r-company-sub { font-size:.85rem; font-weight:600; letter-spacing:.03em; margin:0; color:#1f2937; }
  .r-company-contact { font-size:.7rem; color:#374151; margin:0; }
  .r-meta {
      display:flex; justify-content:space-between;
      padding: 6px 18px; font-size:.8rem; font-weight:600;
      border-bottom: 1px solid #1f2937;
  }
  .r-section-title {
      font-size:.95rem; font-weight:700;
      padding: 8px 18px;
      border-bottom: 1px solid #1f2937;
      text-decoration: underline; text-underline-offset: 3px;
  }
  .r-row {
      display:flex; flex-wrap:wrap;
      padding: 8px 18px;
      border-bottom: 1px solid #1f2937;
      font-size:.95rem; gap: 6px 24px;
  }
  .r-row .r-field { display:flex; gap:6px; }
  .r-row .r-field .lbl { font-weight:700; }
  .r-row .r-field .val { font-weight:400; }
  .r-table {
      width:100%; border-collapse:collapse; font-size:.85rem;
  }
  .r-table th, .r-table td {
      border:1px solid #1f2937; padding:6px 8px; text-align:center;
  }
  .r-table th { font-weight:700; }
  .r-balance-row {
      display:flex; justify-content:space-between;
      padding: 12px 18px;
      border-bottom: 1px solid #1f2937;
      font-size:1rem; font-weight:700;
  }
  .r-balance-row .current { font-size:1.15rem; color:#dc2626; }
  .r-fin-row {
      display:flex; flex-wrap:wrap; justify-content:space-between;
      padding: 8px 18px;
      border-bottom: 1px solid #1f2937;
      font-size:.9rem; gap: 4px 20px;
  }
  .r-fin-row .r-field { display:flex; gap:6px; }
  .r-fin-row .r-field .lbl { font-weight:700; }
  .r-fin-row .r-field .val { font-weight:400; }
  .r-sign-row {
      display:flex; justify-content:space-between; align-items:flex-end;
      padding: 24px 18px 10px 18px;
      border-bottom: 1px solid #1f2937;
      font-size:.9rem; font-weight:600;
      min-height:50px;
  }
  .r-thanks {
      text-align:center; font-weight:700; text-decoration:underline;
      padding: 8px 18px; font-size:.95rem;
      border-bottom: 1px solid #e5e7eb;
  }
  .r-software {
      text-align:left; font-size:.7rem; color:#9ca3af;
      padding: 6px 18px 14px 18px;
  }
</style>
<script src="../../assets/js/JsBarcode.all.min.js"></script>
<script src="../../assets/js/barcode_helper.js"></script>
</head>
<body>

<div class="no-print text-center mt-3 mb-3">
  <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
  <a href="invoices.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<div class="receipt-box">

  <!-- Header -->
  <div class="r-header">
    <div class="r-logo">RMK</div>
    <div>
      <p class="r-company-name">RAYHAAN MOBILE KAHUTA</p>
      <p class="r-company-sub">Near Ameen Plaza, Kallar Road, Kahuta</p>
      <p class="r-company-contact">Cell: 0336-5389945</p>
    </div>
  </div>

  <!-- Date / Invoice No -->
  <div class="r-meta">
    <span>Invoice #: <?= htmlspecialchars($sale['invoice_no']) ?></span>
    <span>Date: <?= date('n/j/Y', strtotime($sale['sale_date'])) ?></span>
  </div>

  <!-- Customer Information -->
  <div class="r-section-title">Customer Information</div>
  <div class="r-row">
    <div class="r-field"><span class="lbl">Name:</span><span class="val"><?= htmlspecialchars($customer['full_name'] ?? 'N/A') ?></span></div>
    <div class="r-field"><span class="lbl">Phone:</span><span class="val"><?= htmlspecialchars($customer['phone'] ?? '') ?></span></div>
    <div class="r-field" style="width:100%;"><span class="lbl">Address:</span><span class="val"><?= nl2br(htmlspecialchars($customer['address'] ?? '')) ?></span></div>
    <div class="r-field"><span class="lbl">CNIC:</span><span class="val"><?= htmlspecialchars($customer['cnic'] ?? 'N/A') ?></span></div>
  </div>

  <!-- Products Table -->
  <div class="r-section-title">Products Detail</div>
  <div style="padding:8px 18px;">
    <table class="r-table">
      <thead>
        <tr>
          <th width="5%">#</th>
          <th width="40%">Product</th>
          <th width="10%">Qty</th>
          <th width="20%">Price</th>
          <th width="15%">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php $i=1; foreach($items as $item):
          $prod = getById('products', $item['product_id']);
          $prodName = preg_replace('/\s*\[IMEI:[^\]]*\]\s*/', '', $item['item_description'] ?: (($p = $prod) ? $p['name'] : 'Item'));
          $prodCode = $item['item_description'] ? '' : (($prod) ? ($prod['code']??'') : '');
          $itemSerials = $prod ? ($serials_by_product[$prod['id']] ?? []) : [];
        ?>
        <tr>
          <td><?=$i++?></td>
          <td class="text-left"><?=htmlspecialchars($prodName)?> <?php if ($prodCode): ?><br><small><?=htmlspecialchars($prodCode)?></small><?php endif; ?>
            <?php if (!empty($itemSerials)): ?>
              <?php foreach ($itemSerials as $sn): ?>
                <div style="margin-top:3px; font-size:.75rem;">
                  <div><strong>IMEI:</strong> <?=htmlspecialchars($sn['imei_number'] ?? '-')?><?php if (!empty($sn['serial_number'])): ?> / <strong>IMEI 2:</strong> <?=htmlspecialchars($sn['serial_number'])?><?php endif; ?></div>
                  <?php if (!empty($sn['imei_number']) && $sn['imei_number'] !== '-'): ?>
                    <div style="margin-top:2px;">
                      <svg class="imei-barcode" data-barcode="<?=htmlspecialchars(trim($sn['imei_number']))?>" data-width="1.0" data-height="24" data-font-size="8"></svg>
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($sn['serial_number']) && $sn['serial_number'] !== '-'): ?>
                    <div style="margin-top:2px;">
                      <svg class="imei-barcode" data-barcode="<?=htmlspecialchars(trim($sn['serial_number']))?>" data-width="1.0" data-height="24" data-font-size="8"></svg>
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($sn['notes'])): ?><span class="text-muted"><?=htmlspecialchars($sn['notes'])?></span><?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>
          <td><?=$item['quantity']?></td>
          <td><?=formatCurrency($item['price'])?></td>
          <td><?=formatCurrency($item['subtotal'])?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Financial Summary -->
  <div class="r-section-title">Payment Summary</div>
  <div class="r-fin-row">
    <div class="r-field"><span class="lbl">Subtotal:</span><span class="val"><?= formatCurrency($sale['subtotal']) ?></span></div>
    <?php if (($sale['discount_amount'] ?? 0) > 0): ?>
      <div class="r-field"><span class="lbl">Discount:</span><span class="val" style="color:#dc2626;">-<?= formatCurrency($sale['discount_amount']) ?></span></div>
    <?php endif; ?>
    <div class="r-field"><span class="lbl">Total:</span><span class="val"><?= formatCurrency($sale['total_amount']) ?></span></div>
    <div class="r-field"><span class="lbl">Amount Paid:</span><span class="val" style="color:#16a34a;"><?= formatCurrency($sale['paid_amount'] ?? $total_paid) ?></span></div>
    <div class="r-field"><span class="lbl">Balance Due:</span><span class="val" style="color:#dc2626;font-weight:700;"><?= formatCurrency($sale['due_amount'] ?? 0) ?></span></div>
    <div class="r-field"><span class="lbl">Status:</span><span class="val"><?= ucfirst($sale['payment_status']) ?></span></div>
    <div class="r-field"><span class="lbl">Total Paid (This Sale):</span><span class="val" style="color:#16a34a;"><?= formatCurrency($total_paid) ?></span></div>
  </div>

  <!-- Balance Row -->
  <div class="r-balance-row">
    <span>Previous Balance = <?= formatCurrency($opening_balance) ?></span>
    <span>Total Paid = <?= formatCurrency($total_paid) ?></span>
    <span class="current">Remaining Balance = <?= formatCurrency($remaining) ?></span>
  </div>

  <!-- Payment History -->
  <?php if ($payments): ?>
    <div class="r-section-title">Payment History</div>
    <div style="padding:8px 18px;">
      <table class="r-table">
        <thead>
          <tr><th>Date</th><th>Amount</th><th>Method</th><th>Type</th></tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td><?= formatDate($p['payment_date']) ?></td>
              <td><?= formatCurrency($p['amount']) ?></td>
              <td><?= ucfirst($p['payment_method']) ?></td>
              <td><?= ucfirst(str_replace('_', ' ', $p['payment_type'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <!-- Signatures -->
  <div class="r-sign-row">
    <span>Customer Signature</span>
    <span>Salesman Signature</span>
    <span>Manager Signature/Stamp</span>
  </div>

  <!-- Thanks -->
  <div class="r-thanks">[Thanks For Visiting:::RAYHAAN MOBILE KAHUTA]</div>

  <!-- Software credit -->
  <div class="r-software">[Software By @ ATR]</div>

</div>

<?php if ($is_print): ?><script>window.print()</script><?php endif; ?>
</body>
</html>
