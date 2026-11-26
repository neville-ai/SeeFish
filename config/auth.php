<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';

$sessionTimeout = session_timeout_seconds();
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


// Tolak sesi autentikasi yang tidak memiliki role yang valid.
if (!isset($_SESSION['role'])
    || !is_string($_SESSION['role'])
    || !in_array($_SESSION['role'], ['Admin Kolam'], true)) {
    destroy_current_session();
    redirect($loginRedirect);
}
