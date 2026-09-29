<?php
ini_set('display_errors', 1); error_reporting(E_ALL);
session_start();
$page_title = 'New Sale';
$base_url = '../../';
require_once '../../includes/functions.php';

$customers = getAll('customers', 'full_name ASC');
$all_products = $pdo->query("
    SELECT p.id, p.code, p.name, p.sale_price, p.stock_quantity, p.product_type
    FROM products p
    WHERE p.status = 1
      AND (p.stock_quantity > 0
           OR (p.product_type = 'mobile'
               AND EXISTS (SELECT 1 FROM product_serials s WHERE s.product_id = p.id AND s.status = 'available')))
    ORDER BY p.name
")->fetchAll();
$bank_accounts = getAll('bank_accounts', 'bank_name ASC, account_name ASC');

$mobile_serials = $pdo->query("SELECT s.id, s.product_id, s.imei_number, s.serial_number AS imei2, s.notes FROM product_serials s JOIN products p ON p.id = s.product_id WHERE p.product_type = 'mobile' AND s.status = 'available' ORDER BY s.product_id, s.imei_number")->fetchAll();
$serials_by_product = [];
foreach ($mobile_serials as $ms) {
    $serials_by_product[$ms['product_id']][] = $ms;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ---------- Validate branch_id (fix FK error) ----------
    $branch_id = $_SESSION['branch_id'] ?? null;
    if ($branch_id !== null) {
        $check = $pdo->prepare("SELECT id FROM branches WHERE id = ?");
        $check->execute([$branch_id]);
        if (!$check->fetch()) {
            $branch_id = null;   // fallback to NULL – allowed because FK is ON DELETE SET NULL
        }
    }
    // -------------------------------------------------------

    $customer_id_raw = $_POST['customer_id'] ?? '';
    $sale_date = $_POST['sale_date'] ?? date('Y-m-d');

    if ($customer_id_raw === 'walkin') {
        $walk_name = trim($_POST['walkin_name'] ?? '');
        $walk_phone = trim($_POST['walkin_phone'] ?? '');
        $walk_cnic = trim($_POST['walkin_cnic'] ?? '') ?: null;
        $walk_address = trim($_POST['walkin_address'] ?? '');
        if (!$walk_name) {
            $_SESSION['error'] = 'Enter the walk-in customer name.';
            header("Location: index.php"); exit;
        }
        if ($walk_cnic) {
            $chk = $pdo->prepare("SELECT id, full_name FROM customers WHERE cnic = ?");
            $chk->execute([$walk_cnic]);
            if ($dup = $chk->fetch()) {
                $_SESSION['error'] = "This CNIC already belongs to customer: {$dup['full_name']}";
                header("Location: index.php"); exit;
            }
        }
        $customer_id = insert('customers', [
            'customer_no' => generateCustomerNo(),
            'full_name'   => $walk_name,
            'phone'       => $walk_phone,
            'cnic'        => $walk_cnic,
            'address'     => $walk_address ?: null,
            'opening_due' => 0,
            'opening_paid' => 0,
            'branch_id'   => $branch_id,          // <-- validated
            'created_by'  => $_SESSION['user_id'] ?? null,
            'created_at'  => date('Y-m-d'),
            'updated_at'  => date('Y-m-d'),
        ]);
    } else {
        $customer_id = (int)$customer_id_raw;
    }

    $descriptions = $_POST['item_description'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['price'] ?? [];
    $received_amount = (float)($_POST['received_amount'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $bank_account_id = (int)($_POST['bank_account_id'] ?? 0);

    if (empty($customer_id) || empty($descriptions)) {
        $_SESSION['error'] = 'Please select a customer and add at least one item.';
        header("Location: index.php"); exit;
    }

    $product_ids = $_POST['product_ids'] ?? [];
    $serial_ids = $_POST['serial_ids'] ?? [];
    $total_amount = 0;
    $items = [];
    foreach ($descriptions as $i => $desc) {
        $desc = trim($desc);
        if ($desc === '') continue;
        $qty = max(1, (int)($quantities[$i] ?? 1));
        $price = (float)($prices[$i] ?? 0);
        $line_total = $qty * $price;
        $total_amount += $line_total;
        $pid = !empty($product_ids[$i]) ? (int)$product_ids[$i] : null;
        $sid = !empty($serial_ids[$i]) ? (int)$serial_ids[$i] : 0;
        if (!$pid) {
            $resolved = resolveProductFromDescription($pdo, $desc);
            if ($resolved) $pid = $resolved;
        }
        $items[] = ['description' => $desc, 'quantity' => $qty, 'price' => $price, 'subtotal' => $line_total, 'product_id' => $pid, 'serial_id' => $sid];
    }

    if (empty($items)) {
        $_SESSION['error'] = 'Please add at least one valid item.';
        header("Location: index.php"); exit;
    }

    $received_amount = min($received_amount, $total_amount);
    $due_amount = $total_amount - $received_amount;
    $payment_status = $due_amount <= 0 ? 'paid' : ($received_amount > 0 ? 'partial' : 'pending');

    $invoice_no = generateInvoiceNo();

    $sale_id = insert('sales', [
        'invoice_no' => $invoice_no,
        'customer_id' => $customer_id,
        'sale_date' => $sale_date,
        'subtotal' => $total_amount,
        'discount_amount' => 0,
        'total_amount' => $total_amount,
        'paid_amount' => $received_amount,
        'due_amount' => $due_amount,
        'payment_method' => $received_amount > 0 ? $payment_method : null,
        'payment_status' => $payment_status,
        'status' => 'active',
        'notes' => $notes ?: null,
        'branch_id' => $branch_id,          // <-- validated
        'created_by' => $_SESSION['user_id'] ?? null,
        'created_at' => date('Y-m-d'),
        'updated_at' => date('Y-m-d'),
    ]);

    foreach ($items as $item) {
        insert('sale_items', [
            'sale_id' => $sale_id,
            'product_id' => $item['product_id'],
            'item_description' => $item['description'],
            'quantity' => $item['quantity'],
            'price' => $item['price'],
            'subtotal' => $item['subtotal'],
        ]);
        if ($item['product_id']) {
            $pdo->prepare("UPDATE products SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id = ?")->execute([$item['quantity'], $item['product_id']]);
        }
        if ($item['serial_id']) {
            $pdo->prepare("UPDATE product_serials SET status = 'sold', sale_id = ?, updated_at = CURDATE() WHERE id = ? AND status = 'available'")->execute([$sale_id, $item['serial_id']]);
        }
    }

    if ($received_amount > 0) {
        $pay_id = insert('payments', [
            'sale_id' => $sale_id,
            'payment_date' => $sale_date,
            'amount' => $received_amount,
            'payment_type' => 'sale',
            'payment_method' => $payment_method,
            'notes' => 'Payment for ' . $invoice_no,
            'branch_id' => $branch_id,          // <-- validated
            'received_by' => $_SESSION['user_id'] ?? null,
            'created_at' => date('Y-m-d'),
        ]);
        if ($payment_method === 'cash') {
            recordCashInflow($pdo, $sale_date, $received_amount, 'Sale - ' . $invoice_no, 'payment', $pay_id, $_SESSION['user_id'] ?? null);
        } elseif ($payment_method === 'bank') {
            recordBankInflow($pdo, $sale_date, $received_amount, 'Sale (bank) - ' . $invoice_no, 'payment', $pay_id, $_SESSION['user_id'] ?? null, $bank_account_id);
        }
    }

    $msg = "Sale created successfully. Invoice #$invoice_no";
    if ($due_amount > 0) $msg .= " (Balance due: " . formatCurrency($due_amount) . ")";
    $_SESSION['success'] = $msg;
    header("Location: invoices.php");
    exit;
}

require_once '../../includes/header.php';
?>

<style>
#wrapper { padding-left: 0 !important; }
.sidebar { display: none !important; }
#content-wrapper { margin-left: 0 !important; width: 100% !important; }

.cart-table th {
    font-size: .72rem; text-transform: uppercase; white-space: nowrap;
    letter-spacing: .3px; background: #f8f9fc; color: #4e73df;
}
.cart-table td { vertical-align: middle; font-size: .85rem; }
.cart-table .item-row:hover { background: #f8f9fc; }

.summary-card {
    background: linear-gradient(135deg, #f8f9fc, #eef0f7);
    border-radius: 8px; padding: 15px;
}
.finance-value { font-size: 1.2rem; font-weight: 700; color: #0f172a; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="m-0 font-weight-bold" style="color:#0f172a;"><i class="fas fa-shopping-cart"></i> New Sale</h5>
  <a href="invoices.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left"></i> Invoices</a>
</div>

<form method="post" id="saleForm" autocomplete="off">
<div class="row">

  <!-- LEFT COLUMN -->
  <div class="col-lg-5 mb-3">

    <!-- Customer & Date -->
    <div class="card shadow mb-3">
      <div class="card-body py-3">
        <div class="form-group mb-2">
          <label class="small text-muted">Customer <span class="text-danger">*</span></label>
          <select name="customer_id" id="customerSelect" class="form-control" required onchange="toggleWalkin(this.value)">
            <option value="">Select Customer</option>
            <option value="walkin">+ Walk-in Customer (New)</option>
            <?php foreach ($customers as $c): ?>
              <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['full_name']) ?> (<?= htmlspecialchars($c['phone']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <div id="walkinFields" style="display:none;" class="mt-2">
            <input type="text" name="walkin_name" class="form-control mb-2" placeholder="Customer Name *">
            <div class="row">
              <div class="col-6"><input type="text" name="walkin_phone" class="form-control mb-2" placeholder="Mobile Number"></div>
              <div class="col-6"><input type="text" name="walkin_cnic" class="form-control mb-2" placeholder="CNIC"></div>
            </div>
            <input type="text" name="walkin_address" class="form-control" placeholder="Address">
          </div>
        </div>
        <div class="row">
          <div class="col-6"><div class="form-group mb-0"><label class="small text-muted">Date</label><input type="text" name="sale_date" class="form-control datepicker" value="<?= date('Y-m-d') ?>" autocomplete="off"></div></div>
          <div class="col-6"><div class="form-group mb-0"><label class="small text-muted">Notes</label><input type="text" name="notes" class="form-control" placeholder="Optional"></div></div>
        </div>
      </div>
    </div>

    <!-- Add Item -->
    <div class="card shadow mb-3 border-left-primary">
      <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap">
        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-barcode"></i> Quick Barcode Scan / Add Item</h6>
        <div class="custom-control custom-checkbox small">
          <input type="checkbox" class="custom-control-input" id="autoAddToCartCheck" checked>
          <label class="custom-control-label text-dark font-weight-bold" for="autoAddToCartCheck" title="Automatically add item to cart on barcode scan">Auto-add to cart on scan</label>
        </div>
      </div>
      <div class="card-body py-3">
        <!-- Dedicated Barcode Scanner Machine Input Box -->
        <div class="form-group mb-3 p-3 rounded shadow-sm" style="background:#f0f7ff; border: 2px dashed #3b82f6;">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="font-weight-bold text-primary mb-0" style="font-size:0.95rem;">
              <i class="fas fa-qrcode mr-1"></i> Barcode Scanner Input (Scan IMEI)
            </label>
            <span class="badge badge-primary px-2 py-1"><i class="fas fa-bolt"></i> Auto-Fill Ready</span>
          </div>
          <small class="text-muted d-block mb-2">Scan mobile sticker barcode or enter IMEI — fields will auto-fill:</small>
          <div class="input-group">
            <div class="input-group-prepend">
              <span class="input-group-text bg-primary text-white font-weight-bold border-0"><i class="fas fa-barcode fa-lg"></i></span>
            </div>
            <input type="text" id="barcodeScannerInput" class="form-control form-control-lg border-primary" style="font-weight:700; font-size:1.15rem; letter-spacing:1px; background:#fff;" placeholder="Scan IMEI barcode sticker here..." autocomplete="off" autofocus>
            <div class="input-group-append">
              <button type="button" class="btn btn-primary px-3 font-weight-bold" id="btnScanLookup"><i class="fas fa-search mr-1"></i> Scan / Find</button>
            </div>
          </div>
          <div id="scanStatusMsg" class="mt-2" style="display:none;"></div>
        </div>

        <div class="form-group mb-2">
          <label class="small text-muted">Description <span class="text-danger">*</span></label>
          <input type="text" id="entryDesc" class="form-control form-control-lg" list="productList" placeholder="Type product name, code, or scan IMEI barcode..." autofocus>
          <input type="hidden" id="entryProductId" value="0">
          <datalist id="productList">
            <option value="">-- Select Product --</option>
            <?php foreach ($all_products as $p): ?>
            <option value="<?= htmlspecialchars($p['name'].' ('.$p['code'].')') ?>" data-price="<?= $p['sale_price'] ?>" data-product-id="<?= $p['id'] ?>" data-stock="<?= (int)$p['stock_quantity'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-mobile="<?= ($p['product_type'] ?? 'general') === 'mobile' ? 1 : 0 ?>"><?= htmlspecialchars($p['name'].' - '.$p['code'])?></option>
            <?php endforeach; ?>
          </datalist>
          <small class="text-muted" id="stockInfo" style="display:none;"></small>
        </div>
        <div class="form-group mb-2" id="serialGroup" style="display:none;">
          <label class="small text-muted">Mobile (IMEI) <span class="text-danger">*</span></label>
          <select id="entrySerial" class="form-control">
            <option value="">Select IMEI / Unit</option>
          </select>
          <small class="text-muted" id="serialInfo"></small>
          <div id="serialBarcodeWrap" style="display:none;" class="mt-2 text-center p-2 bg-light border rounded">
            <div class="small font-weight-bold text-muted mb-1"><i class="fas fa-barcode"></i> Unit Barcode</div>
            <svg id="selectedImeiBarcode" class="imei-barcode"></svg>
            <div class="mt-1">
              <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2" style="font-size:11px;" id="btnSerialSticker"><i class="fas fa-print"></i> Print Barcode Sticker</button>
            </div>
          </div>
        </div>
        <div class="row">
          <div class="col-6"><div class="form-group mb-2"><label class="small text-muted">Qty</label><input type="number" id="entryQty" class="form-control form-control-lg" value="1" min="1"></div></div>
          <div class="col-6"><div class="form-group mb-2"><label class="small text-muted">Unit Price</label><input type="number" id="entryPrice" class="form-control form-control-lg" step="0.01" min="0" placeholder="Enter price"></div></div>
        </div>
        <div id="entryPreview" class="summary-card mt-2" style="display:none;">
          <div class="d-flex justify-content-between"><span class="text-muted">Line Amount</span><span class="font-weight-bold" id="entryAmountPreview">0.00</span></div>
        </div>
      </div>
    </div>

    <button type="button" id="addToCartBtn" class="btn btn-primary btn-block btn-sm"><i class="fas fa-cart-plus"></i> Add to Cart</button>
  </div>

  <!-- RIGHT COLUMN -->
  <div class="col-lg-7 mb-3">
    <div class="card shadow h-100">
      <div class="card-header py-2"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-list"></i> Cart</h6></div>
      <div class="card-body p-0">

        <!-- Cart Table -->
        <div class="table-responsive" style="max-height:200px; overflow-y:auto;">
          <table class="table table-sm table-bordered cart-table mb-0" id="cartTable">
            <thead class="sticky-top">
              <tr>
                <th>Description</th>
                <th style="width:50px;" class="text-center">Qty</th>
                <th style="width:90px;" class="text-right">Price</th>
                <th style="width:90px;" class="text-right">Amount</th>
                <th style="width:28px;"></th>
              </tr>
            </thead>
            <tbody id="cartBody"></tbody>
          </table>
        </div>
        <div class="text-center text-muted small py-3" id="emptyCart"><i class="fas fa-box-open fa-2x d-block mb-2"></i>Cart is empty</div>

        <!-- Payment Summary -->
        <div class="px-3 pb-3" id="summaryArea" style="display:none;">
          <hr class="my-2">

          <div class="row">
            <div class="col-6">
              <div class="form-group mb-2">
                <label class="small text-muted">Total Amount</label>
                <div class="finance-value" id="totalDisplay">0.00</div>
              </div>
            </div>
            <div class="col-6">
              <div class="form-group mb-2">
                <label class="small text-muted">Amount Received</label>
                <input type="number" name="received_amount" id="receivedAmount" class="form-control form-control-lg font-weight-bold" step="0.01" min="0" placeholder="0.00 = credit sale" autocomplete="off">
              </div>
            </div>
            <div class="col-6">
              <div class="form-group mb-2">
                <label class="small text-muted">Payment Method</label>
                <select name="payment_method" id="paymentMethod" class="form-control">
                  <option value="cash">Cash</option>
                  <option value="bank">Bank</option>
                </select>
              </div>
            </div>
          </div>
          <div class="row" id="bankAccountRow" style="display:none;">
            <div class="col-12">
              <div class="form-group mb-2">
                <label class="small text-muted">Bank Account</label>
                <select name="bank_account_id" class="form-control">
                  <option value="">Select Account</option>
                  <?php foreach ($bank_accounts as $ba): ?>
                    <option value="<?= $ba['id'] ?>"><?= htmlspecialchars($ba['bank_name'] . ' - ' . $ba['account_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <div class="row">
            <div class="col-12">
              <div id="balanceBox" class="summary-card mt-1">
                <div class="d-flex justify-content-between align-items-center">
                  <span class="font-weight-bold">Balance Due</span>
                  <span class="font-weight-bold" style="font-size:1.3rem;" id="dueDisplay">0.00</span>
                </div>
                <div class="d-flex justify-content-between small mt-1">
                  <span class="text-muted">Payment Status</span>
                  <span class="font-weight-bold" id="statusDisplay">Full Payment (Paid)</span>
                </div>
                <div class="d-flex justify-content-between small">
                  <span class="text-muted">Received Amount</span>
                  <span class="font-weight-bold text-success" id="receivedDisplay">0.00</span>
                </div>
              </div>
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-block mt-3 py-2"><i class="fas fa-file-invoice"></i> Generate Invoice</button>
        </div>

        <div class="text-center pb-3" id="emptySummary">
          <button type="submit" class="btn btn-primary btn-block btn-sm mx-3" disabled style="width:auto;"><i class="fas fa-file-invoice"></i> Generate</button>
          <small class="text-muted d-block mt-1">Add items to the cart first</small>
        </div>

      </div>
    </div>
  </div>

</div>
</form>

<script>
var SERIALS_BY_PRODUCT = <?= json_encode($serials_by_product, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
function toggleWalkin(val) {
    $('#walkinFields').toggle(val === 'walkin');
}
$(document).ready(function() {

    $('#saleForm').on('submit', function(e) {
        if ($('#customerSelect').val() === 'walkin' && $.trim($('#walkinFields input[name="walkin_name"]').val()) === '') {
            e.preventDefault();
            showMsg('Walk-in customer ka name zaroori hai');
        }
    });

    // Prevent accidental form submission on Enter inside input fields
    $('#saleForm').on('keydown', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            var tag = (e.target.tagName || '').toLowerCase();
            if (tag === 'textarea' || e.target.type === 'submit') return;

            if (e.target.id === 'entryPrice' || e.target.id === 'entryQty') {
                e.preventDefault();
                addToCart();
                return false;
            }

            if (e.target.id === 'barcodeScannerInput' || e.target.id === 'entryDesc') {
                // Handled specifically by their own handlers
                return;
            }

            e.preventDefault();
            return false;
        }
    });

    function updateEntryPreview() {
        var desc = $('#entryDesc').val().trim();
        var qty = parseFloat($('#entryQty').val()) || 1;
        var price = parseFloat($('#entryPrice').val()) || 0;
        if (desc && price > 0) {
            $('#entryPreview').show();
            $('#entryAmountPreview').text((qty * price).toFixed(2));
        } else {
            $('#entryPreview').hide();
        }
    }

    function calcPayment() {
        var total = parseFloat($('#totalDisplay').text()) || 0;
        var received = parseFloat($('#receivedAmount').val()) || 0;
        if (received > total) { received = total; $('#receivedAmount').val(received.toFixed(2)); }
        var due = Math.max(0, total - received);
        $('#dueDisplay').text(due.toFixed(2));
        $('#receivedDisplay').text(received.toFixed(2));

        if (due <= 0) {
            $('#statusDisplay').text('Full Payment (Paid)').removeClass('text-danger').addClass('text-success');
        } else if (received > 0) {
            $('#statusDisplay').text('Partial Payment').removeClass('text-success').addClass('text-warning');
        } else {
            $('#statusDisplay').text('Credit Sale (Khata)').removeClass('text-success').addClass('text-danger');
        }
    }

    $('#addToCartBtn').on('click', addToCart);
    $('#entryQty, #entryPrice').on('keypress', function(e) {
        if (e.which === 13) addToCart();
    });
    $('#entryDesc, #entryQty, #entryPrice').on('input', updateEntryPreview);
    $('#entryDesc').on('input', function() {
        var val = $(this).val();
        var matched = false;
        var selectedSerial = $('#entrySerial').val();
        $('#productList option').each(function() {
            var valLower = (val || '').toLowerCase().trim();
            var matches = $(this).attr('value') === val || ($(this).data('name') && String($(this).data('name')).toLowerCase() === valLower);
            if ($(this).attr('value') && matches) {
                var price = $(this).data('price');
                var productId = $(this).data('product-id');
                var stock = $(this).data('stock');
                var isMobile = parseInt($(this).data('mobile')) === 1;
                if (price) { $('#entryPrice').val(price); updateEntryPreview(); }
                $('#entryProductId').val(productId || 0);
                if (stock !== undefined) {
                    if (stock > 0) {
                        $('#stockInfo').text('In Stock: ' + stock).removeClass('text-danger').addClass('text-muted').show();
                    } else {
                        $('#stockInfo').text('Out of Stock!').removeClass('text-muted').addClass('text-danger').show();
                    }
                }
                populateSerials(productId, isMobile);
                $('#entrySerial').val(selectedSerial || '');
                matched = true;
                return false;
            }
        });
        if (!matched) {
            $('#entryProductId').val(0);
            $('#stockInfo').hide();
            resetSerials();
            if (!val) { $('#entryPrice').val(''); updateEntryPreview(); }
        }
    });

    function populateSerials(productId, isMobile) {
        var $sel = $('#entrySerial');
        $sel.find('option:not(:first)').remove();
        $('#serialInfo').text('').hide();
        if (!isMobile) { $('#serialGroup').hide(); return; }
        var list = SERIALS_BY_PRODUCT[productId] || [];
        if (list.length === 0) {
            $sel.append('<option value="">No IMEI / unit available</option>');
            $('#serialInfo').text('No available IMEI found for this mobile (check purchase record).').show();
        } else {
            list.forEach(function(s) {
                var details = s.notes || '';
                var label = (s.imei_number ? 'IMEI: ' + s.imei_number : '') + (s.imei2 ? ' / ' + s.imei2 : '') + (details ? ' - ' + details : '');
                $sel.append('<option value="' + s.id + '">' + label + '</option>');
            });
        }
        $('#serialGroup').show();
    }

    function resetSerials() {
        $('#entrySerial').find('option:not(:first)').remove();
        $('#serialInfo').text('').hide();
        $('#serialGroup').hide();
    }

    // Sound feedback via Web Audio API
    function playScannerBeep(isSuccess) {
        try {
            var AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            var ctx = new AudioCtx();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            if (isSuccess !== false) {
                osc.frequency.setValueAtTime(1600, ctx.currentTime);
                gain.gain.setValueAtTime(0.18, ctx.currentTime);
                osc.start();
                osc.stop(ctx.currentTime + 0.10);
            } else {
                osc.frequency.setValueAtTime(320, ctx.currentTime);
                gain.gain.setValueAtTime(0.22, ctx.currentTime);
                osc.start();
                osc.stop(ctx.currentTime + 0.28);
            }
        } catch(e) {}
    }

    function processScannedImei(scannedCode) {
        var code = (scannedCode || "").trim();
        if (!code) return;

        var $status = $("#scanStatusMsg");
        $status.html('<span class="text-primary small font-weight-bold"><i class="fas fa-spinner fa-spin mr-1"></i> Looking up IMEI: ' + code + '...</span>').show();

        $.getJSON("imei_lookup.php", { imei: code }, function(res) {
            if (!res.found) {
                playScannerBeep(false);
                $status.html('<div class="alert alert-danger py-1 px-2 mb-0 small font-weight-bold"><i class="fas fa-times-circle mr-1"></i> ' + (res.message || "IMEI not found") + '</div>').show();
                $("#barcodeScannerInput").select().focus();
                return;
            }

            if (res.sold) {
                playScannerBeep(false);
                $status.html('<div class="alert alert-warning py-1 px-2 mb-0 small font-weight-bold"><i class="fas fa-exclamation-triangle mr-1"></i> ' + (res.message || "This IMEI is already marked as SOLD!") + '</div>').show();
                $("#barcodeScannerInput").select().focus();
                return;
            }

            // Available: Auto-fill all fields!
            playScannerBeep(true);

            // 1. Auto-fill description & product ID
            $("#entryDesc").val(res.product_label || (res.product_name + " (" + res.product_code + ")"));
            $("#entryProductId").val(res.product_id);

            // 2. Auto-fill price & qty
            $("#entryPrice").val(parseFloat(res.sale_price || 0).toFixed(2));
            $("#entryQty").val(1);

            // 3. Populate and select serial
            populateSerials(parseInt(res.product_id), true);
            $("#entrySerial").val(res.serial_id).trigger("change");

            // 4. Update preview
            updateEntryPreview();

            $status.html('<div class="alert alert-success py-1 px-2 mb-0 small font-weight-bold"><i class="fas fa-check-circle mr-1"></i> Auto-filled: ' + res.product_name + ' | IMEI: ' + res.imei_number + ' (Rs. ' + parseFloat(res.sale_price).toLocaleString() + ')</div>').show();

            // 5. Check if auto-add to cart is active
            if ($("#autoAddToCartCheck").is(":checked")) {
                setTimeout(function() {
                    addToCart();
                    $("#barcodeScannerInput").val("").focus();
                    $status.html('<div class="alert alert-success py-1 px-2 mb-0 small font-weight-bold"><i class="fas fa-shopping-cart mr-1"></i> Added to cart: ' + res.product_name + ' (IMEI: ' + res.imei_number + ')</div>');
                }, 150);
            } else {
                $("#barcodeScannerInput").val("");
                $("#addToCartBtn").focus();
            }
        }).fail(function() {
            playScannerBeep(false);
            $status.html('<div class="alert alert-danger py-1 px-2 mb-0 small">Server error checking IMEI</div>').show();
            $("#barcodeScannerInput").focus();
        });
    }

    $("#barcodeScannerInput").on("keydown", function(e) {
        if (e.key === "Enter" || e.keyCode === 13) {
            e.preventDefault();
            processScannedImei($(this).val());
        }
    });

    $("#btnScanLookup").on("click", function() {
        processScannedImei($("#barcodeScannerInput").val());
    });

    // Also support scanning into description input
    $("#entryDesc").on("keydown", function(e) {
        if (e.key === "Enter" || e.keyCode === 13) {
            var val = $(this).val().trim();
            if (/^\d{8,20}$/.test(val)) {
                e.preventDefault();
                processScannedImei(val);
            }
        }
    });

    // Global listener for fast hardware barcode scanner input
    var hwScanBuffer = "";
    var hwLastKeyTime = 0;
    $(document).on("keypress", function(e) {
        var targetTag = (e.target.tagName || "").toLowerCase();
        var isSpecialInput = (e.target.id === "barcodeScannerInput" || e.target.id === "entryDesc");

        var now = Date.now();
        var diff = now - hwLastKeyTime;
        hwLastKeyTime = now;

        if (e.key === "Enter" || e.keyCode === 13) {
            if (hwScanBuffer.length >= 8 && /^\d+$/.test(hwScanBuffer)) {
                e.preventDefault();
                var code = hwScanBuffer;
                hwScanBuffer = "";
                $("#barcodeScannerInput").val(code);
                processScannedImei(code);
                return;
            }
            hwScanBuffer = "";
        } else {
            if (diff > 90) {
                hwScanBuffer = "";
            }
            if (e.key && e.key.length === 1) {
                hwScanBuffer += e.key;
            }
        }
    });

    function tryMatchImei(inputVal) {
        var clean = (inputVal || "").trim();
        if (!clean) return false;
        for (var pid in SERIALS_BY_PRODUCT) {
            var serials = SERIALS_BY_PRODUCT[pid];
            for (var i = 0; i < serials.length; i++) {
                var s = serials[i];
                if (s.imei_number === clean || (s.imei2 && s.imei2 === clean)) {
                    var opt = $('#productList option[data-product-id="' + pid + '"]');
                    if (opt.length) {
                        $('#entryDesc').val(opt.val());
                        $('#entryProductId').val(pid);
                        $('#entryPrice').val(parseFloat(opt.data('price') || 0).toFixed(2));
                        populateSerials(parseInt(pid), true);
                        $('#entrySerial').val(s.id).trigger('change');
                        $('#stockInfo').text('Stock: ' + (opt.data('stock') || 0) + ' | IMEI Barcode Scanned!').removeClass('text-danger').addClass('text-success').show();
                        updateEntryPreview();
                        return true;
                    }
                }
            }
        }
        return false;
    }

    $('#entryDesc').on('keydown', function(e) {
        if (e.key === 'Enter') {
            if (tryMatchImei($(this).val())) {
                e.preventDefault();
                $('#addToCartBtn').focus();
            }
        }
    });

    $('#entrySerial').on('change', function() {
        var sid = $(this).val();
        if (!sid) {
            $('#serialInfo').text('').hide();
            $('#serialBarcodeWrap').hide();
            return;
        }
        var list = SERIALS_BY_PRODUCT[parseInt($('#entryProductId').val()) || 0] || [];
        var sn = null;
        list.forEach(function(s) { if (String(s.id) === String(sid)) sn = s; });
        if (sn) {
            var txt = '';
            if (sn.imei_number) txt += 'IMEI 1: ' + sn.imei_number + ' ';
            if (sn.imei2) txt += '| IMEI 2: ' + sn.imei2 + ' ';
            if (sn.notes) txt += '| ' + sn.notes;
            $('#serialInfo').text('Selected: ' + txt).show();

            var barcodeNum = sn.imei_number || sn.imei2;
            if (barcodeNum && typeof JsBarcode !== 'undefined') {
                try {
                    JsBarcode('#selectedImeiBarcode', barcodeNum, {
                        format: 'CODE128',
                        width: 1.15,
                        height: 30,
                        fontSize: 10,
                        margin: 2,
                        displayValue: true
                    });
                    $('#btnSerialSticker').off('click').on('click', function() {
                        printImeiBarcodeSticker(barcodeNum, $('#entryDesc').val(), 'IMEI');
                    });
                    $('#serialBarcodeWrap').show();
                } catch(e) {
                    $('#serialBarcodeWrap').hide();
                }
            } else {
                $('#serialBarcodeWrap').hide();
            }
        }
    });
    $('#receivedAmount').on('input', calcPayment);
    // Keep field empty on load (prevents browser autofill from restoring old values)
    $('#receivedAmount').val('');
    calcPayment();

    $('#paymentMethod').on('change', function() {
      $('#bankAccountRow').toggle($(this).val() === 'bank');
    });

    function addToCart() {
        var desc = $('#entryDesc').val().trim();
        if (!desc) { showMsg('Enter a description first'); return; }
        var qty = parseFloat($('#entryQty').val()) || 1;
        var price = parseFloat($('#entryPrice').val()) || 0;
        if (price <= 0) { showMsg('Enter a valid price'); return; }
        var amount = qty * price;
        var productId = parseInt($('#entryProductId').val()) || 0;
        var isMobile = $('#serialGroup').is(':visible');
        var serialId = isMobile ? (parseInt($('#entrySerial').val()) || 0) : 0;
        if (isMobile && serialId === 0) { showMsg('Please select an IMEI / unit for this mobile'); return; }
        var stockWarn = '';
        var stock = 0;
        $('#productList option').each(function() {
            if ($(this).data('product-id') == productId) {
                stock = parseInt($(this).data('stock')) || 0;
                if (qty > stock) stockWarn = ' <small class="text-danger">(only ' + stock + ' in stock)</small>';
                return false;
            }
        });

        if (isMobile) {
            var list = SERIALS_BY_PRODUCT[productId] || [];
            var sn = null;
            list.forEach(function(s) { if (String(s.id) === String(serialId)) sn = s; });
            if (sn) {
                var imeiTxt = (sn.imei_number ? 'IMEI: ' + sn.imei_number : '') + (sn.imei2 ? ' / ' + sn.imei2 : '');
                desc = desc + (imeiTxt ? ' [' + imeiTxt + ']' : '');
            }
        }

        var safeDesc = $('<span>').text(desc).html();
        var html = '<tr class="item-row">' +
            '<td><input type="hidden" name="item_description[]" value="' + safeDesc + '"><input type="hidden" name="product_ids[]" value="' + productId + '"><input type="hidden" name="serial_ids[]" value="' + serialId + '">' + safeDesc + stockWarn + '</td>' +
            '<td class="text-center p-1"><input type="number" name="quantity[]" class="form-control form-control-sm qty-input text-center" value="' + qty + '" min="1" style="width:48px;"></td>' +
            '<td class="text-right p-1"><input type="number" name="price[]" class="form-control form-control-sm price-input text-right" step="0.01" min="0" value="' + price.toFixed(2) + '" style="width:80px;"></td>' +
            '<td class="text-right font-weight-bold line-total align-middle p-1">' + amount.toFixed(2) + '</td>' +
            '<td class="text-center align-middle p-1"><span class="remove-item" style="cursor:pointer;color:#e74a3b;"><i class="fas fa-times"></i></span></td>' +
            '</tr>';
        $('#cartBody').append(html);
        if (stockWarn) showMsg('Insufficient stock for ' + safeDesc);
        $('#emptyCart').hide();
        $('#summaryArea').show();
        $('#emptySummary').hide();
        clearEntry();
        calcTotals();
    }

    function clearEntry() {
        $('#entryDesc').val('').focus();
        $('#entryPrice').val('');
        $('#entryQty').val(1);
        $('#entryProductId').val(0);
        $('#stockInfo').hide();
        $('#entryPreview').hide();
        resetSerials();
    }

    $(document).on('input', '.qty-input, .price-input', function() {
        calcRow($(this).closest('.item-row'));
        calcTotals();
    });

    function calcRow($row) {
        var qty = parseFloat($row.find('.qty-input').val()) || 0;
        var price = parseFloat($row.find('.price-input').val()) || 0;
        $row.find('.line-total').text((qty * price).toFixed(2));
    }

    function calcTotals() {
        var total = 0, itemCount = 0;
        $('#cartBody .item-row').each(function() {
            itemCount++;
            total += parseFloat($(this).find('.line-total').text()) || 0;
        });
        $('#totalDisplay').text(total.toFixed(2));
        calcPayment();

        if (itemCount === 0) {
            $('#summaryArea').hide();
            $('#emptySummary').show();
        }
    }

    $(document).on('click', '.remove-item', function() {
        $(this).closest('.item-row').remove();
        if ($('#cartBody .item-row').length === 0) $('#emptyCart').show();
        calcTotals();
    });

    function showMsg(msg) {
        var $a = $('<div class="alert alert-warning py-1 small mb-1">' + msg + '</div>');
        $('#addToCartBtn').before($a);
        setTimeout(function() { $a.fadeOut(function() { $(this).remove(); }); }, 1500);
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>