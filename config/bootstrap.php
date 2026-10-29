<?php
declare(strict_types=1);

/**
 * Bootstrap aplikasi SeeFish.
 *
 * File ini hanya memuat konfigurasi environment, helper, timezone,
 * dan penanganan error umum. Tidak ada kredensial yang disimpan di sini.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

/**
 * Memuat .env lokal jika tersedia. Nilai yang sudah ada di environment
 * proses tidak ditimpa.
 */
function load_env_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $separator = strpos($line, '=');
        if ($separator === false) {
            continue;
        }

        $key = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));

        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
            continue;
        }

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        if (array_key_exists($key, $_ENV) || getenv($key) !== false) {
            continue;
        }

        $_ENV[$key] = $value;
    }
}

function env(string $key, ?string $default = null): ?string
{
    if (array_key_exists($key, $_ENV)) {
        return (string) $_ENV[$key];
    }

    if (array_key_exists($key, $_SERVER)) {
        return (string) $_SERVER[$key];
    }

    $value = getenv($key);
    return $value === false ? $default : (string) $value;
}

function app_env(): string
{
    $value = env('APP_ENV', 'production');
    return ($value === null || $value === '') ? 'production' : strtolower($value);
}

function env_bool(string $key, bool $default = false): bool
{
    $value = env($key);
    if ($value === null || $value === '') {
        return $default;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed === null ? $default : $parsed;
}

function is_https_request(): bool
{
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    return isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443';
}

load_env_file(APP_ROOT . '/.env');

date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Makassar') ?? 'Asia/Makassar');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');

set_exception_handler(static function (Throwable $error): void {
    // Jangan menulis pesan lengkap, query, atau data request ke log publik.
    error_log(sprintf(
        'SeeFish unhandled error: %s in %s:%d',
        get_class($error),
        $error->getFile(),
        $error->getLine()
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>Kesalahan - SeeFish</title></head><body>';
    echo '<h1>Terjadi kesalahan</h1>';
    echo '<p>Permintaan tidak dapat diproses. Silakan coba lagi atau hubungi administrator.</p>';
    echo '</body></html>';
    exit;
});

require_once __DIR__ . '/helpers.php';
