<?php
session_start();
$page_title = 'Edit Customer';
$base_url = '../../';
require_once '../../includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$customer = getById('customers', $id);
if (!$customer) redirect('index.php', 'Customer not found', 'error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cnic = trim($_POST['cnic'] ?? '') ?: null;
    if ($cnic) {
        $chk = $pdo->prepare("SELECT id, full_name FROM customers WHERE cnic = ? AND id != ?");
        $chk->execute([$cnic, $id]);
        if ($dup = $chk->fetch()) {
            $_SESSION['error'] = "This CNIC already belongs to customer: {$dup['full_name']}";
            header("Location: edit.php?id=$id");
            exit;
        }
    }
    update('customers', [
        'full_name'     => $_POST['name'],
        'phone'         => $_POST['phone'] ?? '',
        'email'         => $_POST['email'] ?? '',
        'cnic'          => $cnic,
        'address'       => $_POST['address'] ?? '',
        'opening_due'   => (float)($_POST['opening_balance'] ?? 0),
        'opening_paid'  => 0,
        'updated_at'    => date('Y-m-d')
    ], $id);
    redirect('index.php', 'Customer updated successfully');
}

require_once '../../includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-8">
    <div class="card shadow mb-4">
      <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-user-edit"></i> Edit Customer</h6></div>
      <div class="card-body">
        <form method="post">
          <div class="row">
            <div class="col-md-6 form-group"><label class="form-label">Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($customer['full_name']) ?>" required></div>
            <div class="col-md-6 form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($customer['phone']) ?>"></div>
          </div>
          <div class="row">
            <div class="col-md-6 form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($customer['email'] ?? '') ?>"></div>
            <div class="col-md-6 form-group"><label class="form-label">CNIC</label><input type="text" name="cnic" class="form-control" placeholder="XXXXX-XXXXXXX-X" value="<?= htmlspecialchars($customer['cnic'] ?? '') ?>"></div>
          </div>
          <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($customer['address'] ?? '') ?></textarea></div>
          <hr>
          <h6 class="font-weight-bold text-primary"><i class="fas fa-coins"></i> Financial Details</h6>
          <p class="small text-muted">Customer amounts are debit by default (customer owes us).</p>
          <div class="row">
            <div class="col-md-4 form-group"><label class="form-label">Opening Balance (Due)</label><input type="number" name="opening_balance" class="form-control" step="0.01" value="<?= (float)$customer['opening_due'] ?>"></div>
          </div>
          <button type="submit" class="btn btn-primary btn-block py-2"><i class="fas fa-save"></i> Update</button>
          <a href="index.php" class="btn btn-secondary btn-block">Cancel</a>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
