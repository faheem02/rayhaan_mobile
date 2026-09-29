<?php
session_start();
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

$imei = trim($_GET['imei'] ?? '');
if ($imei === '') {
    echo json_encode(['found' => false, 'message' => 'IMEI number required']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT ps.id AS serial_id, ps.product_id, ps.imei_number, ps.serial_number AS imei2,
           ps.status AS serial_status, ps.notes AS serial_notes, ps.purchase_price,
           p.name AS product_name, p.code AS product_code, p.sale_price, p.stock_quantity,
           p.product_type, p.color, p.storage, p.ram
    FROM product_serials ps
    JOIN products p ON p.id = ps.product_id
    WHERE ps.imei_number = ? OR ps.serial_number = ?
    ORDER BY ps.id DESC
    LIMIT 1
");
$stmt->execute([$imei, $imei]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['found' => false, 'message' => 'IMEI (' . htmlspecialchars($imei) . ') not found in database.']);
    exit;
}

if ($row['serial_status'] === 'sold') {
    echo json_encode([
        'found' => true,
        'sold' => true,
        'product_name' => $row['product_name'],
        'imei' => $row['imei_number'],
        'message' => 'This mobile (' . $row['product_name'] . ' - IMEI: ' . $row['imei_number'] . ') is already marked as SOLD.'
    ]);
    exit;
}

echo json_encode([
    'found' => true,
    'sold' => false,
    'serial_id' => (int)$row['serial_id'],
    'product_id' => (int)$row['product_id'],
    'product_name' => $row['product_name'],
    'product_code' => $row['product_code'],
    'product_label' => $row['product_name'] . ' (' . $row['product_code'] . ')',
    'product_type' => $row['product_type'],
    'sale_price' => (float)$row['sale_price'],
    'stock_quantity' => (int)$row['stock_quantity'],
    'imei_number' => $row['imei_number'],
    'imei2' => $row['imei2'],
    'notes' => $row['serial_notes'],
    'details' => trim(($row['storage'] ?: '') . ' ' . ($row['ram'] ?: '') . ' ' . ($row['color'] ?: ''))
]);