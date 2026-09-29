<?php
session_start();
$page_title = 'Customer Purchase History';
$base_url = '../../';
require_once '../../includes/functions.php';

$customer_id = (int)($_GET['customer_id'] ?? 0);
$customers = getAll('customers', 'full_name ASC');
$customer = null;
$sales = [];

if ($customer_id) {
    $customer = getById('customers', $customer_id);
    if ($customer) {
        $sales = getWhere('sales', 'customer_id', $customer_id, 'sale_date DESC');
    }
}

require_once '../../includes/header.php';
?>

<div class="card shadow mb-4">
  <div class="card-header py-3">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-history"></i> Purchase History</h6>
  </div>
  <div class="card-body">
    <form method="get" class="row">
      <div class="col-md-10 mb-3 mb-md-0">
        <label class="form-label">Select Customer</label>
        <select name="customer_id" class="form-control" onchange="this.form.submit()">
          <option value="">-- Choose Customer --</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $customer_id == $c['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['full_name']) ?> (<?= htmlspecialchars($c['cnic']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <noscript><button type="submit" class="btn btn-primary btn-block">View</button></noscript>
      </div>
    </form>
  </div>
</div>

<?php if ($customer): ?>

  <div class="row">
    <div class="col-xl-4 col-md-6 mb-4">
      <div class="card border-left-primary shadow h-100 py-2">
        <div class="card-body">
          <div class="row no-gutters align-items-center">
            <div class="col mr-2">
              <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Purchases</div>
              <div class="h5 mb-0 font-weight-bold text-gray-800"><?= count($sales) ?></div>
            </div>
            <div class="col-auto"><i class="fas fa-shopping-cart fa-2x text-gray-300"></i></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-xl-4 col-md-6 mb-4">
      <div class="card border-left-success shadow h-100 py-2">
        <div class="card-body">
          <div class="row no-gutters align-items-center">
            <div class="col mr-2">
              <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Amount</div>
              <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?= formatCurrency(array_sum(array_column($sales, 'total_amount'))) ?></div>
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
              <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Outstanding</div>
              <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?= formatCurrency(array_sum(array_column($sales, 'due_amount'))) ?></div>
            </div>
            <div class="col-auto"><i class="fas fa-coins fa-2x text-gray-300"></i></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow mb-4">
    <div class="card-header py-3">
      <h6 class="m-0 font-weight-bold text-primary">Sales History — <?= htmlspecialchars($customer['full_name']) ?></h6>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-bordered" width="100%" cellspacing="0">
          <thead>
            <tr>
              <th>Invoice</th>
              <th>Date</th>
              <th>Total</th>
              <th>Paid</th>
              <th>Due</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($sales) > 0): ?>
              <?php foreach ($sales as $s): ?>
              <tr>
                <td><a href="<?= $base_url ?>modules/sales/invoice.php?id=<?= $s['id'] ?>"><span class="badge badge-info"><?= htmlspecialchars($s['invoice_no']) ?></span></a></td>
                <td><?= formatDate($s['sale_date']) ?></td>
                <td>PKR <?= formatCurrency($s['total_amount']) ?></td>
                <td>PKR <?= formatCurrency($s['paid_amount']) ?></td>
                <td class="text-danger">PKR <?= formatCurrency($s['due_amount']) ?></td>
                <td>
                  <span class="badge badge-<?= $s['payment_status'] == 'paid' ? 'success' : ($s['payment_status'] == 'partial' ? 'warning' : 'secondary') ?>">
                    <?= ucfirst($s['payment_status']) ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="6" class="text-center py-4">No purchases found for this customer</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<?php elseif ($customer_id && !$customer): ?>
  <div class="alert alert-danger">Customer not found</div>
<?php else: ?>
  <div class="card shadow mb-4">
    <div class="card-body text-center py-5">
      <i class="fas fa-inbox fa-3x text-gray-300 mb-3"></i>
      <p class="text-gray-500">Select a customer to view purchase history</p>
    </div>
  </div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
