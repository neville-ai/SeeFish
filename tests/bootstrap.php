<?php
declare(strict_types=1);

/**
 * Bootstrap test unit SeeFish.
 *
 * APP_ROOT sengaja diarahkan ke path fixture yang tidak ada agar
 * config/bootstrap.php tidak menemukan file .env proyek maupun test. Test
 * ini tidak memuat koneksi database, autentikasi, logger, halaman, atau
 * handler aplikasi.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__ . '/fixtures/no-app-root');
}

require_once dirname(__DIR__) . '/config/bootstrap.php';

// Jangan biarkan exception yang lolos dari runner dianggap sukses.
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, sprintf(
        "Unhandled test error: %s: %s\n",
        get_class($error),
        $error->getMessage()
    ));
    exit(1);
});

require_once dirname(__DIR__) . '/config/validation.php';
require_once dirname(__DIR__) . '/config/csrf.php';
require_once __DIR__ . '/lib/assertions.php';

$GLOBALS['__test_initial_state'] = [
    '_ENV' => $_ENV,
    '_SERVER' => $_SERVER,
    '_GET' => $_GET,
    '_POST' => $_POST,
    '_REQUEST' => $_REQUEST,
    '_COOKIE' => $_COOKIE,
    '_FILES' => $_FILES,
    '_SESSION' => $_SESSION ?? [],
];

$GLOBALS['__test_saved_env'] = [];

/**
 * Mengubah environment untuk satu test dan mencatat nilai aslinya.
 * Nilai null menghapus key dari $_ENV, $_SERVER, dan environment proses.
 */
function test_env_set(string $key, ?string $value): void
{
    if (!array_key_exists($key, $GLOBALS['__test_saved_env'])) {
        $GLOBALS['__test_saved_env'][$key] = [
            'env_exists' => array_key_exists($key, $_ENV),
            'env_value' => $_ENV[$key] ?? null,
            'server_exists' => array_key_exists($key, $_SERVER),
            'server_value' => $_SERVER[$key] ?? null,
            'getenv_value' => getenv($key),
        ];
    }

    unset($_ENV[$key], $_SERVER[$key]);

    if ($value === null) {
        putenv($key);
        return;
    }

    $_ENV[$key] = $value;
    putenv($key . '=' . $value);
}

function test_env_restore(): void
{
    foreach ($GLOBALS['__test_saved_env'] as $key => $saved) {
        if ($saved['env_exists']) {
            $_ENV[$key] = $saved['env_value'];
        } else {
            unset($_ENV[$key]);
        }

        if ($saved['server_exists']) {
            $_SERVER[$key] = $saved['server_value'];
        } else {
            unset($_SERVER[$key]);
        }

        if ($saved['getenv_value'] === false) {
            putenv($key);
        } else {
            putenv($key . '=' . $saved['getenv_value']);
        }
    }

    $GLOBALS['__test_saved_env'] = [];
}

/**
 * Mengembalikan superglobal dan environment ke keadaan awal test runner.
 */
function test_reset_state(): void
{
    test_env_restore();

    $state = $GLOBALS['__test_initial_state'];
    $_ENV = $state['_ENV'];
    $_SERVER = $state['_SERVER'];
    $_GET = $state['_GET'];
    $_POST = $state['_POST'];
    $_REQUEST = $state['_REQUEST'];
    $_COOKIE = $state['_COOKIE'];
    $_FILES = $state['_FILES'];
    $_SESSION = $state['_SESSION'];
}
