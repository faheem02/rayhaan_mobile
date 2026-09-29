<?php
session_start();
$page_title = 'CNIC Record Management';
$base_url = '../../';
require_once '../../includes/functions.php';

$search = $_GET['search'] ?? '';
$customers = [];

if ($search) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE cnic LIKE ? OR full_name LIKE ? ORDER BY created_at DESC");
    $stmt->execute(["%$search%", "%$search%"]);
    $customers = $stmt->fetchAll();
} else {
    $customers = getAll('customers', 'created_at DESC');
}

require_once '../../includes/header.php';
?>

<div class="card shadow mb-4">
  <div class="card-header py-3">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-search"></i> Search CNIC Records</h6>
  </div>
  <div class="card-body">
    <form method="get" class="row">
      <div class="col-md-10 mb-3 mb-md-0">
        <input type="text" name="search" class="form-control" placeholder="Search by CNIC or Customer Name..." value="<?= htmlspecialchars($search) ?>">
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-search"></i> Search</button>
      </div>
    </form>
  </div>
</div>

<div class="card shadow mb-4">
  <div class="card-header py-3 d-flex justify-content-between align-items-center">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-id-card"></i> CNIC Records</h6>
    <span class="badge badge-secondary"><?= count($customers) ?> record(s)</span>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered" width="100%" cellspacing="0">
        <thead>
          <tr>
            <th>#</th>
            <th>Customer</th>
            <th>CNIC Number</th>
            <th>Expiry</th>
            <th>Guardian</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($customers) > 0): ?>
            <?php foreach ($customers as $i => $c): ?>
              <?php
                $expired = $c['cnic_expiry'] && $c['cnic_expiry'] < date('Y-m-d');
                $expiring = $c['cnic_expiry'] && $c['cnic_expiry'] <= date('Y-m-d', strtotime('+30 days')) && !$expired;
              ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td><a href="view.php?id=<?= $c['id'] ?>"><?= htmlspecialchars($c['full_name']) ?></a></td>
              <td><strong><?= htmlspecialchars($c['cnic']) ?></strong></td>
              <td><?= formatDate($c['cnic_expiry']) ?></td>
              <td><?= htmlspecialchars($c['guardian_name'] ?? '-') ?></td>
              <td>
                <?php if ($expired): ?>
                  <span class="badge badge-danger">Expired</span>
                <?php elseif ($expiring): ?>
                  <span class="badge badge-warning">Expiring Soon</span>
                <?php else: ?>
                  <span class="badge badge-success">Valid</span>
                <?php endif; ?>
              </td>
              <td>
                <a href="edit.php?id=<?= $c['id'] ?>" class="btn btn-primary btn-circle btn-sm">
                  <i class="fas fa-pen"></i>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="7" class="text-center py-4">No records found</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
