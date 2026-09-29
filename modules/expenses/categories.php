<?php
session_start();
$page_title = 'Expense Categories';
$base_url = '../../';
require_once '../../includes/functions.php';

// Handle add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    insert('expense_categories', [
        'name' => $_POST['name'],
        'description' => $_POST['description'] ?? '',
        'status' => isset($_POST['status']) ? 1 : 0,
        'created_at' => date('Y-m-d'),
    ]);
    $_SESSION['success'] = 'Category added successfully';
    header("Location: categories.php");
    exit;
}

// Handle edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
    update('expense_categories', [
        'name' => $_POST['name'],
        'description' => $_POST['description'] ?? '',
        'status' => isset($_POST['status']) ? 1 : 0,
        'updated_at' => date('Y-m-d'),
    ], (int)$_POST['id']);
    $_SESSION['success'] = 'Category updated successfully';
    header("Location: categories.php");
    exit;
}

// Handle toggle status
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $cat = getById('expense_categories', $id);
    if ($cat) {
        update('expense_categories', [
            'status' => $cat['status'] ? 0 : 1,
            'updated_at' => date('Y-m-d'),
        ], $id);
        $_SESSION['success'] = 'Category status updated';
    }
    header("Location: categories.php");
    exit;
}

$categories = getAll('expense_categories', 'name ASC');

require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="m-0 font-weight-bold" style="color:#0f172a;"><i class="fas fa-tags"></i> Expense Categories</h5>
  <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addCatModal"><i class="fas fa-plus"></i> Add Category</button>
</div>

<div class="card shadow mb-4">
  <div class="card-header py-3">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-list"></i> Categories</h6>
  </div>
  <div class="card-body">
    <?php if (empty($categories)): ?>
      <div class="text-center text-muted py-3">
        <i class="fas fa-tags fa-3x mb-3"></i>
        <p>No categories yet. Add one to get started.</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm">
          <thead class="thead-light">
            <tr>
              <th>Name</th>
              <th>Description</th>
              <th>Status</th>
              <th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($categories as $c): ?>
              <tr>
                <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                <td><?= htmlspecialchars($c['description'] ?: '-') ?></td>
                <td>
                  <span class="badge badge-<?= $c['status'] ? 'success' : 'secondary' ?> status-badge">
                    <?= $c['status'] ? 'Active' : 'Inactive' ?>
                  </span>
                </td>
                <td class="text-center">
                  <button class="btn btn-sm btn-info" data-toggle="modal" data-target="#editCatModal<?= $c['id'] ?>" title="Edit"><i class="fas fa-pen"></i></button>
                  <a href="categories.php?toggle=<?= $c['id'] ?>" class="btn btn-sm btn-warning" title="<?= $c['status'] ? 'Deactivate' : 'Activate' ?>"><i class="fas fa-<?= $c['status'] ? 'ban' : 'check' ?>"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCatModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h6 class="modal-title"><i class="fas fa-plus-circle text-primary"></i> Add Category</h6>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="small">Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" placeholder="e.g. Utilities, Rent, Salaries" required>
          </div>
          <div class="form-group">
            <label class="small">Description</label>
            <textarea name="description" class="form-control" rows="2" placeholder="Optional description"></textarea>
          </div>
          <div class="form-group">
            <div class="custom-control custom-switch">
              <input type="checkbox" class="custom-control-input" id="addStatus" name="status" checked>
              <label class="custom-control-label" for="addStatus">Active</label>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
          <button type="submit" name="add_category" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Add</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Category Modals -->
<?php foreach ($categories as $c): ?>
<div class="modal fade" id="editCatModal<?= $c['id'] ?>" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h6 class="modal-title"><i class="fas fa-pen text-info"></i> Edit Category</h6>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" value="<?= $c['id'] ?>">
          <div class="form-group">
            <label class="small">Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($c['name']) ?>" required>
          </div>
          <div class="form-group">
            <label class="small">Description</label>
            <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($c['description'] ?? '') ?></textarea>
          </div>
          <div class="form-group">
            <div class="custom-control custom-switch">
              <input type="checkbox" class="custom-control-input" id="editStatus<?= $c['id'] ?>" name="status" <?= $c['status'] ? 'checked' : '' ?>>
              <label class="custom-control-label" for="editStatus<?= $c['id'] ?>">Active</label>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
          <button type="submit" name="edit_category" class="btn btn-info btn-sm"><i class="fas fa-save"></i> Update</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php require_once '../../includes/footer.php'; ?>
