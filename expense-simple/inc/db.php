<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Kuala_Lumpur');

$host = '127.0.0.1';
$port = 3307;                 // <-- XAMPP MariaDB port
$user = 'root';
$pass = '';
$db   = 'expense_tracker';    // make sure this DB exists

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}
