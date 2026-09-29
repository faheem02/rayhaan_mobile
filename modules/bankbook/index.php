<?php
session_start();
$page_title = 'Bank Book';
$base_url = '../../';
require_once '../../includes/functions.php';

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$account_filter = (int)($_GET['account'] ?? 0);

// Recompute an account's current balance from its transactions
function recomputeBankAccount($pdo, $accountId) {
    $acct = getById('bank_accounts', $accountId);
    if (!$acct) return;
    $sums = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('deposit','transfer_in') THEN amount ELSE 0 END),0) AS inc,
        COALESCE(SUM(CASE WHEN transaction_type IN ('withdrawal','transfer_out') THEN amount ELSE 0 END),0) AS outc
        FROM bank_transactions WHERE bank_account_id = ?");
    $sums->execute([$accountId]);
    $r = $sums->fetch();
    $bal = (float)$acct['opening_balance'] + (float)$r['inc'] - (float)$r['outc'];
    $pdo->prepare("UPDATE bank_accounts SET current_balance = ?, updated_at = CURDATE() WHERE id = ?")->execute([$bal, $accountId]);
}

// Handle delete transaction
if (isset($_GET['delete_tran'])) {
    $tid = (int)$_GET['delete_tran'];
    $t = getById('bank_transactions', $tid);
    if ($t) {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM bank_transactions WHERE id = ?")->execute([$tid]);
        recomputeBankAccount($pdo, (int)$t['bank_account_id']);
        $pdo->commit();
        $_SESSION['success'] = 'Transaction deleted. Account balance updated.';
    } else {
        $_SESSION['error'] = 'Transaction not found';
    }
    header("Location: index.php?from=$from&to=$to" . ($account_filter ? "&account=$account_filter" : ''));
    exit;
}

// Handle edit transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_transaction'])) {
    $tid = (int)($_POST['tran_id'] ?? 0);
    $old = getById('bank_transactions', $tid);
    if ($old) {
        $bank_account_id = (int)($_POST['bank_account_id'] ?? 0);
        $tran_date = $_POST['tran_date'];
        $type = $_POST['tran_type'];
        $amount = (float)($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $cheque_no = trim($_POST['cheque_no'] ?? '');
        $cheque_date = $_POST['cheque_date'] ?: null;
        $cheque_status = $_POST['cheque_status'] ?? null;
        if ($amount <= 0 || !$bank_account_id) {
            $_SESSION['error'] = 'Enter a valid amount and select a bank account';
        } else {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE bank_transactions SET bank_account_id=?, transaction_date=?, transaction_type=?, amount=?, description=?, cheque_no=?, cheque_date=?, cheque_status=? WHERE id=?")
                ->execute([$bank_account_id, $tran_date, $type, $amount, $description, $cheque_no ?: null, $cheque_date, $cheque_status, $tid]);
            recomputeBankAccount($pdo, (int)$old['bank_account_id']);
            recomputeBankAccount($pdo, $bank_account_id);
            $pdo->commit();
            $_SESSION['success'] = 'Transaction updated. Account balance updated.';
        }
    } else {
        $_SESSION['error'] = 'Transaction not found';
    }
    header("Location: index.php?from=$from&to=$to" . ($account_filter ? "&account=$account_filter" : ''));
    exit;
}

// Handle add account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_account'])) {
    insert('bank_accounts', [
        'account_name' => $_POST['account_name'],
        'bank_name' => $_POST['bank_name'],
        'account_no' => $_POST['account_no'],
        'account_type' => $_POST['account_type'] ?? 'current',
        'branch_code' => $_POST['branch_code'] ?? '',
        'opening_balance' => (float)($_POST['opening_balance'] ?? 0),
        'current_balance' => (float)($_POST['opening_balance'] ?? 0),
        'status' => 1,
        'created_at' => date('Y-m-d'),
    ]);
    $_SESSION['success'] = 'Bank account added successfully';
    header("Location: index.php?from=$from&to=$to");
    exit;
}

// Handle add transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_transaction'])) {
    $bank_account_id = (int)$_POST['bank_account_id'];
    $tran_date = $_POST['tran_date'];
    $type = $_POST['tran_type'];
    $amount = (float)($_POST['amount'] ?? 0);
    $description = $_POST['description'] ?? '';
    $ref_type = $_POST['reference_type'] ?? '';
    $ref_id = (int)($_POST['reference_id'] ?? 0) ?: null;
    $cheque_no = $_POST['cheque_no'] ?? '';
    $cheque_date = $_POST['cheque_date'] ?: null;
    $cheque_status = $_POST['cheque_status'] ?? null;

    if ($amount <= 0) {
        $_SESSION['error'] = 'Amount must be greater than 0';
    } else {
        $pdo->beginTransaction();

        insert('bank_transactions', [
            'bank_account_id' => $bank_account_id,
            'transaction_date' => $tran_date,
            'transaction_type' => $type,
            'amount' => $amount,
            'description' => $description,
            'reference_type' => $ref_type ?: null,
            'reference_id' => $ref_id,
            'cheque_no' => $cheque_no ?: null,
            'cheque_date' => $cheque_date,
            'cheque_status' => $cheque_status,
            'created_by' => $_SESSION['user_id'] ?? 1,
            'created_at' => date('Y-m-d'),
        ]);

        $adjustment = $type === 'deposit' || $type === 'transfer_in' ? $amount : -$amount;
        $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")
            ->execute([$adjustment, $bank_account_id]);

        $pdo->commit();
        $_SESSION['success'] = ucfirst(str_replace('_', ' ', $type)) . ' of ' . formatCurrency($amount) . ' recorded';
    }
    header("Location: index.php?from=$from&to=$to" . ($account_filter ? "&account=$account_filter" : ''));
    exit;
}

// Handle delete account
if (isset($_GET['delete_account'])) {
    $id = (int)$_GET['delete_account'];
    delete('bank_accounts', $id);
    $_SESSION['success'] = 'Bank account removed';
    header("Location: index.php?from=$from&to=$to");
    exit;
}

// Fetch bank accounts
$accounts = getAll('bank_accounts', 'bank_name ASC, account_name ASC');

// Fetch transactions
$sql = "SELECT bt.*, ba.account_name, ba.bank_name
        FROM bank_transactions bt
        JOIN bank_accounts ba ON bt.bank_account_id = ba.id
        WHERE bt.transaction_date BETWEEN ? AND ?";
$params = [$from, $to];
if ($account_filter) {
    $sql .= " AND bt.bank_account_id = ?";
    $params[] = $account_filter;
}
$sql .= " ORDER BY bt.transaction_date ASC, bt.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Pre-fetch related records for richer descriptions
$payment_ids = []; $expense_ids = []; $supplier_payment_ids = [];
foreach ($transactions as $t) {
    if ($t['reference_type'] === 'payment' && $t['reference_id']) $payment_ids[] = (int)$t['reference_id'];
    elseif ($t['reference_type'] === 'expense' && $t['reference_id']) $expense_ids[] = (int)$t['reference_id'];
    elseif ($t['reference_type'] === 'supplier_payment' && $t['reference_id']) $supplier_payment_ids[] = (int)$t['reference_id'];
}

$payment_info = [];
if (!empty($payment_ids)) {
    $ids = implode(',', $payment_ids);
    $rows = $pdo->query("SELECT p.id, p.sale_id, s.invoice_no, c.full_name AS customer_name,
           GROUP_CONCAT(DISTINCT COALESCE(pr.name, si.item_description) SEPARATOR ', ') AS products
           FROM payments p
           LEFT JOIN sales s ON p.sale_id = s.id
           LEFT JOIN customers c ON s.customer_id = c.id
           LEFT JOIN sale_items si ON si.sale_id = s.id
           LEFT JOIN products pr ON si.product_id = pr.id
           WHERE p.id IN ($ids)
           GROUP BY p.id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { $payment_info[$r['id']] = $r; }
}

$expense_info = [];
if (!empty($expense_ids)) {
    $ids = implode(',', $expense_ids);
    $rows = $pdo->query("SELECT e.id, e.description, ec.name AS category_name
           FROM expenses e
           LEFT JOIN expense_categories ec ON e.category_id = ec.id
           WHERE e.id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { $expense_info[$r['id']] = $r; }
}

$supplier_pay_info = [];
if (!empty($supplier_payment_ids)) {
    $ids = implode(',', $supplier_payment_ids);
    $rows = $pdo->query("SELECT sp.id, s.contact_person, s.name AS company_name
           FROM supplier_payments sp
           LEFT JOIN suppliers s ON sp.supplier_id = s.id
           WHERE sp.id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { $supplier_pay_info[$r['id']] = $r; }
}

// Calculate totals
$total_deposits = 0;
$total_withdrawals = 0;
foreach ($transactions as $t) {
    if (in_array($t['transaction_type'], ['deposit', 'transfer_in'])) {
        $total_deposits += $t['amount'];
    } elseif (in_array($t['transaction_type'], ['withdrawal', 'transfer_out'])) {
        $total_withdrawals += $t['amount'];
    }
}

require_once '../../includes/header.php';
?>

<style>
.status-badge { font-size: .75rem; padding: 3px 10px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="m-0 font-weight-bold" style="color:#0f172a;"><i class="fas fa-university"></i> Bank Book</h5>
  <div>
    <button class="btn btn-success btn-sm" data-toggle="modal" data-target="#addAccountModal"><i class="fas fa-plus"></i> Add Account</button>
    <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addTranModal"><i class="fas fa-exchange-alt"></i> Add Transaction</button>
  </div>
</div>

<form method="get" class="form-inline mb-4">
  <label class="mr-2 text-muted small">From:</label>
  <input type="text" name="from" class="form-control form-control-sm mr-3 datepicker" value="<?= $from ?>" autocomplete="off">
  <label class="mr-2 text-muted small">To:</label>
  <input type="text" name="to" class="form-control form-control-sm mr-3 datepicker" value="<?= $to ?>" autocomplete="off">
  <label class="mr-2 text-muted small">Account:</label>
  <select name="account" class="form-control form-control-sm mr-3">
    <option value="">All Accounts</option>
    <?php foreach ($accounts as $a): ?>
      <option value="<?= $a['id'] ?>" <?= $account_filter === (int)$a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['bank_name'] . ' - ' . $a['account_name']) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search"></i> View</button>
  <a href="index.php" class="btn btn-sm btn-secondary ml-2">Reset</a>
</form>

<!-- Summary Cards -->
<div class="row mb-4">
  <div class="col-md-4 mb-3">
    <div class="card border-left-success shadow h-100 py-2">
      <div class="card-body py-2">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Deposits</div>
            <div class="h5 mb-0 font-weight-bold" style="color:#0f172a;"><?= formatCurrency($total_deposits) ?></div>
          </div>
          <div class="col-auto"><i class="fas fa-arrow-down fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4 mb-3">
    <div class="card border-left-danger shadow h-100 py-2">
      <div class="card-body py-2">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Withdrawals</div>
            <div class="h5 mb-0 font-weight-bold" style="color:#0f172a;"><?= formatCurrency($total_withdrawals) ?></div>
          </div>
          <div class="col-auto"><i class="fas fa-arrow-up fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4 mb-3">
    <div class="card border-left-primary shadow h-100 py-2">
      <div class="card-body py-2">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Net Bank Flow</div>
            <div class="h5 mb-0 font-weight-bold" style="color:#0f172a;"><?= formatCurrency($total_deposits - $total_withdrawals) ?></div>
          </div>
          <div class="col-auto"><i class="fas fa-calculator fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Bank Accounts -->
<div class="card shadow mb-4">
  <div class="card-header py-3">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-university"></i> Bank Accounts</h6>
  </div>
  <div class="card-body">
    <?php if (empty($accounts)): ?>
      <div class="text-center text-muted py-3">
        <i class="fas fa-university fa-3x mb-3"></i>
        <p>No bank accounts yet. Add one to get started.</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm" width="100%" cellspacing="0">
          <thead class="thead-light">
            <tr>
              <th>Bank</th>
              <th>Account</th>
              <th>Account No.</th>
              <th>Type</th>
              <th class="text-right">Opening</th>
              <th class="text-right">Current Balance</th>
              <th class="text-center">Status</th>
              <th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($accounts as $a): ?>
              <tr>
                <td><?= htmlspecialchars($a['bank_name']) ?></td>
                <td><strong><?= htmlspecialchars($a['account_name']) ?></strong></td>
                <td><?= htmlspecialchars($a['account_no']) ?></td>
                <td><span class="badge badge-info"><?= ucfirst($a['account_type']) ?></span></td>
                <td class="text-right"><?= formatCurrency($a['opening_balance']) ?></td>
                <td class="text-right font-weight-bold" style="color:#0f172a;"><?= formatCurrency($a['current_balance']) ?></td>
                <td class="text-center">
                  <span class="badge badge-<?= $a['status'] ? 'success' : 'secondary' ?> status-badge"><?= $a['status'] ? 'Active' : 'Inactive' ?></span>
                </td>
                <td class="text-center">
                  <a href="index.php?account=<?= $a['id'] ?>&from=<?= $from ?>&to=<?= $to ?>" class="btn btn-sm btn-info" title="Filter by account"><i class="fas fa-filter"></i></a>
                  <a href="index.php?delete_account=<?= $a['id'] ?>&from=<?= $from ?>&to=<?= $to ?>" class="btn btn-sm btn-danger" onclick="return confirm('Remove this account?')" title="Delete"><i class="fas fa-trash"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Transactions -->
<div class="card shadow mb-4">
  <div class="card-header py-3">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-list"></i> Transactions</h6>
  </div>
  <div class="card-body">
    <?php if (empty($transactions)): ?>
      <div class="text-center text-muted py-3">
        <i class="fas fa-exchange-alt fa-3x mb-3"></i>
        <p>No transactions in this period.</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm" width="100%" cellspacing="0">
          <thead class="thead-light">
            <tr>
              <th>Date</th>
              <th>Account</th>
              <th>Type</th>
              <th>Description</th>
              <th class="text-right">Amount</th>
              <th>Cheque</th>
              <th>Reference</th>
              <th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($transactions as $t):
              $is_inflow = in_array($t['transaction_type'], ['deposit', 'transfer_in']);
              $is_outflow = in_array($t['transaction_type'], ['withdrawal', 'transfer_out']);
              $label = ucfirst(str_replace('_', ' ', $t['transaction_type']));
              $color = $is_inflow ? 'success' : ($is_outflow ? 'danger' : 'secondary');
            ?>
              <tr>
                <td><?= formatDate($t['transaction_date']) ?></td>
                <td><small><?= htmlspecialchars($t['bank_name']) ?> - <?= htmlspecialchars($t['account_name']) ?></small></td>
                <td><span class="badge badge-<?= $color ?>"><?= $label ?></span></td>
                <?php
                  $rich_desc = htmlspecialchars($t['description'] ?? '-');
                  if ($t['reference_type'] === 'payment' && isset($payment_info[$t['reference_id']])) {
                      $pi = $payment_info[$t['reference_id']];
                      $parts = [];
                      if ($pi['customer_name']) $parts[] = 'Customer: <strong>' . htmlspecialchars($pi['customer_name']) . '</strong>';
                      if ($pi['invoice_no']) $parts[] = 'Invoice: <strong>' . htmlspecialchars($pi['invoice_no']) . '</strong>';
                      if ($pi['products']) $parts[] = 'Product: <span class="text-muted">' . htmlspecialchars($pi['products']) . '</span>';
                      if (!empty($parts)) $rich_desc = implode(' | ', $parts);
                  } elseif ($t['reference_type'] === 'expense' && isset($expense_info[$t['reference_id']])) {
                      $ei = $expense_info[$t['reference_id']];
                      $parts = [];
                      $parts[] = 'Category: <strong>' . htmlspecialchars($ei['category_name'] ?? 'Uncategorized') . '</strong>';
                      if ($ei['description']) $parts[] = '<span class="text-muted">' . htmlspecialchars($ei['description']) . '</span>';
                      if (!empty($parts)) $rich_desc = implode(' | ', $parts);
                  } elseif ($t['reference_type'] === 'supplier_payment' && isset($supplier_pay_info[$t['reference_id']])) {
                      $sp = $supplier_pay_info[$t['reference_id']];
                      $name = ($sp['contact_person'] ?? '') . ' (' . ($sp['company_name'] ?? '') . ')';
                      $rich_desc = 'Supplier: <strong>' . htmlspecialchars(trim($name, ' ()')) . '</strong>';
                  }
                ?>
                <td class="small"><?= $rich_desc ?></td>
                <td class="text-right font-weight-bold <?= $is_inflow ? 'text-success' : ($is_outflow ? 'text-danger' : '') ?>">
                  <?= $is_inflow ? '+' : ($is_outflow ? '-' : '') ?><?= formatCurrency($t['amount']) ?>
                </td>
                <td>
                  <?php if ($t['cheque_no']): ?>
                    <small><?= htmlspecialchars($t['cheque_no']) ?>
                    <?php if ($t['cheque_status']): ?>
                      <br><span class="badge badge-<?= $t['cheque_status'] === 'cleared' ? 'success' : ($t['cheque_status'] === 'bounced' ? 'danger' : 'warning') ?> status-badge"><?= ucfirst($t['cheque_status']) ?></span>
                    <?php endif; ?>
                    </small>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($t['reference_type']): ?>
                    <small class="text-muted"><?= htmlspecialchars(ucfirst($t['reference_type'])) ?> #<?= $t['reference_id'] ?></small>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
                <td class="text-center" style="white-space:nowrap;">
                  <button type="button" class="btn btn-sm btn-outline-primary btn-edit-btran" title="Edit"
                    data-id="<?= $t['id'] ?>"
                    data-account="<?= (int)$t['bank_account_id'] ?>"
                    data-date="<?= htmlspecialchars($t['transaction_date']) ?>"
                    data-type="<?= htmlspecialchars($t['transaction_type']) ?>"
                    data-amount="<?= (float)$t['amount'] ?>"
                    data-description="<?= htmlspecialchars($t['description'] ?? '', ENT_QUOTES) ?>"
                    data-cheque-no="<?= htmlspecialchars($t['cheque_no'] ?? '', ENT_QUOTES) ?>"
                    data-cheque-date="<?= htmlspecialchars($t['cheque_date'] ?? '') ?>"
                    data-cheque-status="<?= htmlspecialchars($t['cheque_status'] ?? '') ?>">
                    <i class="fas fa-pen"></i>
                  </button>
                  <a href="index.php?delete_tran=<?= $t['id'] ?>&from=<?= $from ?>&to=<?= $to ?><?= $account_filter ? "&account=$account_filter" : '' ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Delete this transaction? Account balance will be updated.')"><i class="fas fa-trash"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Add Account Modal -->
<div class="modal fade" id="addAccountModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h6 class="modal-title"><i class="fas fa-plus-circle text-success"></i> Add Bank Account</h6>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 form-group">
              <label class="small">Bank Name <span class="text-danger">*</span></label>
              <input type="text" name="bank_name" class="form-control" placeholder="e.g. HBL, UBL" required>
            </div>
            <div class="col-md-6 form-group">
              <label class="small">Account Name <span class="text-danger">*</span></label>
              <input type="text" name="account_name" class="form-control" placeholder="e.g. Main Account" required>
            </div>
          </div>
          <div class="row">
            <div class="col-md-6 form-group">
              <label class="small">Account No. <span class="text-danger">*</span></label>
              <input type="text" name="account_no" class="form-control" placeholder="Account number" required>
            </div>
            <div class="col-md-3 form-group">
              <label class="small">Account Type</label>
              <select name="account_type" class="form-control">
                <option value="current">Current</option>
                <option value="savings">Savings</option>
                <option value="loan">Loan</option>
              </select>
            </div>
            <div class="col-md-3 form-group">
              <label class="small">Branch Code</label>
              <input type="text" name="branch_code" class="form-control" placeholder="Code">
            </div>
          </div>
          <div class="form-group">
            <label class="small">Opening Balance</label>
            <input type="number" name="opening_balance" class="form-control" step="0.01" value="0">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
          <button type="submit" name="add_account" class="btn btn-success btn-sm"><i class="fas fa-save"></i> Add Account</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add Transaction Modal -->
<div class="modal fade" id="addTranModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h6 class="modal-title"><i class="fas fa-exchange-alt text-primary"></i> Add Bank Transaction</h6>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="small">Bank Account <span class="text-danger">*</span></label>
            <select name="bank_account_id" class="form-control" required>
              <option value="">Select Account</option>
              <?php foreach ($accounts as $a): ?>
                <option value="<?= $a['id'] ?>" <?= $account_filter === (int)$a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['bank_name'] . ' - ' . $a['account_name'] . ' (' . $a['account_no'] . ')') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row">
            <div class="col-md-6 form-group">
              <label class="small">Date</label>
              <input type="text" name="tran_date" class="form-control datepicker" value="<?= date('Y-m-d') ?>" required autocomplete="off">
            </div>
            <div class="col-md-6 form-group">
              <label class="small">Type</label>
              <select name="tran_type" class="form-control" required>
                <option value="deposit">Deposit</option>
                <option value="withdrawal">Withdrawal</option>
                <option value="transfer_in">Transfer In</option>
                <option value="transfer_out">Transfer Out</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="small">Amount</label>
            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required>
          </div>
          <div class="form-group">
            <label class="small">Description</label>
            <textarea name="description" class="form-control" rows="2" placeholder="Transaction description..."></textarea>
          </div>
          <hr>
          <h6 class="font-weight-bold small text-muted">Cheque Details (optional)</h6>
          <div class="row">
            <div class="col-md-4 form-group">
              <label class="small">Cheque No.</label>
              <input type="text" name="cheque_no" class="form-control" placeholder="Cheque #">
            </div>
            <div class="col-md-4 form-group">
              <label class="small">Cheque Date</label>
              <input type="text" name="cheque_date" class="form-control datepicker" autocomplete="off">
            </div>
            <div class="col-md-4 form-group">
              <label class="small">Cheque Status</label>
              <select name="cheque_status" class="form-control">
                <option value="">Select</option>
                <option value="pending">Pending</option>
                <option value="cleared">Cleared</option>
                <option value="bounced">Bounced</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
          </div>
          <hr>
          <h6 class="font-weight-bold small text-muted">Reference (optional)</h6>
          <div class="row">
            <div class="col-md-6 form-group">
              <label class="small">Reference Type</label>
              <select name="reference_type" class="form-control">
                <option value="">None</option>
                <option value="sale">Sale</option>
                <option value="payment">Payment</option>
                <option value="expense">Expense</option>
                <option value="purchase">Purchase</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-md-6 form-group">
              <label class="small">Reference ID</label>
              <input type="number" name="reference_id" class="form-control" placeholder="ID #">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
          <button type="submit" name="add_transaction" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Add Transaction</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Transaction Modal -->
<div class="modal fade" id="editTranModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h6 class="modal-title"><i class="fas fa-edit text-primary"></i> Edit Bank Transaction</h6>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="tran_id" id="editTranId" value="0">
          <div class="form-group">
            <label class="small">Bank Account <span class="text-danger">*</span></label>
            <select name="bank_account_id" id="editTranAccount" class="form-control" required>
              <option value="">Select Account</option>
              <?php foreach ($accounts as $a): ?>
                <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['bank_name'] . ' - ' . $a['account_name'] . ' (' . $a['account_no'] . ')') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row">
            <div class="col-md-6 form-group">
              <label class="small">Date</label>
              <input type="text" name="tran_date" id="editTranDate" class="form-control datepicker" required autocomplete="off">
            </div>
            <div class="col-md-6 form-group">
              <label class="small">Type</label>
              <select name="tran_type" id="editTranType" class="form-control" required>
                <option value="deposit">Deposit</option>
                <option value="withdrawal">Withdrawal</option>
                <option value="transfer_in">Transfer In</option>
                <option value="transfer_out">Transfer Out</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="small">Amount</label>
            <input type="number" name="amount" id="editTranAmount" class="form-control" step="0.01" min="0.01" required>
          </div>
          <div class="form-group">
            <label class="small">Description</label>
            <textarea name="description" id="editTranDesc" class="form-control" rows="2"></textarea>
          </div>
          <hr>
          <h6 class="font-weight-bold small text-muted">Cheque Details (optional)</h6>
          <div class="row">
            <div class="col-md-4 form-group">
              <label class="small">Cheque No.</label>
              <input type="text" name="cheque_no" id="editChequeNo" class="form-control" placeholder="Cheque #">
            </div>
            <div class="col-md-4 form-group">
              <label class="small">Cheque Date</label>
              <input type="text" name="cheque_date" id="editChequeDate" class="form-control datepicker" autocomplete="off">
            </div>
            <div class="col-md-4 form-group">
              <label class="small">Cheque Status</label>
              <select name="cheque_status" id="editChequeStatus" class="form-control">
                <option value="">Select</option>
                <option value="pending">Pending</option>
                <option value="cleared">Cleared</option>
                <option value="bounced">Bounced</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
          <button type="submit" name="edit_transaction" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
$(document).on('click', '.btn-edit-btran', function() {
    var b = $(this);
    $('#editTranId').val(b.data('id'));
    $('#editTranAccount').val(String(b.data('account')) || '');
    $('#editTranDate').val(b.data('date'));
    $('#editTranType').val(b.data('type'));
    $('#editTranAmount').val(b.data('amount'));
    $('#editTranDesc').val(b.data('description') || '');
    $('#editChequeNo').val(b.data('cheque-no') || '');
    $('#editChequeDate').val(b.data('cheque-date') || '');
    $('#editChequeStatus').val(b.data('cheque-status') || '');
    $('#editTranModal').modal('show');
});
</script>

<?php require_once '../../includes/footer.php'; ?>
