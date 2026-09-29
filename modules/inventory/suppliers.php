<?php
session_start();
$page_title = 'Suppliers';
$base_url = '../../';
require_once '../../includes/functions.php';

if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $c = getById('suppliers', $id);
    if ($c) { $ns = $c['status'] ? 0 : 1; $pdo->prepare("UPDATE suppliers SET status=?,updated_at=CURDATE() WHERE id=?")->execute([$ns,$id]); redirect('suppliers.php', 'Supplier status updated'); }
}
if (isset($_GET['delete'])) { delete('suppliers', (int)$_GET['delete']); redirect('suppliers.php', 'Supplier deleted'); }

$items = $pdo->query("
    SELECT s.*,
           IFNULL(purch.total_purchase, 0) AS total_purchase,
           IFNULL(sp.total_paid, 0) AS total_paid
    FROM suppliers s
    LEFT JOIN (SELECT supplier_id, SUM(total_amount) AS total_purchase FROM purchases WHERE status != 'cancelled' GROUP BY supplier_id) purch ON purch.supplier_id = s.id
    LEFT JOIN (SELECT supplier_id, SUM(amount) AS total_paid FROM supplier_payments GROUP BY supplier_id) sp ON sp.supplier_id = s.id
    ORDER BY s.name ASC
")->fetchAll();

require_once '../../includes/header.php';
?>

<div class="card shadow mb-4">
  <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-truck-loading"></i> Suppliers</h6>
    <a href="supplier_create.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> New Supplier</a>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered table-hover">
        <thead class="thead-light"><tr><th>ID</th><th>Name</th><th>Phone</th><th>Address</th><th class="text-right">Closing Balance</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if (empty($items)): ?><tr><td colspan="6" class="text-center text-muted">No suppliers found</td></tr>
          <?php else: foreach ($items as $i => $c):
            $closing = (float)$c['opening_balance'] + (float)$c['adjustment'] + (float)$c['total_purchase'] - (float)$c['total_paid'];
          ?>
            <tr>
              <td><span class="badge badge-secondary"><?= $c['id'] ?></span></td>
              <td><strong><?= htmlspecialchars($c['contact_person']) ?></strong><?= $c['name'] ? '<br><span class="text-muted">' . htmlspecialchars($c['name']) . '</span>' : '' ?></td>
              <td><?= htmlspecialchars($c['phone'] ?? '-') ?></td>
              <td><?= htmlspecialchars($c['city'] ?? ($c['address'] ?? '-')) ?></td>
              <td class="text-right font-weight-bold" style="color:#0f172a;"><?= formatCurrency($closing) ?></td>
              <td>
                <a href="supplier_view.php?id=<?=$c['id']?>" class="btn btn-sm btn-info" title="Details"><i class="fas fa-eye"></i></a>
                <a href="supplier_edit.php?id=<?=$c['id']?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                <a href="suppliers.php?delete=<?=$c['id']?>" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
