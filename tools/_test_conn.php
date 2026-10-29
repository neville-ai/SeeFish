<?php
require_once __DIR__ . '/../config/bootstrap.php';

$dbHost = (string) (env('DB_HOST', '127.0.0.1') ?? '127.0.0.1');
$dbPort = (int) (env('DB_PORT', '3306') ?? '3306');
$dbName = (string) (env('DB_NAME', 'seefish_db') ?? 'seefish_db');
$dbUser = (string) (env('DB_USER', '') ?? '');
$dbPass = (string) (env('DB_PASS', '') ?? '');

echo "Host: $dbHost:$dbPort, DB: $dbName, User: $dbUser\n";

try {
    $conn = mysqli_connect($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    echo "Connected successfully!\n";
} catch (Throwable $e) {
    echo "Connection error: " . $e->getMessage() . "\n";
}

