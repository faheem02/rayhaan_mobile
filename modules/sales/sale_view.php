<?php
session_start();
require_once '../../includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid ID']);
    exit;
}

$sale = getById('sales', $id);
if (!$sale) {
    http_response_code(404);
    echo json_encode(['error' => 'Sale not found']);
    exit;
}

$customer = $sale['customer_id'] ? getById('customers', $sale['customer_id']) : null;
$items = getWhere('sale_items', 'sale_id', $id);

$payments = $pdo->prepare("SELECT * FROM payments WHERE sale_id = ? ORDER BY payment_date ASC");
$payments->execute([$id]);
$payments = $payments->fetchAll();

$serials = $pdo->prepare("SELECT s.*, p.product_type FROM product_serials s JOIN products p ON p.id = s.product_id WHERE s.sale_id = ? ORDER BY s.id");
$serials->execute([$id]);
$serials = $serials->fetchAll();

$data = [
    'id' => $sale['id'],
    'invoice_no' => $sale['invoice_no'],
    'sale_date' => $sale['sale_date'],
    'subtotal' => $sale['subtotal'],
    'discount_amount' => $sale['discount_amount'] ?? 0,
    'total_amount' => $sale['total_amount'],
    'paid_amount' => $sale['paid_amount'] ?? 0,
    'due_amount' => $sale['due_amount'] ?? 0,
    'payment_status' => $sale['payment_status'],
    'payment_method' => $sale['payment_method'],
    'notes' => $sale['notes'],
    'created_at' => $sale['created_at'],
    'customer_name' => $customer ? $customer['full_name'] : '-',
    'customer_phone' => $customer ? $customer['phone'] : '',
    'customer_address' => $customer ? $customer['address'] : '',
    'customer_cnic' => $customer ? $customer['cnic'] : '',
    'items' => [],
    'serials' => [],
    'payments' => []
];

foreach ($items as $item) {
    $prod = getById('products', $item['product_id']);
    $prodName = $prod ? $prod['name'] : 'Unknown';
    $prodCode = $prod ? $prod['code'] : '';
    $prodType = $prod ? $prod['product_type'] : 'general';
    $desc = preg_replace('/\s*\[IMEI:[^\]]*\]\s*/', '', $item['item_description'] ?: '');
    $data['items'][] = [
        'product_id' => $item['product_id'],
        'product_name' => $desc ?: $prodName,
        'product_code' => $prodCode,
        'product_type' => $prodType,
        'quantity' => $item['quantity'],
        'price' => $item['price'],
        'subtotal' => $item['subtotal'],
    ];
}

foreach ($serials as $s) {
    $data['serials'][] = [
        'serial_id' => $s['id'],
        'product_id' => $s['product_id'],
        'imei1' => $s['imei_number'],
        'imei2' => $s['serial_number'],
        'serial_number' => $s['serial_number'],
        'notes' => $s['notes'],
        'status' => $s['status'],
        'product_type' => $s['product_type'],
    ];
}

foreach ($payments as $p) {
    $data['payments'][] = [
        'payment_date' => $p['payment_date'],
        'amount' => $p['amount'],
        'payment_method' => $p['payment_method'],
        'payment_type' => $p['payment_type'],
        'notes' => $p['notes'],
    ];
}

header('Content-Type: application/json');
echo json_encode($data);
