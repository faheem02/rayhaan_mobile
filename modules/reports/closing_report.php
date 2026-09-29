<?php
session_start();
$is_print = isset($_GET['print']);
$page_title = 'Closing Report';
$base_url = '../../';
require_once '../../includes/functions.php';

$search = trim($_GET['search'] ?? '');

if ($is_print) {
    $print_style = true;
} else {
    require_once '../../includes/header.php';
}

$where = '';
$params = [];
if ($search !== '') {
    $where = "WHERE c.full_name LIKE ? OR c.phone LIKE ? OR c.customer_no LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%"];
}

$customers = $pdo->prepare("
    SELECT
        c.id,
        c.customer_no,
        c.full_name,
        c.phone,
        c.opening_due,
        c.opening_paid,
        COALESCE(s.total_sales, 0) AS total_sales,
        COALESCE(s.total_due, 0) AS total_due,
        COALESCE(r.total_returns, 0) AS total_returns,
        COALESCE(p.total_payments, 0) AS total_payments,
        COALESCE(prods.products, '-') AS products
    FROM customers c
    LEFT JOIN (
        SELECT customer_id,
               SUM(total_amount) AS total_sales,
               SUM(due_amount) AS total_due
        FROM sales
        WHERE status != 'cancelled'
        GROUP BY customer_id
    ) s ON s.customer_id = c.id
    LEFT JOIN (
        SELECT customer_id,
               SUM(amount) AS total_returns
        FROM sale_returns
        GROUP BY customer_id
    ) r ON r.customer_id = c.id
    LEFT JOIN (
        SELECT s2.customer_id,
               SUM(p2.amount) AS total_payments
        FROM payments p2
        JOIN sales s2 ON s2.id = p2.sale_id
        GROUP BY s2.customer_id
    ) p ON p.customer_id = c.id
    LEFT JOIN (
        SELECT s3.customer_id,
               GROUP_CONCAT(DISTINCT COALESCE(pr.name, si.item_description) SEPARATOR ', ') AS products
        FROM sale_items si
        JOIN sales s3 ON s3.id = si.sale_id AND s3.status != 'cancelled'
        LEFT JOIN products pr ON pr.id = si.product_id
        GROUP BY s3.customer_id
    ) prods ON prods.customer_id = c.id
    $where
    ORDER BY c.full_name ASC
");
$customers->execute($params);
$customers = $customers->fetchAll();

$grand_price = 0;
$grand_due = 0;
$grand_balance = 0;

foreach ($customers as &$cust) {
    $opening = (float)$cust['opening_due'] - (float)$cust['opening_paid'];
    $sales = (float)$cust['total_sales'];
    $returns = (float)$cust['total_returns'];
    $payments = (float)$cust['total_payments'];
    $closing = $opening + $sales - $returns - $payments;

    $cust['net_closing'] = $closing;

    $grand_price += $sales;
    $grand_due += (float)$cust['total_due'];
    $grand_balance += $closing;
}
unset($cust);

if ($is_print):
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Closing Report</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css">
<style>
  @media print {
    @page { size: A4 landscape; margin: 8mm; }
    body{ background:#fff; font-size:10px; }
    .no-print { display:none !important; }
    .wrap { box-shadow:none !important; border:1px solid #000 !important; max-width:100%; margin:0; }
  }
  body { background: #f1f5f9; font-family: 'Segoe UI', Arial, sans-serif; font-size:12px; }
  .wrap { max-width: 1100px; margin: 20px auto; background: #fff; border: 2px solid #1f2937; border-radius: 4px; padding: 0; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
  .r-header { display:flex; align-items:center; gap:14px; padding: 14px 18px 8px 18px; border-bottom: 2px solid #1f2937; }
  .r-logo { width:60px; height:60px; border:3px solid #4b5563; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:1.4rem; color:#374151; flex-shrink:0; }
  .r-company-name { font-size:1.6rem; font-weight:700; margin:0; color:#1f2937; }
  .r-company-sub { font-size:.85rem; font-weight:600; letter-spacing:.03em; margin:0; color:#1f2937; }
  .r-company-contact { font-size:.7rem; color:#374151; margin:0; }
  .r-section-title { font-size:.95rem; font-weight:700; padding: 8px 18px; border-bottom: 1px solid #1f2937; text-decoration: underline; text-underline-offset: 3px; }
  .r-content { padding: 8px 18px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #1f2937; padding: 4px 6px; text-align: center; font-size:11px; }
  th { background: #f8fafc; color: #475569; font-weight:700; }
  td.text-right { text-align: right; }
  td.text-left { text-align: left; }
  .r-software { text-align:left; font-size:.7rem; color:#9ca3af; padding: 6px 18px 14px 18px; }
</style>
</head>
<body>

<div class="no-print text-center mt-3 mb-3">
  <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-download"></i> Print / PDF</button>
  <button class="btn btn-secondary" onclick="window.close()"><i class="fas fa-times"></i> Close</button>
</div>

<div class="wrap">
  <div class="r-header">
    <div class="r-logo">RMK</div>
    <div>
      <p class="r-company-name">RAYHAAN MOBILE KAHUTA</p>
      <p class="r-company-sub">Mobile Phone Sales</p>
      <p class="r-company-contact">Near Ameen Plaza, Kallar Road, Kahuta | Cell: 0336-5389945</p>
    </div>
  </div>

  <div class="r-section-title">Closing Report</div>

  <div style="padding:8px 18px;">
    <table>
      <thead>
        <tr>
          <th style="width:40px;">Sr#</th>
          <th style="width:90px;">Acc No.</th>
          <th style="width:140px;" class="text-left">Name</th>
          <th style="width:100px;">Phone</th>
          <th class="text-left">Product</th>
          <th style="width:80px;">Price</th>
          <th style="width:80px;">Due</th>
          <th style="width:80px;">Balance</th>
        </tr>
      </thead>
      <tbody>
        <?php $i = 1; foreach ($customers as $c): ?>
          <tr>
            <td><?= $i++ ?></td>
            <td><?= htmlspecialchars($c['customer_no'] ?? '-') ?></td>
            <td class="text-left"><?= htmlspecialchars($c['full_name']) ?></td>
            <td><?= htmlspecialchars($c['phone'] ?? '-') ?></td>
            <td class="text-left small"><?= htmlspecialchars($c['products']) ?></td>
            <td class="text-right"><?= formatCurrency($c['total_sales']) ?></td>
            <td class="text-right"><?= formatCurrency($c['total_due']) ?></td>
            <td class="text-right" style="font-weight:700;color:<?= $c['net_closing'] > 0 ? '#dc2626' : ($c['net_closing'] < 0 ? '#16a34a' : '#1f2937') ?>;">
              <?= formatCurrency($c['net_closing']) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:#f8f9fc;font-weight:bold;">
          <td colspan="5" class="text-right">Grand Totals</td>
          <td class="text-right"><?= formatCurrency($grand_price) ?></td>
          <td class="text-right"><?= formatCurrency($grand_due) ?></td>
          <td class="text-right" style="color:<?= $grand_balance > 0 ? '#dc2626' : ($grand_balance < 0 ? '#16a34a' : '#1f2937') ?>;">
            <?= formatCurrency($grand_balance) ?>
          </td>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="r-software">[Software By @ ATR]</div>
</div>

<script>window.print()</script>
</body>
</html>
<?php
else:
?>
<style>
.status-badge { font-size: .75rem; padding: 3px 10px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="m-0 font-weight-bold" style="color:#0f172a;"><i class="fas fa-file-invoice"></i> Closing Report</h5>
  <div>
    <a href="closing_report.php?print=1<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="btn btn-sm btn-success" target="_blank"><i class="fas fa-print"></i> Print</a>
  </div>
</div>

<div class="card shadow mb-4">
  <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-list"></i> All Customers Closing Balances</h6>
    <form method="get" class="form-inline">
      <input type="text" name="search" class="form-control form-control-sm" style="min-width:220px;" placeholder="Search by name, phone or acc no..." value="<?= htmlspecialchars($search) ?>" autocomplete="off">
      <button type="submit" class="btn btn-sm btn-primary ml-1"><i class="fas fa-search"></i></button>
      <?php if ($search !== ''): ?>
        <a href="closing_report.php" class="btn btn-sm btn-secondary ml-1"><i class="fas fa-times"></i></a>
      <?php endif; ?>
    </form>
  </div>
  <div class="card-body">
    <?php if (empty($customers)): ?>
      <div class="text-center text-muted py-3">
        <i class="fas fa-users fa-3x mb-3"></i>
        <p>No customers found.</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm" width="100%" cellspacing="0">
          <thead class="thead-light">
            <tr>
              <th>Sr#</th>
              <th>Acc No.</th>
              <th>Name</th>
              <th>Phone</th>
              <th>Product</th>
              <th class="text-right">Price</th>
              <th class="text-right">Due</th>
              <th class="text-right">Balance</th>
            </tr>
          </thead>
          <tbody>
            <?php $i = 1; foreach ($customers as $c): ?>
              <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($c['customer_no'] ?? '-') ?></td>
                <td>
                  <a href="<?= $base_url ?>modules/customers/view.php?id=<?= $c['id'] ?>">
                    <strong><?= htmlspecialchars($c['full_name']) ?></strong>
                  </a>
                </td>
                <td><?= htmlspecialchars($c['phone'] ?? '-') ?></td>
                <td class="small"><?= htmlspecialchars($c['products']) ?></td>
                <td class="text-right font-weight-bold" style="color:#0f172a;"><?= formatCurrency($c['total_sales']) ?></td>
                <td class="text-right font-weight-bold" style="color:#dc3545;"><?= formatCurrency($c['total_due']) ?></td>
                <td class="text-right font-weight-bold" style="color:<?= $c['net_closing'] > 0 ? '#dc3545' : ($c['net_closing'] < 0 ? '#28a745' : '#0f172a') ?>;">
                  <?= formatCurrency($c['net_closing']) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot class="table-active">
            <tr>
              <th colspan="5" class="text-right font-weight-bold" style="font-size:1rem;">Grand Totals</th>
              <th class="text-right font-weight-bold" style="font-size:1rem;color:#0f172a;"><?= formatCurrency($grand_price) ?></th>
              <th class="text-right font-weight-bold" style="font-size:1rem;color:#dc3545;"><?= formatCurrency($grand_due) ?></th>
              <th class="text-right font-weight-bold" style="font-size:1rem;color:<?= $grand_balance > 0 ? '#dc3545' : ($grand_balance < 0 ? '#28a745' : '#0f172a') ?>;">
                <?= formatCurrency($grand_balance) ?>
              </th>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
<?php endif; ?>
