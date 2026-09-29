<?php
require_once __DIR__ . '/../config/db.php';

// Get all records from a table
function getAll($table, $order = 'id DESC') {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM $table ORDER BY $order");
    return $stmt->fetchAll();
}

// Get single record by ID
function getById($table, $id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// Get records with a condition
function getWhere($table, $column, $value, $order = 'id DESC') {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE $column = ? ORDER BY $order");
    $stmt->execute([$value]);
    return $stmt->fetchAll();
}

// Insert and return last ID
function insert($table, $data) {
    global $pdo;
    $columns = implode(', ', array_keys($data));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    $stmt = $pdo->prepare("INSERT INTO $table ($columns) VALUES ($placeholders)");
    $stmt->execute(array_values($data));
    return $pdo->lastInsertId();
}

// Update record
function update($table, $data, $id) {
    global $pdo;
    $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($data)));
    $stmt = $pdo->prepare("UPDATE $table SET $sets WHERE id = ?");
    $stmt->execute([...array_values($data), $id]);
    return $stmt->rowCount();
}

// Delete record
function delete($table, $id) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->rowCount();
}

// Count records
function countRows($table, $column = null, $value = null) {
    global $pdo;
    if ($column && $value !== null) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
        $stmt->execute([$value]);
    } else {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
    }
    return $stmt->fetchColumn();
}

// Next sequence number for prefixed codes (MAX-based so deleted rows never cause duplicate keys)
function nextSequenceNumber($table, $column, $prefix, $pad) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT $column FROM $table WHERE $column LIKE ?");
    $stmt->execute(["$prefix%"]);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $val) {
        $num = (int)substr((string)$val, strlen($prefix));
        if ($num > $max) $max = $num;
    }
    $count = $max + 1;
    // Safety loop: skip any number that somehow still exists
    while (getWhere($table, $column, $prefix . str_pad($count, $pad, '0', STR_PAD_LEFT))) {
        $count++;
    }
    return $count;
}

// Generate invoice number
function generateInvoiceNo() {
    global $pdo;
    $prefix = 'INV-' . date('ymd') . '-';
    $count = nextSequenceNumber('sales', 'invoice_no', $prefix, 3);
    return $prefix . str_pad($count, 3, '0', STR_PAD_LEFT);
}

// Generate customer number
function generateCustomerNo() {
    global $pdo;
    $prefix = 'CUS-' . date('ym') . '-';
    $count = nextSequenceNumber('customers', 'customer_no', $prefix, 4);
    return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
}

// Generate product code (e.g. MOB-0001, LAP-0001, GEN-0001)
function generateProductCode($type = 'general') {
    global $pdo;
    $prefix = strtoupper(in_array($type, ['mobile', 'laptop']) ? substr($type, 0, 3) : 'GEN') . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE code LIKE ?");
    $stmt->execute(["$prefix%"]);
    $count = (int)$stmt->fetchColumn() + 1;
    $code = $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
    while (getWhere('products', 'code', $code)) {
        $count++;
        $code = $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
    return $code;
}

// Find an active product by exact name + type (case-insensitive, trimmed) — for merge
function findProductByNameType($name, $type = 'general') {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_type = ? AND TRIM(LOWER(name)) = ? AND status = 1 ORDER BY id LIMIT 1");
    $stmt->execute([$type, strtolower(trim($name))]);
    return $stmt->fetch() ?: null;
}

// Resolve a product from a free-text sale description (e.g. "HP ELITE BOOK (LAP-01) [IMEI: x]")
function resolveProductFromDescription($pdo, $desc) {
    $d = strtolower(trim($desc));
    $d = preg_replace('/\[[^\]]*imei[^\]]*\]/i', '', $d);
    $d = preg_replace('/\((.*?)\)/', '', $d);
    $d = trim(preg_replace('/\s+/', ' ', $d));
    if ($d === '') return null;
    $stmt = $pdo->prepare("SELECT id FROM products WHERE TRIM(LOWER(name)) = ? AND status = 1 ORDER BY id LIMIT 1");
    $stmt->execute([$d]);
    if ($id = $stmt->fetchColumn()) return (int)$id;
    $stmt = $pdo->prepare("SELECT id FROM products WHERE TRIM(LOWER(code)) = ? AND status = 1 ORDER BY id LIMIT 1");
    $stmt->execute([$d]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

// Format currency
function formatCurrency($amount) {
    return number_format($amount, 2);
}

// Format date
function formatDate($date) {
    return $date ? date('d-m-Y', strtotime($date)) : '-';
}

// Get customer full name with ID
function customerName($id) {
    $c = getById('customers', $id);
    return $c ? $c['full_name'] : 'N/A';
}

// Record a cash inflow in cash book
function recordCashInflow($pdo, $date, $amount, $description, $reference_type = null, $reference_id = null, $created_by = null) {
    $today = $date ?: date('Y-m-d');
    $daily = $pdo->prepare("SELECT * FROM cash_book_daily WHERE date = ?");
    $daily->execute([$today]);
    $daily_rec = $daily->fetch();

    if ($daily_rec) {
        $daily_id = $daily_rec['id'];
    } else {
        $prev = $pdo->query("SELECT closing_balance FROM cash_book_daily WHERE date < '$today' ORDER BY date DESC LIMIT 1")->fetch();
        $opening = $prev ? (float)$prev['closing_balance'] : 0;
        $daily_id = insert('cash_book_daily', [
            'date' => $today,
            'opening_balance' => $opening,
            'total_inflow' => 0,
            'total_outflow' => 0,
            'closing_balance' => $opening,
            'status' => 'open',
            'created_by' => $created_by,
            'created_at' => date('Y-m-d'),
        ]);
    }

    insert('cash_book', [
        'daily_id' => $daily_id,
        'transaction_date' => $today,
        'transaction_type' => 'inflow',
        'amount' => $amount,
        'description' => $description,
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'created_by' => $created_by,
        'created_at' => date('Y-m-d'),
    ]);

    $update = $pdo->prepare("UPDATE cash_book_daily SET total_inflow = total_inflow + ?, closing_balance = opening_balance + total_inflow - total_outflow WHERE id = ?");
    $update->execute([$amount, $daily_id]);
}

// Record bank inflow
function recordBankInflow($pdo, $date, $amount, $description, $reference_type = null, $reference_id = null, $created_by = null, $bank_account_id = null) {
    if ($bank_account_id) {
        $stmt = $pdo->prepare("SELECT id, current_balance FROM bank_accounts WHERE id = ? AND status = 1");
        $stmt->execute([$bank_account_id]);
        $account = $stmt->fetch();
    } else {
        $stmt = $pdo->query("SELECT id, current_balance FROM bank_accounts WHERE status = 1 ORDER BY id ASC LIMIT 1");
        $account = $stmt->fetch();
    }
    if (!$account) {
        $account_id = insert('bank_accounts', [
            'account_name' => 'Default Account',
            'bank_name' => 'Default Bank',
            'account_no' => 'AUTO-' . date('YmdHis'),
            'account_type' => 'current',
            'opening_balance' => 0,
            'current_balance' => 0,
            'status' => 1,
            'created_at' => $date,
        ]);
        $account = ['id' => $account_id, 'current_balance' => 0];
    }

    insert('bank_transactions', [
        'bank_account_id' => $account['id'],
        'transaction_date' => $date,
        'transaction_type' => 'deposit',
        'amount' => $amount,
        'description' => $description,
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'created_by' => $created_by ?: 1,
        'created_at' => date('Y-m-d'),
    ]);

    $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$amount, $account['id']]);
    return $account['id'];
}

// Record a cash outflow
function recordCashOutflow($pdo, $date, $amount, $description, $reference_type = null, $reference_id = null, $created_by = null) {
    $today = $date ?: date('Y-m-d');
    $daily = $pdo->prepare("SELECT * FROM cash_book_daily WHERE date = ?");
    $daily->execute([$today]);
    $daily_rec = $daily->fetch();

    if ($daily_rec) {
        $daily_id = $daily_rec['id'];
    } else {
        $prev = $pdo->query("SELECT closing_balance FROM cash_book_daily WHERE date < '$today' ORDER BY date DESC LIMIT 1")->fetch();
        $opening = $prev ? (float)$prev['closing_balance'] : 0;
        $daily_id = insert('cash_book_daily', [
            'date' => $today,
            'opening_balance' => $opening,
            'total_inflow' => 0,
            'total_outflow' => 0,
            'closing_balance' => $opening,
            'status' => 'open',
            'created_by' => $created_by,
            'created_at' => date('Y-m-d'),
        ]);
    }

    insert('cash_book', [
        'daily_id' => $daily_id,
        'transaction_date' => $today,
        'transaction_type' => 'outflow',
        'amount' => $amount,
        'description' => $description,
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'created_by' => $created_by,
        'created_at' => date('Y-m-d'),
    ]);

    $update = $pdo->prepare("UPDATE cash_book_daily SET total_outflow = total_outflow + ?, closing_balance = opening_balance + total_inflow - total_outflow WHERE id = ?");
    $update->execute([$amount, $daily_id]);
}

// Record bank outflow
function recordBankOutflow($pdo, $date, $amount, $description, $reference_type = null, $reference_id = null, $created_by = null, $bank_account_id = null) {
    if ($bank_account_id) {
        $stmt = $pdo->prepare("SELECT id, current_balance FROM bank_accounts WHERE id = ? AND status = 1");
        $stmt->execute([$bank_account_id]);
        $account = $stmt->fetch();
    } else {
        $stmt = $pdo->query("SELECT id, current_balance FROM bank_accounts WHERE status = 1 ORDER BY id ASC LIMIT 1");
        $account = $stmt->fetch();
    }
    if (!$account) {
        $account_id = insert('bank_accounts', [
            'account_name' => 'Default Account',
            'bank_name' => 'Default Bank',
            'account_no' => 'AUTO-' . date('YmdHis'),
            'account_type' => 'current',
            'opening_balance' => 0,
            'current_balance' => 0,
            'status' => 1,
            'created_at' => $date,
        ]);
        $account = ['id' => $account_id, 'current_balance' => 0];
    }

    insert('bank_transactions', [
        'bank_account_id' => $account['id'],
        'transaction_date' => $date,
        'transaction_type' => 'withdrawal',
        'amount' => $amount,
        'description' => $description,
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'created_by' => $created_by ?: 1,
        'created_at' => date('Y-m-d'),
    ]);

    $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$amount, $account['id']]);
    return $account['id'];
}

// Redirect with message
function redirect($url, $msg = null, $type = 'success') {
    if ($msg) {
        session_start();
        $_SESSION[$type] = $msg;
    }
    header("Location: $url");
    exit;
}
