<?php
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access Denied");
}

date_default_timezone_set('Asia/Jakarta');

// KONFIGURASI DATABASE ANDA
$host = '127.0.0.1';
$db   = '<yourdb>';
$user = '<yourUser>';
$pass = '<YourPass>'; // Sesuaikan dengan password database server Anda
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false, // Mencegah SQL Injection
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $pdo->exec("SET time_zone = '+07:00';");
} catch (\PDOException $e) {
    error_log("DB Connection Failed: " . $e->getMessage());
    http_response_code(500);
    die("Sistem sedang dalam pemeliharaan. Silakan kembali beberapa saat lagi.");
}
?>