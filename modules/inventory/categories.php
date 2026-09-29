<?php
session_start();
$page_title = 'Categories';
$base_url = '../../';
require_once '../../includes/functions.php';

if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $c = getById('categories', $id);
    if ($c) {
        $ns = $c['status'] ? 0 : 1;
        $pdo->prepare("UPDATE categories SET status = ?, updated_at = CURDATE() WHERE id = ?")->execute([$ns, $id]);
        redirect('categories.php', 'Category status updated');
    }
}
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    delete('categories', $id);
    redirect('categories.php', 'Category deleted');
}

$items = getAll('categories', 'name ASC');
require_once '../../includes/header.php';
?>

<div class="card shadow mb-4">
  <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-tags"></i> Categories</h6>
    <a href="category_create.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> New Category</a>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered table-hover">
        <thead class="thead-light">
          <tr><th>Name</th><th>Description</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($items)): ?>
            <tr><td colspan="4" class="text-center text-muted">No categories found</td></tr>
          <?php else: ?>
            <?php foreach ($items as $c): ?>
              <tr>
                <td><?= htmlspecialchars($c['name']) ?></td>
                <td><?= htmlspecialchars($c['description'] ?? '-') ?></td>
                <td><span class="badge badge-<?= $c['status'] ? 'success' : 'secondary' ?>"><?= $c['status'] ? 'Active' : 'Inactive' ?></span></td>
                <td>
                  <a href="category_edit.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>
                  <a href="categories.php?toggle=<?= $c['id'] ?>" class="btn btn-sm btn-<?= $c['status'] ? 'secondary' : 'success' ?>"><i class="fas fa-<?= $c['status'] ? 'times' : 'check' ?>"></i></a>
                  <a href="categories.php?delete=<?= $c['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
