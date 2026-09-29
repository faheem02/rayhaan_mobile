<?php
session_start();
$page_title = 'General Ledger';
$base_url = '../../';
require_once '../../includes/functions.php';
$bank_accounts = getAll('bank_accounts', 'bank_name ASC, account_name ASC');
$users = $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll();

// Handle Add/Edit party
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_party'])) {
    $id = (int)($_POST['party_id'] ?? 0);
    $data = [
        'name' => $_POST['name'],
        'phone' => $_POST['phone'] ?? '',
        'address' => $_POST['address'] ?? '',
        'opening_balance' => (float)($_POST['opening_balance'] ?? 0),
        'notes' => $_POST['notes'] ?? '',
        'created_at' => date('Y-m-d'),
    ];
    if ($id) {
        $data['updated_at'] = date('Y-m-d');
        update('general_parties', $data, $id);
        $_SESSION['success'] = 'Party updated';
    } else {
        insert('general_parties', $data);
        $_SESSION['success'] = 'Party created';
    }
    header("Location: index.php");
    exit;
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM general_parties WHERE id = ?")->execute([$id]);
    $_SESSION['success'] = 'Party deleted';
    header("Location: index.php");
    exit;
}

$parties = $pdo->query("SELECT gp.*,
    (SELECT COALESCE(SUM(CASE WHEN gt.type='receipt' THEN gt.amount ELSE -gt.amount END), 0) FROM general_transactions gt WHERE gt.party_id = gp.id) AS net_transactions
    FROM general_parties gp ORDER BY gp.name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?=$page_title?></title>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
</head>
<body>
<?php include $base_url . 'includes/header.php'; ?>

<div class="container-fluid">
  <h1 class="h3 mb-3" style="color:#0f172a;">General Ledger</h1>

  <?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><?=$_SESSION['success']; unset($_SESSION['success'])?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
  <?php endif; ?>

  <!-- Add Party Card -->
  <div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
      <h6 class="m-0 font-weight-bold" style="color:#0f172a;">Add / Edit Party</h6>
    </div>
    <div class="card-body">
      <form method="post" class="form-row align-items-end">
        <input type="hidden" name="party_id" id="editPartyId" value="0">
        <div class="col-md-3 mb-2">
          <label class="small font-weight-bold">Name *</label>
          <input type="text" name="name" id="editName" class="form-control form-control-sm" required>
        </div>
        <div class="col-md-2 mb-2">
          <label class="small font-weight-bold">Phone</label>
          <input type="text" name="phone" id="editPhone" class="form-control form-control-sm">
        </div>
        <div class="col-md-3 mb-2">
          <label class="small font-weight-bold">Address</label>
          <input type="text" name="address" id="editAddress" class="form-control form-control-sm">
        </div>
        <div class="col-md-2 mb-2">
          <label class="small font-weight-bold">Opening Balance</label>
          <input type="number" step="0.01" name="opening_balance" id="editOpening" class="form-control form-control-sm" value="0">
        </div>
        <div class="col-md-2 mb-2">
          <label class="small font-weight-bold">Notes</label>
          <input type="text" name="notes" id="editNotes" class="form-control form-control-sm">
        </div>
        <div class="col-md-12 mb-2">
          <button type="submit" name="save_party" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save Party</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="resetForm()"><i class="fas fa-times"></i> Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Parties List -->
  <div class="card shadow mb-4">
    <div class="card-header py-3">
      <h6 class="m-0 font-weight-bold" style="color:#0f172a;">All Parties</h6>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-bordered table-hover">
          <thead class="thead-light">
            <tr><th>Name</th><th>Phone</th><th>Address</th><th class="text-right">Opening</th><th class="text-right">Balance</th><th class="text-center">Actions</th></tr>
          </thead>
          <tbody>
            <?php if (empty($parties)): ?>
              <tr><td colspan="6" class="text-center text-muted">No parties added yet</td></tr>
            <?php else: foreach ($parties as $p):
              $balance = (float)$p['opening_balance'] + (float)$p['net_transactions'];
            ?>
              <tr>
                <td><?=htmlspecialchars($p['name'])?></td>
                <td><?=htmlspecialchars($p['phone'] ?? '-')?></td>
                <td><?=htmlspecialchars($p['address'] ?? '-')?></td>
                <td class="text-right"><?=formatCurrency($p['opening_balance'])?></td>
                <td class="text-right"><strong style="color:#dc2626;"><?=formatCurrency($balance)?></strong></td>
                <td class="text-center">
                  <button type="button" class="btn btn-sm btn-info" title="View Ledger" onclick="viewLedger(<?=$p['id']?>)"><i class="fas fa-book"></i></button>
                  <a href="ledger_print.php?id=<?=$p['id']?>" target="_blank" class="btn btn-sm btn-secondary" title="Print Ledger"><i class="fas fa-print"></i></a>
                  <button type="button" class="btn btn-sm btn-primary" title="Edit" onclick="editParty(<?=$p['id']?>)"><i class="fas fa-pen"></i></button>
                  <a href="index.php?delete=<?=$p['id']?>" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Delete this party?')"><i class="fas fa-trash"></i></a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Ledger View Modal -->
  <div class="modal fade" id="ledgerModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="ledgerTitle">Party Ledger</h5>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body" id="ledgerBody">
          <div class="text-center text-muted py-5">Select a party to view ledger</div>
        </div>
      </div>
    </div>
  </div>

</div>

<?php include $base_url . 'includes/footer.php'; ?>

<script>
function resetForm() {
    document.getElementById('editPartyId').value = 0;
    document.getElementById('editName').value = '';
    document.getElementById('editPhone').value = '';
    document.getElementById('editAddress').value = '';
    document.getElementById('editOpening').value = 0;
    document.getElementById('editNotes').value = '';
}

function editParty(id) {
    fetch('party_get.php?id=' + id)
    .then(function(r) { return r.json(); })
    .then(function(d) {
        document.getElementById('editPartyId').value = d.id;
        document.getElementById('editName').value = d.name;
        document.getElementById('editPhone').value = d.phone || '';
        document.getElementById('editAddress').value = d.address || '';
        document.getElementById('editOpening').value = d.opening_balance || 0;
        document.getElementById('editNotes').value = d.notes || '';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

function viewLedger(id) {
    document.getElementById('ledgerTitle').textContent = 'Loading...';
    document.getElementById('ledgerBody').innerHTML = '<div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    $('#ledgerModal').modal('show');
    fetch('view_ledger.php?id=' + id)
    .then(function(r) { return r.text(); })
    .then(function(html) {
        document.getElementById('ledgerTitle').textContent = 'Party Ledger';
        document.getElementById('ledgerBody').innerHTML = html;
    });
}
</script>
</body>
</html>
