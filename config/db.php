<?php
$host = 'localhost';
$username = 'root';
$password = '';

// ============================================================
// DB MODE: change this to switch between Client and Test DB
//   'client' - production database (rayhaan_mobile)
//   'test'   - testing/development database (rayhaan_mobile_test)
// ============================================================
$db_mode = 'client';

$databases = [
    'client' => 'rayhaan_mobile',
    'test'   => 'rayhaan_mobile_test',
];

$dbname = $databases[$db_mode] ?? $databases['client'];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
