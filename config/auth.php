<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';

$sessionTimeout = (int) (env('SESSION_TIMEOUT', '1800') ?? '1800');
$sessionTimeout = max(300, $sessionTimeout);
$loginRedirect = defined('AUTH_LOGIN_REDIRECT') ? AUTH_LOGIN_REDIRECT : 'login';

if (!empty($_SESSION['last_activity'])
    && (time() - (int) $_SESSION['last_activity']) > $sessionTimeout) {
    destroy_current_session();
    redirect($loginRedirect . '?error=timeout');
}

$_SESSION['last_activity'] = time();

if (empty($_SESSION['login']) || empty($_SESSION['user_id'])) {
    redirect($loginRedirect);
}
