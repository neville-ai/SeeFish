<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/csrf.php';

if (!is_post()) {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Logout harus dilakukan melalui formulir.';
    exit;
}

if (empty($_SESSION['login'])) {
    redirect('login');
}

csrf_verify('dashboard');

require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/config/logger.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$username = (string) ($_SESSION['username'] ?? '-');

write_log($conn, 'LOGOUT', $userId, 'username=' . $username);

destroy_current_session();
redirect('login');
