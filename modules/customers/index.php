<?php
session_start();
$page_title = 'Customer Management';
$base_url = '../../';
require_once '../../includes/functions.php';

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $customer = getById('customers', $id);
    if ($customer) {
        delete('customers', $id);
        redirect('index.php', 'Customer deleted successfully');
    }
}

$customers = getAll('customers', 'created_at DESC');
require_once '../../includes/header.php';
?>

<div class="card shadow mb-4">
  <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-users"></i> All Customers</h6>
    <a href="create.php" class="btn btn-primary btn-sm">
      <i class="fas fa-user-plus"></i> New Customer
    </a>
  </div>
  <div class="card-body">
    <div class="row mb-3">
      <div class="col-md-4">
        <input type="text" id="customerSearch" class="form-control" placeholder="Search by name, phone, CNIC, or customer no..." autofocus>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-bordered table-hover" id="dataTable" width="100%" cellspacing="0">
        <thead>
          <tr>
            <th>#</th>
            <th>Customer No</th>
            <th>Full Name</th>
            <th>Phone</th>
            <th>CNIC</th>
            <th>Address</th>
            <th>Opening Due</th>
            <th>Remaining</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($customers) > 0): ?>
            <?php foreach ($customers as $i => $c): ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td><span class="badge badge-secondary"><?= htmlspecialchars($c['customer_no']) ?></span></td>
              <td>
                <a href="view.php?id=<?= $c['id'] ?>">
                  <?= htmlspecialchars($c['full_name']) ?>
                </a>
              </td>
              <td><?= htmlspecialchars($c['phone']) ?></td>
              <td><?= htmlspecialchars($c['cnic']) ?></td>
              <td><?= htmlspecialchars($c['address'] ?: ($c['city'] ?? '-')) ?></td>
              <td class="text-right"><?= formatCurrency($c['opening_due'] ?? 0) ?></td>
              <td class="text-right"><?= formatCurrency(($c['opening_due'] ?? 0) - ($c['opening_paid'] ?? 0)) ?></td>
              <td><?= formatDate($c['created_at']) ?></td>
              <td class="text-nowrap">
                <a href="view.php?id=<?= $c['id'] ?>" class="btn btn-info btn-circle btn-sm" title="View">
                  <i class="fas fa-eye"></i>
                </a>
                <a href="edit.php?id=<?= $c['id'] ?>" class="btn btn-primary btn-circle btn-sm" title="Edit">
                  <i class="fas fa-pen"></i>
                </a>
                <a href="index.php?delete=<?= $c['id'] ?>" class="btn btn-danger btn-circle btn-sm" title="Delete" onclick="return confirm('Delete this customer?')">
                  <i class="fas fa-trash"></i>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="10" class="text-center py-4">No customers found.
                <a href="create.php" class="btn btn-primary btn-sm">Add Customer</a>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    $('#customerSearch').on('keyup', function() {
        var q = $(this).val().toLowerCase();
        $('#dataTable tbody tr').each(function() {
            var text = $(this).text().toLowerCase();
            $(this).toggle(text.indexOf(q) > -1);
        });
    });
});
</script>
