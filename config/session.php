<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function session_cookie_secure(): bool
{
    $setting = strtolower((string) env('SESSION_SECURE', 'auto'));

    if (in_array($setting, ['1', 'true', 'on', 'yes'], true)) {
        return true;
    }

    if (in_array($setting, ['0', 'false', 'off', 'no'], true)) {
        return false;
    }

    return is_https_request();
}

function session_timeout_seconds(): int
{
    $configured = (int) (env('SESSION_TIMEOUT', '1800') ?? '1800');

    // Timeout minimal 5 menit dan maksimal 30 menit.
    return min(1800, max(300, $configured));
}

function destroy_current_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'] ?? '/',
            'domain'   => $params['domain'] ?? '',
            'secure'   => session_cookie_secure(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    session_destroy();
}

if (session_status() === PHP_SESSION_ACTIVE) {
    return;
}

if (session_status() === PHP_SESSION_NONE) {
    // Penguatan session fixation dan transport session.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) session_timeout_seconds());
    $sessionName = (string) env('SESSION_NAME', 'SEEFISH_SESSID');
    if (preg_match('/^[a-zA-Z0-9_-]+$/', $sessionName) === 1) {
        session_name($sessionName);
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => session_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
}
