<?php
session_start();
$page_title = 'Expenses';
$base_url = '../../';
require_once '../../includes/functions.php';

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$cat_filter = (int)($_GET['category'] ?? 0);

$categories = getAll('expense_categories', 'name ASC');
$bank_accounts = getAll('bank_accounts', 'bank_name ASC, account_name ASC');

// Handle add expense
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
    $expense_date = $_POST['expense_date'];
    $amount = (float)($_POST['amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $bank_account_id = (int)($_POST['bank_account_id'] ?? 0);

    if ($amount <= 0) {
        $_SESSION['error'] = 'Amount must be greater than 0';
        header("Location: index.php?from=$from&to=$to" . ($cat_filter ? "&category=$cat_filter" : ''));
        exit;
    }

    $expense_id = insert('expenses', [
        'category_id' => (int)($_POST['category_id'] ?? 0) ?: null,
        'expense_date' => $expense_date,
        'amount' => $amount,
        'description' => $_POST['description'] ?? '',
        // 'vendor_name' => $_POST['vendor_name'] ?? '',
        // 'bill_no' => $_POST['bill_no'] ?? '',
        'payment_method' => $payment_method,
        'bank_account_id' => $bank_account_id ?: null,
        'approval_status' => 'pending',
        'notes' => $_POST['notes'] ?? '',
        'branch_id' => $_SESSION['branch_id'] ?? null,
        'created_by' => $_SESSION['user_id'] ?? 1,
        'created_at' => date('Y-m-d'),
    ]);

    $_SESSION['success'] = 'Expense #' . $expense_id . ' recorded: ' . formatCurrency($amount) . ' (' . ucfirst($payment_method) . ')';
    header("Location: index.php?from=$from&to=$to" . ($cat_filter ? "&category=$cat_filter" : ''));
    exit;
}

// Handle edit expense
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_expense'])) {
    $eid = (int)$_POST['expense_id'];
    $amount = (float)($_POST['amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $bank_account_id = (int)($_POST['bank_account_id'] ?? 0);

    if ($amount <= 0) {
        $_SESSION['error'] = 'Amount must be greater than 0';
    } else {
        update('expenses', [
            'category_id' => (int)($_POST['category_id'] ?? 0) ?: null,
            'expense_date' => $_POST['expense_date'],
            'amount' => $amount,
            'description' => $_POST['description'] ?? '',
            // 'vendor_name' => $_POST['vendor_name'] ?? '',
            // 'bill_no' => $_POST['bill_no'] ?? '',
            'payment_method' => $payment_method,
            'bank_account_id' => $bank_account_id ?: null,
            'notes' => $_POST['notes'] ?? '',
            'updated_at' => date('Y-m-d'),
        ], $eid);
        $_SESSION['success'] = 'Expense #' . $eid . ' updated';
    }
    header("Location: index.php?from=$from&to=$to" . ($cat_filter ? "&category=$cat_filter" : ''));
    exit;
}

// Handle approve
if (isset($_GET['approve'])) {
    $eid = (int)$_GET['approve'];
    $exp = getById('expenses', $eid);
    if ($exp && $exp['approval_status'] === 'pending') {
        $created_by = $exp['created_by'] ?: 1;
        if ($exp['payment_method'] === 'cash') {
            recordCashOutflow($pdo, $exp['expense_date'], $exp['amount'], 'Expense: ' . ($exp['description'] ?: '#' . $eid), 'expense', $eid, $created_by);
        } elseif ($exp['payment_method'] === 'bank') {
            recordBankOutflow($pdo, $exp['expense_date'], $exp['amount'], 'Expense: ' . ($exp['description'] ?: '#' . $eid), 'expense', $eid, $created_by, (int)$exp['bank_account_id']);
        }
        $pdo->prepare("UPDATE expenses SET approval_status = 'approved', approved_by = ?, updated_at = CURDATE() WHERE id = ?")->execute([$_SESSION['user_id'] ?? 1, $eid]);
        $_SESSION['success'] = 'Expense #' . $eid . ' approved and recorded in ' . ucfirst($exp['payment_method']) . ' book';
    } else {
        $_SESSION['error'] = 'Expense not found or already processed';
    }
    header("Location: index.php?from=$from&to=$to" . ($cat_filter ? "&category=$cat_filter" : ''));
    exit;
}

// Handle reject
if (isset($_GET['reject'])) {
    $eid = (int)$_GET['reject'];
    $pdo->prepare("UPDATE expenses SET approval_status = 'rejected', updated_at = CURDATE() WHERE id = ?")->execute([$eid]);
    $_SESSION['success'] = 'Expense #' . $eid . ' rejected';
    header("Location: index.php?from=$from&to=$to" . ($cat_filter ? "&category=$cat_filter" : ''));
    exit;
}

// Handle delete
if (isset($_GET['delete'])) {
    $eid = (int)$_GET['delete'];
    delete('expenses', $eid);
    $_SESSION['success'] = 'Expense #' . $eid . ' deleted';
    header("Location: index.php?from=$from&to=$to" . ($cat_filter ? "&category=$cat_filter" : ''));
    exit;
}

// Fetch expenses
$sql = "SELECT e.*, ec.name AS category_name, ba.bank_name, ba.account_name
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        LEFT JOIN bank_accounts ba ON e.bank_account_id = ba.id
        WHERE e.expense_date BETWEEN ? AND ?";
$params = [$from, $to];
if ($cat_filter) {
    $sql .= " AND e.category_id = ?";
    $params[] = $cat_filter;
}
$sql .= " ORDER BY e.expense_date DESC, e.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

// Calculate totals
$total_amount = 0;
$cash_total = 0;
$bank_total = 0;
foreach ($expenses as $e) {
    $total_amount += $e['amount'];
    if ($e['payment_method'] === 'cash') {
        $cash_total += $e['amount'];
    } elseif ($e['payment_method'] === 'bank') {
        $bank_total += $e['amount'];
    }
}

require_once '../../includes/header.php';
?>

<style>
.status-badge { font-size: .75rem; padding: 3px 10px; }
.detail-label { font-size: .78rem; color: #858796; }
.detail-value { font-weight: 600; color: #0f172a; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="m-0 font-weight-bold" style="color:#0f172a;"><i class="fas fa-file-invoice-dollar"></i> Expenses</h5>
  <div>
    <a href="categories.php" class="btn btn-secondary btn-sm"><i class="fas fa-tags"></i> Categories</a>
    <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addExpenseModal"><i class="fas fa-plus"></i> Add Expense</button>
  </div>
</div>

<form method="get" class="form-inline mb-4">
  <label class="mr-2 text-muted small">From:</label>
  <input type="text" name="from" class="form-control form-control-sm mr-3 datepicker" value="<?= $from ?>" autocomplete="off">
  <label class="mr-2 text-muted small">To:</label>
  <input type="text" name="to" class="form-control form-control-sm mr-3 datepicker" value="<?= $to ?>" autocomplete="off">
  <label class="mr-2 text-muted small">Category:</label>
  <select name="category" class="form-control form-control-sm mr-3">
    <option value="">All Categories</option>
    <?php foreach ($categories as $c): ?>
      <option value="<?= $c['id'] ?>" <?= $cat_filter === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search"></i> View</button>
  <a href="index.php" class="btn btn-sm btn-secondary ml-2">This Month</a>
</form>

<!-- Summary Cards -->
<div class="row mb-4">
  <div class="col-md-4 mb-3">
    <div class="card border-left-danger shadow h-100 py-2">
      <div class="card-body py-2">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Expenses</div>
            <div class="h5 mb-0 font-weight-bold" style="color:#0f172a;"><?= formatCurrency($total_amount) ?></div>
          </div>
          <div class="col-auto"><i class="fas fa-arrow-up fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4 mb-3">
    <div class="card border-left-success shadow h-100 py-2">
      <div class="card-body py-2">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Cash</div>
            <div class="h5 mb-0 font-weight-bold" style="color:#0f172a;"><?= formatCurrency($cash_total) ?></div>
          </div>
          <div class="col-auto"><i class="fas fa-money-bill-wave fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4 mb-3">
    <div class="card border-left-info shadow h-100 py-2">
      <div class="card-body py-2">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Bank</div>
            <div class="h5 mb-0 font-weight-bold" style="color:#0f172a;"><?= formatCurrency($bank_total) ?></div>
          </div>
          <div class="col-auto"><i class="fas fa-university fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Expenses Table -->
<div class="card shadow mb-4">
  <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-list"></i> Expense Records</h6>
    <span class="text-muted small"><?= count($expenses) ?> record<?= count($expenses) !== 1 ? 's' : '' ?></span>
  </div>
  <div class="card-body">
    <?php if (empty($expenses)): ?>
      <div class="text-center text-muted py-3">
        <i class="fas fa-file-invoice fa-3x mb-3"></i>
        <p>No expenses found for this period.</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm" width="100%" cellspacing="0">
          <thead class="thead-light">
            <tr>
              <th>Date</th>
              <th>Category</th>
              <th>Description</th>
              <!-- <th>Vendor</th> -->
              <!-- <th>Bill No</th> -->
              <th class="text-right">Amount</th>
              <th>Method</th>
              <th>Status</th>
              <th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($expenses as $e): ?>
              <tr>
                <td class="text-nowrap"><?= formatDate($e['expense_date']) ?></td>
                <td><span class="badge badge-secondary"><?= htmlspecialchars($e['category_name'] ?? 'Uncategorized') ?></span></td>
                <td>
                  <a href="#" onclick="viewExpense(<?= $e['id'] ?>);return false;" title="View details">
                    <?= htmlspecialchars(mb_substr($e['description'] ?: '-', 0, 40)) ?>
                    <?= mb_strlen($e['description'] ?? '') > 40 ? '...' : '' ?>
                  </a>
                </td>
               
                <!-- <td>?= htmlspecialchars($e['bill_no'] ?: '-') ?></td> -->
                <td class="text-right font-weight-bold text-danger"><?= formatCurrency($e['amount']) ?></td>
                <td>
                  <span class="badge badge-<?= $e['payment_method'] === 'cash' ? 'success' : 'info' ?> status-badge"><?= ucfirst($e['payment_method']) ?></span>
                  <?php if ($e['payment_method'] === 'bank' && $e['bank_name']): ?>
                    <br><small class="text-muted"><?= htmlspecialchars($e['bank_name'] . ' - ' . $e['account_name']) ?></small>
                  <?php endif; ?>
                </td>
                <td>
                  <?php
                  $sb = match($e['approval_status']) {
                    'approved' => 'success',
                    'rejected' => 'danger',
                    default => 'warning'
                  };
                  ?>
                  <span class="badge badge-<?= $sb ?> status-badge"><?= ucfirst($e['approval_status']) ?></span>
                </td>
                <td class="text-center">
                  <a href="expense_receipt.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-secondary" title="Print Slip" target="_blank"><i class="fas fa-print"></i></a>
                  <button class="btn btn-sm btn-info" onclick="viewExpense(<?= $e['id'] ?>)" title="View"><i class="fas fa-eye"></i></button>
                  <?php if ($e['approval_status'] === 'pending'): ?>
                    <a href="index.php?approve=<?= $e['id'] ?>&from=<?= $from ?>&to=<?= $to ?><?= $cat_filter ? "&category=$cat_filter" : '' ?>" class="btn btn-sm btn-success" title="Approve" onclick="return confirm('Approve this expense? The amount will be deducted from <?= ucfirst($e['payment_method']) ?> book.')"><i class="fas fa-check"></i></a>
                    <a href="index.php?reject=<?= $e['id'] ?>&from=<?= $from ?>&to=<?= $to ?><?= $cat_filter ? "&category=$cat_filter" : '' ?>" class="btn btn-sm btn-warning" title="Reject"><i class="fas fa-times"></i></a>
                    <button class="btn btn-sm btn-primary" onclick="editExpense(<?= $e['id'] ?>)" title="Edit"><i class="fas fa-pen"></i></button>
                  <?php endif; ?>
                  <a href="index.php?delete=<?= $e['id'] ?>&from=<?= $from ?>&to=<?= $to ?><?= $cat_filter ? "&category=$cat_filter" : '' ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this expense?')" title="Delete"><i class="fas fa-trash"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Add Expense Modal -->
<div class="modal fade" id="addExpenseModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h6 class="modal-title"><i class="fas fa-plus-circle text-primary"></i> Add Expense</h6>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-4 form-group">
              <label class="small">Date <span class="text-danger">*</span></label>
              <input type="text" name="expense_date" class="form-control datepicker" value="<?= date('Y-m-d') ?>" required autocomplete="off">
            </div>
            <div class="col-md-4 form-group">
              <label class="small">Amount <span class="text-danger">*</span></label>
              <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required>
            </div>
            <div class="col-md-4 form-group">
              <label class="small">Category</label>
              <select name="category_id" class="form-control">
                <option value="">Uncategorized</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="small">Description</label>
            <textarea name="description" class="form-control" rows="2" placeholder="What is this expense for?"></textarea>
          </div>
          <div class="row">
            <div class="col-md-6 form-group">
              <label class="small">Payment Method <span class="text-danger">*</span></label>
              <select name="payment_method" class="form-control payment-method-select">
                <option value="cash">Cash</option>
                <option value="bank">Bank</option>
              </select>
            </div>
            <div class="col-md-6 form-group bank-account-row" style="display:none;">
              <label class="small">Bank Account</label>
              <select name="bank_account_id" class="form-control">
                <option value="">Select Account</option>
                <?php foreach ($bank_accounts as $ba): ?>
                  <option value="<?= $ba['id'] ?>"><?= htmlspecialchars($ba['bank_name'] . ' - ' . $ba['account_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="small">Notes</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
          <button type="submit" name="add_expense" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Add Expense</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Expense Modal -->
<div class="modal fade" id="editExpenseModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h6 class="modal-title"><i class="fas fa-edit text-primary"></i> Edit Expense</h6>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="expense_id" id="edit_id">
          <div class="row">
            <div class="col-md-4 form-group">
              <label class="small">Date <span class="text-danger">*</span></label>
              <input type="text" name="expense_date" id="edit_expense_date" class="form-control datepicker" required autocomplete="off">
            </div>
            <div class="col-md-4 form-group">
              <label class="small">Amount <span class="text-danger">*</span></label>
              <input type="number" name="amount" id="edit_amount" class="form-control" step="0.01" min="0.01" required>
            </div>
            <div class="col-md-4 form-group">
              <label class="small">Category</label>
              <select name="category_id" id="edit_category_id" class="form-control">
                <option value="">Uncategorized</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="small">Description</label>
            <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
          </div>
          <div class="row">
            <div class="col-md-6 form-group">
              <label class="small">Payment Method <span class="text-danger">*</span></label>
              <select name="payment_method" id="edit_payment_method" class="form-control payment-method-select-edit">
                <option value="cash">Cash</option>
                <option value="bank">Bank</option>
              </select>
            </div>
            <div class="col-md-6 form-group bank-account-row-edit" style="display:none;">
              <label class="small">Bank Account</label>
              <select name="bank_account_id" id="edit_bank_account_id" class="form-control">
                <option value="">Select Account</option>
                <?php foreach ($bank_accounts as $ba): ?>
                  <option value="<?= $ba['id'] ?>"><?= htmlspecialchars($ba['bank_name'] . ' - ' . $ba['account_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="small">Notes</label>
            <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
          <button type="submit" name="edit_expense" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Update Expense</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- View Expense Modal -->
<div class="modal fade" id="viewExpenseModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title"><i class="fas fa-file-invoice-dollar text-info"></i> Expense Details</h6>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body" id="viewExpenseBody">
        <div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
$(document).on('change', '.payment-method-select', function() {
    $(this).closest('.modal-body').find('.bank-account-row').toggle($(this).val() === 'bank');
});
$(document).on('change', '.payment-method-select-edit', function() {
    $(this).closest('.modal-body').find('.bank-account-row-edit').toggle($(this).val() === 'bank');
});

function viewExpense(id) {
    $('#viewExpenseModal').modal('show');
    $('#viewExpenseBody').html('<div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
    $.get('expense_view.php?id=' + id, function(data) {
        $('#viewExpenseBody').html(data);
    }).fail(function() {
        $('#viewExpenseBody').html('<div class="alert alert-danger">Failed to load expense details.</div>');
    });
}

var expensesData = <?= json_encode($expenses) ?>;

function editExpense(id) {
    var e = expensesData.find(function(item) { return item.id == id; });
    if (!e) { alert('Expense not found'); return; }
    $('#edit_id').val(e.id);
    $('#edit_expense_date').val(e.expense_date);
    $('#edit_amount').val(e.amount);
    $('#edit_category_id').val(e.category_id || '');
    $('#edit_description').val(e.description || '');
    $('#edit_payment_method').val(e.payment_method);
    $('#edit_bank_account_id').val(e.bank_account_id || '');
    $('#edit_notes').val(e.notes || '');
    $('#edit_payment_method').trigger('change');
    $('#editExpenseModal').modal('show');
}
</script>

<?php require_once '../../includes/footer.php'; ?>
