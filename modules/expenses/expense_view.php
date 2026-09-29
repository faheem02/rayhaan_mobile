<?php
session_start();
$base_url = '../../';
require_once '../../includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$exp = $pdo->prepare("
    SELECT e.*, ec.name AS category_name, 
           ba.bank_name, ba.account_name,
           cu.username AS created_by_name,
           au.username AS approved_by_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    LEFT JOIN bank_accounts ba ON e.bank_account_id = ba.id
    LEFT JOIN users cu ON e.created_by = cu.id
    LEFT JOIN users au ON e.approved_by = au.id
    WHERE e.id = ?
");
$exp->execute([$id]);
$e = $exp->fetch();

if (!$e) {
    echo '<div class="alert alert-danger">Expense not found.</div>';
    exit;
}

$status_badge = match($e['approval_status']) {
    'approved' => 'success',
    'rejected' => 'danger',
    default => 'warning'
};
?>

<div class="row">
  <div class="col-md-6">
    <table class="table table-sm table-borderless mb-0">
      <tr>
        <td class="detail-label">Expense #</td>
        <td class="detail-value"><?= $e['id'] ?></td>
      </tr>
      <tr>
        <td class="detail-label">Date</td>
        <td class="detail-value"><?= formatDate($e['expense_date']) ?></td>
      </tr>
      <tr>
        <td class="detail-label">Category</td>
        <td class="detail-value"><span class="badge badge-secondary"><?= htmlspecialchars($e['category_name'] ?? 'Uncategorized') ?></span></td>
      </tr>
      <tr>
        <td class="detail-label">Amount</td>
        <td class="detail-value text-danger h5 mb-0"><?= formatCurrency($e['amount']) ?></td>
      </tr>
      <tr>
        <td class="detail-label">Payment Method</td>
        <td class="detail-value">
          <span class="badge badge-<?= $e['payment_method'] === 'cash' ? 'success' : 'info' ?>"><?= ucfirst($e['payment_method']) ?></span>
          <?php if ($e['payment_method'] === 'bank' && $e['bank_name']): ?>
            <small class="text-muted d-block"><?= htmlspecialchars($e['bank_name'] . ' - ' . $e['account_name']) ?></small>
          <?php endif; ?>
        </td>
      </tr>
    </table>
  </div>
  <div class="col-md-6">
    <table class="table table-sm table-borderless mb-0">
      
      <tr>
        <td class="detail-label">Status</td>
        <td class="detail-value"><span class="badge badge-<?= $status_badge ?>"><?= ucfirst($e['approval_status']) ?></span></td>
      </tr>
      <tr>
        <td class="detail-label">Created By</td>
        <td class="detail-value"><?= htmlspecialchars($e['created_by_name'] ?? 'System') ?></td>
      </tr>
      <tr>
        <td class="detail-label">Approved By</td>
        <td class="detail-value"><?= htmlspecialchars($e['approved_by_name'] ?? '-') ?></td>
      </tr>
      <tr>
        <td class="detail-label">Created At</td>
        <td class="detail-value"><?= formatDate($e['created_at']) ?></td>
      </tr>
    </table>
  </div>
</div>
<?php if ($e['description'] || $e['notes']): ?>
  <hr class="my-2">
  <?php if ($e['description']): ?>
    <div class="mb-1">
      <span class="detail-label">Description</span>
      <div class="detail-value"><?= nl2br(htmlspecialchars($e['description'])) ?></div>
    </div>
  <?php endif; ?>
  <?php if ($e['notes']): ?>
    <div class="mb-1">
      <span class="detail-label">Notes</span>
      <div class="detail-value"><?= nl2br(htmlspecialchars($e['notes'])) ?></div>
    </div>
  <?php endif; ?>
<?php endif; ?>
