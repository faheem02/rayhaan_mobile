<?php
require_once '../../includes/functions.php';

$product_id = (int)($_GET['product_id'] ?? 0);
if (!$product_id) { echo json_encode([]); exit; }

$stmt = $pdo->prepare("
    SELECT pu.purchase_date, s.name AS supplier_name, s.contact_person AS supplier_contact,
           pu.person_name, pu.invoice_no,
           pi.quantity, pi.purchase_price
    FROM purchase_items pi
    JOIN purchases pu ON pu.id = pi.purchase_id
    LEFT JOIN suppliers s ON s.id = pu.supplier_id
    WHERE pi.product_id = ?
    ORDER BY pu.purchase_date DESC
    LIMIT 20
");
$stmt->execute([$product_id]);
echo json_encode($stmt->fetchAll());
