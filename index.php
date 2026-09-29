<?php
session_start();
$page_title = 'Dashboard';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Date range filter (default: today)
$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d');

// Period summary stats
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE sale_date BETWEEN ? AND ? AND status != 'cancelled'");
$stmt->execute([$from, $to]);
$period_sales = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date BETWEEN ? AND ?");
$stmt->execute([$from, $to]);
$period_expense = (float)$stmt->fetchColumn();

// COGS - cost basis from serial (mobile) or product purchase price
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(COALESCE(ps.purchase_price, p.purchase_price, 0) * si.quantity), 0)
    FROM sale_items si
    JOIN sales s ON s.id = si.sale_id AND s.status != 'cancelled' AND s.sale_date BETWEEN ? AND ?
    LEFT JOIN product_serials ps ON ps.id = si.serial_id
    LEFT JOIN products p ON p.id = si.product_id
");
$stmt->execute([$from, $to]);
$period_cogs = (float)$stmt->fetchColumn();
$period_profit = $period_sales - $period_cogs;

// Cash in hand as of end of period
$stmt = $pdo->prepare("SELECT closing_balance FROM cash_book_daily WHERE date <= ? ORDER BY date DESC LIMIT 1");
$stmt->execute([$to]);
$cash_in_hand = (float)$stmt->fetchColumn();

// Current stock value (at cost) - non-serialized by qty x cost, serialized by actual unit cost
$stock_value = (float)$pdo->query("SELECT COALESCE(SUM(stock_quantity * purchase_price), 0) FROM products WHERE status = 1 AND has_serial = 0")->fetchColumn();
$stock_value += (float)$pdo->query("SELECT COALESCE(SUM(ps.purchase_price), 0) FROM product_serials ps JOIN products p ON p.id = ps.product_id WHERE ps.status = 'available' AND p.status = 1")->fetchColumn();

// Khata / credit balances (customers owing money)
$khatas = $pdo->query("
    SELECT s.id, s.invoice_no, s.sale_date, s.total_amount, s.paid_amount, s.due_amount,
           c.full_name, c.id as cid, c.phone
    FROM sales s
    JOIN customers c ON s.customer_id = c.id
    WHERE s.due_amount > 0 AND s.status != 'cancelled'
    ORDER BY s.due_amount DESC LIMIT 10
")->fetchAll();

// Recent sales
$recent_sales = $pdo->query("
    SELECT s.id, s.invoice_no, s.total_amount, s.payment_status, s.sale_date,
           c.full_name, c.id as cid
    FROM sales s
    JOIN customers c ON s.customer_id = c.id
    ORDER BY s.id DESC LIMIT 5
")->fetchAll();

// Recent customers
$recent_customers = $pdo->query("SELECT id, full_name, phone, cnic, created_at FROM customers ORDER BY created_at DESC LIMIT 5")->fetchAll();

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-tachometer-alt"></i> Summary <span class="text-muted small font-weight-normal"><?= formatDate($from) ?> &rarr; <?= formatDate($to) ?></span></h6>
  <a href="modules/inventory/products.php" class="btn btn-sm btn-info"><i class="fas fa-boxes"></i> View Product List</a>
</div>

<form method="get" class="form-inline mb-4">
  <label class="mr-1 text-muted small">From:</label>
  <input type="text" name="from" class="form-control form-control-sm mr-2 datepicker" value="<?= htmlspecialchars($from) ?>" autocomplete="off">
  <label class="mr-1 text-muted small">To:</label>
  <input type="text" name="to" class="form-control form-control-sm mr-3 datepicker" value="<?= htmlspecialchars($to) ?>" autocomplete="off">
  <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter"></i> View</button>
</form>

<div class="row">

  <div class="col-xl-4 col-md-6 mb-4">
    <div class="card border-left-success shadow h-100 py-2">
      <div class="card-body">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Sales</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($period_sales)?></div>
          </div>
          <div class="col-auto"><i class="fas fa-shopping-cart fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-4 col-md-6 mb-4">
    <div class="card border-left-primary shadow h-100 py-2">
      <div class="card-body">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Profit</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($period_profit)?></div>
          </div>
          <div class="col-auto"><i class="fas fa-chart-line fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-4 col-md-6 mb-4">
    <div class="card border-left-danger shadow h-100 py-2">
      <div class="card-body">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Expense</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($period_expense)?></div>
          </div>
          <div class="col-auto"><i class="fas fa-file-invoice-dollar fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-4 col-md-6 mb-4">
    <div class="card border-left-warning shadow h-100 py-2">
      <div class="card-body">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Cash in Hand</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($cash_in_hand)?></div>
          </div>
          <div class="col-auto"><i class="fas fa-money-bill-wave fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-4 col-md-6 mb-4">
    <div class="card border-left-info shadow h-100 py-2">
      <div class="card-body">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Stock Value</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($stock_value)?></div>
          </div>
          <div class="col-auto"><i class="fas fa-boxes fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>

</div>

<div class="row">

  <!-- Khata / Credit Balances -->
  <div class="col-lg-12 mb-4">
    <div class="card shadow mb-4">
      <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-danger"><i class="fas fa-book"></i> Khata / Credit Balances</h6>
        <a href="modules/reports/closing_report.php" class="btn btn-danger btn-sm">View All</a>
      </div>
      <div class="card-body">
        <?php if (count($khatas)): ?>
        <div class="table-responsive">
          <table class="table table-bordered" width="100%" cellspacing="0">
            <thead>
              <tr><th>Customer</th><th>Phone</th><th>Invoice</th><th>Date</th><th>Total</th><th>Paid</th><th>Due</th></tr>
            </thead>
            <tbody>
              <?php foreach ($khatas as $d): ?>
              <tr>
                <td><a href="modules/customers/view.php?id=<?=$d['cid']?>"><?=htmlspecialchars($d['full_name'])?></a></td>
                <td><?=htmlspecialchars($d['phone'])?></td>
                <td><a href="modules/sales/invoice.php?id=<?=$d['id']?>"><?=htmlspecialchars($d['invoice_no'])?></a></td>
                <td><?=formatDate($d['sale_date'])?></td>
                <td>PKR <?=formatCurrency($d['total_amount'])?></td>
                <td>PKR <?=formatCurrency($d['paid_amount'])?></td>
                <td class="text-danger font-weight-bold">PKR <?=formatCurrency($d['due_amount'])?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
          <p class="text-center text-muted py-3 mb-0"><i class="fas fa-check-circle text-success fa-2x d-block mb-2"></i>No outstanding balances</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row">

  <!-- Recent Sales -->
  <div class="col-lg-6 mb-4">
    <div class="card shadow mb-4">
      <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-file-invoice"></i> Recent Sales</h6>
        <a href="modules/sales/invoices.php" class="btn btn-primary btn-sm">View All</a>
      </div>
      <div class="card-body">
        <?php if (count($recent_sales)): ?>
        <div class="table-responsive">
          <table class="table table-bordered" width="100%" cellspacing="0">
            <thead>
              <tr><th>Invoice</th><th>Customer</th><th>Amount</th><th>Status</th><th>Date</th></tr>
            </thead>
            <tbody>
              <?php foreach ($recent_sales as $s): ?>
              <tr>
                <td><a href="modules/sales/invoice.php?id=<?=$s['id']?>"><?=htmlspecialchars($s['invoice_no'])?></a></td>
                <td><a href="modules/customers/view.php?id=<?=$s['cid']?>"><?=htmlspecialchars($s['full_name'])?></a></td>
                <td>PKR <?=formatCurrency($s['total_amount'])?></td>
                <td><span class="badge badge-<?=$s['payment_status']==='paid'?'success':'warning'?>"><?=ucfirst($s['payment_status'])?></span></td>
                <td><?=formatDate($s['sale_date'])?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
          <p class="text-center text-muted py-3 mb-0">No sales yet</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent Customers -->
  <div class="col-lg-6 mb-4">
    <div class="card shadow mb-4">
      <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-users"></i> Recent Customers</h6>
        <a href="modules/customers/index.php" class="btn btn-primary btn-sm">View All</a>
      </div>
      <div class="card-body">
        <?php if (count($recent_customers)): ?>
        <div class="table-responsive">
          <table class="table table-bordered" width="100%" cellspacing="0">
            <thead>
              <tr><th>Name</th><th>Phone</th><th>CNIC</th><th>Date</th></tr>
            </thead>
            <tbody>
              <?php foreach ($recent_customers as $c): ?>
              <tr>
                <td><a href="modules/customers/view.php?id=<?=$c['id']?>"><?=htmlspecialchars($c['full_name'])?></a></td>
                <td><?=htmlspecialchars($c['phone'])?></td>
                <td><?=htmlspecialchars($c['cnic'])?></td>
                <td><?=formatDate($c['created_at'])?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
          <p class="text-center text-muted py-3 mb-0">No customers yet</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>
