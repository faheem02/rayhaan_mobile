<?php
ini_set('display_errors', 1); error_reporting(E_ALL);
session_start();
$page_title = 'Invoices';
$base_url = '../../';
require_once '../../includes/functions.php';

// Handle delete (full reversal: stock, IMEIs, payments incl. cash/bank book)
if (isset($_GET['delete'])) {
    $sale_id = (int)$_GET['delete'];
    $sale = getById('sales', $sale_id);
    if (!$sale) {
        $_SESSION['error'] = 'Sale not found.';
        header("Location: invoices.php");
        exit;
    }
    try {
        $pdo->beginTransaction();

        // 1) Restore product stock
        $items = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
        $items->execute([$sale_id]);
        foreach ($items->fetchAll() as $it) {
            if (!empty($it['product_id'])) {
                $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?")->execute([(int)$it['quantity'], (int)$it['product_id']]);
            }
        }

        // 2) Restore IMEI / serials back to available
        $pdo->prepare("UPDATE product_serials SET status = 'available', sale_id = NULL, updated_at = CURDATE() WHERE sale_id = ?")->execute([$sale_id]);

        // 3) Reverse payments (cash / bank book) and remove them
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

        // 4) Remove returns, items and the sale itself
        $pdo->prepare("DELETE FROM sale_returns WHERE sale_id = ?")->execute([$sale_id]);
        $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$sale_id]);
        $pdo->prepare("DELETE FROM sales WHERE id = ?")->execute([$sale_id]);

        $pdo->commit();
        $_SESSION['success'] = "Sale {$sale['invoice_no']} deleted. Stock, IMEIs and payments reversed.";
    } catch (Throwable $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Delete failed: ' . $e->getMessage();
    }
    header("Location: invoices.php");
    exit;
}

$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';

$sql = "SELECT s.*, c.full_name AS customer_name, c.phone AS customer_phone,
       COALESCE((SELECT SUM(p2.amount) FROM payments p2 WHERE p2.sale_id = s.id), 0) AS total_paid_sum
       FROM sales s
       JOIN customers c ON s.customer_id = c.id WHERE 1=1";
$params = [];
if ($search) {
    $sql .= " AND (s.invoice_no LIKE ? OR c.full_name LIKE ? OR c.phone LIKE ?)";
    $params = array_fill(0, 3, "%$search%");
}
if ($status) {
    $sql .= " AND s.payment_status = ?";
    $params[] = $status;
}
$sql .= " ORDER BY s.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

require_once '../../includes/header.php';
?>

<div class="card shadow mb-4">
  <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-file-invoice"></i> Invoices</h6>
    <a href="index.php" class="btn btn-primary btn-sm">
      <i class="fas fa-plus-circle"></i> New Sale
    </a>
  </div>
  <div class="card-body">
    <form method="get" class="mb-3">
      <div class="row">
        <div class="col-md-4">
          <input type="text" name="search" class="form-control" placeholder="Search by invoice no, customer..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-md-3">
          <select name="status" class="form-control">
            <option value="">All Status</option>
            <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
            <option value="partial" <?= $status === 'partial' ? 'selected' : '' ?>>Partial</option>
            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
          </select>
        </div>
        <div class="col-md-3">
          <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
          <a href="invoices.php" class="btn btn-secondary">Reset</a>
        </div>
      </div>
    </form>

    <div class="table-responsive">
      <table class="table table-bordered table-hover" width="100%" cellspacing="0">
        <thead class="thead-light">
          <tr>
            <th>Invoice #</th>
            <th>Customer</th>
            <th>Date</th>
            <th>Total</th>
            <th>Amount Paid</th>
            <th>Balance Due</th>
            <th>Method</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($sales)): ?>
            <tr><td colspan="9" class="text-center text-muted">No invoices found</td></tr>
          <?php else: ?>
            <?php foreach ($sales as $s):
              $paid = max((float)$s['paid_amount'], (float)$s['total_paid_sum']);
              $remaining = (float)$s['total_amount'] - $paid;
            ?>
              <tr>
                <td><a href="invoice.php?id=<?= $s['id'] ?>"><strong><?= htmlspecialchars($s['invoice_no']) ?></strong></a></td>
                <td><?= htmlspecialchars($s['customer_name']) ?><br><small class="text-muted"><?= htmlspecialchars($s['customer_phone']) ?></small></td>
                <td><?= formatDate($s['sale_date']) ?></td>
                <td class="text-right font-weight-bold"><?= formatCurrency($s['total_amount']) ?></td>
                <td class="text-right text-success"><?= formatCurrency($paid) ?></td>
                <td class="text-right"><span class="<?= $remaining > 0 ? 'text-danger font-weight-bold' : 'text-muted' ?>"><?= formatCurrency($remaining) ?></span></td>
                <td><?= $s['payment_method'] ? ucfirst(str_replace('_', ' ', $s['payment_method'])) : '-' ?></td>
                <td>
                  <?php
                  $badge = match($s['payment_status']) {
                    'paid' => 'success',
                    'partial' => 'warning',
                    'pending' => 'danger',
                    default => 'secondary'
                  };
                  ?>
                  <span class="badge badge-<?= $badge ?>"><?= ucfirst($s['payment_status']) ?></span>
                </td>
                <td class="text-nowrap">
                  <button type="button" class="btn btn-sm btn-info" title="View" data-id="<?= $s['id'] ?>" onclick="viewSale(this)"><i class="fas fa-eye"></i></button>
                  <a href="sale_edit.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-primary" title="Edit"><i class="fas fa-pen"></i></a>
                  <a href="invoice.php?id=<?= $s['id'] ?>&print=1" class="btn btn-sm btn-secondary" title="Print" target="_blank"><i class="fas fa-print"></i></a>
                  <a href="invoices.php?delete=<?= $s['id'] ?>" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Delete this sale (Invoice #<?= htmlspecialchars($s['invoice_no'], ENT_QUOTES) ?>)? Stock, IMEIs and payments will be reversed.')"><i class="fas fa-trash"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="saleModal" tabindex="-1" role="dialog" aria-labelledby="saleModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="saleModalLabel"><i class="fas fa-file-invoice"></i> Sale Details</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body" id="saleModalBody">
        <div class="text-center py-4">
          <i class="fas fa-spinner fa-spin fa-2x"></i>
          <p class="mt-2 text-muted">Loading...</p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
        <a href="#" id="salePrintLink" class="btn btn-primary" target="_blank"><i class="fas fa-print"></i> Print</a>
      </div>
    </div>
  </div>
</div>

<script>
function viewSale(btn) {
    var id = btn.getAttribute('data-id');
    document.getElementById('salePrintLink').href = 'invoice.php?id=' + id + '&print=1';
    document.getElementById('saleModalBody').innerHTML =
        '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i><p class="mt-2 text-muted">Loading...</p></div>';
    $('#saleModal').modal('show');

    fetch('sale_view.php?id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.error) {
                document.getElementById('saleModalBody').innerHTML = '<div class="alert alert-danger">' + d.error + '</div>';
                return;
            }
            var badge = d.payment_status === 'paid' ? 'success' : (d.payment_status === 'partial' ? 'warning' : 'danger');
            var html = '';

            // Header info
            html += '<div class="row mb-3">';
            html += '<div class="col-md-3"><small class="text-muted">Date</small><p class="font-weight-bold mb-0">' + escapeHtml(d.sale_date) + '</p></div>';
            html += '<div class="col-md-3"><small class="text-muted">Invoice No.</small><p class="font-weight-bold mb-0">' + escapeHtml(d.invoice_no || '-') + '</p></div>';
            html += '<div class="col-md-3"><small class="text-muted">Customer</small><p class="font-weight-bold mb-0">' + escapeHtml(d.customer_name) + '</p></div>';
            html += '<div class="col-md-3"><small class="text-muted">Status</small><p class="mb-0"><span class="badge badge-' + badge + '">' + (d.payment_status.charAt(0).toUpperCase() + d.payment_status.slice(1)) + '</span></p></div>';
            html += '</div>';

            if (d.customer_phone || d.customer_address || d.customer_cnic) {
                html += '<div class="row mb-3">';
                if (d.customer_phone) html += '<div class="col-md-4"><small class="text-muted">Phone</small><p class="mb-0">' + escapeHtml(d.customer_phone) + '</p></div>';
                if (d.customer_cnic) html += '<div class="col-md-4"><small class="text-muted">CNIC</small><p class="mb-0">' + escapeHtml(d.customer_cnic) + '</p></div>';
                if (d.customer_address) html += '<div class="col-md-4"><small class="text-muted">Address</small><p class="mb-0">' + escapeHtml(d.customer_address) + '</p></div>';
                html += '</div>';
            }

            if (d.notes) {
                html += '<div class="mb-3"><small class="text-muted">Notes</small><p class="mb-0">' + escapeHtml(d.notes) + '</p></div>';
            }

            // Items with IMEI
            html += '<h6 class="font-weight-bold" style="color:#0f172a;">Product Details</h6>';
            html += '<div class="table-responsive"><table class="table table-bordered table-sm"><thead class="thead-light"><tr><th>#</th><th>Product</th><th class="text-center">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr></thead><tbody>';
            var serialByProduct = {};
            (d.serials || []).forEach(function(sn) {
                if (!serialByProduct[sn.product_id]) serialByProduct[sn.product_id] = [];
                serialByProduct[sn.product_id].push(sn);
            });
            for (var i = 0; i < d.items.length; i++) {
                var it = d.items[i];
                html += '<tr><td class="text-center">' + (i + 1) + '</td>';
                html += '<td class="text-left"><span class="font-weight-bold">' + escapeHtml(it.product_name) + '</span>';
                if (it.product_code) html += ' <span class="badge badge-secondary">' + escapeHtml(it.product_code) + '</span>';
                html += ' <span class="badge badge-info">' + escapeHtml(it.product_type) + '</span>';
                if (it.product_type === 'mobile') {
                    var units = serialByProduct[it.product_id] || [];
                    if (units.length > 0) {
                        for (var u = 0; u < units.length; u++) {
                            var sn = units[u];
                            html += '<div class="mt-1 p-2" style="background:#f8f9fc;border:1px solid #e2e8f0;border-radius:4px;">';
                            html += '<div class="d-flex align-items-center justify-content-between flex-wrap">';
                            html += '<div>';
                            html += '<code class="font-weight-bold">IMEI 1: ' + escapeHtml(sn.imei1 || '-') + '</code>';
                            if (sn.imei2) html += ' &nbsp; <code class="font-weight-bold">IMEI 2: ' + escapeHtml(sn.imei2) + '</code>';
                            html += '</div>';
                            if (sn.imei1 && sn.imei1 !== "-") {
                                html += '<button type="button" class="btn btn-xs btn-outline-primary py-0 px-2" style="font-size:11px;" onclick="printImeiBarcodeSticker(\x27' + escapeHtml(sn.imei1) + '\x27, \x27' + escapeHtml(it.product_name) + '\x27, \x27IMEI 1\x27)"><i class="fas fa-barcode"></i> Print Sticker</button>';
                            }
                            html += '</div>';
                            if (sn.imei1 && sn.imei1 !== "-") {
                                html += '<div class="mt-1"><svg class="imei-barcode" data-barcode="' + escapeHtml(sn.imei1) + '" data-width="1.0" data-height="24" data-font-size="9"></svg></div>';
                            }
                            if (sn.imei2 && sn.imei2 !== "-") {
                                html += '<div class="mt-1"><svg class="imei-barcode" data-barcode="' + escapeHtml(sn.imei2) + '" data-width="1.0" data-height="24" data-font-size="9"></svg></div>';
                            }
                            if (sn.notes) html += '<small class="text-muted d-block mt-1"><i class="fas fa-info-circle"></i> ' + escapeHtml(sn.notes) + '</small>';
                            html += '</div>';
                        }
                    }
                }
                html += '</td>';
                html += '<td class="text-center">' + it.quantity + '</td>';
                html += '<td class="text-right">' + formatNum(it.price) + '</td>';
                html += '<td class="text-right">' + formatNum(it.subtotal) + '</td></tr>';
            }
            html += '<tfoot><tr class="font-weight-bold" style="background:#f8f9fc;"><td colspan="4" class="text-right">Total Amount</td><td class="text-right">' + formatNum(d.total_amount) + '</td></tr></tfoot>';
            html += '</tbody></table></div>';

            // Payments
            if (d.payments && d.payments.length > 0) {
                html += '<h6 class="font-weight-bold" style="color:#0f172a;">Payment History</h6>';
                html += '<div class="table-responsive"><table class="table table-bordered table-sm"><thead class="thead-light"><tr><th>Date</th><th class="text-right">Amount</th><th>Method</th><th>Type</th><th>Notes</th></tr></thead><tbody>';
                for (var p = 0; p < d.payments.length; p++) {
                    var pay = d.payments[p];
                    html += '<tr><td>' + escapeHtml(pay.payment_date) + '</td>';
                    html += '<td class="text-right text-success font-weight-bold">' + formatNum(pay.amount) + '</td>';
                    html += '<td>' + escapeHtml(pay.payment_method) + '</td>';
                    html += '<td>' + escapeHtml((pay.payment_type || '').replace(/_/g, ' ')) + '</td>';
                    html += '<td class="small">' + escapeHtml(pay.notes || '-') + '</td></tr>';
                }
                html += '</tbody></table></div>';
            }

            html += '<p class="text-muted small mb-0">Created: ' + escapeHtml(d.created_at) + '</p>';
            document.getElementById('saleModalBody').innerHTML = html;
            renderImeiBarcodes(document.getElementById('saleModalBody'));
        })
        .catch(function() {
            document.getElementById('saleModalBody').innerHTML = '<div class="alert alert-danger">Failed to load sale details.</div>';
        });
}

function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function formatNum(n) {
    return parseFloat(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<?php require_once '../../includes/footer.php'; ?>
