<?php
session_start();
$page_title = 'Sales Report';
$base_url = '../../';
require_once '../../includes/functions.php';

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

require_once '../../includes/header.php';
?>

<style>
.status-badge { font-size: .75rem; padding: 3px 10px; }
</style>

<?php
// Main sales query
$stmt = $pdo->prepare("
    SELECT s.*, c.full_name, c.phone, c.customer_no,
           (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS item_count
    FROM sales s
    JOIN customers c ON c.id = s.customer_id
    WHERE s.sale_date BETWEEN ? AND ?
    ORDER BY s.sale_date DESC, s.id DESC
");
$stmt->execute([$from, $to]);
$sales = $stmt->fetchAll();

// Summary totals
$total_count = count($sales);
$total_revenue = 0;
$total_received = 0;
$total_due = 0;

$method_totals = ['cash' => 0, 'card' => 0, 'bank' => 0];
$status_counts = ['paid' => 0, 'partial' => 0, 'pending' => 0];
$daily_totals = [];

foreach ($sales as $s) {
    $amt = (float)$s['total_amount'];
    $paid = (float)$s['paid_amount'];
    $due = (float)$s['due_amount'];
    $total_revenue += $amt;
    $total_received += $paid;
    $total_due += $due;

    $method = $s['payment_method'] === 'bank_transfer' ? 'bank' : $s['payment_method'];
    if (isset($method_totals[$method])) $method_totals[$method] += $amt;

    $ps = $s['payment_status'];
    if (isset($status_counts[$ps])) $status_counts[$ps]++;

    $day = $s['sale_date'];
    if (!isset($daily_totals[$day])) $daily_totals[$day] = ['count' => 0, 'amount' => 0];
    $daily_totals[$day]['count']++;
    $daily_totals[$day]['amount'] += $amt;
}

// Payment method colors
$method_colors = [
    'cash' => ['badge' => 'success', 'icon' => 'money-bill-wave'],
    'card' => ['badge' => 'info', 'icon' => 'credit-card'],
    'bank' => ['badge' => 'info', 'icon' => 'university'],
];
$status_badges = [
    'paid' => 'success',
    'partial' => 'warning',
    'pending' => 'secondary',
];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="m-0 font-weight-bold" style="color:#0f172a;"><i class="fas fa-cart-plus"></i> Sales Report</h5>
</div>

<form method="get" class="form-inline mb-4">
  <label class="mr-2 text-muted small">From:</label>
  <input type="text" name="from" class="form-control form-control-sm mr-3 datepicker" value="<?= $from ?>" autocomplete="off">
  <label class="mr-2 text-muted small">To:</label>
  <input type="text" name="to" class="form-control form-control-sm mr-3 datepicker" value="<?= $to ?>" autocomplete="off">
  <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search"></i> View</button>
  <a href="sales_report.php" class="btn btn-sm btn-secondary ml-2">This Month</a>
  <a href="sales_report.php?from=<?= date('Y-01-01') ?>&to=<?= date('Y-12-31') ?>" class="btn btn-sm btn-info ml-2">This Year</a>
</form>

<div class="row mb-4">
  <div class="col-md-3 mb-3">
    <div class="card border-left-primary shadow h-100 py-2">
      <div class="card-body py-2">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Sales</div>
            <div class="h5 mb-0 font-weight-bold" style="color:#0f172a;"><?= $total_count ?></div>
          </div>
          <div class="col-auto"><i class="fas fa-shopping-cart fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-3 mb-3">
    <div class="card border-left-success shadow h-100 py-2">
      <div class="card-body py-2">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Revenue</div>
            <div class="h5 mb-0 font-weight-bold" style="color:#0f172a;"><?= formatCurrency($total_revenue) ?></div>
          </div>
          <div class="col-auto"><i class="fas fa-money-bill-wave fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-3 mb-3">
    <div class="card border-left-info shadow h-100 py-2">
      <div class="card-body py-2">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Amount Received</div>
            <div class="h5 mb-0 font-weight-bold" style="color:#0f172a;"><?= formatCurrency($total_received) ?></div>
          </div>
          <div class="col-auto"><i class="fas fa-hand-holding-usd fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-3 mb-3">
    <div class="card border-left-warning shadow h-100 py-2">
      <div class="card-body py-2">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Balance Due</div>
            <div class="h5 mb-0 font-weight-bold" style="color:#0f172a;"><?= formatCurrency($total_due) ?></div>
          </div>
          <div class="col-auto"><i class="fas fa-coins fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Payment Method & Status Breakdown -->
<!-- <div class="row mb-4">
  <div class="col-md-6 mb-3">
    <div class="card shadow h-100">
      <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-credit-card"></i> By Payment Method</h6>
      </div>
      <div class="card-body">
        <?php if ($total_revenue > 0): ?>
          <div class="table-responsive">
            <table class="table table-sm table-borderless mb-0">
              <?php foreach ($method_totals as $method => $amt): ?>
                <?php $pct = $total_revenue > 0 ? round($amt / $total_revenue * 100, 1) : 0; ?>
                <tr>
                  <td width="30%">
                    <span class="badge badge-<?= $method_colors[$method]['badge'] ?> status-badge">
                      <i class="fas fa-<?= $method_colors[$method]['icon'] ?>"></i> <?= ucfirst(str_replace('_', ' ', $method)) ?>
                    </span>
                  </td>
                  <td width="40%">
                    <div class="progress" style="height:8px;">
                      <div class="progress-bar bg-<?= $method_colors[$method]['badge'] ?>" style="width:<?= $pct ?>%"></div>
                    </div>
                  </td>
                  <td width="15%" class="text-right"><strong><?= formatCurrency($amt) ?></strong></td>
                  <td width="15%" class="text-right text-muted"><?= $pct ?>%</td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        <?php else: ?>
          <p class="text-muted mb-0 text-center">No sales data</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-6 mb-3">
    <div class="card shadow h-100">
      <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-tag"></i> By Payment Status</h6>
      </div>
      <div class="card-body">
        <?php if ($total_count > 0): ?>
          <div class="table-responsive">
            <table class="table table-sm table-borderless mb-0">
              <?php
              $status_badges = [
                  'paid' => 'success',
                  'partial' => 'warning',
                  'pending' => 'secondary',
              ];
              ?>
              <?php foreach ($status_counts as $status => $count): ?>
                <?php $pct = $total_count > 0 ? round($count / $total_count * 100, 1) : 0; ?>
                <tr>
                  <td width="30%">
                    <span class="badge badge-<?= $status_badges[$status] ?> status-badge"><?= ucfirst($status) ?></span>
                  </td>
                  <td width="40%">
                    <div class="progress" style="height:8px;">
                      <div class="progress-bar bg-<?= $status_badges[$status] ?>" style="width:<?= $pct ?>%"></div>
                    </div>
                  </td>
                  <td width="15%" class="text-right"><strong><?= $count ?></strong></td>
                  <td width="15%" class="text-right text-muted"><?= $pct ?>%</td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        <?php else: ?>
          <p class="text-muted mb-0 text-center">No sales data</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div> -->

<!-- Daily Trend -->
<!-- <div class="card shadow mb-4">
  <div class="card-header py-3">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-calendar-day"></i> Daily Sales Trend</h6>
  </div>
  <div class="card-body">
    <?php if (!empty($daily_totals)): ?>
      <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm" width="100%" cellspacing="0">
          <thead class="thead-light">
            <tr>
              <th>Date</th>
              <th class="text-right">Sales Count</th>
              <th class="text-right">Total Amount</th>
              <th class="text-right">Avg per Sale</th>
            </tr>
          </thead>
          <tbody>
            <?php krsort($daily_totals); ?>
            <?php foreach ($daily_totals as $day => $data): ?>
              <tr>
                <td><strong><?= formatDate($day) ?></strong></td>
                <td class="text-right"><?= $data['count'] ?></td>
                <td class="text-right font-weight-bold" style="color:#0f172a;"><?= formatCurrency($data['amount']) ?></td>
                <td class="text-right"><?= formatCurrency($data['amount'] / $data['count']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="text-muted mb-0 text-center">No sales in this period</p>
    <?php endif; ?>
  </div>
</div> -->

<!-- Detailed Sales List -->
<div class="card shadow mb-4">
  <div class="card-header py-3">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-list"></i> Sales Details</h6>
  </div>
  <div class="card-body">
    <?php if (empty($sales)): ?>
      <div class="text-center text-muted py-3">
        <i class="fas fa-shopping-cart fa-3x mb-3"></i>
        <p>No sales found in this period.</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm" width="100%" cellspacing="0">
          <thead class="thead-light">
            <tr>
              <th>Date</th>
              <th>Invoice</th>
              <th>Customer</th>
              <th>Items</th>
              <th>Method</th>
              <th class="text-right">Total</th>
              <th class="text-right">Paid</th>
              <th class="text-right">Due</th>
              <th>Status</th>
              <th class="text-center">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sales as $s): ?>
              <tr>
                <td><?= formatDate($s['sale_date']) ?></td>
                <td>
                  <a href="<?= $base_url ?>modules/sales/invoice.php?id=<?= $s['id'] ?>">
                    <strong><?= htmlspecialchars($s['invoice_no']) ?></strong>
                  </a>
                </td>
                <td>
                  <a href="<?= $base_url ?>modules/customers/view.php?id=<?= $s['customer_id'] ?>">
                    <?= htmlspecialchars($s['full_name']) ?>
                  </a>
                  <small class="d-block text-muted"><?= htmlspecialchars($s['phone']) ?></small>
                </td>
                <td class="text-center"><?= $s['item_count'] ?></td>
                <td>
                  <?php $mkey = $s['payment_method'] === 'bank_transfer' ? 'bank' : $s['payment_method']; ?>
                  <span class="badge badge-<?= ($method_colors[$mkey]['badge'] ?? 'secondary') ?> status-badge">
                    <?= ucfirst(str_replace('_', ' ', $s['payment_method'])) ?>
                  </span>
                </td>
                <td class="text-right font-weight-bold" style="color:#0f172a;"><?= formatCurrency($s['total_amount']) ?></td>
                <td class="text-right text-success"><?= formatCurrency($s['paid_amount']) ?></td>
                <td class="text-right text-danger"><?= formatCurrency($s['due_amount']) ?></td>
                <td>
                  <?php
                  $badge = $status_badges[$s['payment_status']] ?? 'secondary';
                  ?>
                  <span class="badge badge-<?= $badge ?> status-badge"><?= ucfirst($s['payment_status']) ?></span>
                </td>
                <td class="text-center">
                  <a href="<?= $base_url ?>modules/sales/invoice.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-info" title="View Invoice">
                    <i class="fas fa-eye"></i>
                  </a>
                  <a href="<?= $base_url ?>modules/sales/invoice.php?id=<?= $s['id'] ?>&print=1" class="btn btn-sm btn-outline-secondary" title="Print" target="_blank">
                    <i class="fas fa-print"></i>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
