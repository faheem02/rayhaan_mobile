<?php
session_start();
require_once '../../includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid ID']);
    exit;
}

$purchase = getById('purchases', $id);
if (!$purchase) {
    http_response_code(404);
    echo json_encode(['error' => 'Purchase not found']);
    exit;
}

$supplier = $purchase['supplier_id'] ? getById('suppliers', $purchase['supplier_id']) : null;
$items = getWhere('purchase_items', 'purchase_id', $id);

$serials = $pdo->prepare("SELECT * FROM product_serials WHERE purchase_id = ?");
$serials->execute([$id]);
$serials = $serials->fetchAll();

$data = [
    'id' => $purchase['id'],
    'purchase_date' => $purchase['purchase_date'],
    'invoice_no' => $purchase['invoice_no'],
    'total_amount' => $purchase['total_amount'],
    'paid_amount' => $purchase['paid_amount'],
    'due_amount' => $purchase['due_amount'],
    'status' => $purchase['status'],
    'notes' => $purchase['notes'],
    'created_at' => $purchase['created_at'],
    'supplier_name' => $supplier ? $supplier['name'] : '-',
    'supplier_contact' => $supplier ? $supplier['contact_person'] : '',
    'person_name' => $purchase['person_name'] ?? '',
    'person_phone' => $purchase['person_phone'] ?? '',
    'person_cnic' => $purchase['person_cnic'] ?? '',
    'person_address' => $purchase['person_address'] ?? '',
    'items' => [],
    'serials' => []
];

foreach ($items as $item) {
    $prod = getById('products', $item['product_id']);
    $data['items'][] = [
        'product_id' => $item['product_id'],
        'product_name' => $prod ? $prod['name'] : 'Unknown',
        'product_code' => $prod ? $prod['code'] : '',
        'product_type' => $prod ? $prod['product_type'] : 'general',
        'quantity' => $item['quantity'],
        'purchase_price' => $item['purchase_price'],
        'subtotal' => $item['subtotal'],
    ];
}

$prod_cache = [];
foreach ($serials as $s) {
    $pid = $s['product_id'];
    if (!isset($prod_cache[$pid])) {
        $p = getById('products', $pid);
        $prod_cache[$pid] = $p ? $p['product_type'] : 'general';
    }
    $data['serials'][] = [
        'serial_id' => $s['id'],
        'product_id' => $pid,
        'imei_number' => $s['imei_number'],
        'imei2' => $s['serial_number'],
        'serial_number' => $s['serial_number'],
        'status' => $s['status'],
        'notes' => $s['notes'],
        'purchase_price' => $s['purchase_price'],
        'product_type' => $prod_cache[$pid],
    ];
}

header('Content-Type: application/json');
echo json_encode($data);
