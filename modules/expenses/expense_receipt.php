<?php
session_start();
$id = (int)($_GET['id'] ?? 0);
$is_print = isset($_GET['print']);
require_once '../../includes/functions.php';

$stmt = $pdo->prepare("
    SELECT e.*, ec.name AS category_name,
           ba.bank_name, ba.account_name,
           u.username AS created_by_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    LEFT JOIN bank_accounts ba ON e.bank_account_id = ba.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE e.id = ?
");
$stmt->execute([$id]);
$e = $stmt->fetch();

if (!$e) {
    $_SESSION['error'] = 'Expense not found.';
    header("Location: index.php");
    exit;
}

$voucher_no = 'EXP-' . str_pad($e['id'], 5, '0', STR_PAD_LEFT);
$title = 'Expense Voucher ' . $voucher_no;
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
  .amount-box .amount{font-size:2rem;font-weight:bold;color:#dc3545}
  table.details{width:100%}
  table.details td{padding:6px 10px}
  table.details td:first-child{color:#64748b;width:40%}
  table.details td:last-child{font-weight:600;width:60%}
</style>
</head>
<body>

<div class="no-print text-center mt-3 mb-3">
  <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
  <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Expenses</a>
</div>

<div class="wrap">

  <div class="receipt-header">
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <h4 class="font-weight-bold mb-1" style="color:#0f172a;">RAYHAAN MOBILE KAHUTA</h4>
        <p class="text-muted mb-0">Near Ameen Plaza, Kallar Road, Kahuta | Cell: 0336-5389945</p>
      </div>
      <div class="text-right">
        <h4 class="font-weight-bold mb-1" style="color:#dc3545;">EXPENSE VOUCHER</h4>
        <p class="mb-0"><strong><?= $voucher_no ?></strong></p>
        <p class="mb-0 text-muted"><?= formatDate($e['expense_date']) ?></p>
      </div>
    </div>
  </div>

  <div class="amount-box mb-4">
    <p class="text-muted mb-1">Expense Amount</p>
    <div class="amount"><?= formatCurrency($e['amount']) ?></div>
    <p class="mb-0 mt-2">
      <span class="badge badge-<?= $e['payment_method'] === 'cash' ? 'success' : 'info' ?> px-3 py-1">
        <?= ucfirst($e['payment_method']) ?>
      </span>
      <span class="badge badge-<?= match($e['approval_status']){'approved'=>'success','rejected'=>'danger',default=>'warning'} ?> px-3 py-1 ml-1">
        <?= ucfirst($e['approval_status']) ?>
      </span>
    </p>
  </div>

  <table class="details">
    <tr><td>Description</td><td><?= htmlspecialchars($e['description'] ?: 'N/A') ?></td></tr>
    <tr><td>Category</td><td><?= htmlspecialchars($e['category_name'] ?? 'Uncategorized') ?></td></tr>
    <?php if ($e['vendor_name']): ?>
      <tr><td>Vendor / Payee</td><td><?= htmlspecialchars($e['vendor_name']) ?></td></tr>
    <?php endif; ?>
    <?php if ($e['bill_no']): ?>
      <tr><td>Bill / Invoice No</td><td><?= htmlspecialchars($e['bill_no']) ?></td></tr>
    <?php endif; ?>
    <?php if ($e['payment_method'] === 'bank' && $e['bank_name']): ?>
      <tr><td>Bank Account</td><td><?= htmlspecialchars($e['bank_name'] . ' - ' . $e['account_name']) ?></td></tr>
    <?php endif; ?>
    <?php if ($e['notes']): ?>
      <tr><td>Notes</td><td><?= nl2br(htmlspecialchars($e['notes'])) ?></td></tr>
    <?php endif; ?>
    <tr><td>Recorded By</td><td><?= htmlspecialchars($e['created_by_name'] ?? 'System') ?></td></tr>
    <tr><td>Created At</td><td><?= formatDate($e['created_at']) ?></td></tr>
  </table>

  <div class="receipt-footer text-center">
    <p class="text-muted small mb-1">Expense recorded on <?= formatDate($e['expense_date']) ?></p>
    <p class="text-muted small mb-0">This is a computer-generated voucher.</p>
  </div>

</div>

<?php if ($is_print): ?><script>window.print()</script><?php endif; ?>
</body>
</html>
