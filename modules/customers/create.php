<?php
session_start();
$page_title = 'New Customer';
$base_url = '../../';
require_once '../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $branch_id = $_SESSION['branch_id'] ?? null;
    if ($branch_id && !getById('branches', $branch_id)) {
        $branch_id = null;
    }
    $cnic = trim($_POST['cnic'] ?? '') ?: null;
    if ($cnic) {
        $chk = $pdo->prepare("SELECT id, full_name FROM customers WHERE cnic = ?");
        $chk->execute([$cnic]);
        if ($dup = $chk->fetch()) {
            $_SESSION['error'] = "This CNIC already belongs to customer: {$dup['full_name']}";
            header("Location: create.php");
            exit;
        }
    }
    insert('customers', [
        'customer_no'   => generateCustomerNo(),
        'full_name'     => $_POST['name'],
        'phone'         => $_POST['phone'] ?? '',
        'email'         => $_POST['email'] ?? '',
        'cnic'          => $cnic,
        'address'       => $_POST['address'] ?? '',
        'opening_due'   => (float)($_POST['opening_balance'] ?? 0),
        'opening_paid'  => 0,
        'branch_id'     => $branch_id,
        'created_by'    => 1,
        'created_at'    => date('Y-m-d'),
        'updated_at'    => date('Y-m-d')
    ]);
    redirect('index.php', 'Customer created successfully');
}
require_once '../../includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-8">
    <div class="card shadow mb-4">
      <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-user-plus"></i> New Customer</h6></div>
      <div class="card-body">
        <form method="post">
          <div class="row">
            <div class="col-md-6 form-group"><label class="form-label">Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required></div>
            <div class="col-md-6 form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
          </div>
          <div class="row">
            <div class="col-md-6 form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
            <div class="col-md-6 form-group"><label class="form-label">CNIC</label><input type="text" name="cnic" class="form-control" placeholder="XXXXX-XXXXXXX-X"></div>
          </div>
          <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>
          <hr>
          <h6 class="font-weight-bold text-primary"><i class="fas fa-coins"></i> Financial Details</h6>
          <p class="small text-muted">Customer amounts are debit by default (customer owes us).</p>
          <div class="row">
            <div class="col-md-4 form-group"><label class="form-label">Opening Balance (Due)</label><input type="number" name="opening_balance" class="form-control" step="0.01" value="0"></div>
          </div>
          <button type="submit" class="btn btn-primary btn-block py-2"><i class="fas fa-save"></i> Create</button>
          <a href="index.php" class="btn btn-secondary btn-block">Cancel</a>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
