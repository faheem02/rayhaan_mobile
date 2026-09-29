<?php
require_once '../../includes/functions.php';

$product_id = (int)($_GET['product_id'] ?? 0);
if (!$product_id) { echo json_encode(['error' => 'Invalid product']); exit; }

$stmt = $pdo->prepare("
    SELECT p.*, c.name AS category_name, b.name AS brand_name, s.name AS supplier_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN brands b ON b.id = p.brand_id
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    WHERE p.id = ?
");
$stmt->execute([$product_id]);
$product = $stmt->fetch();
if (!$product) { echo json_encode(['error' => 'Product not found']); exit; }

$serials = [];
if (in_array($product['product_type'], ['mobile', 'laptop'], true)) {
    $st = $pdo->prepare("
        SELECT ps.id, ps.imei_number, ps.serial_number, ps.purchase_price, ps.status, ps.notes,
               pu.invoice_no AS purchase_invoice, s.invoice_no AS sale_invoice
        FROM product_serials ps
        LEFT JOIN purchases pu ON pu.id = ps.purchase_id
        LEFT JOIN sales s ON s.id = ps.sale_id
        WHERE ps.product_id = ?
        ORDER BY ps.id
    ");
    $st->execute([$product_id]);
    $serials = $st->fetchAll();
}

echo json_encode(['product' => $product, 'serials' => $serials]);
