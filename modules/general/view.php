<?php
session_start();
$page_title = 'General Ledger';
$base_url = '../../';
require_once '../../includes/functions.php';
$id = (int)($_GET['id'] ?? 0);
$party = getById('general_parties', $id);
if (!$party) { $_SESSION['error'] = 'Party not found'; header("Location: index.php"); exit; }

$bank_accounts = getAll('bank_accounts', 'bank_name ASC, account_name ASC');

// Handle Add Transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_transaction'])) {
    $txn_date = $_POST['transaction_date'] ?? date('Y-m-d');
    $amount = (float)($_POST['amount'] ?? 0);
    $type = $_POST['type'] ?? 'receipt';
    $method = $_POST['payment_method'] ?? 'cash';
    $bank_account_id = (int)($_POST['bank_account_id'] ?? 0);
    $desc = trim($_POST['description'] ?? '');
    $created_by = $_SESSION['user_id'] ?? 1;

    $txn_id = insert('general_transactions', [
        'party_id' => $id,
        'transaction_date' => $txn_date,
        'type' => $type,
        'amount' => $amount,
        'payment_method' => $method,
        'bank_account_id' => $bank_account_id ?: null,
        'description' => $desc ?: null,
        'created_by' => $created_by,
        'created_at' => date('Y-m-d'),
    ]);

    $ref_desc = 'General ' . $type . ' - ' . htmlspecialchars($party['name']) . ($desc ? ' (' . $desc . ')' : '');
    if ($type === 'payment') {
        if ($method === 'cash') {
            recordCashOutflow($pdo, $txn_date, $amount, $ref_desc, 'general_transaction', $txn_id, $created_by);
        } elseif ($method === 'bank') {
            recordBankOutflow($pdo, $txn_date, $amount, $ref_desc, 'general_transaction', $txn_id, $created_by, $bank_account_id);
        }
    } else {
        if ($method === 'cash') {
            recordCashInflow($pdo, $txn_date, $amount, $ref_desc, 'general_transaction', $txn_id, $created_by);
        } elseif ($method === 'bank') {
            recordBankInflow($pdo, $txn_date, $amount, $ref_desc, 'general_transaction', $txn_id, $created_by, $bank_account_id);
        }
    }

    $_SESSION['success'] = 'Transaction recorded';
    header("Location: view.php?id=$id");
    exit;
}

// Handle Delete Transaction
if (isset($_GET['delete_txn'])) {
    $txn_id = (int)$_GET['delete_txn'];
    $txn = $pdo->prepare("SELECT * FROM general_transactions WHERE id = ? AND party_id = ?");
    $txn->execute([$txn_id, $id]);
    $txn_data = $txn->fetch();
    if ($txn_data) {
        if ($txn_data['payment_method'] === 'cash') {
            $pdo->prepare("DELETE FROM cash_book WHERE reference_type = 'general_transaction' AND reference_id = ?")->execute([$txn_id]);
        } elseif ($txn_data['payment_method'] === 'bank') {
            $pdo->prepare("DELETE FROM bank_transactions WHERE reference_type = 'general_transaction' AND reference_id = ?")->execute([$txn_id]);
        }
        $pdo->prepare("DELETE FROM general_transactions WHERE id = ?")->execute([$txn_id]);
        $_SESSION['success'] = 'Transaction deleted';
    }
    header("Location: view.php?id=$id");
    exit;
}

$transactions = $pdo->prepare("SELECT gt.*, u.username FROM general_transactions gt LEFT JOIN users u ON gt.created_by = u.id WHERE gt.party_id = ? ORDER BY gt.transaction_date ASC, gt.id ASC");
$transactions->execute([$id]);
$transactions = $transactions->fetchAll();

$opening = (float)$party['opening_balance'];
$total_receipts = array_sum(array_map(fn($t) => $t['type'] === 'receipt' ? (float)$t['amount'] : 0, $transactions));
$total_payments = array_sum(array_map(fn($t) => $t['type'] === 'payment' ? (float)$t['amount'] : 0, $transactions));
$closing = $opening + $total_receipts - $total_payments;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?=$page_title?> - <?=htmlspecialchars($party['name'])?></title>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
</head>
<body>
<?php include $base_url . 'includes/header.php'; ?>

<div class="container-fluid">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0" style="color:#0f172a;"><?=htmlspecialchars($party['name'])?> - Ledger</h1>
    <div>
      <a href="ledger_print.php?id=<?=$id?>" target="_blank" class="btn btn-sm btn-danger"><i class="fas fa-print"></i> Print Ledger</a>
      <a href="index.php" class="btn btn-sm btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
  </div>

  <?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><?=$_SESSION['success']; unset($_SESSION['success'])?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
  <?php endif; ?>

  <div class="row">
    <div class="col-md-4">
      <div class="card shadow mb-4">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold" style="color:#0f172a;">Party Info</h6></div>
        <div class="card-body">
          <p class="mb-1"><strong>Phone:</strong> <?=htmlspecialchars($party['phone'] ?? '-')?></p>
          <p class="mb-1"><strong>Address:</strong> <?=htmlspecialchars($party['address'] ?? '-')?></p>
          <hr>
          <p class="mb-1"><strong>Opening Balance:</strong> <?=formatCurrency($opening)?></p>
          <p class="mb-1"><strong>Total Receipts:</strong> <span class="text-success"><?=formatCurrency($total_receipts)?></span></p>
          <p class="mb-1"><strong>Total Payments:</strong> <span class="text-danger"><?=formatCurrency($total_payments)?></span></p>
          <p class="mb-0"><strong>Closing Balance:</strong> <span style="color:#dc2626;font-weight:700;"><?=formatCurrency($closing)?></span></p>
        </div>
      </div>

      <!-- Add Transaction -->
      <div class="card shadow mb-4">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold" style="color:#0f172a;">Add Transaction</h6></div>
        <div class="card-body">
          <form method="post">
            <div class="form-group">
              <label class="small font-weight-bold">Date</label>
              <input type="text" name="transaction_date" class="form-control form-control-sm datepicker" value="<?=date('Y-m-d')?>" required autocomplete="off">
            </div>
            <div class="form-group">
              <label class="small font-weight-bold">Type</label>
              <select name="type" class="form-control form-control-sm" required>
                <option value="receipt">Receipt (Money In)</option>
                <option value="payment">Payment (Money Out)</option>
              </select>
            </div>
            <div class="form-group">
              <label class="small font-weight-bold">Amount</label>
              <input type="number" step="0.01" name="amount" class="form-control form-control-sm" required>
            </div>
            <div class="form-group">
              <label class="small font-weight-bold">Payment Method</label>
              <select name="payment_method" class="form-control form-control-sm" onchange="toggleBankField(this)">
                <option value="cash">Cash</option>
                <option value="bank">Bank</option>
              </select>
            </div>
            <div class="form-group bank-field" style="display:none;">
              <label class="small font-weight-bold">Bank Account</label>
              <select name="bank_account_id" class="form-control form-control-sm">
                <option value="">Select</option>
                <?php foreach ($bank_accounts as $b): ?>
                  <option value="<?=$b['id']?>"><?=htmlspecialchars($b['account_name'])?> (<?=htmlspecialchars($b['bank_name'])?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="small font-weight-bold">Description</label>
              <input type="text" name="description" class="form-control form-control-sm" placeholder="Reason">
            </div>
            <button type="submit" name="add_transaction" class="btn btn-success btn-sm"><i class="fas fa-plus"></i> Add Transaction</button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-md-8">
      <div class="card shadow mb-4">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold" style="color:#0f172a;">Transactions</h6></div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-bordered table-sm mb-0">
              <thead class="thead-light">
                <tr><th>Date</th><th>Description</th><th class="text-right">Receipt</th><th class="text-right">Payment</th><th class="text-right">Balance</th><th class="text-center">Action</th></tr>
              </thead>
              <tbody>
                <tr style="background:#f8f9fc;"><td colspan="4" class="text-right"><strong>Opening Balance</strong></td><td class="text-right"><strong><?=formatCurrency($opening)?></strong></td><td></td></tr>
                <?php $bal = $opening; foreach ($transactions as $t):
                  $bal += $t['type'] === 'receipt' ? (float)$t['amount'] : -(float)$t['amount'];
                  $type_badge = $t['type'] === 'receipt' ? 'success' : 'danger';
                  $type_label = $t['type'] === 'receipt' ? 'Receipt' : 'Payment';
                ?>
                  <tr>
                    <td><?=formatDate($t['transaction_date'])?></td>
                    <td class="small">
                      <span class="badge badge-<?=$type_badge?>"><?=$type_label?></span>
                      <?=htmlspecialchars($t['description'] ?? '-')?>
                      <small class="text-muted d-block">by <?=htmlspecialchars($t['username'] ?? '?')?> | <?=ucfirst($t['payment_method'])?></small>
                    </td>
                    <td class="text-right"><?=$t['type']==='receipt'?formatCurrency($t['amount']):'-'?></td>
                    <td class="text-right"><?=$t['type']==='payment'?formatCurrency($t['amount']):'-'?></td>
                    <td class="text-right"><strong><?=formatCurrency($bal)?></strong></td>
                    <td class="text-center">
                      <a href="view.php?id=<?=$id?>&delete_txn=<?=$t['id']?>" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Delete this transaction?\n\nThis will also remove from Cash/Bank Book.')"><i class="fas fa-trash"></i></a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr style="background:#f8f9fc;font-weight:bold;">
                  <td colspan="2" class="text-right">Totals</td>
                  <td class="text-right"><?=formatCurrency($total_receipts)?></td>
                  <td class="text-right"><?=formatCurrency($total_payments)?></td>
                  <td class="text-right"><?=formatCurrency($closing)?></td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<?php include $base_url . 'includes/footer.php'; ?>

<script>
function toggleBankField(sel) {
    var field = sel.closest('form').querySelector('.bank-field');
    if (field) field.style.display = sel.value === 'bank' ? '' : 'none';
}
</script>
</body>
</html>
