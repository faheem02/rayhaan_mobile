<?php
session_start();
$page_title = 'Edit Purchase';
$base_url = '../../';
require_once '../../includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$purchase = getById('purchases', $id);
if (!$purchase) {
    $_SESSION['error'] = 'Purchase not found.';
    header("Location: purchases.php");
    exit;
}

$suppliers = getAll('suppliers', 'name ASC');
$bank_accounts = getAll('bank_accounts', 'bank_name ASC, account_name ASC');
$brands = getAll('brands', 'name ASC');

function serialStats($pdo, $purchaseId, $productId) {
    $st = $pdo->prepare("SELECT status, COALESCE(NULLIF(imei_number,''), NULLIF(serial_number,''),'') AS val FROM product_serials WHERE purchase_id = ? AND product_id = ?");
    $st->execute([$purchaseId, $productId]);
    $rows = $st->fetchAll();
    $stats = ['total' => count($rows), 'filled' => 0, 'available' => 0, 'sold' => 0];
    foreach ($rows as $r) {
        if ($r['val'] !== '') $stats['filled']++;
        if ($r['status'] === 'available') $stats['available']++;
        if ($r['status'] === 'sold') $stats['sold']++;
    }
    return $stats;
}

function reverseRef($pdo, $type, $refId) {
    $st = $pdo->prepare("SELECT * FROM cash_book WHERE reference_type = ? AND reference_id = ? AND transaction_type = 'outflow'");
    $st->execute([$type, $refId]);
    foreach ($st->fetchAll() as $r) {
        $pdo->prepare("UPDATE cash_book_daily SET total_outflow = GREATEST(0, total_outflow - ?), closing_balance = opening_balance + total_inflow - total_outflow WHERE id = ?")->execute([$r['amount'], $r['daily_id']]);
        $pdo->prepare("DELETE FROM cash_book WHERE id = ?")->execute([$r['id']]);
    }
    $st = $pdo->prepare("SELECT * FROM bank_transactions WHERE reference_type = ? AND reference_id = ? AND transaction_type = 'withdrawal'");
    $st->execute([$type, $refId]);
    foreach ($st->fetchAll() as $r) {
        $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$r['amount'], $r['bank_account_id']]);
        $pdo->prepare("DELETE FROM bank_transactions WHERE id = ?")->execute([$r['id']]);
    }
}

function reversePurchaseOutflows($pdo, $purchaseId, $supplierId, $invoiceNo, $purchaseDate) {
    reverseRef($pdo, 'purchase', $purchaseId);
    if ($supplierId) {
        $descs = ['Purchase payment'];
        if ($invoiceNo) $descs[] = "Purchase payment - Invoice $invoiceNo";
        $ph = implode(',', array_fill(0, count($descs), '?'));
        $st = $pdo->prepare("SELECT id FROM supplier_payments WHERE supplier_id = ? AND payment_date = ? AND description IN ($ph)");
        $st->execute(array_merge([$supplierId, $purchaseDate], $descs));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            reverseRef($pdo, 'supplier_payment', (int)$pid);
            $pdo->prepare("DELETE FROM supplier_payments WHERE id = ?")->execute([(int)$pid]);
        }
    }
}

function purchasePaymentInfo($pdo, $purchase) {
    $info = ['method' => 'cash', 'bank_account_id' => 0, 'paid' => (float)$purchase['paid_amount']];
    $st = $pdo->prepare("SELECT COUNT(*) FROM cash_book WHERE reference_type = 'purchase' AND reference_id = ? AND transaction_type = 'outflow'");
    $st->execute([$purchase['id']]);
    if ($st->fetchColumn() > 0) $info['method'] = 'cash';
    $st = $pdo->prepare("SELECT bank_account_id FROM bank_transactions WHERE reference_type = 'purchase' AND reference_id = ? AND transaction_type = 'withdrawal' LIMIT 1");
    $st->execute([$purchase['id']]);
    $ba = $st->fetchColumn();
    if ($ba !== false && $ba !== null && $ba !== '') { $info['method'] = 'bank'; $info['bank_account_id'] = (int)$ba; }
    if ($purchase['supplier_id']) {
        $descs = ['Purchase payment'];
        if ($purchase['invoice_no']) $descs[] = "Purchase payment - Invoice {$purchase['invoice_no']}";
        $ph = implode(',', array_fill(0, count($descs), '?'));
        $st = $pdo->prepare("SELECT payment_method, bank_account_id FROM supplier_payments WHERE supplier_id = ? AND payment_date = ? AND description IN ($ph) LIMIT 1");
        $st->execute(array_merge([$purchase['supplier_id'], $purchase['purchase_date']], $descs));
        $sp = $st->fetch();
        if ($sp) {
            $info['method'] = (in_array($sp['payment_method'], ['bank', 'bank_transfer'], true)) ? 'bank' : 'cash';
            $info['bank_account_id'] = (int)($sp['bank_account_id'] ?? 0);
        }
    }
    return $info;
}

$pay_info = purchasePaymentInfo($pdo, $purchase);

// ------------------------------------------------------------
// Single Save handler - updates everything at once
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supType = $_POST['supplier_type'] ?? 'supplier';
    $new_supplier_id = $supType === 'supplier' ? ((int)($_POST['supplier_id'] ?? 0) ?: null) : null;
    $person_name = $supType === 'person' ? trim($_POST['person_name'] ?? '') : '';
    $person_phone = $supType === 'person' ? trim($_POST['person_phone'] ?? '') : '';
    $person_cnic = $supType === 'person' ? trim($_POST['person_cnic'] ?? '') : '';
    $person_address = $supType === 'person' ? trim($_POST['person_address'] ?? '') : '';
    $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
    $invoice_no = trim($_POST['invoice_no'] ?? '');
    $status = $_POST['status'] ?? 'received';
    $notes = trim($_POST['notes'] ?? '');
    $user_id = $_SESSION['user_id'] ?? 1;

    $old_supplier_id = (int)$purchase['supplier_id'];
    $old_invoice = $purchase['invoice_no'];
    $old_date = $purchase['purchase_date'];

    if ($supType === 'person' && !$person_name) {
        $_SESSION['error'] = 'Enter the person name for this purchase.';
        header("Location: purchase_edit.php?id=$id");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1) Header
        $pdo->prepare("UPDATE purchases SET supplier_id=?, invoice_no=?, purchase_date=?, notes=?, status=?, person_name=?, person_phone=?, person_cnic=?, person_address=?, updated_at=NOW() WHERE id=?")
            ->execute([$new_supplier_id, $invoice_no, $purchase_date, $notes, $status, $person_name ?: null, $person_phone ?: null, $person_cnic ?: null, $person_address ?: null, $id]);

        // 2) Delete marked items
        foreach (($_POST['remove_item'] ?? []) as $rid) {
            $rid = (int)$rid;
            $st = $pdo->prepare("SELECT pi.*, p.name AS pname FROM purchase_items pi JOIN products p ON p.id = pi.product_id WHERE pi.id = ? AND pi.purchase_id = ?");
            $st->execute([$rid, $id]);
            $row = $st->fetch();
            if (!$row) continue;
            $ss = serialStats($pdo, $id, $row['product_id']);
            if ($ss['sold'] > 0) {
                throw new Exception("Cannot remove item '{$row['pname']}' - it has sold units.");
            }
            $pdo->prepare("UPDATE products SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id = ?")->execute([$row['quantity'], $row['product_id']]);
            $pdo->prepare("DELETE FROM product_serials WHERE purchase_id = ? AND product_id = ?")->execute([$id, $row['product_id']]);
            $pdo->prepare("DELETE FROM purchase_items WHERE id = ?")->execute([$rid]);
        }

        // 3) Update existing items (qty / price) + reconcile serials
        foreach (($_POST['item_id'] ?? []) as $k => $iid) {
            $iid = (int)$iid;
            $st = $pdo->prepare("SELECT pi.*, p.name AS pname FROM purchase_items pi JOIN products p ON p.id = pi.product_id WHERE pi.id = ? AND pi.purchase_id = ?");
            $st->execute([$iid, $id]);
            $old = $st->fetch();
            if (!$old) continue;

            $new_price = max(0, (float)($_POST['item_price'][$k] ?? 0));
            $new_qty = max(1, (int)($_POST['item_qty'][$k] ?? 1));

            if ($new_qty !== (int)$old['quantity']) {
                $ss = serialStats($pdo, $id, $old['product_id']);
                if ($ss['total'] > 0) {
                    if ($new_qty > $old['quantity']) {
                        $add = $new_qty - $old['quantity'];
                        for ($i = 0; $i < $add; $i++) {
                            $pdo->prepare("INSERT INTO product_serials (product_id, imei_number, serial_number, purchase_id, status, purchase_price, notes, created_at, updated_at) VALUES (?, NULL, NULL, ?, 'available', ?, '', ?, ?)")
                                ->execute([$old['product_id'], $id, $old['purchase_price'], date('Y-m-d'), date('Y-m-d')]);
                        }
                    } else {
                        $need = $old['quantity'] - $new_qty;
                        $removable = $ss['available'];
                        if ($need > $removable) {
                            throw new Exception("Cannot reduce quantity of '{$old['pname']}' below " . ($old['quantity'] - $removable) . " because its units are sold or already used.");
                        }
                        $st = $pdo->prepare("SELECT id, (COALESCE(NULLIF(imei_number,''), NULLIF(serial_number,''),'') <> '') AS filled FROM product_serials WHERE purchase_id = ? AND product_id = ? AND status = 'available' ORDER BY filled ASC, id ASC");
                        $st->execute([$id, $old['product_id']]);
                        $rows = $st->fetchAll();
                        for ($i = 0; $i < $need; $i++) {
                            $pdo->prepare("DELETE FROM product_serials WHERE id = ?")->execute([$rows[$i]['id']]);
                        }
                    }
                }
            }

            $new_subtotal = $new_qty * $new_price;
            $pdo->prepare("UPDATE purchase_items SET quantity=?, purchase_price=?, subtotal=? WHERE id=?")->execute([$new_qty, $new_price, $new_subtotal, $iid]);

            $diff = $new_qty - (int)$old['quantity'];
            if ($diff != 0) {
                $pdo->prepare("UPDATE products SET stock_quantity = GREATEST(0, stock_quantity + ?) WHERE id = ?")->execute([$diff, $old['product_id']]);
            }
        }

        // 4) Add new item (general / laptop only)
        $add_type = $_POST['add_product_type'] ?? '';
        $add_is_new = (int)($_POST['add_is_new'] ?? 0);
        $add_product_id = (int)($_POST['add_product_id'] ?? 0);
        $add_qty = max(1, (int)($_POST['add_quantity'] ?? 1));
        $add_price = max(0, (float)($_POST['add_price'] ?? 0));

        if ($add_type !== '' && ($add_product_id || $add_is_new) && $add_price > 0) {
            if ($add_is_new) {
                $new_name = trim($_POST['add_new_name'] ?? '');
                $new_sale_price = (float)($_POST['add_new_sale_price'] ?? 0);
                $new_brand_id = (int)($_POST['add_new_brand_id'] ?? 0) ?: null;
                if (!$new_name) throw new Exception('Enter product name for the new item.');
                if ($new_sale_price <= 0) throw new Exception('Enter a sale price for the new item.');
                if ($add_type === 'laptop') {
                    $new_storage = trim($_POST['add_laptop_storage'] ?? '');
                    $new_ram = trim($_POST['add_laptop_ram'] ?? '');
                    $new_color = trim($_POST['add_laptop_color'] ?? '');
                    $new_processor = trim($_POST['add_laptop_processor'] ?? '');
                    $new_screen_size = trim($_POST['add_laptop_screen_size'] ?? '');
                } else {
                    $new_storage = $new_ram = $new_color = $new_processor = $new_screen_size = '';
                }
                // Merge into existing product with same name + type instead of creating a duplicate row
                $existing = findProductByNameType($new_name, $add_type);
                if ($existing) {
                    $add_product_id = (int)$existing['id'];
                    $fill = [];
                    if (empty($existing['color']))  $fill['color'] = $new_color;
                    if (empty($existing['storage'])) $fill['storage'] = $new_storage;
                    if (empty($existing['ram']))     $fill['ram'] = $new_ram;
                    if (empty($existing['processor'])) $fill['processor'] = $new_processor;
                    if (empty($existing['screen_size'])) $fill['screen_size'] = $new_screen_size;
                    if (!$existing['category_id'] && $catId) $fill['category_id'] = $catId;
                    if (!$existing['brand_id'] && $new_brand_id) $fill['brand_id'] = $new_brand_id;
                    if ((float)$existing['sale_price'] <= 0 && $new_sale_price > 0) $fill['sale_price'] = $new_sale_price;
                    if ($fill) {
                        $fill['updated_at'] = date('Y-m-d');
                        update('products', $fill, $add_product_id);
                    }
                } else {
                    $autoCat = $pdo->prepare("SELECT id FROM categories WHERE product_type = ? AND status = 1 ORDER BY id LIMIT 1");
                    $autoCat->execute([$add_type]);
                    $catId = $autoCat->fetchColumn() ?: null;
                    $add_product_id = insert('products', [
                        'code' => generateProductCode($add_type),
                        'name' => $new_name,
                        'description' => '',
                        'category_id' => $catId,
                        'brand_id' => $new_brand_id,
                        'supplier_id' => null,
                        'color' => $new_color,
                        'imei_no_1' => '',
                        'imei_no_2' => '',
                        'storage' => $new_storage,
                        'ram' => $new_ram,
                        'processor' => $new_processor,
                        'screen_size' => $new_screen_size,
                        'graphics' => '',
                        'warranty_months' => null,
                        'product_condition' => 'New',
                        'purchase_price' => $add_price,
                        'sale_price' => $new_sale_price,
                        'stock_quantity' => 0,
                        'min_stock_level' => 0,
                        'unit' => 'pcs',
                        'product_type' => $add_type,
                        'has_serial' => 0,
                        'status' => 1,
                        'created_at' => date('Y-m-d'),
                        'updated_at' => date('Y-m-d'),
                    ]);
                }
            }
            if (!$add_product_id) throw new Exception('Select a product for the new item.');
            $subtotal = $add_qty * $add_price;
            $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, quantity, purchase_price, subtotal) VALUES (?, ?, ?, ?, ?)")->execute([$id, $add_product_id, $add_qty, $add_price, $subtotal]);
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?")->execute([$add_qty, $add_product_id]);
            if ($add_type === 'laptop') {
                $sins = $pdo->prepare("INSERT INTO product_serials (product_id, imei_number, serial_number, purchase_id, status, purchase_price, notes, created_at, updated_at) VALUES (?, NULL, NULL, ?, 'available', ?, NULL, NOW(), NOW())");
                for ($i = 0; $i < $add_qty; $i++) $sins->execute([$add_product_id, $id, $add_price]);
            }
        }

        // 5) Update serial / IMEI rows
        foreach (($_POST['serial_id'] ?? []) as $k => $sid) {
            $sid = (int)$sid;
            if (!$sid) continue;
            $st = $pdo->prepare("SELECT ps.*, p.product_type AS ptype FROM product_serials ps JOIN products p ON p.id = ps.product_id WHERE ps.id = ? AND ps.purchase_id = ?");
            $st->execute([$sid, $id]);
            $srow = $st->fetch();
            if (!$srow) continue;
            if ($srow['status'] === 'sold') continue;

            $imei1 = trim($_POST['serial_imei1'][$k] ?? '');
            $imei2 = trim($_POST['serial_imei2'][$k] ?? '');
            $snotes = trim($_POST['serial_notes'][$k] ?? '');
            $serial_price = max(0, (float)($_POST['serial_price'][$k] ?? 0));

            if ($srow['ptype'] === 'mobile') {
                foreach ([
                    ['v' => $imei1, 'old' => (string)($srow['imei_number'] ?? '')],
                    ['v' => $imei2, 'old' => (string)($srow['serial_number'] ?? '')],
                ] as $pair) {
                    if ($pair['v'] !== '' && $pair['v'] !== $pair['old'] && !preg_match('/^\d{15}$/', $pair['v'])) {
                        throw new Exception("IMEI '{$pair['v']}' must be exactly 15 digits.");
                    }
                }
            }
            foreach (array_filter([$imei1, $imei2]) as $v) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM product_serials WHERE (imei_number = ? OR serial_number = ?) AND id <> ?");
                $chk->execute([$v, $v, $sid]);
                if ($chk->fetchColumn() > 0) {
                    throw new Exception("IMEI '$v' already exists in system.");
                }
            }

            if ($srow['ptype'] === 'mobile') {
                $pdo->prepare("UPDATE product_serials SET imei_number = ?, serial_number = ?, notes = ?, purchase_price = ?, updated_at = NOW() WHERE id = ?")->execute([$imei1 ?: null, $imei2 ?: null, $snotes ?: null, $serial_price, $sid]);
            } else {
                $pdo->prepare("UPDATE product_serials SET serial_number = ?, notes = ?, purchase_price = ?, updated_at = NOW() WHERE id = ?")->execute([$imei1 ?: null, $snotes ?: null, $serial_price, $sid]);
            }
        }

        // 5b) Mobile/laptop items: price is the average of per-serial purchase prices
        $mi = $pdo->prepare("SELECT pi.id AS item_id, pi.product_id FROM purchase_items pi JOIN products p ON p.id = pi.product_id WHERE pi.purchase_id = ? AND p.product_type IN ('mobile','laptop')");
        $mi->execute([$id]);
        foreach ($mi->fetchAll() as $mrow) {
            $ss = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(COALESCE(purchase_price,0)),0) AS s FROM product_serials WHERE purchase_id = ? AND product_id = ?");
            $ss->execute([$id, $mrow['product_id']]);
            $agg = $ss->fetch();
            if ((int)$agg['c'] > 0) {
                $sum = round((float)$agg['s'], 2);
                $avg = round($sum / (int)$agg['c'], 2);
                $pdo->prepare("UPDATE purchase_items SET purchase_price = ?, subtotal = ? WHERE id = ?")->execute([$avg, $sum, $mrow['item_id']]);
            }
        }

        // 6) Recompute totals
        $t = $pdo->prepare("SELECT COALESCE(SUM(subtotal),0) FROM purchase_items WHERE purchase_id = ?");
        $t->execute([$id]);
        $new_total = (float)$t->fetchColumn();

        // 7) Payment reconciliation
        $new_paid = max(0, (float)($_POST['paid_amount'] ?? 0));
        if ($new_paid > $new_total) $new_paid = $new_total;
        $method = $_POST['payment_method'] ?? 'cash';
        $bank_account_id = (int)($_POST['bank_account_id'] ?? 0);
        $old_paid = (float)$purchase['paid_amount'];

        if (abs($new_paid - $old_paid) > 0.001) {
            reversePurchaseOutflows($pdo, $id, $old_supplier_id, $old_invoice, $old_date);
            if ($new_paid > 0) {
                if ($new_supplier_id > 0) {
                    $payment_id = insert('supplier_payments', [
                        'supplier_id' => $new_supplier_id,
                        'amount' => $new_paid,
                        'payment_method' => $method,
                        'bank_account_id' => $bank_account_id ?: null,
                        'description' => 'Purchase payment' . ($invoice_no ? " - Invoice $invoice_no" : ''),
                        'payment_date' => $purchase_date,
                        'created_by' => $user_id,
                        'created_at' => date('Y-m-d'),
                    ]);
                    $sup = getById('suppliers', $new_supplier_id);
                    $ref_desc = 'Supplier payment - ' . htmlspecialchars(($sup['contact_person'] ?? '') ?: ($sup['name'] ?? 'Supplier #' . $new_supplier_id));
                    if ($method === 'bank') {
                        recordBankOutflow($pdo, $purchase_date, $new_paid, $ref_desc, 'supplier_payment', $payment_id, $user_id, $bank_account_id);
                    } else {
                        recordCashOutflow($pdo, $purchase_date, $new_paid, $ref_desc, 'supplier_payment', $payment_id, $user_id);
                    }
                } else {
                    $ref_desc = ($person_name ? 'Payment to ' . $person_name : 'Purchase payment') . ($invoice_no ? " - Invoice $invoice_no" : '');
                    if ($method === 'bank') {
                        recordBankOutflow($pdo, $purchase_date, $new_paid, $ref_desc, 'purchase', $id, $user_id, $bank_account_id);
                    } else {
                        recordCashOutflow($pdo, $purchase_date, $new_paid, $ref_desc, 'purchase', $id, $user_id);
                    }
                }
            }
        }

        $due = max(0, $new_total - $new_paid);
        $pdo->prepare("UPDATE purchases SET total_amount = ?, paid_amount = ?, due_amount = ? WHERE id = ?")->execute([$new_total, $new_paid, $due, $id]);

        $pdo->commit();
        $_SESSION['success'] = "Purchase updated. Total: " . formatCurrency($new_total) . " | Due: " . formatCurrency($due);
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }
    header("Location: purchase_edit.php?id=$id");
    exit;
}

// ------------------------------------------------------------
// Render data
// ------------------------------------------------------------
$items = getWhere('purchase_items', 'purchase_id', $id);
$total_amount = array_sum(array_column($items, 'subtotal'));
$is_person = !empty($purchase['person_name']);

$serials = $pdo->prepare("SELECT s.*, p.name AS product_name, p.code AS product_code, p.product_type FROM product_serials s JOIN products p ON p.id = s.product_id WHERE s.purchase_id = ? ORDER BY s.id");
$serials->execute([$id]);
$serials = $serials->fetchAll();

$laptop_products = $pdo->query("SELECT id, code, name, purchase_price FROM products WHERE product_type='laptop' AND status=1 ORDER BY name")->fetchAll();
$general_products = $pdo->query("SELECT id, code, name, purchase_price FROM products WHERE product_type='general' AND status=1 ORDER BY name")->fetchAll();

$all_purchases = $pdo->query("SELECT p.id, p.invoice_no, p.purchase_date, s.name AS supplier_name, p.person_name FROM purchases p LEFT JOIN suppliers s ON p.supplier_id = s.id ORDER BY p.purchase_date DESC, p.id DESC LIMIT 300")->fetchAll();

require_once '../../includes/header.php';
?>

<style>
  .item-row td { vertical-align: middle; }
  .edit-section-title { font-size: .85rem; font-weight: 700; letter-spacing: .02em; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; margin-top: 22px; }
</style>

<div class="card shadow mb-4">
  <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-truck"></i> Edit Purchase #<?=$id?></h6>
    <div class="d-flex align-items-center">
      <form method="get" class="form-inline mr-2">
        <input type="hidden" name="id" value="0">
        <select name="id" class="form-control form-control-sm mr-2" onchange="if(this.value){location='purchase_edit.php?id='+this.value;}">
          <option value="">-- Jump to another purchase --</option>
          <?php foreach ($all_purchases as $ap): ?>
            <option value="<?=$ap['id']?>" <?=$ap['id']==$id?'selected':''?>>#<?=$ap['id']?> <?=htmlspecialchars(($ap['supplier_name'] ?: $ap['person_name'] ?: 'N/A'))?> <?=htmlspecialchars($ap['invoice_no'])?> (<?=formatDate($ap['purchase_date'])?>)</option>
          <?php endforeach; ?>
        </select>
      </form>
      <a href="purchases.php" class="btn btn-sm btn-secondary"><i class="fas fa-arrow-left"></i> Back to Purchases</a>
    </div>
  </div>

  <div class="card-body">
    <form method="post" id="editPurchaseForm" onsubmit="return validateForm()">
      <div class="edit-section-title"><i class="fas fa-info-circle"></i> Purchase Details</div>
      <div class="row">
        <div class="col-md-3 form-group">
          <label class="font-weight-bold small">Date</label>
          <input type="text" name="purchase_date" class="form-control datepicker" value="<?=$purchase['purchase_date']?>" required autocomplete="off">
        </div>
        <div class="col-md-3 form-group">
          <label class="font-weight-bold small">Supplier / Person</label>
          <select name="supplier_type" id="supplierType" class="form-control" onchange="toggleSupplierType(this.value)">
            <option value="supplier" <?=$is_person?'':'selected'?>>Supplier</option>
            <option value="person" <?=$is_person?'selected':''?>>Random Person</option>
          </select>
          <div id="supplierSelectWrap" class="mt-2" <?=$is_person?'style="display:none;"':''?>>
            <select name="supplier_id" class="form-control">
              <option value="">Select Supplier</option>
              <?php foreach ($suppliers as $s): ?>
                <option value="<?=$s['id']?>" <?=$purchase['supplier_id']==$s['id']?'selected':''?>><?=htmlspecialchars($s['contact_person'] ? $s['contact_person'] . ' (' . $s['name'] . ')' : $s['name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div id="personFieldsWrap" class="mt-2" <?=$is_person?'':'style="display:none;"'?>>
            <input type="text" name="person_name" class="form-control mb-2" placeholder="Person Name" value="<?=htmlspecialchars($purchase['person_name']??'')?>">
            <input type="text" name="person_phone" class="form-control mb-2" placeholder="Mobile Number" value="<?=htmlspecialchars($purchase['person_phone']??'')?>">
            <input type="text" name="person_cnic" class="form-control mb-2" placeholder="CNIC" value="<?=htmlspecialchars($purchase['person_cnic']??'')?>">
            <input type="text" name="person_address" class="form-control" placeholder="Address" value="<?=htmlspecialchars($purchase['person_address']??'')?>">
          </div>
        </div>
        <div class="col-md-3 form-group">
          <label class="font-weight-bold small">Invoice No.</label>
          <input type="text" name="invoice_no" class="form-control" value="<?=htmlspecialchars($purchase['invoice_no']??'')?>">
        </div>
        <div class="col-md-3 form-group">
          <label class="font-weight-bold small">Status</label>
          <select name="status" class="form-control">
            <option value="received" <?=$purchase['status']==='received'?'selected':''?>>Received</option>
            <option value="pending" <?=$purchase['status']==='pending'?'selected':''?>>Pending</option>
            <option value="cancelled" <?=$purchase['status']==='cancelled'?'selected':''?>>Cancelled</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="font-weight-bold small">Notes</label>
        <textarea name="notes" class="form-control" rows="2"><?=htmlspecialchars($purchase['notes']??'')?></textarea>
      </div>

      <!-- Items -->
      <div class="edit-section-title"><i class="fas fa-list"></i> Items (edit quantity / price)</div>
      <div class="table-responsive">
        <table class="table table-bordered" id="itemsTable">
          <thead class="thead-light">
            <tr><th class="text-center" style="width:50px;">#</th><th>Product</th><th class="text-center">Type</th><th class="text-center" style="width:100px;">Qty</th><th style="width:150px;">Price</th><th class="text-right" style="width:140px;">Subtotal</th><th class="text-center" style="width:60px;">Remove</th></tr>
          </thead>
          <tbody>
            <?php $i = 1; foreach ($items as $item):
              $prod = getById('products', $item['product_id']);
              $isSerialTracked = in_array($prod['product_type'] ?? '', ['mobile', 'laptop'], true);
            ?>
            <tr class="item-row" data-pid="<?=$item['product_id']?>" data-avg="<?=$item['purchase_price']?>">
              <td class="text-center"><?=$i++?></td>
              <td><?=htmlspecialchars($prod['name']??'Unknown')?> <small class="text-muted d-block"><?=htmlspecialchars($prod['code']??'')?></small></td>
              <td class="text-center"><span class="badge badge-info"><?=ucfirst($prod['product_type']??'general')?></span></td>
              <td class="text-center">
                <input type="hidden" name="item_id[]" value="<?=$item['id']?>">
                <input type="number" name="item_qty[]" class="form-control form-control-sm text-center item-qty" value="<?=$item['quantity']?>" min="1" oninput="updateTotals()">
              </td>
              <td>
                <?php if ($isSerialTracked): ?>
                  <input type="hidden" name="item_price[]" class="item-price-hidden" value="<?=$item['purchase_price']?>">
                  <div class="form-control form-control-sm text-right bg-light font-weight-bold"><?=formatCurrency($item['purchase_price'])?></div>
                  <small class="text-muted">Avg per unit - edit in units table below</small>
                <?php else: ?>
                  <input type="number" name="item_price[]" class="form-control form-control-sm text-right item-price" value="<?=$item['purchase_price']?>" min="0" step="0.01" oninput="updateTotals()">
                <?php endif; ?>
              </td>
              <td class="text-right item-subtotal"><?=formatCurrency($item['subtotal'])?></td>
              <td class="text-center">
                <input type="checkbox" name="remove_item[]" value="<?=$item['id']?>" class="form-check-input" title="Remove this item">
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="font-weight-bold bg-light"><td colspan="5" class="text-right">Total</td><td class="text-right" id="totalDisplay"><?=formatCurrency($total_amount)?></td><td></td></tr>
          </tfoot>
        </table>
      </div>

      <!-- Add Item -->
      <div class="bg-light rounded p-3 border">
        <label class="font-weight-bold small mb-2"><i class="fas fa-plus-circle"></i> Add Item to This Purchase</label>
        <div class="row">
          <div class="col-md-2 form-group mb-2">
            <label class="small font-weight-bold">Product Type</label>
            <select name="add_product_type" id="addProductType" class="form-control form-control-sm" onchange="switchAddType(this.value)">
              <option value="general">General</option>
              <option value="laptop">Laptop</option>
            </select>
            <small class="text-muted">Mobile needs IMEI - use new purchase.</small>
          </div>
          <div class="col-md-4 form-group mb-2">
            <label class="small font-weight-bold">Product</label>
            <select name="add_product_id" id="addProductSelect" class="form-control form-control-sm" onchange="toggleAddNew(this.value)">
              <option value="">Select Product</option>
              <option value="new">++ Add New Product</option>
              <?php foreach ($general_products as $gp): ?>
                <option value="<?=$gp['id']?>" data-type="general"><?=htmlspecialchars($gp['name'])?> (<?=htmlspecialchars($gp['code'])?>)</option>
              <?php endforeach; ?>
              <?php foreach ($laptop_products as $lp): ?>
                <option value="<?=$lp['id']?>" data-type="laptop"><?=htmlspecialchars($lp['name'])?> (<?=htmlspecialchars($lp['code'])?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2 form-group mb-2">
            <label class="small font-weight-bold">Quantity</label>
            <input type="number" name="add_quantity" class="form-control form-control-sm" value="1" min="1">
          </div>
          <div class="col-md-2 form-group mb-2">
            <label class="small font-weight-bold">Purchase Price</label>
            <input type="number" name="add_price" class="form-control form-control-sm" id="addPrice" min="0" step="0.01">
          </div>
          <div class="col-md-2 form-group mb-2">
            <label class="small font-weight-bold">&nbsp;</label>
            <div><span class="text-muted small">Added on Save</span></div>
          </div>
        </div>

        <div id="addNewWrap" class="rounded p-3 border mt-1 bg-white" style="display:none;">
          <div class="row">
            <div class="col-md-3 form-group mb-2">
              <label class="small font-weight-bold">Product Name *</label>
              <input type="text" name="add_new_name" class="form-control form-control-sm" placeholder="e.g. LED Bulb">
            </div>
            <div class="col-md-3 form-group mb-2">
              <label class="small font-weight-bold">Company (Brand)</label>
              <select name="add_new_brand_id" class="form-control form-control-sm">
                <option value="">Select Company</option>
                <?php foreach ($brands as $b): ?>
                  <option value="<?=$b['id']?>"><?=htmlspecialchars($b['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3 form-group mb-2">
              <label class="small font-weight-bold">Sale Price *</label>
              <input type="number" name="add_new_sale_price" class="form-control form-control-sm" min="0" step="0.01" placeholder="Selling price">
            </div>
          </div>
          <div class="row" id="addLaptopSpecs" style="display:none;">
            <div class="col-md-3 form-group mb-2">
              <label class="small font-weight-bold">Processor</label>
              <select name="add_laptop_processor" class="form-control form-control-sm">
                <option value="">Select</option>
                <option>Core i3</option>
                <option>Core i5</option>
                <option>Core i7</option>
                <option>Core i9</option>
                <option>Ryzen 5</option>
                <option>Ryzen 7</option>
              </select>
            </div>
            <div class="col-md-3 form-group mb-2">
              <label class="small font-weight-bold">Screen Size</label>
              <select name="add_laptop_screen_size" class="form-control form-control-sm">
                <option value="">Select</option>
                <option value="13.3">13.3"</option>
                <option value="14">14"</option>
                <option value="15.6">15.6"</option>
                <option value="16">16"</option>
                <option value="17.3">17.3"</option>
              </select>
            </div>
            <div class="col-md-3 form-group mb-2">
              <label class="small font-weight-bold">RAM</label>
              <input type="text" name="add_laptop_ram" class="form-control form-control-sm" placeholder="e.g. 8GB">
            </div>
            <div class="col-md-3 form-group mb-2">
              <label class="small font-weight-bold">Storage</label>
              <input type="text" name="add_laptop_storage" class="form-control form-control-sm" placeholder="e.g. 512GB">
            </div>
          </div>
        </div>
        <input type="hidden" name="add_is_new" value="0" id="addIsNew">
      </div>

      <!-- Serials / IMEI -->
      <?php if (!empty($serials)): ?>
      <div class="edit-section-title"><i class="fas fa-qrcode"></i> Product Identifiers / IMEI <span class="badge badge-primary"><?= count($serials) ?> units</span></div>
      <div class="table-responsive">
        <table class="table table-bordered table-sm" id="serialsTable">
          <thead class="thead-light">
            <tr><th class="text-center" style="width:40px;">#</th><th>Product</th><th>IMEI 1</th><th>IMEI 2</th><th>Details</th><th style="width:110px;">Price</th><th class="text-center" style="width:110px;">Status</th></tr>
          </thead>
          <tbody>
            <?php foreach ($serials as $idx => $sr):
              $isMobile = ($sr['product_type'] ?? '') === 'mobile';
              $locked = $sr['status'] === 'sold';
            ?>
            <tr class="serial-row" data-pid="<?=$sr['product_id']?>">
              <td class="text-center"><?= $idx + 1 ?></td>
              <td><?= htmlspecialchars($sr['product_name']) ?> <small class="text-muted d-block"><?= htmlspecialchars($sr['product_code']) ?></small></td>
              <?php if ($isMobile): ?>
                <td>
                  <input type="hidden" name="serial_id[]" value="<?=$sr['id']?>">
                  <input type="text" name="serial_imei1[]" class="form-control form-control-sm imei-input" data-orig="<?=htmlspecialchars($sr['imei_number']??'')?>" value="<?=htmlspecialchars($sr['imei_number']??'')?>" placeholder="IMEI 1" maxlength="15" <?=$locked?'readonly disabled':''?> oninput="this.value=this.value.replace(/\D/g,'')">
                </td>
                <td>
                  <input type="text" name="serial_imei2[]" class="form-control form-control-sm imei-input" data-orig="<?=htmlspecialchars($sr['serial_number']??'')?>" value="<?=htmlspecialchars($sr['serial_number']??'')?>" placeholder="IMEI 2" maxlength="15" <?=$locked?'readonly disabled':''?> oninput="this.value=this.value.replace(/\D/g,'')">
                </td>
              <?php else: ?>
                <td colspan="2">
                  <input type="hidden" name="serial_id[]" value="<?=$sr['id']?>">
                  <input type="text" name="serial_imei1[]" class="form-control form-control-sm" value="<?=htmlspecialchars($sr['serial_number']??'')?>" placeholder="Serial Number" <?=$locked?'readonly disabled':''?>>
                  <input type="hidden" name="serial_imei2[]" value="">
                </td>
              <?php endif; ?>
              <td>
                <input type="text" name="serial_notes[]" class="form-control form-control-sm" value="<?=htmlspecialchars($sr['notes']??'')?>" placeholder="Details" <?=$locked?'readonly disabled':''?>>
              </td>
              <td>
                <input type="number" name="serial_price[]" class="form-control form-control-sm text-right serial-price" step="0.01" min="0" value="<?=htmlspecialchars($sr['purchase_price'] ?? 0)?>" <?=$locked?'readonly disabled':''?> oninput="recalcFromSerials()">
              </td>
              <td class="text-center">
                <span class="badge badge-<?= $sr['status'] === 'available' ? 'success' : ($sr['status'] === 'sold' ? 'secondary' : 'warning') ?>"><?= ucfirst($sr['status']) ?></span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <small class="text-muted">Sold units are locked. IMEI must be exactly 15 digits.</small>
      </div>
      <?php endif; ?>

      <!-- Payment -->
      <div class="edit-section-title"><i class="fas fa-money-bill-wave"></i> Payment</div>
      <div class="row">
        <div class="col-md-4 form-group">
          <label class="small font-weight-bold">Paid Amount</label>
          <input type="number" name="paid_amount" id="paidAmount" class="form-control" step="0.01" min="0" value="<?=$pay_info['paid']?>" oninput="updateDue()">
        </div>
        <div class="col-md-4 form-group">
          <label class="small font-weight-bold">Payment Method</label>
          <select name="payment_method" class="form-control" id="paymentMethod" onchange="toggleBankAccount(this.value)">
            <option value="cash" <?=$pay_info['method']!=='bank'?'selected':''?>>Cash</option>
            <option value="bank" <?=$pay_info['method']==='bank'?'selected':''?>>Bank Transfer</option>
          </select>
        </div>
        <div class="col-md-4 form-group" id="bankAccountGroup" style="display:<?=$pay_info['method']==='bank'?'':'none'?>;">
          <label class="small font-weight-bold">Bank Account</label>
          <select name="bank_account_id" class="form-control">
            <option value="">Select Account</option>
            <?php foreach ($bank_accounts as $ba): ?>
              <option value="<?=$ba['id']?>" <?=$pay_info['bank_account_id']==$ba['id']?'selected':''?>><?=htmlspecialchars($ba['bank_name'] . ' - ' . $ba['account_name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="row mt-3">
        <div class="col-md-4"><div class="border rounded p-3 bg-light"><small class="text-muted d-block">Total</small><strong id="totalDisplay2"><?=formatCurrency($total_amount)?></strong></div></div>
        <div class="col-md-4"><div class="border rounded p-3 bg-light"><small class="text-muted d-block">Paid</small><strong id="paidDisplay"><?=formatCurrency($pay_info['paid'])?></strong></div></div>
        <div class="col-md-4"><div class="border rounded p-3 bg-light"><small class="text-muted d-block">Due</small><strong id="dueDisplay"><?=formatCurrency(max(0,$total_amount-$pay_info['paid']))?></strong></div></div>
      </div>

      <hr>
      <button type="submit" name="save_all" class="btn btn-primary px-4" onclick="return confirm('Save all changes?')"><i class="fas fa-save"></i> Save Changes</button>

      <script>
      function toggleSupplierType(val) {
        document.getElementById('supplierSelectWrap').style.display = val === 'person' ? 'none' : '';
        document.getElementById('personFieldsWrap').style.display = val === 'person' ? '' : 'none';
      }

      function switchAddType(type) {
        var sel = document.getElementById('addProductSelect');
        document.getElementById('addLaptopSpecs').style.display = type === 'laptop' ? '' : 'none';
        if (sel.value === 'new' || !sel.value) return;
        var opt = sel.options[sel.selectedIndex];
        if (opt.dataset.type !== type) sel.value = '';
      }

      function toggleAddNew(val) {
        var isNew = val === 'new';
        document.getElementById('addNewWrap').style.display = isNew ? '' : 'none';
        document.getElementById('addIsNew').value = isNew ? '1' : '0';
        var type = document.getElementById('addProductType').value;
        document.getElementById('addLaptopSpecs').style.display = (isNew && type === 'laptop') ? '' : 'none';
        if (isNew) document.getElementById('addPrice').value = '';
      }

      function toggleBankAccount(method) {
        document.getElementById('bankAccountGroup').style.display = method === 'bank' ? '' : 'none';
      }

      function fmt(n) {
        return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      }

      function updateTotals() {
        var total = 0;
        document.querySelectorAll('#itemsTable tbody .item-row').forEach(function(row) {
          var qty = parseFloat(row.querySelector('.item-qty').value) || 0;
          var priceInp = row.querySelector('.item-price');
          var price = priceInp ? (parseFloat(priceInp.value) || 0) : (parseFloat(row.getAttribute('data-avg')) || 0);
          row.querySelector('.item-subtotal').textContent = fmt(qty * price);
          total += qty * price;
        });
        document.getElementById('totalDisplay').textContent = fmt(total);
        document.getElementById('totalDisplay2').textContent = fmt(total);
        updateDue();
      }

      function recalcFromSerials() {
        var sums = {};
        document.querySelectorAll('#serialsTable tbody .serial-row').forEach(function(row) {
          var pid = row.getAttribute('data-pid');
          if (!pid) return;
          var v = parseFloat(row.querySelector('.serial-price').value) || 0;
          sums[pid] = sums[pid] || { count: 0, sum: 0 };
          sums[pid].count += 1;
          sums[pid].sum += v;
        });
        document.querySelectorAll('#itemsTable tbody .item-row').forEach(function(row) {
          var s = sums[row.getAttribute('data-pid')];
          if (!s) return;
          var qty = parseFloat(row.querySelector('.item-qty').value) || 1;
          var avg = s.count > 0 ? (s.sum / s.count) : 0;
          row.setAttribute('data-avg', avg.toFixed(2));
          var hidden = row.querySelector('.item-price-hidden');
          if (hidden) hidden.value = avg.toFixed(2);
          var avgBox = row.querySelector('.form-control.bg-light');
          if (avgBox) avgBox.textContent = fmt(avg);
          row.querySelector('.item-subtotal').textContent = fmt(qty * avg);
        });
        updateTotals();
      }

      function updateDue() {
        var total = parseFloat(document.getElementById('totalDisplay').textContent.replace(/,/g, '')) || 0;
        var paid = parseFloat(document.getElementById('paidAmount').value) || 0;
        document.getElementById('paidDisplay').textContent = fmt(paid);
        document.getElementById('dueDisplay').textContent = fmt(Math.max(0, total - paid));
      }

      function validateForm() {
        var errors = [];
        document.querySelectorAll('.imei-input').forEach(function(inp) {
          if (inp.disabled) return;
          inp.classList.remove('is-invalid');
          var v = inp.value.trim();
          var orig = (inp.getAttribute('data-orig') || '').trim();
          if (v && v !== orig && v.length !== 15) {
            inp.classList.add('is-invalid');
            errors.push('IMEI "' + v + '" must be exactly 15 digits (' + v.length + ' entered).');
          }
        });
        var removed = document.querySelectorAll('input[name="remove_item[]"]:checked').length;
        if (removed) errors.push(removed + ' item(s) will be removed and stock reduced.');
        if (errors.length) {
          alert(errors.join('\n'));
          return false;
        }
        return true;
      }

      // Prevent Enter key auto-submit on inputs (e.g. barcode / IMEI scanner)
      document.getElementById('editPurchaseForm').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
          var tag = (e.target.tagName || '').toLowerCase();
          if (tag !== 'textarea' && e.target.type !== 'submit') {
            e.preventDefault();
            return false;
          }
        }
      });

      updateTotals();
      if (document.querySelector('#serialsTable .serial-price')) recalcFromSerials();
      </script>
    </form>
  </div>
</div>
<?php require_once '../../includes/footer.php'; ?>
