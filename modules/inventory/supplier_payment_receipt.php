<?php
session_start();
$payment_id = (int)($_GET['payment_id'] ?? 0);
$supplier_id = (int)($_GET['supplier_id'] ?? 0);
$is_print = isset($_GET['print']);
require_once '../../includes/functions.php';

$stmt = $pdo->prepare("
    SELECT sp.*, s.name AS supplier_name, s.contact_person,
           ba.bank_name, ba.account_name,
           u.username AS created_by_name
    FROM supplier_payments sp
    LEFT JOIN suppliers s ON sp.supplier_id = s.id
    LEFT JOIN bank_accounts ba ON sp.bank_account_id = ba.id
    LEFT JOIN users u ON sp.created_by = u.id
    WHERE sp.id = ? AND sp.supplier_id = ?
");
$stmt->execute([$payment_id, $supplier_id]);
$p = $stmt->fetch();

if (!$p) {
    $_SESSION['error'] = 'Payment not found.';
    header("Location: suppliers.php");
    exit;
}

$voucher_no = 'SPP-' . str_pad($p['id'], 5, '0', STR_PAD_LEFT);
$title = 'Payment Receipt ' . $voucher_no;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?=$title?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
<style>
  @media print{body{font-size:12px}.no-print{display:none!important}}
  body{background:#f1f5f9;font-family:'Segoe UI',sans-serif}
  .wrap{max-width:700px;margin:30px auto;background:#fff;border-radius:12px;padding:35px;box-shadow:0 4px 24px rgba(0,0,0,.08)}
  .receipt-header{border-bottom:2px dashed #e2e8f0;padding-bottom:20px;margin-bottom:20px}
  .receipt-footer{border-top:2px dashed #e2e8f0;padding-top:20px;margin-top:20px}
  .amount-box{background:#f8fafc;border-radius:8px;padding:15px;text-align:center;border:2px solid #e2e8f0}
  .amount-box .amount{font-size:2rem;font-weight:bold;color:#28a745}
  table.details{width:100%}
  table.details td{padding:6px 10px}
  table.details td:first-child{color:#64748b;width:40%}
  table.details td:last-child{font-weight:600;width:60%}
</style>
</head>
<body>

<div class="no-print text-center mt-3 mb-3">
  <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
  <button class="btn btn-secondary" onclick="window.close()"><i class="fas fa-times"></i> Close</button>
</div>

<div class="wrap">

  <div class="receipt-header">
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <h4 class="font-weight-bold mb-1" style="color:#0f172a;">RAYHAAN MOBILE KAHUTA</h4>
        <p class="text-muted mb-0">Near Ameen Plaza, Kallar Road, Kahuta | Cell: 0336-5389945</p>
      </div>
      <div class="text-right">
        <h4 class="font-weight-bold mb-1" style="color:#28a745;">PAYMENT RECEIPT</h4>
        <p class="mb-0"><strong><?= $voucher_no ?></strong></p>
        <p class="mb-0 text-muted"><?= formatDate($p['payment_date']) ?></p>
      </div>
    </div>
  </div>

  <div class="amount-box mb-4">
    <p class="text-muted mb-1">Payment Amount</p>
    <div class="amount"><?= formatCurrency($p['amount']) ?></div>
    <p class="mb-0 mt-2">
      <span class="badge badge-<?= $p['payment_method'] === 'cash' ? 'success' : 'info' ?> px-3 py-1">
        <?= ucfirst($p['payment_method']) ?>
      </span>
    </p>
  </div>

  <table class="details">
    <tr><td>Supplier</td><td><?= htmlspecialchars(($p['contact_person'] ?? '') . ' (' . ($p['supplier_name'] ?? '') . ')') ?></td></tr>
    <tr><td>Description</td><td><?= htmlspecialchars($p['description'] ?: 'N/A') ?></td></tr>
    <?php if ($p['payment_method'] === 'bank' && $p['bank_name']): ?>
      <tr><td>Bank Account</td><td><?= htmlspecialchars($p['bank_name'] . ' - ' . $p['account_name']) ?></td></tr>
    <?php endif; ?>
    <tr><td>Recorded By</td><td><?= htmlspecialchars($p['created_by_name'] ?? 'System') ?></td></tr>
    <tr><td>Created At</td><td><?= formatDate($p['created_at']) ?></td></tr>
  </table>

  <div class="receipt-footer text-center">
    <p class="text-muted small mb-1">Payment recorded on <?= formatDate($p['payment_date']) ?></p>
    <p class="text-muted small mb-0">This is a computer-generated receipt.</p>
  </div>

</div>

<?php if ($is_print): ?><script>window.print()</script><?php endif; ?>
</body>
</html>
