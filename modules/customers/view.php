<?php
session_start();
$page_title = 'Customer Details';
$base_url = '../../';
require_once '../../includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$customer = getById('customers', $id);
if (!$customer) redirect('index.php', 'Customer not found', 'error');

$users = $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll();

// Handle Overview financial update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_financials'])) {
    update('customers', [
        'opening_due' => (float)($_POST['opening_due'] ?? 0),
        'opening_paid' => (float)($_POST['opening_paid'] ?? 0),
        'updated_at' => date('Y-m-d'),
    ], $id);
    $_SESSION['success'] = 'Financial details updated';
    header("Location: view.php?id=$id&tab=overview");
    exit;
}

// Handle Sale Return save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_return'])) {
    $ret_date = $_POST['return_date'] ?? date('Y-m-d');
    $amount = (float)($_POST['amount'] ?? 0);
    $qty = max(1, (int)($_POST['quantity'] ?? 1));
    $notes = trim($_POST['notes'] ?? '');
    $sale_item_id = (int)($_POST['sale_item_id'] ?? 0);
    if ($amount > 0 && $sale_item_id > 0) {
        $item = $pdo->prepare("SELECT si.sale_id, si.product_id, s.customer_id FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.id = ?");
        $item->execute([$sale_item_id]);
        $item = $item->fetch();
        if ($item && (int)$item['customer_id'] === $id) {
            $sale_id = (int)$item['sale_id'];
            $product_id = !empty($item['product_id']) ? (int)$item['product_id'] : null;
            insert('sale_returns', [
                'sale_id' => $sale_id,
                'product_id' => $product_id,
                'customer_id' => $id,
                'return_date' => $ret_date,
                'amount' => $amount,
                'quantity' => $qty,
                'notes' => $notes ?: null,
                'created_by' => (int)($_POST['created_by'] ?? $_SESSION['user_id'] ?? 1),
                'created_at' => date('Y-m-d'),
            ]);
            if ($product_id) {
                $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?")->execute([$qty, $product_id]);
            }
            $_SESSION['success'] = 'Sale return recorded. Product stock restored.';
        } else {
            $_SESSION['error'] = 'Selected product not found for this customer';
        }
    } else {
        $_SESSION['error'] = 'Select a product and enter a valid amount';
    }
    header("Location: view.php?id=$id&tab=returns");
    exit;
}

// Handle Credit Received save (lump-sum payment against a sale / khata)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment'])) {
    $pay_date = $_POST['payment_date'] ?? date('Y-m-d');
    $amount = (float)($_POST['amount'] ?? 0);
    $method = $_POST['payment_method'] ?? 'cash';
    $desc = trim($_POST['notes'] ?? '');
    $sale_id = !empty($_POST['sale_id']) ? (int)$_POST['sale_id'] : 0;
    $bank_account_id = (int)($_POST['bank_account_id'] ?? 0);
    $created_by = (int)($_POST['created_by'] ?? $_SESSION['user_id'] ?? 1);

    if ($amount <= 0 || !$sale_id) {
        $_SESSION['error'] = 'Select a sale and enter a valid amount';
        header("Location: view.php?id=$id&tab=payments");
        exit;
    }

    $sale = getById('sales', $sale_id);
    if (!$sale || (int)$sale['customer_id'] !== $id) {
        $_SESSION['error'] = 'Sale not found for this customer';
        header("Location: view.php?id=$id&tab=payments");
        exit;
    }

    $due = max(0, (float)$sale['due_amount']);
    $apply = min($amount, $due);
    if ($apply <= 0) {
        $_SESSION['error'] = 'This sale has no remaining balance';
        header("Location: view.php?id=$id&tab=payments");
        exit;
    }

    $new_due = round($due - $apply, 2);
    $new_paid = round((float)$sale['paid_amount'] + $apply, 2);
    $new_status = $new_due <= 0 ? 'paid' : 'partial';

    update('sales', [
        'paid_amount' => $new_paid,
        'due_amount' => $new_due,
        'payment_status' => $new_status,
        'updated_at' => date('Y-m-d'),
    ], $sale_id);

    $payment_id = insert('payments', [
        'sale_id' => $sale_id,
        'payment_date' => $pay_date,
        'amount' => $apply,
        'payment_type' => 'credit_payment',
        'payment_method' => $method,
        'notes' => $desc ?: 'Credit received',
        'received_by' => $created_by,
        'created_at' => date('Y-m-d'),
    ]);

    if ($method === 'cash') {
        recordCashInflow($pdo, $pay_date, $apply, 'Credit received - Customer #' . $id, 'payment', $payment_id, $created_by);
    } elseif ($method === 'bank') {
        recordBankInflow($pdo, $pay_date, $apply, 'Credit received (bank) - Customer #' . $id, 'payment', $payment_id, $created_by, $bank_account_id);
    }

    $_SESSION['success'] = 'Payment received for ' . $sale['invoice_no'];
    header("Location: view.php?id=$id&tab=payments");
    exit;
}

// Delete handlers
if (isset($_GET['del_sale'])) {
    $pdo->prepare("DELETE FROM sales WHERE id = ? AND customer_id = ?")->execute([(int)$_GET['del_sale'], $id]);
    $_SESSION['success'] = 'Sale deleted';
    header("Location: view.php?id=$id&tab=overview");
    exit;
}
if (isset($_GET['del_return'])) {
    $del_rid = (int)$_GET['del_return'];
    $del_ret = getById('sale_returns', $del_rid);
    if ($del_ret && (int)$del_ret['customer_id'] === $id) {
        if (!empty($del_ret['product_id'])) {
            $pdo->prepare("UPDATE products SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id = ?")->execute([(int)$del_ret['quantity'], (int)$del_ret['product_id']]);
        }
        $pdo->prepare("DELETE FROM sale_returns WHERE id = ?")->execute([$del_rid]);
    }
    $_SESSION['success'] = 'Return deleted';
    header("Location: view.php?id=$id&tab=returns");
    exit;
}
if (isset($_GET['del_payment'])) {
    $del_id = (int)$_GET['del_payment'];
    $pay = getById('payments', $del_id);
    if ($pay && $pay['sale_id']) {
        $sale = getById('sales', $pay['sale_id']);
        if ($sale && (int)$sale['customer_id'] === $id) {
            $new_paid = max(0, round((float)$sale['paid_amount'] - (float)$pay['amount'], 2));
            $new_due = round((float)$sale['total_amount'] - $new_paid, 2);
            $new_status = $new_due <= 0 ? 'paid' : ($new_paid > 0 ? 'partial' : 'pending');
            update('sales', [
                'paid_amount' => $new_paid,
                'due_amount' => $new_due,
                'payment_status' => $new_status,
                'updated_at' => date('Y-m-d'),
            ], $sale['id']);
        }
    }
    $pdo->prepare("DELETE FROM payments WHERE id = ?")->execute([$del_id]);
    $_SESSION['success'] = 'Payment deleted';
    header("Location: view.php?id=$id&tab=payments");
    exit;
}

// Fetch data
$sales = $pdo->prepare("SELECT s.*, u.username AS added_by FROM sales s LEFT JOIN users u ON u.id = s.created_by WHERE s.customer_id = ? ORDER BY s.sale_date DESC LIMIT 50");
$sales->execute([$id]);
$sales_data = $sales->fetchAll();

$payments = $pdo->prepare("SELECT p.*, s.invoice_no, u.username AS added_by FROM payments p LEFT JOIN sales s ON s.id = p.sale_id LEFT JOIN users u ON u.id = p.received_by WHERE p.sale_id IN (SELECT s2.id FROM (SELECT id FROM sales WHERE customer_id = ?) s2) ORDER BY p.payment_date DESC LIMIT 50");
$payments->execute([$id]);
$payments_data = $payments->fetchAll();

$returns = $pdo->prepare("SELECT sr.*, s.invoice_no, u.username AS added_by, p.name AS product_name, p.color AS product_color, p.storage AS product_storage, p.ram AS product_ram FROM sale_returns sr LEFT JOIN sales s ON s.id = sr.sale_id LEFT JOIN products p ON p.id = sr.product_id LEFT JOIN users u ON u.id = sr.created_by WHERE sr.customer_id = ? ORDER BY sr.return_date DESC LIMIT 50");
$returns->execute([$id]);
$returns_data = $returns->fetchAll();

$return_items = $pdo->prepare("SELECT si.id, si.sale_id, si.product_id, si.item_description, si.price, p.name, p.color, p.storage, p.ram, s.invoice_no FROM sale_items si JOIN sales s ON s.id = si.sale_id LEFT JOIN products p ON p.id = si.product_id WHERE s.customer_id = ? AND s.status = 'active' ORDER BY s.sale_date DESC, si.id DESC");
$return_items->execute([$id]);
$return_items_data = $return_items->fetchAll();

$returnLabel = function($it) {
    if (!empty($it['name'])) {
        $label = $it['name'];
        $specs = array_filter([$it['storage'] ?? '', $it['ram'] ?? '', $it['color'] ?? '']);
        if ($specs) $label .= ' (' . implode(' / ', $specs) . ')';
    } else {
        $label = $it['item_description'] ?? 'Item';
    }
    return $label . ' — ' . ($it['invoice_no'] ?? '#' . $it['sale_id']);
};

// Calculations
$opening_due = (float)$customer['opening_due'];
$opening_paid = (float)$customer['opening_paid'];
$credit_sale = 0;
foreach ($sales_data as $s) {
    if ($s['status'] !== 'cancelled') $credit_sale += (float)$s['total_amount'];
}
$payments_total = array_sum(array_column($payments_data, 'amount'));
$returns_total = array_sum(array_column($returns_data, 'amount'));
$closing = ($opening_due - $opening_paid) + $credit_sale - $returns_total - $payments_total;

// Sales with remaining balance (khata)
$credit_sales = array_filter($sales_data, function($s) {
    return $s['status'] === 'active' && (float)$s['due_amount'] > 0;
});

$sale_items = $pdo->prepare("
    SELECT si.*, p.code, p.name, si.item_description
    FROM sale_items si
    LEFT JOIN products p ON p.id = si.product_id
    WHERE si.sale_id IN (SELECT s2.id FROM (SELECT id FROM sales WHERE customer_id = ? AND status = 'active') s2)
");
$sale_items->execute([$id]);
$sale_items_data = $sale_items->fetchAll();

$sale_items_map = [];
foreach ($sale_items_data as $item) {
    $sale_items_map[$item['sale_id']][] = $item;
}

$bank_accounts = getAll('bank_accounts', 'bank_name ASC, account_name ASC');

// Overview stats
$total_products_qty = 0;
$all_items = $pdo->prepare("
    SELECT SUM(si.quantity) as total_qty FROM sale_items si
    WHERE si.sale_id IN (SELECT s2.id FROM (SELECT id FROM sales WHERE customer_id = ?) s2)
");
$all_items->execute([$id]);
$all_items_data = $all_items->fetch();
$total_products_qty = (int)($all_items_data['total_qty'] ?? 0);
$distinct_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM (
        SELECT si.product_id FROM sale_items si WHERE si.product_id IS NOT NULL
        AND si.sale_id IN (SELECT s2.id FROM (SELECT id FROM sales WHERE customer_id = ?) s2)
        UNION
        SELECT si.item_description FROM sale_items si WHERE si.product_id IS NULL AND si.item_description IS NOT NULL AND si.item_description != ''
        AND si.sale_id IN (SELECT s2.id FROM (SELECT id FROM sales WHERE customer_id = ?) s2)
    ) d
");
$distinct_stmt->execute([$id, $id]);
$total_distinct_products = (int)$distinct_stmt->fetchColumn();

// Products purchased list with names (including free-text items)
$products_purchased = $pdo->prepare("
    SELECT name, code, product_type, qty FROM (
        SELECT p.name, p.code, p.product_type, SUM(si.quantity) AS qty, 0 AS sort
        FROM sale_items si
        JOIN products p ON si.product_id = p.id
        WHERE si.sale_id IN (SELECT id FROM sales WHERE customer_id = ?)
        GROUP BY si.product_id
        UNION ALL
        SELECT si.item_description AS name, '' AS code, 'general' AS product_type, SUM(si.quantity) AS qty, 1 AS sort
        FROM sale_items si
        WHERE si.product_id IS NULL AND si.item_description IS NOT NULL AND si.item_description != ''
        AND si.sale_id IN (SELECT id FROM sales WHERE customer_id = ?)
        GROUP BY si.item_description
    ) combined ORDER BY qty DESC
");
$products_purchased->execute([$id, $id]);
$products_purchased = $products_purchased->fetchAll();

// Build customer ledger (unified running balance)
$customer_ledger = [];
$net_opening = $opening_due - $opening_paid;
foreach ($sales_data as $s) {
    if ($s['status'] === 'cancelled') continue;
    $customer_ledger[] = [
        'date' => $s['sale_date'],
        'type' => 'sale',
        'ref' => $s['invoice_no'] ?? '#' . $s['id'],
        'debit' => (float)$s['total_amount'],
        'credit' => 0,
        'desc' => 'Sale',
    ];
}
foreach ($payments_data as $p) {
    $desc = 'Payment received';
    if ($p['notes']) $desc .= ' (' . $p['notes'] . ')';
    $customer_ledger[] = [
        'date' => $p['payment_date'],
        'type' => 'payment',
        'ref' => $p['invoice_no'] ?? '#' . $p['id'],
        'debit' => 0,
        'credit' => (float)$p['amount'],
        'desc' => $desc,
    ];
}
foreach ($returns_data as $r) {
    $customer_ledger[] = [
        'date' => $r['return_date'],
        'type' => 'return',
        'ref' => $r['invoice_no'] ?? '#' . $r['id'],
        'debit' => 0,
        'credit' => (float)$r['amount'],
        'desc' => $r['notes'] ?? 'Sale return',
    ];
}
usort($customer_ledger, function($a, $b) { return strcmp($a['date'], $b['date']); });

$tab = $_GET['tab'] ?? 'overview';

// Handle CSV download
if (isset($_GET['download']) && $_GET['download'] === 'csv') {
    $dl_tab = $_GET['tab'] ?? 'returns';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customer_' . $id . '_' . $dl_tab . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($dl_tab === 'returns') {
        fputcsv($out, ['Date', 'Invoice ID', 'Product', 'Qty', 'Amount', 'Notes', 'By']);
        foreach ($returns_data as $r) {
            $pname = $r['product_name'] ? ($r['product_name'] . (($r['product_color'] ?? '') ? ' (' . trim(implode(' / ', array_filter([$r['product_storage'], $r['product_ram'], $r['product_color']]))) . ')' : '')) : '-';
            fputcsv($out, [$r['return_date'], $r['invoice_no'] ?? '-', $pname, $r['quantity'], $r['amount'], $r['notes'] ?? '-', $r['added_by'] ?? '-']);
        }
    } elseif ($dl_tab === 'payments') {
        fputcsv($out, ['Date', 'Invoice ID', 'Amount', 'Method', 'By']);
        foreach ($payments_data as $r) {
            fputcsv($out, [$r['payment_date'], $r['invoice_no'] ?? '#' . $r['id'], $r['amount'], ucfirst($r['payment_method']), $r['added_by'] ?? '-']);
        }
    }
    fclose($out);
    exit;
}

require_once '../../includes/header.php';
?>

<style>
.status-badge { font-size: .75rem; padding: 3px 10px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="m-0 font-weight-bold" style="color:#0f172a;"><i class="fas fa-user"></i> <?= htmlspecialchars($customer['full_name']) ?> <small class="text-muted">[<?= htmlspecialchars($customer['customer_no']) ?>]</small></h5>
  <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<div class="alert alert-light border small py-2 mb-4">
  <i class="fas fa-phone text-primary mr-1"></i> <?= htmlspecialchars($customer['phone'] ?? '-') ?>
  <span class="mx-3 text-muted">|</span>
  <i class="fas fa-id-card text-primary mr-1"></i> <?= htmlspecialchars($customer['cnic'] ?? '-') ?>
  <span class="mx-3 text-muted">|</span>
  <i class="fas fa-map-marker-alt text-primary mr-1"></i> <?= htmlspecialchars($customer['address'] ?: ($customer['city'] ?? '-')) ?>
</div>

<ul class="nav nav-tabs mb-4" id="customerTabs">
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'overview' ? 'active' : '' ?>" href="view.php?id=<?= $id ?>&tab=overview"><i class="fas fa-chart-pie"></i> Overview</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'returns' ? 'active' : '' ?>" href="view.php?id=<?= $id ?>&tab=returns"><i class="fas fa-undo"></i> Sale Return</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'payments' ? 'active' : '' ?>" href="view.php?id=<?= $id ?>&tab=payments"><i class="fas fa-hand-holding-usd"></i> Credit Received</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'ledger' ? 'active' : '' ?>" href="view.php?id=<?= $id ?>&tab=ledger"><i class="fas fa-balance-scale"></i> Ledger</a>
  </li>
</ul>

<?php if ($tab === 'overview'): ?>
<div class="row">
  <div class="col-lg-12 mb-4">
    <!-- Financial Summary -->
    <div class="card shadow mb-4">
      <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-calculator"></i> Financial Summary</h6>
        <button class="btn btn-sm btn-outline-primary" onclick="$('#editFinancials').toggleClass('d-none')"><i class="fas fa-pen"></i> Edit</button>
      </div>
      <div class="card-body">
        <div id="editFinancials" class="d-none mb-4 p-3 bg-light rounded border">
          <form method="post">
            <div class="form-row">
              <div class="form-group col-md-6 mb-2">
                <label class="small text-muted">Opening Due</label>
                <input type="number" name="opening_due" class="form-control form-control-sm" step="0.01" value="<?= $customer['opening_due'] ?>">
              </div>
              <div class="form-group col-md-6 mb-2">
                <label class="small text-muted">Opening Paid</label>
                <input type="number" name="opening_paid" class="form-control form-control-sm" step="0.01" value="<?= $customer['opening_paid'] ?>">
              </div>
            </div>
            <button type="submit" name="update_financials" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Update</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="$('#editFinancials').addClass('d-none')">Cancel</button>
          </form>
        </div>
        <div class="row">
          <div class="col-md-6">
            <table class="table table-sm table-borderless mb-0" style="font-size:.9rem;">
              <tr><td><i class="fas fa-coins text-primary fa-fw mr-2"></i> Opening Due</td><td class="text-right font-weight-bold"><?= formatCurrency($opening_due) ?></td></tr>
              <tr><td><i class="fas fa-check-circle text-success fa-fw mr-2"></i> Opening Paid</td><td class="text-right font-weight-bold text-success">- <?= formatCurrency($opening_paid) ?></td></tr>
              <tr><td><i class="fas fa-balance-scale fa-fw mr-2"></i> <strong>Net Opening</strong></td><td class="text-right font-weight-bold"><?= formatCurrency($opening_due - $opening_paid) ?></td></tr>
              <tr><td colspan="2"><hr class="my-1"></td></tr>
              <tr><td><i class="fas fa-shopping-cart text-info fa-fw mr-2"></i> Total Sales</td><td class="text-right font-weight-bold">+ <?= formatCurrency($credit_sale) ?></td></tr>
              <tr><td><i class="fas fa-undo text-danger fa-fw mr-2"></i> Sale Return</td><td class="text-right font-weight-bold text-danger">- <?= formatCurrency($returns_total) ?></td></tr>
              <tr><td><i class="fas fa-hand-holding-usd text-warning fa-fw mr-2"></i> Credit Received</td><td class="text-right font-weight-bold text-success">- <?= formatCurrency($payments_total) ?></td></tr>
            </table>
          </div>
          <div class="col-md-6">
            <table class="table table-sm table-borderless mb-0" style="font-size:.9rem;">
              <tr><td><i class="fas fa-shopping-cart text-primary fa-fw mr-2"></i> Total Sales</td><td class="text-right font-weight-bold"><?= count($sales_data) ?></td></tr>
              <tr><td><i class="fas fa-file-invoice-dollar text-warning fa-fw mr-2"></i> Credit (Khata) Sales</td><td class="text-right font-weight-bold text-warning"><?= count($credit_sales) ?></td></tr>
              <tr><td><i class="fas fa-money-bill-wave text-success fa-fw mr-2"></i> Payments</td><td class="text-right font-weight-bold text-success"><?= count($payments_data) ?></td></tr>
              <tr><td><i class="fas fa-undo text-danger fa-fw mr-2"></i> Returns</td><td class="text-right font-weight-bold text-danger"><?= count($returns_data) ?></td></tr>
              <tr><td colspan="2"><hr class="my-1"></td></tr>
              <tr><td><i class="fas fa-boxes text-info fa-fw mr-2"></i> Products Purchased</td><td class="text-right font-weight-bold"><?= $total_products_qty ?> (<?= $total_distinct_products ?> kinds)</td></tr>
            </table>
          </div>
        </div>
        <div class="text-center p-3 bg-primary text-white rounded mt-3">
          <div class="small text-uppercase opacity-75">Closing Balance</div>
          <h3 class="font-weight-bold mb-0"><?= formatCurrency($closing) ?></h3>
        </div>
      </div>
    </div>

    <!-- Recent Sales -->
    <div class="card shadow">
      <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-shopping-cart"></i> Recent Sales</h6>
      </div>
      <div class="card-body">
        <?php if (empty($sales_data)): ?>
          <p class="text-muted mb-0 text-center">No sales yet</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-bordered">
              <thead class="thead-light">
                <tr>
                  <th>Invoice</th>
                  <th>Date</th>
                  <th class="text-right">Total</th>
                  <th class="text-right">Paid</th>
                  <th class="text-right">Due</th>
                  <th class="text-center">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_slice($sales_data, 0, 10) as $s):
                  $pstat = $s['payment_status'];
                  $badge = $pstat === 'paid' ? 'success' : ($pstat === 'partial' ? 'warning' : 'danger');
                ?>
                  <tr>
                    <td><a href="<?= $base_url ?>modules/sales/invoice.php?id=<?= $s['id'] ?>"><span class="badge badge-secondary"><?= htmlspecialchars($s['invoice_no']) ?></span></a></td>
                    <td class="text-nowrap"><?= formatDate($s['sale_date']) ?></td>
                    <td class="text-right font-weight-bold"><?= formatCurrency($s['total_amount']) ?></td>
                    <td class="text-right text-success"><?= formatCurrency($s['paid_amount']) ?></td>
                    <td class="text-right text-danger font-weight-bold"><?= formatCurrency($s['due_amount']) ?></td>
                    <td class="text-center"><span class="badge badge-<?= $badge ?>"><?= ucfirst($pstat) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Products Purchased -->
    <div class="card shadow mt-4">
      <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-success"><i class="fas fa-boxes"></i> Products Purchased (<?=$total_distinct_products?> kinds)</h6>
      </div>
      <div class="card-body">
        <?php if (empty($products_purchased)): ?>
          <p class="text-muted mb-0 text-center">No products purchased</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
              <thead class="thead-light">
                <tr><th>#</th><th>Product</th><th>Code</th><th>Type</th><th class="text-center">Qty</th></tr>
              </thead>
              <tbody>
                <?php $i = 1; foreach ($products_purchased as $pp): ?>
                  <tr>
                    <td><?=$i++?></td>
                    <td class="font-weight-bold"><?=htmlspecialchars($pp['name'])?></td>
                    <td><span class="badge badge-secondary"><?=htmlspecialchars($pp['code'])?></span></td>
                    <td><span class="badge badge-info"><?=ucfirst($pp['product_type'])?></span></td>
                    <td class="text-center font-weight-bold"><?=(int)$pp['qty']?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php elseif ($tab === 'returns'): ?>
<div class="row">
  <div class="col-lg-5 mb-4">
    <div class="card shadow">
      <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-danger"><i class="fas fa-plus-circle"></i> Add Sale Return</h6></div>
      <div class="card-body">
        <?php if (empty($return_items_data)): ?>
          <p class="text-muted mb-0 text-center py-3">No products found from this customer's sales.</p>
        <?php else: ?>
        <form method="post">
          <div class="form-group">
            <label class="small">Date</label>
            <input type="text" name="return_date" class="form-control datepicker" value="<?= date('Y-m-d') ?>" required autocomplete="off">
          </div>
          <div class="form-group">
            <label class="small">Product <span class="text-danger">*</span></label>
            <select name="sale_item_id" id="returnItem" class="form-control" required onchange="fillReturnAmount(this)">
              <option value="">-- Select product to return --</option>
              <?php foreach ($return_items_data as $ri): ?>
                <option value="<?= $ri['id'] ?>" data-price="<?= $ri['price'] ?>"><?= htmlspecialchars($returnLabel($ri)) ?> — <?= formatCurrency($ri['price']) ?></option>
              <?php endforeach; ?>
            </select>
            <small class="text-muted">Only products from this customer's active sales are shown.</small>
          </div>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label class="small">Qty</label>
              <input type="number" name="quantity" id="returnQty" class="form-control" value="1" min="1" oninput="fillReturnAmount(document.getElementById('returnItem'))">
            </div>
            <div class="form-group col-md-6">
              <label class="small">Amount <span class="text-danger">*</span></label>
              <input type="number" name="amount" id="returnAmount" class="form-control" step="0.01" min="0.01" required>
            </div>
          </div>
          <div class="form-group">
            <label class="small">Notes</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Reason for return"></textarea>
          </div>
          <div class="form-group">
            <label class="small">By</label>
            <select name="created_by" class="form-control">
              <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= ($u['id'] == ($_SESSION['user_id'] ?? 1)) ? 'selected' : '' ?>><?= htmlspecialchars($u['username']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" name="save_return" class="btn btn-danger btn-block py-2"><i class="fas fa-save"></i> Save Return</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-7 mb-4">
    <div class="card shadow">
      <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-danger"><i class="fas fa-undo"></i> Sale Return History</h6>
        <div>
          <span class="badge badge-danger status-badge mr-2">Total: <?= formatCurrency($returns_total) ?></span>
          <a href="view.php?id=<?= $id ?>&tab=returns&download=csv" class="btn btn-sm btn-success"><i class="fas fa-download"></i> Download</a>
        </div>
      </div>
      <div class="card-body">
        <?php if (empty($returns_data)): ?>
          <p class="text-muted mb-0 text-center">No returns yet</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-bordered table-hover table-sm">
              <thead class="thead-light">
                <tr><th>Date</th><th>Invoice ID</th><th>Product</th><th class="text-center">Qty</th><th class="text-right">Amount</th><th>Notes</th><th>By</th><th>Action</th></tr>
              </thead>
              <tbody>
                <?php foreach ($returns_data as $r):
                  $pname = !empty($r['product_name']) ? ($r['product_name'] . (($r['product_color'] ?? '') ? ' (' . trim(implode(' / ', array_filter([$r['product_storage'], $r['product_ram'], $r['product_color']]))) . ')' : '')) : '-';
                ?>
                  <tr>
                    <td><?= formatDate($r['return_date']) ?></td>
                    <td><span class="badge badge-secondary"><?= htmlspecialchars($r['invoice_no'] ?? '-') ?></span></td>
                    <td><?= htmlspecialchars($pname) ?></td>
                    <td class="text-center"><?= (int)$r['quantity'] ?></td>
                    <td class="text-right font-weight-bold text-danger"><?= formatCurrency($r['amount']) ?></td>
                    <td><?= htmlspecialchars($r['notes'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r['added_by'] ?? '-') ?></td>
                    <td><a href="view.php?id=<?= $id ?>&tab=returns&del_return=<?= $r['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
    </div>
</div>

<script>
function fillReturnAmount(sel) {
    var opt = sel.options[sel.selectedIndex];
    var price = opt && opt.dataset.price ? (parseFloat(opt.dataset.price) || 0) : 0;
    var qty = parseFloat(document.getElementById('returnQty').value) || 1;
    document.getElementById('returnAmount').value = (price * qty).toFixed(2);
}
</script>

<?php elseif ($tab === 'payments'): ?>
<div class="row">
  <!-- LEFT: Receive Credit Payment -->
  <div class="col-lg-5 mb-4">
    <div class="card shadow h-100">
      <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-success"><i class="fas fa-hand-holding-usd"></i> Receive Credit Payment</h6>
      </div>
      <div class="card-body">
        <?php if (empty($credit_sales)): ?>
          <p class="text-muted mb-0 text-center py-3">No outstanding credit (khata) for this customer.</p>
        <?php else: ?>
        <form method="post" id="paymentForm">
          <div class="form-group mb-3">
            <label class="small font-weight-bold text-muted">Select Sale</label>
            <select name="sale_id" id="saleSelector" class="form-control" required>
              <option value="">-- Choose a sale --</option>
              <?php foreach ($credit_sales as $s): ?>
                <option value="<?= $s['id'] ?>" data-items='<?= htmlspecialchars(json_encode($sale_items_map[$s['id']] ?? [])) ?>'
                  data-total="<?= $s['total_amount'] ?>" data-paid="<?= $s['paid_amount'] ?>" data-due="<?= $s['due_amount'] ?>">
                  #<?= htmlspecialchars($s['invoice_no']) ?> — Due: <?= formatCurrency($s['due_amount']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div id="saleDetail" style="display:none;">
            <div class="bg-light rounded p-2 mb-2 border">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="small font-weight-bold text-muted">Purchased Items</span>
                <span class="small font-weight-bold" id="detailSaleLabel"></span>
              </div>
              <div id="detailItems" class="small"></div>
            </div>

            <div class="card border-left-success mb-3">
              <div class="card-body py-3">
                <div class="d-flex justify-content-between small">
                  <span class="text-muted">Invoice Total</span>
                  <span class="font-weight-bold" id="detailTotal">0.00</span>
                </div>
                <div class="d-flex justify-content-between small">
                  <span class="text-muted">Already Paid</span>
                  <span class="font-weight-bold text-success" id="detailPaid">0.00</span>
                </div>
                <hr class="my-2">
                <div class="d-flex justify-content-between align-items-center">
                  <span class="h5 mb-0 font-weight-bold">Balance Due</span>
                  <span class="h4 mb-0 font-weight-bold text-danger" id="detailDue">0.00</span>
                </div>
              </div>
            </div>

            <hr class="my-2">
            <h6 class="font-weight-bold text-success mb-2"><i class="fas fa-money-bill-wave"></i> Record Payment</h6>
            <div class="row">
              <div class="col-6">
                <div class="form-group mb-2">
                  <label class="small text-muted">Date</label>
                  <input type="text" name="payment_date" class="form-control datepicker" value="<?= date('Y-m-d') ?>" required autocomplete="off">
                </div>
              </div>
              <div class="col-6">
                <div class="form-group mb-2">
                  <label class="small text-muted">Method</label>
                  <select name="payment_method" class="form-control payment-method-select" required>
                    <option value="cash">Cash</option>
                    <option value="bank">Bank</option>
                  </select>
                </div>
              </div>
            </div>
            <div class="bank-account-row" style="display:none;">
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
            <div class="form-group mb-2">
              <label class="small text-muted">Payment Amount <span class="text-danger">*</span></label>
              <input type="number" name="amount" id="payAmount" class="form-control form-control-lg font-weight-bold" step="0.01" min="0.01" required>
              <small class="text-muted" id="newRemainingHint" style="display:none;">Remaining after this: <span class="font-weight-bold text-info" id="newRemainingAmount">0.00</span></small>
            </div>
            <div class="row">
              <div class="col-6">
                <div class="form-group mb-2">
                  <label class="small text-muted">Notes</label>
                  <textarea name="notes" class="form-control" rows="1" placeholder="Optional notes"></textarea>
                </div>
              </div>
              <div class="col-6">
                <div class="form-group mb-2">
                  <label class="small text-muted">Recorded By</label>
                  <select name="created_by" class="form-control" required>
                    <?php foreach ($users as $u): ?>
                      <option value="<?= $u['id'] ?>" <?= ($u['id'] == ($_SESSION['user_id'] ?? 1)) ? 'selected' : '' ?>><?= htmlspecialchars($u['username']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
            <button type="submit" name="save_payment" class="btn btn-success btn-block py-2 font-weight-bold"><i class="fas fa-save"></i> Record Payment</button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- RIGHT: Payment History -->
  <div class="col-lg-7 mb-4">
    <div class="card shadow">
      <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-history"></i> Payment History</h6>
        <span class="badge badge-success" style="font-size:0.85rem;padding:6px 12px;">Total Received: <?= formatCurrency($payments_total) ?></span>
      </div>
      <div class="card-body">
        <?php if (empty($payments_data)): ?>
          <p class="text-muted mb-0 text-center py-3">No payments received</p>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-bordered table-hover table-sm mb-0">
            <thead class="thead-light">
              <tr>
                <th>Date</th>
                <th>Invoice</th>
                <th class="text-right">Amount</th>
                <th>Method</th>
                <th>Notes</th>
                <th>By</th>
                <th class="text-center">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($payments_data as $p): ?>
                <tr>
                  <td><?= formatDate($p['payment_date']) ?></td>
                  <td><a href="<?= $base_url ?>modules/sales/invoice.php?id=<?= $p['sale_id'] ?>"><span class="badge badge-secondary"><?= htmlspecialchars($p['invoice_no']) ?></span></a></td>
                  <td class="text-right text-success font-weight-bold"><?= formatCurrency($p['amount']) ?></td>
                  <td><span class="badge badge-<?= $p['payment_method'] === 'cash' ? 'success' : 'info' ?>"><?= ucfirst($p['payment_method']) ?></span></td>
                  <td class="small"><?= htmlspecialchars($p['notes'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($p['added_by'] ?? '-') ?></td>
                  <td class="text-center">
                    <a href="<?= $base_url ?>modules/payments/receipt.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-info" title="Receipt"><i class="fas fa-eye"></i></a>
                    <a href="<?= $base_url ?>modules/payments/receipt.php?id=<?= $p['id'] ?>&print=1" class="btn btn-sm btn-secondary" title="Print" target="_blank"><i class="fas fa-print"></i></a>
                    <a href="view.php?id=<?= $id ?>&tab=payments&del_payment=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this payment?')"><i class="fas fa-trash"></i></a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
$(document).on('change', '.payment-method-select', function() {
    $(this).closest('form').find('.bank-account-row').toggle($(this).val() === 'bank');
});

$(document).ready(function() {
    function loadSaleDetail(saleId) {
        var opt = $('#saleSelector option[value="' + saleId + '"]');
        if (!opt.length) { $('#saleDetail').hide(); return; }

        var items = opt.data('items') || [];
        var label = opt.text().trim();

        $('#detailSaleLabel').text(label);

        var itemsHtml = '';
        if (items.length) {
            items.forEach(function(it) {
                var itemName = it.item_description || it.name || 'Item';
                itemsHtml += '<div>' + itemName + ' × ' + it.quantity + '</div>';
            });
        }
        $('#detailItems').html(itemsHtml || '<span class="text-muted">No items</span>');

        $('#detailTotal').text(parseFloat(opt.data('total') || 0).toFixed(2));
        $('#detailPaid').text(parseFloat(opt.data('paid') || 0).toFixed(2));
        var due = parseFloat(opt.data('due') || 0);
        $('#detailDue').text(due.toFixed(2));
        $('#payAmount').val(due.toFixed(2)).trigger('input');
        $('#saleDetail').show();
    }

    $('#saleSelector').on('change', function() {
        var val = $(this).val();
        if (val) loadSaleDetail(val);
        else $('#saleDetail').hide();
    });

    $('#payAmount').on('input', function() {
        var dueAmt = parseFloat($('#detailDue').text()) || 0;
        var entered = parseFloat($(this).val()) || 0;
        var newRem = Math.max(0, dueAmt - entered);
        if (entered > 0) {
            $('#newRemainingHint').show();
            $('#newRemainingAmount').text(newRem.toFixed(2));
        } else {
            $('#newRemainingHint').hide();
        }
    });
});
</script>
<style>
.summary-divider { border-top:1px dashed #d1d3e2; margin:4px 0; }
.border-left-success { border-left: 4px solid #1cc88a !important; }
</style>

<?php elseif ($tab === 'ledger'): ?>
<div class="card shadow mb-4">
  <div class="card-header py-3 d-flex justify-content-between align-items-center">
    <h6 class="m-0 font-weight-bold text-info"><i class="fas fa-balance-scale"></i> Customer Ledger</h6>
    <div>
      <a href="ledger_print.php?id=<?= $id ?>" target="_blank" class="btn btn-sm btn-danger mr-2"><i class="fas fa-file-pdf"></i> PDF</a>
      <span class="badge badge-primary status-badge mr-2">Opening: <?= formatCurrency($net_opening) ?></span>
      <span class="badge badge-success status-badge mr-2">Closing: <?= formatCurrency($closing) ?></span>
    </div>
  </div>
  <div class="card-body">
    <?php if (empty($customer_ledger)): ?>
      <p class="text-muted mb-0 text-center py-3">No transactions found for this customer.</p>
    <?php else: ?>
      <div class="row mb-3">
        <div class="col-md-6">
          <input type="text" id="ledgerSearch" class="form-control" placeholder="Search by date, ref, description...">
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm" id="ledgerTable">
          <thead class="thead-light">
          <tr class="table-secondary">
              <td colspan="5" class="text-right font-weight-bold">Opening Balance</td>
              <td class="text-right font-weight-bold"><?= formatCurrency($net_opening) ?></td>
            </tr>

          <tr>
              <th>Date</th>
              <th>Ref</th>
              <th>Description</th>
              <th class="text-right">Debit</th>
              <th class="text-right">Credit</th>
              <th class="text-right">Balance</th>
            </tr>
          </thead>
          <tbody>

            <?php $bal = $net_opening; $total_debit = 0; $total_credit = 0; foreach ($customer_ledger as $l):
              $bal += $l['debit'] - $l['credit'];
              $total_debit += $l['debit'];
              $total_credit += $l['credit'];
            ?>
              <tr class="ledger-row <?= $l['type'] === 'payment' || $l['type'] === 'return' ? 'table-success' : '' ?>">
                <td class="text-nowrap"><?= formatDate($l['date']) ?></td>
                <td><span class="badge badge-<?= $l['type'] === 'sale' ? 'primary' : ($l['type'] === 'return' ? 'danger' : 'success') ?>"><?= htmlspecialchars($l['ref']) ?></span></td>
                <td class="small"><?= htmlspecialchars($l['desc']) ?></td>
                <td class="text-right"><?= $l['debit'] ? formatCurrency($l['debit']) : '-' ?></td>
                <td class="text-right"><?= $l['credit'] ? formatCurrency($l['credit']) : '-' ?></td>
                <td class="text-right font-weight-bold"><?= formatCurrency($bal) ?></td>
              </tr>
            <?php endforeach; ?>
            <tr class="no-results" style="display:none;"><td colspan="6" class="text-center text-muted">No matching records</td></tr>
          </tbody>
          <tfoot>
            <tr class="font-weight-bold" style="background:#f8f9fc;">
              <td colspan="3" class="text-right">Totals</td>
              <td class="text-right"><?= formatCurrency($total_debit) ?></td>
              <td class="text-right"><?= formatCurrency($total_credit) ?></td>
              <td class="text-right"><?= formatCurrency($closing) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
$(document).ready(function() {
  $('#ledgerSearch').on('keyup', function() {
    var val = $(this).val().toLowerCase();
    var visible = 0;
    $('#ledgerTable tbody tr.ledger-row').each(function() {
      var text = $(this).text().toLowerCase();
      var match = text.indexOf(val) > -1;
      $(this).toggle(match);
      if (match) visible++;
    });
    $('.no-results').toggle(visible === 0);
  });
});
</script>

<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
