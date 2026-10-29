<?php
declare(strict_types=1);

/**
 * Probe test-only untuk membuktikan isolasi APP_ROOT pada PHP built-in server.
 *
 * File ini tidak memuat bootstrap aplikasi dan tidak membaca .env. Probe hanya
 * memeriksa konstanta yang ditetapkan oleh server_prepend.php.
 */

if (
    PHP_SAPI !== 'cli-server'
    || (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1')
) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$expected = defined('SEE_FISH_HTTP_TEST_ROOT') ? SEE_FISH_HTTP_TEST_ROOT : null;
$actual = defined('APP_ROOT') ? APP_ROOT : null;
$projectRoot = dirname(__DIR__, 2);
$reasons = [];

if (!is_string($expected) || $expected === '') {
    $reasons[] = 'SEE_FISH_HTTP_TEST_ROOT tidak ditetapkan; auto_prepend_file tidak aktif';
}

if (!is_string($actual) || $actual === '') {
    $reasons[] = 'APP_ROOT tidak ditetapkan';
} elseif (is_string($expected) && $actual !== $expected) {
    $reasons[] = 'APP_ROOT tidak sama dengan root isolasi yang diharapkan';
}

if (is_string($actual) && $actual === $projectRoot) {
    $reasons[] = 'APP_ROOT menunjuk ke root proyek';
}

$envFilePresent = is_string($expected) && is_file($expected . DIRECTORY_SEPARATOR . '.env');
if ($envFilePresent) {
    $reasons[] = 'root isolasi memiliki file .env';
}

$ok = $reasons === [];

echo json_encode([
    'ok' => $ok,
    'mechanism' => 'auto_prepend_file',
    'env_file_present' => $envFilePresent,
    'reasons' => $reasons,
], JSON_UNESCAPED_SLASHES);
