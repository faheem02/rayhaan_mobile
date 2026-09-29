<?php
ini_set('display_errors', 1); error_reporting(E_ALL);
session_start();
$page_title = 'Edit Sale';
$base_url = '../../';
require_once '../../includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$sale = getById('sales', $id);
if (!$sale) {
    $_SESSION['error'] = 'Sale not found.';
    header("Location: invoices.php");
    exit;
}

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

// Items of this sale
$item_stmt = $pdo->prepare("SELECT si.*, p.name AS product_name, p.code AS product_code, p.product_type FROM sale_items si LEFT JOIN products p ON p.id = si.product_id WHERE si.sale_id = ? ORDER BY si.id");
$item_stmt->execute([$id]);
$sale_items = $item_stmt->fetchAll();

// Serials sold on this sale
$ser_stmt = $pdo->prepare("SELECT * FROM product_serials WHERE sale_id = ? ORDER BY id");
$ser_stmt->execute([$id]);
$sold_serials = $ser_stmt->fetchAll();
$sold_serials_by_product = [];
foreach ($sold_serials as $sn) {
    $sold_serials_by_product[$sn['product_id']][] = $sn;
}

// Selectable serials per mobile product: available + this sale's sold serials
$mobile_serials = $pdo->query("SELECT s.*, p.product_type FROM product_serials s JOIN products p ON p.id = s.product_id WHERE p.product_type = 'mobile' AND (s.status = 'available' OR s.sale_id = " . (int)$id . ") ORDER BY s.product_id, s.imei_number")->fetchAll();
$serials_by_product = [];
foreach ($mobile_serials as $ms) {
    $serials_by_product[$ms['product_id']][] = $ms;
}

// Undo the old sale effects: restore stock, serials and reverse/delete payments
function reverseSaleEffects($pdo, $sale) {
    $sale_id = (int)$sale['id'];
    $items = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
    $items->execute([$sale_id]);
    foreach ($items->fetchAll() as $it) {
        if (!empty($it['product_id'])) {
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?")->execute([(int)$it['quantity'], (int)$it['product_id']]);
        }
    }
    $pdo->prepare("UPDATE product_serials SET status = 'available', sale_id = NULL, updated_at = CURDATE() WHERE sale_id = ?")->execute([$sale_id]);
    $pays = $pdo->prepare("SELECT * FROM payments WHERE sale_id = ?");
    $pays->execute([$sale_id]);
    foreach ($pays->fetchAll() as $pay) {
        $pid = (int)$pay['id'];
        $cb = $pdo->prepare("SELECT * FROM cash_book WHERE reference_type = 'payment' AND reference_id = ? AND transaction_type = 'inflow'");
        $cb->execute([$pid]);
        foreach ($cb->fetchAll() as $r) {
            $pdo->prepare("UPDATE cash_book_daily SET total_inflow = GREATEST(0, total_inflow - ?), closing_balance = opening_balance + total_inflow - total_outflow WHERE id = ?")->execute([$r['amount'], $r['daily_id']]);
            $pdo->prepare("DELETE FROM cash_book WHERE id = ?")->execute([$r['id']]);
        }
        $bt = $pdo->prepare("SELECT * FROM bank_transactions WHERE reference_type = 'payment' AND reference_id = ? AND transaction_type = 'deposit'");
        $bt->execute([$pid]);
        foreach ($bt->fetchAll() as $r) {
            $pdo->prepare("UPDATE bank_accounts SET current_balance = GREATEST(0, current_balance - ?) WHERE id = ?")->execute([$r['amount'], $r['bank_account_id']]);
            $pdo->prepare("DELETE FROM bank_transactions WHERE id = ?")->execute([$r['id']]);
        }
        $pdo->prepare("DELETE FROM payments WHERE id = ?")->execute([$pid]);
    }
}

// ------------------------------------------------------------
// Save handler - reverse old sale effects, then re-apply new ones
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        reverseSaleEffects($pdo, $sale);

        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $sale_date = $_POST['sale_date'] ?? date('Y-m-d');
        if (!$customer_id) throw new Exception('Select a customer.');

        $descriptions = $_POST['item_description'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $prices = $_POST['price'] ?? [];
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
            if (!$pid) $pid = resolveProductFromDescription($pdo, $desc);
            $items[] = ['description' => $desc, 'quantity' => $qty, 'price' => $price, 'subtotal' => $line_total, 'product_id' => $pid, 'serial_id' => $sid];
        }
        if (empty($items)) throw new Exception('Add at least one valid item.');

        $received_amount = (float)($_POST['received_amount'] ?? 0);
        $received_amount = min($received_amount, $total_amount);
        $due_amount = $total_amount - $received_amount;
        $payment_status = $due_amount <= 0 ? 'paid' : ($received_amount > 0 ? 'partial' : 'pending');
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $bank_account_id = (int)($_POST['bank_account_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        $pdo->prepare("UPDATE sales SET customer_id=?, sale_date=?, subtotal=?, total_amount=?, paid_amount=?, due_amount=?, payment_method=?, payment_status=?, notes=?, updated_at=? WHERE id=?")
            ->execute([$customer_id, $sale_date, $total_amount, $total_amount, $received_amount, $due_amount, $received_amount > 0 ? $payment_method : null, $payment_status, $notes ?: null, date('Y-m-d'), $id]);

        $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$id]);
        foreach ($items as $item) {
            insert('sale_items', [
                'sale_id' => $id,
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
                $pdo->prepare("UPDATE product_serials SET status = 'sold', sale_id = ?, updated_at = CURDATE() WHERE id = ? AND status = 'available'")->execute([$id, $item['serial_id']]);
            }
        }

        if ($received_amount > 0) {
            $pay_id = insert('payments', [
                'sale_id' => $id,
                'payment_date' => $sale_date,
                'amount' => $received_amount,
                'payment_type' => 'sale',
                'payment_method' => $payment_method,
                'notes' => 'Payment for ' . $sale['invoice_no'],
                'branch_id' => $_SESSION['branch_id'] ?? null,
                'received_by' => $_SESSION['user_id'] ?? null,
                'created_at' => date('Y-m-d'),
            ]);
            if ($payment_method === 'cash') {
                recordCashInflow($pdo, $sale_date, $received_amount, 'Sale - ' . $sale['invoice_no'], 'payment', $pay_id, $_SESSION['user_id'] ?? null);
            } elseif ($payment_method === 'bank') {
                recordBankInflow($pdo, $sale_date, $received_amount, 'Sale (bank) - ' . $sale['invoice_no'], 'payment', $pay_id, $_SESSION['user_id'] ?? null, $bank_account_id);
            }
        }

        $pdo->commit();
        $msg = "Sale {$sale['invoice_no']} updated successfully.";
        if ($due_amount > 0) $msg .= " (Balance due: " . formatCurrency($due_amount) . ")";
        $_SESSION['success'] = $msg;
    } catch (Throwable $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Save failed: ' . $e->getMessage();
    }
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
  <h5 class="m-0 font-weight-bold" style="color:#0f172a;"><i class="fas fa-edit"></i> Edit Sale</h5>
  <div>
    <a href="invoice.php?id=<?= $id ?>" class="btn btn-sm btn-secondary"><i class="fas fa-file-invoice"></i> View Invoice</a>
    <a href="invoices.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left"></i> Invoices</a>
  </div>
</div>

<form method="post" id="saleForm">
<div class="row">

  <!-- LEFT COLUMN -->
  <div class="col-lg-5 mb-3">

    <!-- Customer & Date -->
    <div class="card shadow mb-3">
      <div class="card-body py-3">
        <div class="form-group mb-2">
          <label class="small text-muted">Customer <span class="text-danger">*</span></label>
          <select name="customer_id" id="customerSelect" class="form-control" required>
            <option value="">Select Customer</option>
            <?php foreach ($customers as $c): ?>
              <option value="<?= $c['id'] ?>" <?= (int)$c['id'] === (int)$sale['customer_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['full_name']) ?> (<?= htmlspecialchars($c['phone']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row">
          <div class="col-6"><div class="form-group mb-0"><label class="small text-muted">Date</label><input type="text" name="sale_date" class="form-control datepicker" value="<?= htmlspecialchars($sale['sale_date']) ?>" autocomplete="off"></div></div>
          <div class="col-6"><div class="form-group mb-0"><label class="small text-muted">Notes</label><input type="text" name="notes" class="form-control" placeholder="Optional" value="<?= htmlspecialchars($sale['notes'] ?? '') ?>"></div></div>
        </div>
      </div>
    </div>

    <!-- Add Item -->
    <div class="card shadow mb-3">
      <div class="card-header py-2"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-plus-circle"></i> Add Item</h6></div>
      <div class="card-body py-3">
        <div class="form-group mb-2">
          <label class="small text-muted">Description <span class="text-danger">*</span></label>
          <input type="text" id="entryDesc" class="form-control form-control-lg" list="productList" placeholder="Type or select product..." autofocus>
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
            <tbody id="cartBody">
              <?php
              $row_total = 0;
              $serial_ptr = [];
              foreach ($sale_items as $it):
                  $row_total += (float)$it['subtotal'];
                  $pid = (int)$it['product_id'];
                  $ptype = $it['product_type'] ?? 'general';
                  $safeDesc = htmlspecialchars($it['item_description'] ?? '', ENT_QUOTES);
                  $name = htmlspecialchars($it['product_name'] ?? ($it['item_description'] ?? ''), ENT_QUOTES);
                  $curId = 0;
                  $serialOptions = '';
                  if ($ptype === 'mobile' && $pid) {
                      $pidx = $serial_ptr[$pid] ?? 0;
                      $serial_ptr[$pid] = $pidx + 1;
                      $list = $sold_serials_by_product[$pid] ?? [];
                      $cur = $list[$pidx] ?? null;
                      $curId = $cur ? (int)$cur['id'] : 0;
                      foreach (($serials_by_product[$pid] ?? []) as $sn) {
                          $label = ($sn['imei_number'] ? 'IMEI: ' . $sn['imei_number'] : '') . ($sn['serial_number'] ? ' / ' . $sn['serial_number'] : '') . ($sn['notes'] ? ' - ' . $sn['notes'] : '');
                          $sel = ((int)$sn['id'] === $curId) ? ' selected' : '';
                          $serialOptions .= '<option value="' . (int)$sn['id'] . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
                      }
                  }
              ?>
              <tr class="item-row">
                <td>
                  <input type="hidden" name="item_description[]" value="<?= $safeDesc ?>">
                  <input type="hidden" name="product_ids[]" value="<?= $pid ?>">
                  <?php if ($ptype === 'mobile' && $pid): ?>
                    <span class="desc-text font-weight-bold"><?= $safeDesc ?></span>
                    <select name="serial_ids[]" class="form-control form-control-sm mt-1 row-serial-select" data-name="<?= $name ?>">
                      <option value="">Select IMEI / Unit</option>
                      <?= $serialOptions ?>
                    </select>
                  <?php else: ?>
                    <input type="hidden" name="serial_ids[]" value="0">
                    <span class="desc-text"><?= $safeDesc ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-center p-1"><input type="number" name="quantity[]" class="form-control form-control-sm qty-input text-center" value="<?= (int)$it['quantity'] ?>" min="1" style="width:48px;"></td>
                <td class="text-right p-1"><input type="number" name="price[]" class="form-control form-control-sm price-input text-right" step="0.01" min="0" value="<?= number_format((float)$it['price'], 2, '.', '') ?>" style="width:80px;"></td>
                <td class="text-right font-weight-bold line-total align-middle p-1"><?= number_format((float)$it['subtotal'], 2, '.', '') ?></td>
                <td class="text-center align-middle p-1"><span class="remove-item" style="cursor:pointer;color:#e74a3b;"><i class="fas fa-times"></i></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="text-center text-muted small py-3" id="emptyCart" <?= $row_total > 0 ? 'style="display:none;"' : '' ?>><i class="fas fa-box-open fa-2x d-block mb-2"></i>Cart is empty</div>

        <!-- Payment Summary -->
        <div class="px-3 pb-3" id="summaryArea" <?= $row_total > 0 ? '' : 'style="display:none;"' ?>>
          <hr class="my-2">

          <div class="row">
            <div class="col-6">
              <div class="form-group mb-2">
                <label class="small text-muted">Total Amount</label>
                <div class="finance-value" id="totalDisplay"><?= number_format($row_total, 2, '.', '') ?></div>
              </div>
            </div>
            <div class="col-6">
              <div class="form-group mb-2">
                <label class="small text-muted">Amount Received</label>
                <input type="number" name="received_amount" id="receivedAmount" class="form-control form-control-lg font-weight-bold" step="0.01" min="0" value="<?= number_format((float)$sale['paid_amount'], 2, '.', '') ?>" placeholder="0.00 = credit sale">
              </div>
            </div>
            <div class="col-6">
              <div class="form-group mb-2">
                <label class="small text-muted">Payment Method</label>
                <select name="payment_method" id="paymentMethod" class="form-control">
                  <option value="cash" <?= $sale['payment_method'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                  <option value="bank" <?= in_array($sale['payment_method'], ['bank', 'bank_transfer'], true) ? 'selected' : '' ?>>Bank</option>
                </select>
              </div>
            </div>
          </div>
          <div class="row" id="bankAccountRow" style="<?= in_array($sale['payment_method'], ['bank', 'bank_transfer'], true) ? '' : 'display:none;' ?>">
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

          <button type="submit" class="btn btn-primary btn-block mt-3 py-2"><i class="fas fa-save"></i> Save Changes</button>
        </div>

        <div class="text-center pb-3" id="emptySummary" <?= $row_total > 0 ? 'style="display:none;"' : '' ?>>
          <button type="submit" class="btn btn-primary btn-block btn-sm mx-3" disabled style="width:auto;"><i class="fas fa-save"></i> Save</button>
          <small class="text-muted d-block mt-1">Add items to the cart first</small>
        </div>

      </div>
    </div>
  </div>

</div>
</form>

<script>
var SERIALS_BY_PRODUCT = <?= json_encode($serials_by_product, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
$(document).ready(function() {

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

    $('#entrySerial').on('change', function() {
        var sid = $(this).val();
        if (!sid) { $('#serialInfo').text('').hide(); return; }
        var list = SERIALS_BY_PRODUCT[parseInt($('#entryProductId').val()) || 0] || [];
        var sn = null;
        list.forEach(function(s) { if (String(s.id) === String(sid)) sn = s; });
        if (sn) {
            var txt = '';
            if (sn.imei_number) txt += 'IMEI 1: ' + sn.imei_number + ' ';
            if (sn.imei2) txt += '| IMEI 2: ' + sn.imei2 + ' ';
            if (sn.notes) txt += '| ' + sn.notes;
            $('#serialInfo').text('Selected: ' + txt).show();
        }
    });
    $('#receivedAmount').on('input', calcPayment);

    $('#paymentMethod').on('change', function() {
      $('#bankAccountRow').toggle($(this).val() === 'bank');
    });

    // When the serial select inside an existing cart row changes, update its description
    $(document).on('change', '.row-serial-select', function() {
        var $row = $(this).closest('.item-row');
        var pid = parseInt($row.find('input[name="product_ids[]"]').val()) || 0;
        var sid = $(this).val();
        var name = $(this).data('name') || '';
        var label = '';
        (SERIALS_BY_PRODUCT[pid] || []).forEach(function(s) {
            if (String(s.id) === String(sid)) {
                label = (s.imei_number ? 'IMEI: ' + s.imei_number : '') + (s.imei2 ? ' / ' + s.imei2 : '');
            }
        });
        var full = label ? name + ' [' + label + ']' : name;
        $row.find('input[name="item_description[]"]').val(full);
        $row.find('.desc-text').text(full);
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

            if (e.target.id === 'entryDesc') {
                return;
            }

            e.preventDefault();
            return false;
        }
    });

    calcPayment();
});
</script>

<?php require_once '../../includes/footer.php'; ?>
