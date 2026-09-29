<?php
session_start();
require_once '../../includes/functions.php';
$id = (int)($_GET['id'] ?? 0);
$party = getById('general_parties', $id);
if ($party) {
    echo json_encode($party);
} else {
    echo json_encode(['error' => 'Not found']);
}
