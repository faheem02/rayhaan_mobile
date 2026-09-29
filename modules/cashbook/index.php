<?php
session_start();
$page_title = 'Cash Book';
$base_url = '../../';
require_once '../../includes/functions.php';

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

// Recompute a day's totals from its cash_book rows
function recomputeDay($pdo, $date) {
    $day = $pdo->prepare("SELECT * FROM cash_book_daily WHERE date = ?");
    $day->execute([$date]);
    $d = $day->fetch();
    if (!$d) return;
    $sums = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type='inflow' THEN amount ELSE 0 END),0) AS inf,
        COALESCE(SUM(CASE WHEN transaction_type='outflow' THEN amount ELSE 0 END),0) AS outf
        FROM cash_book WHERE daily_id = ?");
    $sums->execute([$d['id']]);
    $r = $sums->fetch();
    $inf = (float)$r['inf'];
    $outf = (float)$r['outf'];
    $closing = (float)$d['opening_balance'] + $inf - $outf;
    $pdo->prepare("UPDATE cash_book_daily SET total_inflow = ?, total_outflow = ?, closing_balance = ? WHERE id = ?")
        ->execute([$inf, $outf, $closing, $d['id']]);
}

// Handle delete transaction
if (isset($_GET['delete_tran'])) {
    $tid = (int)$_GET['delete_tran'];
    $t = getById('cash_book', $tid);
    if ($t && in_array($t['transaction_type'], ['inflow', 'outflow'], true)) {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM cash_book WHERE id = ?")->execute([$tid]);
        recomputeDay($pdo, $t['transaction_date']);
        $pdo->commit();
        $_SESSION['success'] = 'Transaction deleted. Day totals updated.';
    } else {
        $_SESSION['error'] = 'Transaction not found or cannot be deleted';
    }
    header("Location: index.php?from=$from&to=$to");
    exit;
}

// Handle edit transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_transaction'])) {
    $tid = (int)($_POST['tran_id'] ?? 0);
    $old = getById('cash_book', $tid);
    if ($old && in_array($old['transaction_type'], ['inflow', 'outflow'], true)) {
        $tran_date = $_POST['tran_date'];
        $type = $_POST['tran_type'];
        $amount = (float)($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        if ($amount <= 0) {
            $_SESSION['error'] = 'Amount must be greater than 0';
        } else {
            $pdo->beginTransaction();
            $day = $pdo->prepare("SELECT id FROM cash_book_daily WHERE date = ?");
            $day->execute([$tran_date]);
            $new_daily_id = $day->fetchColumn();
            if (!$new_daily_id) {
                $pdo->rollBack();
                $_SESSION['error'] = "No open day for $tran_date. Open the day first.";
            } else {
                $pdo->prepare("UPDATE cash_book SET daily_id = ?, transaction_date = ?, transaction_type = ?, amount = ?, description = ? WHERE id = ?")
                    ->execute([(int)$new_daily_id, $tran_date, $type, $amount, $description, $tid]);
                recomputeDay($pdo, $old['transaction_date']);
                recomputeDay($pdo, $tran_date);
                $pdo->commit();
                $_SESSION['success'] = 'Transaction updated. Day totals updated.';
            }
        }
    } else {
        $_SESSION['error'] = 'Transaction not found or cannot be edited';
    }
    header("Location: index.php?from=$from&to=$to");
    exit;
}

// Handle open day
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['open_day'])) {
    $date = $_POST['date'];
    $opening = (float)($_POST['opening_balance'] ?? 0);

    $existing = $pdo->prepare("SELECT id FROM cash_book_daily WHERE date = ?");
    $existing->execute([$date]);
    if ($existing->fetch()) {
        $_SESSION['error'] = "Day already exists for $date";
    } else {
        $pdo->beginTransaction();
        insert('cash_book_daily', [
            'date' => $date,
            'opening_balance' => $opening,
            'total_inflow' => 0,
            'total_outflow' => 0,
            'closing_balance' => $opening,
            'status' => 'open',
            'created_by' => $_SESSION['user_id'] ?? 1,
            'created_at' => date('Y-m-d'),
        ]);
        insert('cash_book', [
            'daily_id' => null,
            'transaction_date' => $date,
            'transaction_type' => 'opening_balance',
            'amount' => $opening,
            'description' => 'Opening balance',
            'reference_type' => 'opening',
            'reference_id' => null,
            'created_by' => $_SESSION['user_id'] ?? 1,
            'created_at' => date('Y-m-d'),
        ]);
        $pdo->commit();
        $_SESSION['success'] = "Day opened for $date with balance " . formatCurrency($opening);
    }
    header("Location: index.php?from=$from&to=$to");
    exit;
}

// Handle add transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_transaction'])) {
    $tran_date = $_POST['tran_date'];
    $type = $_POST['tran_type'];
    $amount = (float)($_POST['amount'] ?? 0);
    $description = $_POST['description'] ?? '';
    $ref_type = $_POST['reference_type'] ?? '';
    $ref_id = (int)($_POST['reference_id'] ?? 0) ?: null;

    if ($amount <= 0) {
        $_SESSION['error'] = 'Amount must be greater than 0';
    } else {
        $pdo->beginTransaction();

        $daily = $pdo->prepare("SELECT id, opening_balance, total_inflow, total_outflow, closing_balance FROM cash_book_daily WHERE date = ?");
        $daily->execute([$tran_date]);
        $day = $daily->fetch();

        if (!$day) {
            $_SESSION['error'] = "No open day for $tran_date. Open the day first.";
            $pdo->rollBack();
            header("Location: index.php?from=$from&to=$to");
            exit;
        }

        insert('cash_book', [
            'daily_id' => $day['id'],
            'transaction_date' => $tran_date,
            'transaction_type' => $type,
            'amount' => $amount,
            'description' => $description,
            'reference_type' => $ref_type ?: null,
            'reference_id' => $ref_id,
            'created_by' => $_SESSION['user_id'] ?? 1,
            'created_at' => date('Y-m-d'),
        ]);

        $new_inflow = $day['total_inflow'] + ($type === 'inflow' ? $amount : 0);
        $new_outflow = $day['total_outflow'] + ($type === 'outflow' ? $amount : 0);
        $new_closing = $day['opening_balance'] + $new_inflow - $new_outflow;

        $pdo->prepare("UPDATE cash_book_daily SET total_inflow = ?, total_outflow = ?, closing_balance = ? WHERE id = ?")
            ->execute([$new_inflow, $new_outflow, $new_closing, $day['id']]);

        $pdo->commit();
        $_SESSION['success'] = ucfirst($type) . ' of ' . formatCurrency($amount) . ' recorded';
    }
    header("Location: index.php?from=$from&to=$to");
    exit;
}

// Handle close day
if (isset($_GET['close_day'])) {
    $date = $_GET['close_day'];
    $pdo->prepare("UPDATE cash_book_daily SET status = 'closed', updated_at = CURDATE() WHERE date = ?")->execute([$date]);
    $_SESSION['success'] = "Day $date closed";
    header("Location: index.php?from=$from&to=$to");
    exit;
}

// Fetch daily summaries
$stmt = $pdo->prepare("SELECT * FROM cash_book_daily WHERE date BETWEEN ? AND ? ORDER BY date DESC");
$stmt->execute([$from, $to]);
$dailies = $stmt->fetchAll();

// Fetch all transactions for the period
$stmt = $pdo->prepare("SELECT cb.*, cbd.date AS daily_date FROM cash_book cb LEFT JOIN cash_book_daily cbd ON cb.daily_id = cbd.id WHERE cb.transaction_date BETWEEN ? AND ? ORDER BY cb.transaction_date ASC, cb.id ASC");
$stmt->execute([$from, $to]);
$transactions = $stmt->fetchAll();

// Pre-fetch related records for richer descriptions
$payment_ids = []; $expense_ids = []; $supplier_payment_ids = [];
foreach ($transactions as $t) {
    if ($t['reference_type'] === 'payment' && $t['reference_id']) $payment_ids[] = (int)$t['reference_id'];
    elseif ($t['reference_type'] === 'expense' && $t['reference_id']) $expense_ids[] = (int)$t['reference_id'];
    elseif ($t['reference_type'] === 'supplier_payment' && $t['reference_id']) $supplier_payment_ids[] = (int)$t['reference_id'];
}

// Build lookup maps
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
$total_inflow = 0;
$total_outflow = 0;
foreach ($transactions as $t) {
    if ($t['transaction_type'] === 'inflow') $total_inflow += $t['amount'];
    elseif ($t['transaction_type'] === 'outflow') $total_outflow += $t['amount'];
}

require_once '../../includes/header.php';
?>

<style>
.status-badge { font-size: .75rem; padding: 3px 10px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="m-0 font-weight-bold" style="color:#0f172a;"><i class="fas fa-money-bill-wave"></i> Cash Book</h5>
  <div>
    <button class="btn btn-success btn-sm" data-toggle="modal" data-target="#openDayModal"><i class="fas fa-play"></i> Open Day</button>
    <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addTranModal"><i class="fas fa-plus"></i> Add Transaction</button>
  </div>
</div>

<form method="get" class="form-inline mb-4">
  <label class="mr-2 text-muted small">From:</label>
  <input type="text" name="from" class="form-control form-control-sm mr-3 datepicker" value="<?= $from ?>" autocomplete="off">
  <label class="mr-2 text-muted small">To:</label>
  <input type="text" name="to" class="form-control form-control-sm mr-3 datepicker" value="<?= $to ?>" autocomplete="off">
  <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search"></i> View</button>
  <a href="index.php" class="btn btn-sm btn-secondary ml-2">This Month</a>
</form>

<!-- Summary Cards -->
<div class="row mb-4">
  <div class="col-md-4 mb-3">
    <div class="card border-left-success shadow h-100 py-2">
      <div class="card-body py-2">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Inflow</div>
            <div class="h5 mb-0 font-weight-bold" style="color:#0f172a;"><?= formatCurrency($total_inflow) ?></div>
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
            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Outflow</div>
            <div class="h5 mb-0 font-weight-bold" style="color:#0f172a;"><?= formatCurrency($total_outflow) ?></div>
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
            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Net Cash Flow</div>
            <div class="h5 mb-0 font-weight-bold" style="color:#0f172a;"><?= formatCurrency($total_inflow - $total_outflow) ?></div>
          </div>
          <div class="col-auto"><i class="fas fa-calculator fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Daily Summaries -->
<div class="card shadow mb-4">
  <div class="card-header py-3">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-calendar-day"></i> Daily Summary</h6>
  </div>
  <div class="card-body">
    <?php if (empty($dailies)): ?>
      <div class="text-center text-muted py-3">
        <i class="fas fa-calendar fa-3x mb-3"></i>
        <p>No daily records found. Open a day to start.</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm" width="100%" cellspacing="0">
          <thead class="thead-light">
            <tr>
              <th>Date</th>
              <th class="text-right">Opening</th>
              <th class="text-right">Inflow</th>
              <th class="text-right">Outflow</th>
              <th class="text-right">Closing</th>
              <th class="text-center">Status</th>
              <th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dailies as $d): ?>
              <tr>
                <td><strong><?= formatDate($d['date']) ?></strong></td>
                <td class="text-right"><?= formatCurrency($d['opening_balance']) ?></td>
                <td class="text-right text-success"><?= formatCurrency($d['total_inflow']) ?></td>
                <td class="text-right text-danger"><?= formatCurrency($d['total_outflow']) ?></td>
                <td class="text-right font-weight-bold" style="color:#0f172a;"><?= formatCurrency($d['closing_balance']) ?></td>
                <td class="text-center">
                  <?php if ($d['status'] === 'open'): ?>
                    <span class="badge badge-success status-badge">Open</span>
                  <?php else: ?>
                    <span class="badge badge-secondary status-badge">Closed</span>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <?php if ($d['status'] === 'open'): ?>
                    <a href="index.php?close_day=<?= $d['date'] ?>&from=<?= $from ?>&to=<?= $to ?>" class="btn btn-sm btn-warning" onclick="return confirm('Close day for <?= $d['date'] ?>?')"><i class="fas fa-lock"></i> Close</a>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
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
              <th>Type</th>
              <th>Description</th>
              <th class="text-right">Amount</th>
              <th>Reference</th>
              <th class="text-right">Balance</th>
              <th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $running = 0;
            foreach ($transactions as $t):
              if ($t['transaction_type'] === 'opening_balance') {
                  $running = $t['amount'];
                  $label = 'Opening Balance';
                  $color = 'secondary';
              } elseif ($t['transaction_type'] === 'inflow') {
                  $running += $t['amount'];
                  $label = 'Inflow';
                  $color = 'success';
              } elseif ($t['transaction_type'] === 'outflow') {
                  $running -= $t['amount'];
                  $label = 'Outflow';
                  $color = 'danger';
              } else {
                  $label = ucfirst($t['transaction_type']);
                  $color = 'secondary';
              }
            ?>
              <?php
                $rich_desc = htmlspecialchars($t['description'] ?? '');
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
              <tr>
                <td><?= formatDate($t['transaction_date']) ?></td>
                <td><span class="badge badge-<?= $color ?>"><?= $label ?></span></td>
                <td class="small"><?= $rich_desc ?></td>
                <td class="text-right font-weight-bold <?= $t['transaction_type'] === 'inflow' ? 'text-success' : ($t['transaction_type'] === 'outflow' ? 'text-danger' : '') ?>">
                  <?= $t['transaction_type'] === 'inflow' ? '+' : ($t['transaction_type'] === 'outflow' ? '-' : '') ?><?= formatCurrency($t['amount']) ?>
                </td>
                <td>
                  <?php if ($t['reference_type']): ?>
                    <small class="text-muted"><?= htmlspecialchars(ucfirst($t['reference_type'])) ?> #<?= $t['reference_id'] ?></small>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
                <td class="text-right font-weight-bold" style="color:#0f172a;"><?= formatCurrency($running) ?></td>
                <td class="text-center" style="white-space:nowrap;">
                  <?php if (in_array($t['transaction_type'], ['inflow', 'outflow'], true)): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit-tran" title="Edit"
                      data-id="<?= $t['id'] ?>"
                      data-date="<?= htmlspecialchars($t['transaction_date']) ?>"
                      data-type="<?= htmlspecialchars($t['transaction_type']) ?>"
                      data-amount="<?= (float)$t['amount'] ?>"
                      data-description="<?= htmlspecialchars($t['description'] ?? '', ENT_QUOTES) ?>">
                      <i class="fas fa-pen"></i>
                    </button>
                    <a href="index.php?delete_tran=<?= $t['id'] ?>&from=<?= $from ?>&to=<?= $to ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Delete this transaction? Day totals will be updated.')"><i class="fas fa-trash"></i></a>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Open Day Modal -->
<div class="modal fade" id="openDayModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-sm" role="document">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h6 class="modal-title"><i class="fas fa-play text-success"></i> Open Day</h6>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="small">Date</label>
            <input type="text" name="date" class="form-control datepicker" value="<?= date('Y-m-d') ?>" required autocomplete="off">
          </div>
          <div class="form-group">
            <label class="small">Opening Balance</label>
            <input type="number" name="opening_balance" class="form-control" step="0.01" value="0" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
          <button type="submit" name="open_day" class="btn btn-success btn-sm"><i class="fas fa-play"></i> Open Day</button>
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
          <h6 class="modal-title"><i class="fas fa-plus-circle text-primary"></i> Add Transaction</h6>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 form-group">
              <label class="small">Date</label>
              <input type="text" name="tran_date" class="form-control datepicker" value="<?= date('Y-m-d') ?>" required autocomplete="off">
            </div>
            <div class="col-md-6 form-group">
              <label class="small">Type</label>
              <select name="tran_type" class="form-control" required>
                <option value="inflow">Inflow (Cash In)</option>
                <option value="outflow">Outflow (Cash Out)</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="small">Amount</label>
            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required>
          </div>
          <div class="form-group">
            <label class="small">Description</label>
            <textarea name="description" class="form-control" rows="2" placeholder="e.g. Sale payment, expense, etc."></textarea>
          </div>
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
          <button type="submit" name="add_transaction" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Add</button>
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
          <h6 class="modal-title"><i class="fas fa-edit text-primary"></i> Edit Transaction</h6>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="tran_id" id="editTranId" value="0">
          <div class="row">
            <div class="col-md-6 form-group">
              <label class="small">Date</label>
              <input type="text" name="tran_date" id="editTranDate" class="form-control datepicker" required autocomplete="off">
            </div>
            <div class="col-md-6 form-group">
              <label class="small">Type</label>
              <select name="tran_type" id="editTranType" class="form-control" required>
                <option value="inflow">Inflow (Cash In)</option>
                <option value="outflow">Outflow (Cash Out)</option>
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
$(document).on('click', '.btn-edit-tran', function() {
    var b = $(this);
    $('#editTranId').val(b.data('id'));
    $('#editTranDate').val(b.data('date'));
    $('#editTranType').val(b.data('type'));
    $('#editTranAmount').val(b.data('amount'));
    $('#editTranDesc').val(b.data('description') || '');
    $('#editTranModal').modal('show');
});
</script>

<?php require_once '../../includes/footer.php'; ?>
