<?php
session_start();
$page_title = 'IMEI Search';
$base_url = '../../';
require_once '../../includes/functions.php';

$search_imei = trim($_GET['imei'] ?? '');
$results = [];
$searched = false;

if ($search_imei !== '') {
    $searched = true;
    $like = '%' . $search_imei . '%';
    $stmt = $pdo->prepare("
        SELECT s.*, p.name AS product_name, p.code AS product_code, p.color, p.storage, p.ram,
               p.product_condition, p.product_type,
               pu.purchase_date, pu.invoice_no AS purchase_invoice,
               pu.person_name AS p_person_name, pu.person_phone AS p_person_phone, pu.person_cnic AS p_person_cnic,
               sup.name AS supplier_name, sup.contact_person AS supplier_contact
        FROM product_serials s
        JOIN products p ON p.id = s.product_id
        LEFT JOIN purchases pu ON pu.id = s.purchase_id
        LEFT JOIN suppliers sup ON sup.id = pu.supplier_id
        WHERE s.imei_number LIKE ? OR s.serial_number LIKE ?
        ORDER BY (s.imei_number = ? OR s.serial_number = ?) DESC, s.id DESC
    ");
    $stmt->execute([$like, $like, $search_imei, $search_imei]);
    $results = $stmt->fetchAll();

    // Fetch sale details for sold units
    foreach ($results as &$r) {
        $r['sale'] = null;
        if ($r['status'] === 'sold' && $r['sale_id']) {
            $st = $pdo->prepare("
                SELECT sa.invoice_no, sa.sale_date, sa.total_amount, sa.paid_amount, sa.due_amount, sa.payment_status,
                       c.full_name AS customer_name, c.phone AS customer_phone, c.cnic AS customer_cnic,
                       si.price AS sale_price
                FROM sales sa
                LEFT JOIN customers c ON c.id = sa.customer_id
                LEFT JOIN sale_items si ON si.sale_id = sa.id AND (si.serial_id = ? OR (si.serial_id = 0 AND si.product_id = ?))
                WHERE sa.id = ?
                LIMIT 1
            ");
            $st->execute([$r['id'], $r['product_id'], $r['sale_id']]);
            $r['sale'] = $st->fetch() ?: null;
        }
    }
    unset($r);
}

// Fetch in-stock mobile units for quick stickers batch printing
$stock_units = $pdo->query("
    SELECT ps.*, p.name AS product_name, p.code AS product_code, p.sale_price
    FROM product_serials ps
    JOIN products p ON p.id = ps.product_id
    WHERE ps.status = 'available'
    ORDER BY p.name ASC, ps.id DESC
")->fetchAll();

require_once '../../includes/header.php';
?>

<style>
  .imei-detail-label { font-size: .78rem; text-transform: uppercase; letter-spacing: .4px; color: #858796; font-weight: 700; margin-bottom: 2px; }
  .imei-detail-value { font-size: 1rem; color: #0f172a; font-weight: 600; word-break: break-word; }
  .section-title { font-size: .9rem; font-weight: 800; color: #4e73df; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 12px; }
  .big-imei { font-family: monospace; font-size: 1.35rem; font-weight: 800; letter-spacing: 1px; color: #0f172a; }
</style>

<div class="card shadow mb-4 border-left-primary">
  <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-search"></i> Search Mobile by IMEI & Print Barcodes</h6>
    <div>
      <a href="print_stickers.php?all=1" target="_blank" class="btn btn-primary btn-sm font-weight-bold shadow-sm">
        <i class="fas fa-print mr-1"></i> Print All In-Stock Stickers
      </a>
    </div>
  </div>
  <div class="card-body">
    <form method="get" autocomplete="off">
      <div class="row">
        <div class="col-md-9">
          <input type="text" name="imei" class="form-control form-control-lg imei-input" placeholder="Enter IMEI Number (IMEI 1 or IMEI 2)..." value="<?= htmlspecialchars($search_imei) ?>" maxlength="15" oninput="this.value=this.value.replace(/\D/g,'')" autofocus>
        </div>
        <div class="col-md-3">
          <button type="submit" class="btn btn-primary btn-lg btn-block"><i class="fas fa-search"></i> Search</button>
        </div>
      </div>
      <?php if ($searched): ?>
        <a href="imei_search.php" class="btn btn-secondary btn-sm mt-2"><i class="fas fa-redo"></i> Reset</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($searched): ?>
  <?php if (empty($results)): ?>
    <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> No record found for this IMEI. Please check the IMEI number and try again.</div>
  <?php else: ?>
    <?php foreach ($results as $r): ?>
      <?php
        $is_sold = $r['status'] === 'sold';
        $supplier_display = '-';
        if (!empty($r['supplier_name'])) {
            $supplier_display = trim(($r['supplier_contact'] ? $r['supplier_contact'] . ' (' : '') . $r['supplier_name'] . ($r['supplier_contact'] ? ')' : ''));
        } elseif (!empty($r['p_person_name'])) {
            $supplier_display = $r['p_person_name'];
        }
      ?>
      <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap">
          <h6 class="m-0 font-weight-bold text-primary">
            <i class="fas fa-mobile-alt"></i> <?= htmlspecialchars($r['product_name']) ?>
            <small class="text-muted">(<?= htmlspecialchars($r['product_code']) ?>)</small>
          </h6>
          <span class="badge badge-<?= $is_sold ? 'danger' : 'success' ?> p-2">
            <?= $is_sold ? '<i class="fas fa-check-circle"></i> SOLD' : '<i class="fas fa-warehouse"></i> IN STOCK' ?>
          </span>
        </div>
        <div class="card-body">

          <!-- Unit / Product Info -->
          <div class="section-title"><i class="fas fa-info-circle"></i> Mobile Details</div>
          <div class="row mb-3">
            <div class="col-md-6 mb-3">
              <div class="p-3 bg-white rounded border shadow-sm h-100">
                <div class="imei-detail-label font-weight-bold text-primary mb-1"><i class="fas fa-barcode"></i> IMEI No. 1</div>
                <div class="big-imei text-dark"><?= htmlspecialchars($r['imei_number'] ?? '-') ?></div>
                <?php if (!empty($r['imei_number']) && $r['imei_number'] !== '-'): ?>
                  <div class="mt-2 p-2 bg-light rounded border text-center d-inline-block">
                    <svg class="imei-barcode" data-barcode="<?=htmlspecialchars(trim($r['imei_number']))?>" data-width="1.2" data-height="36" data-font-size="10"></svg>
                  </div>
                  <div class="mt-2">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.open('print_stickers.php?imei=' + encodeURIComponent('<?=htmlspecialchars(trim($r['imei_number']))?>'), '_blank', 'width=750,height=550')"><i class="fas fa-print"></i> Print Barcode Sticker</button>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <div class="col-md-6 mb-3">
              <div class="p-3 bg-white rounded border shadow-sm h-100">
                <div class="imei-detail-label font-weight-bold text-primary mb-1"><i class="fas fa-barcode"></i> IMEI No. 2</div>
                <div class="big-imei text-dark"><?= htmlspecialchars($r['serial_number'] ?? '-') ?></div>
                <?php if (!empty($r['serial_number']) && $r['serial_number'] !== '-'): ?>
                  <div class="mt-2 p-2 bg-light rounded border text-center d-inline-block">
                    <svg class="imei-barcode" data-barcode="<?=htmlspecialchars(trim($r['serial_number']))?>" data-width="1.2" data-height="36" data-font-size="10"></svg>
                  </div>
                  <div class="mt-2">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="printImeiBarcodeSticker('<?=htmlspecialchars(trim($r['serial_number']))?>', '<?=htmlspecialchars(addslashes($r['product_name'] ?? ''))?>', 'IMEI 2')"><i class="fas fa-print"></i> Print Barcode Sticker</button>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="row mb-3">
            <div class="col-md-4 mb-2">
              <div class="imei-detail-label">Color</div>
              <div class="imei-detail-value font-weight-bold"><?= htmlspecialchars($r['color'] ?: '-') ?></div>
            </div>
            <div class="col-md-4 mb-2">
              <div class="imei-detail-label">Storage / RAM</div>
              <div class="imei-detail-value font-weight-bold"><?= htmlspecialchars(trim(($r['storage'] ?: '-') . ' / ' . ($r['ram'] ?: '-'))) ?></div>
            </div>
            <div class="col-md-4 mb-2">
              <div class="imei-detail-label">Condition</div>
              <div class="imei-detail-value font-weight-bold"><?= htmlspecialchars($r['product_condition'] ?: '-') ?></div>
            </div>
          </div>

          <hr>

          <!-- Purchase Info -->
          <div class="section-title"><i class="fas fa-truck"></i> Purchase Details</div>
          <div class="row mb-3">
            <div class="col-md-3 mb-2">
              <div class="imei-detail-label">Purchase Date</div>
              <div class="imei-detail-value"><?= formatDate($r['purchase_date'] ?? null) ?></div>
            </div>
            <div class="col-md-3 mb-2">
              <div class="imei-detail-label">Supplier / Person</div>
              <div class="imei-detail-value"><?= htmlspecialchars($supplier_display) ?></div>
            </div>
            <div class="col-md-2 mb-2">
              <div class="imei-detail-label">Phone</div>
              <div class="imei-detail-value"><?= htmlspecialchars($r['p_person_phone'] ?: '-') ?></div>
            </div>
            <div class="col-md-2 mb-2">
              <div class="imei-detail-label">Invoice No.</div>
              <div class="imei-detail-value"><?= htmlspecialchars($r['purchase_invoice'] ?: '-') ?></div>
            </div>
            <div class="col-md-2 mb-2">
              <div class="imei-detail-label">Purchase Price</div>
              <div class="imei-detail-value text-success">Rs. <?= formatCurrency($r['purchase_price']) ?></div>
            </div>
          </div>

          <?php if ($is_sold && $r['sale']): ?>
            <hr>

            <!-- Sale Info -->
            <div class="section-title" style="color:#e74a3b;"><i class="fas fa-shopping-cart"></i> Sale Details</div>
            <div class="row">
              <div class="col-md-3 mb-2">
                <div class="imei-detail-label">Sale Date</div>
                <div class="imei-detail-value"><?= formatDate($r['sale']['sale_date'] ?? null) ?></div>
              </div>
              <div class="col-md-3 mb-2">
                <div class="imei-detail-label">Customer</div>
                <div class="imei-detail-value"><?= htmlspecialchars($r['sale']['customer_name'] ?: 'Walk-in Customer') ?></div>
              </div>
              <div class="col-md-2 mb-2">
                <div class="imei-detail-label">Phone</div>
                <div class="imei-detail-value"><?= htmlspecialchars($r['sale']['customer_phone'] ?: '-') ?></div>
              </div>
              <div class="col-md-2 mb-2">
                <div class="imei-detail-label">Sale Price</div>
                <div class="imei-detail-value text-danger">Rs. <?= formatCurrency($r['sale']['sale_price'] ?? $r['sale']['total_amount']) ?></div>
              </div>
              <div class="col-md-2 mb-2">
                <div class="imei-detail-label">Profit</div>
                <div class="imei-detail-value <?= ((float)($r['sale']['sale_price'] ?? 0) - (float)$r['purchase_price']) >= 0 ? 'text-success' : 'text-danger' ?>">
                  Rs. <?= formatCurrency((float)($r['sale']['sale_price'] ?? 0) - (float)$r['purchase_price']) ?>
                </div>
              </div>
              <div class="col-md-3 mb-2">
                <div class="imei-detail-label">Sale Invoice No.</div>
                <div class="imei-detail-value"><?= htmlspecialchars($r['sale']['invoice_no'] ?: '-') ?></div>
              </div>
              <div class="col-md-3 mb-2">
                <div class="imei-detail-label">Payment Status</div>
                <div class="imei-detail-value">
                  <span class="badge badge-<?= $r['sale']['payment_status'] === 'paid' ? 'success' : ($r['sale']['payment_status'] === 'partial' ? 'warning' : 'danger') ?>">
                    <?= ucfirst($r['sale']['payment_status'] ?? '-') ?>
                  </span>
                </div>
              </div>
            </div>
          <?php endif; ?>

        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php else: ?>
  <div class="alert alert-info"><i class="fas fa-fingerprint"></i> Enter an IMEI number above to view the complete history of a mobile — purchase details (date, supplier, price) and sale details (customer, rate, date) will be shown here.</div>
<?php endif; ?>

<!-- In-Stock Mobiles Batch Sticker Print Section -->
<div class="card shadow mb-4">
  <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap">
    <h6 class="m-0 font-weight-bold text-primary">
      <i class="fas fa-boxes mr-1"></i> Available Mobiles in Stock (<?= count($stock_units) ?>) — Print Stickers
    </h6>
    <div>
      <button type="button" class="btn btn-sm btn-success font-weight-bold" id="btnPrintSelectedStickers">
        <i class="fas fa-print mr-1"></i> Print Selected Stickers
      </button>
      <a href="print_stickers.php?all=1" target="_blank" class="btn btn-sm btn-outline-primary font-weight-bold ml-1">
        <i class="fas fa-print mr-1"></i> Print All (<?= count($stock_units) ?>)
      </a>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-bordered mb-0">
        <thead class="thead-light">
          <tr>
            <th width="40" class="text-center">
              <input type="checkbox" id="selectAllStickers" checked>
            </th>
            <th>Product / Model</th>
            <th>IMEI 1 & Barcode</th>
            <th>IMEI 2</th>
            <th>Details</th>
            <th width="120" class="text-center">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($stock_units)): ?>
            <tr><td colspan="6" class="text-center py-4 text-muted">No available mobile units found in stock.</td></tr>
          <?php else: ?>
            <?php foreach ($stock_units as $su): ?>
              <tr>
                <td class="text-center align-middle">
                  <input type="checkbox" class="sticker-select-chk" value="<?= $su['id'] ?>" checked>
                </td>
                <td class="align-middle font-weight-bold">
                  <?= htmlspecialchars($su['product_name']) ?>
                  <small class="d-block text-muted"><?= htmlspecialchars($su['product_code']) ?></small>
                </td>
                <td class="align-middle">
                  <span class="font-weight-bold font-monospace"><?= htmlspecialchars($su['imei_number'] ?? '-') ?></span>
                  <?php if (!empty($su['imei_number']) && $su['imei_number'] !== '-'): ?>
                    <div class="mt-1">
                      <svg class="imei-barcode" data-barcode="<?= htmlspecialchars(trim($su['imei_number'])) ?>" data-width="0.9" data-height="22" data-font-size="8"></svg>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="align-middle font-monospace small">
                  <?= htmlspecialchars($su['serial_number'] ?? '-') ?>
                </td>
                <td class="align-middle small text-muted">
                  <?= htmlspecialchars($su['notes'] ?: '-') ?>
                </td>
                <td class="text-center align-middle">
                  <a href="print_stickers.php?imei=<?= urlencode($su['imei_number']) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-1 px-2" title="Print Sticker for this mobile">
                    <i class="fas fa-barcode mr-1"></i> Sticker
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
$(document).ready(function() {
  $('#selectAllStickers').on('change', function() {
    $('.sticker-select-chk').prop('checked', $(this).is(':checked'));
  });

  $('#btnPrintSelectedStickers').on('click', function() {
    var selected = [];
    $('.sticker-select-chk:checked').each(function() {
      selected.push($(this).val());
    });
    if (selected.length === 0) {
      alert('Please select at least one mobile unit to print stickers.');
      return;
    }
    var url = 'print_stickers.php?ids=' + selected.join(',');
    window.open(url, '_blank', 'width=800,height=600');
  });
});
</script>
<?php require_once '../../includes/footer.php'; ?>
