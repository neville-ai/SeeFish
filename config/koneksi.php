<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$dbHost = (string) (env('DB_HOST', '127.0.0.1') ?? '127.0.0.1');
$dbPort = (int) (env('DB_PORT', '3306') ?? '3306');
$dbName = (string) (env('DB_NAME', 'seefish_db') ?? 'seefish_db');
$dbUser = (string) (env('DB_USER', '') ?? '');
$dbPass = (string) (env('DB_PASS', '') ?? '');

try {
    $conn = mysqli_connect($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    mysqli_set_charset($conn, 'utf8mb4');
} catch (Throwable $error) {
    // Jangan tampilkan host, user, password, atau detail koneksi.
    error_log('SeeFish database connection failed: ' . get_class($error));
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Layanan sedang tidak tersedia.';
    exit;
}
