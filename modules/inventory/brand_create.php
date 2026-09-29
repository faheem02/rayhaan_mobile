<?php
session_start();
$page_title = 'New Brand';
$base_url = '../../';
require_once '../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    insert('brands', ['name'=>$_POST['name'],'description'=>$_POST['description']??'','status'=>isset($_POST['status'])?1:0,'created_at'=>date('Y-m-d'),'updated_at'=>date('Y-m-d')]);
    redirect('brands.php', 'Brand created');
}

require_once '../../includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-6">
    <div class="card shadow mb-4">
      <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-copyright"></i> New Brand</h6></div>
      <div class="card-body">
        <form method="post">
          <div class="form-group"><label class="form-label">Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
          <div class="form-group"><div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="s" name="status" checked><label class="custom-control-label" for="s">Active</label></div></div>
          <button type="submit" class="btn btn-primary btn-block py-2"><i class="fas fa-save"></i> Create</button>
          <a href="brands.php" class="btn btn-secondary btn-block">Cancel</a>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
