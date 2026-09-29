<?php
session_start();
$page_title = 'Edit Category';
$base_url = '../../';
require_once '../../includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$item = getById('categories', $id);
if (!$item) redirect('categories.php', 'Category not found', 'error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    update('categories', [
        'name' => $_POST['name'],
        'description' => $_POST['description'] ?? '',
        'status' => isset($_POST['status']) ? 1 : 0,
        'updated_at' => date('Y-m-d'),
    ], $id);
    redirect('categories.php', 'Category updated');
}

require_once '../../includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-6">
    <div class="card shadow mb-4">
      <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-edit"></i> Edit Category</h6></div>
      <div class="card-body">
        <form method="post">
          <div class="form-group">
            <label class="form-label">Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($item['name']) ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($item['description']) ?></textarea>
          </div>
          <div class="form-group">
            <div class="custom-control custom-switch">
              <input type="checkbox" class="custom-control-input" id="statusSwitch" name="status" <?= $item['status'] ? 'checked' : '' ?>>
              <label class="custom-control-label" for="statusSwitch">Active</label>
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-block py-2"><i class="fas fa-save"></i> Update</button>
          <a href="categories.php" class="btn btn-secondary btn-block">Cancel</a>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
