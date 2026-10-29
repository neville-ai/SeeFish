<?php
declare(strict_types=1);

/**
 * auto_prepend_file untuk PHP built-in server pada smoke test HTTP.
 *
 * Menetapkan APP_ROOT ke direktori yang tidak berisi aplikasi dan tidak
 * memiliki .env. Probe HTTP memverifikasi bahwa file ini benar-benar dijalankan
 * sebelum endpoint aplikasi dijalankan.
 */

if (!defined('SEE_FISH_HTTP_TEST_ROOT')) {
    define('SEE_FISH_HTTP_TEST_ROOT', __DIR__ . DIRECTORY_SEPARATOR . 'no-app-root');
}

if (!defined('APP_ROOT')) {
    define('APP_ROOT', SEE_FISH_HTTP_TEST_ROOT);
}

/**
 * Trace test-only: mencatat file yang benar-benar di-include pada request.
 *
 * Hanya aktif jika runner mengirim header trace dan menetapkan path file
 * temporer di luar workspace. Tidak memuat bootstrap aplikasi dan tidak
 * membaca .env.
 */
$traceRequested = ($_SERVER['HTTP_X_SEEFISH_TEST_TRACE'] ?? '') === '1';
$traceFile = getenv('SEE_FISH_HTTP_TRACE_FILE');

if ($traceRequested && is_string($traceFile) && $traceFile !== '') {
    register_shutdown_function(static function () use ($traceFile): void {
        $included = array_map(
            static fn(string $path): string => str_replace('\\', '/', $path),
            get_included_files()
        );

        $payload = json_encode([
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'included' => $included,
        ], JSON_UNESCAPED_SLASHES);

        if (is_string($payload)) {
            @file_put_contents($traceFile, $payload . "\n", FILE_APPEND | LOCK_EX);
        }
    });
}
