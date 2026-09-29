<?php
session_start();
$id = (int)($_GET['id'] ?? 0);
$is_print = isset($_GET['print']);
require_once '../../includes/functions.php';

$stmt = $pdo->prepare("
    SELECT p.*, s.invoice_no, s.sale_date, s.total_amount, s.paid_amount, s.due_amount,
           s.payment_status AS sale_status, s.discount_amount,
           c.id AS account_no, c.full_name AS customer_name, c.phone AS customer_phone,
           c.address AS customer_address, c.cnic AS customer_cnic,
           u.full_name AS received_by_name,
           (SELECT GROUP_CONCAT(DISTINCT COALESCE(pr.name, sit.item_description) SEPARATOR ', ')
            FROM sale_items sit
            LEFT JOIN products pr ON pr.id = sit.product_id
            WHERE sit.sale_id = s.id) AS product_names
    FROM payments p
    LEFT JOIN sales s ON p.sale_id = s.id
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN users u ON p.received_by = u.id
    WHERE p.id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$payment = $stmt->fetch();

if (!$payment) {
    $_SESSION['error'] = 'Receipt not found.';
    header("Location: ../customers/index.php");
    exit;
}

$customer_id = $payment['customer_id'] ?? null;
if (!$customer_id) {
    $cs = $pdo->query("SELECT customer_id FROM sales WHERE id = " . (int)$payment['sale_id'])->fetch();
    $customer_id = $cs['customer_id'] ?? 0;
}

$customer_data = $pdo->query("SELECT opening_due, opening_paid FROM customers WHERE id = " . (int)$customer_id)->fetch();
$opening_balance = (float)($customer_data['opening_due'] ?? 0) - (float)($customer_data['opening_paid'] ?? 0);

$total_sales = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE customer_id = " . (int)$customer_id . " AND status != 'cancelled'")->fetchColumn();
$total_payments = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE sale_id IN (SELECT id FROM sales WHERE customer_id = " . (int)$customer_id . ")")->fetchColumn();
$total_returns = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM sale_returns WHERE customer_id = " . (int)$customer_id)->fetchColumn();

$outstanding_after  = $opening_balance + $total_sales - $total_payments - $total_returns;
$outstanding_before = $outstanding_after + (float)$payment['amount'];

$receipt_no = 'RCT-' . str_pad($payment['id'], 5, '0', STR_PAD_LEFT);
$title = 'Receipt ' . $receipt_no;
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
      display:flex;
      align-items:center;
      gap:14px;
      padding: 14px 18px 8px 18px;
      border-bottom: 2px solid #1f2937;
  }
  .r-logo {
      width:60px; height:60px;
      border:3px solid #4b5563;
      border-radius:50%;
      display:flex; align-items:center; justify-content:center;
      font-weight:bold; font-size:1.4rem; color:#374151;
      flex-shrink:0;
  }
  .r-company-name {
      font-size:1.6rem;
      font-weight:700;
      margin:0;
      color:#1f2937;
  }
  .r-company-sub {
      font-size:.85rem;
      font-weight:600;
      letter-spacing:.03em;
      margin:0;
      color:#1f2937;
  }
  .r-company-contact {
      font-size:.7rem;
      color:#374151;
      margin:0;
  }
  .r-meta {
      display:flex;
      justify-content:space-between;
      padding: 6px 18px;
      font-size:.8rem;
      font-weight:600;
      border-bottom: 1px solid #1f2937;
  }
  .r-section-title {
      font-size:.95rem;
      font-weight:700;
      padding: 8px 18px;
      border-bottom: 1px solid #1f2937;
      text-decoration: underline;
      text-underline-offset: 3px;
  }
  .r-row {
      display:flex;
      flex-wrap:wrap;
      padding: 8px 18px;
      border-bottom: 1px solid #1f2937;
      font-size:.95rem;
      gap: 6px 24px;
  }
  .r-row .r-field {
      display:flex;
      gap:6px;
  }
  .r-row .r-field .lbl { font-weight:700; }
  .r-row .r-field .val { font-weight:400; }
  .r-balance-row {
      display:flex;
      justify-content:space-between;
      padding: 12px 18px;
      border-bottom: 1px solid #1f2937;
      font-size:1rem;
      font-weight:700;
  }
  .r-balance-row .current {
      font-size: 1.15rem;
      color:#dc2626;
  }
  .r-sign-row {
      display:flex;
      justify-content:space-between;
      align-items:flex-end;
      padding: 24px 18px 10px 18px;
      border-bottom: 1px solid #1f2937;
      font-size:.9rem;
      font-weight:600;
      min-height: 50px;
  }
  .r-thanks {
      text-align:center;
      font-weight:700;
      text-decoration: underline;
      padding: 8px 18px;
      font-size:.95rem;
      border-bottom: 1px solid #e5e7eb;
  }
  .r-software {
      text-align:left;
      font-size:.7rem;
      color:#9ca3af;
      padding: 6px 18px 14px 18px;
  }
</style>
</head>
<body>

<div class="no-print text-center mt-3 mb-3">
  <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
  <a href="../customers/index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Customers</a>
</div>

<div class="receipt-box">

  <!-- Header -->
  <div class="r-header">
    <div class="r-logo">RMK</div>
    <div>
      <p class="r-company-name">RAYHAAN MOBILE KAHUTA</p>
      <p class="r-company-sub">Mobile Phone Sales</p>
      <p class="r-company-contact">Near Ameen Plaza, Kallar Road, Kahuta | Cell: 0336-5389945</p>
    </div>
  </div>

  <!-- Date / Receipt No -->
  <div class="r-meta">
    <span><?= htmlspecialchars($receipt_no) ?></span>
    <span><?= date('n/j/Y', strtotime($payment['payment_date'])) ?></span>
    <span><?= $payment['invoice_no'] ? 'Invoice: ' . htmlspecialchars($payment['invoice_no']) : 'Khata Payment' ?></span>
  </div>

  <!-- Customer Information -->
  <div class="r-section-title">Customer Information</div>
  <div class="r-row">
    <div class="r-field"><span class="lbl">Account #:</span><span class="val"><?= htmlspecialchars($payment['account_no'] ?? '-') ?></span></div>
    <div class="r-field"><span class="lbl">Name:</span><span class="val"><?= htmlspecialchars($payment['customer_name'] ?? 'N/A') ?></span></div>
    <div class="r-field"><span class="lbl">Phone:</span><span class="val"><?= htmlspecialchars($payment['customer_phone'] ?? '-') ?></span></div>
  </div>
  <div class="r-row">
    <div class="r-field"><span class="lbl">CNIC:</span><span class="val"><?= htmlspecialchars($payment['customer_cnic'] ?: '00') ?></span></div>
  </div>
  <div class="r-row">
    <div class="r-field"><span class="lbl">Address:</span><span class="val"><?= htmlspecialchars($payment['customer_address'] ?? '-') ?></span></div>
  </div>

  <!-- Product And Payment Detail -->
  <div class="r-section-title">Payment Detail</div>
  <div class="r-row">
    <div class="r-field"><span class="lbl">Products:</span><span class="val"><?= htmlspecialchars($payment['product_names'] ?? '-') ?></span></div>
    <?php if ((float)($payment['discount_amount'] ?? 0) > 0): ?>
      <div class="r-field"><span class="lbl">Discount:</span><span class="val text-danger">- <?= formatCurrency($payment['discount_amount']) ?></span></div>
    <?php endif; ?>
    <div class="r-field"><span class="lbl">Method:</span><span class="val"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $payment['payment_method'] ?? 'cash'))) ?></span></div>
    <?php if (!empty($payment['reference_no'])): ?>
      <div class="r-field"><span class="lbl">Ref #:</span><span class="val"><?= htmlspecialchars($payment['reference_no']) ?></span></div>
    <?php endif; ?>
    <?php if (!empty($payment['notes'])): ?>
      <div class="r-field"><span class="lbl">Notes:</span><span class="val"><?= htmlspecialchars($payment['notes']) ?></span></div>
    <?php endif; ?>
  </div>

  <!-- Balance Row -->
  <div class="r-balance-row">
    <span>Previous Balance = <?= formatCurrency($outstanding_before) ?></span>
    <span class="text-success">Payment Received = <?= formatCurrency($payment['amount']) ?></span>
    <span class="current">Remaining Balance = <?= formatCurrency($outstanding_after) ?></span>
  </div>

  <!-- Received By -->
  <div class="r-row">
    <div class="r-field"><span class="lbl">Received By:</span><span class="val"><?= htmlspecialchars($payment['received_by_name'] ?? '-') ?></span></div>
  </div>

  <!-- Signatures -->
  <div class="r-sign-row">
    <span>Customer Signature</span>
    <span>Manager Signature/Stamp:</span>
  </div>

  <!-- Thanks -->
  <div class="r-thanks">[Thanks For Visiting:::RAYHAAN MOBILE KAHUTA]</div>

  <!-- Software credit -->
  <div class="r-software">[Software By @ ATR ]</div>

</div>

<?php if ($is_print): ?><script>window.print()</script><?php endif; ?>
</body>
</html>
