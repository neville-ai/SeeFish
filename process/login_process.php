<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';

csrf_verify('../login.php');

$user = post_trimmed('user');
$pass = post_raw('pass');

if ($user === '' || $pass === '' || strlen($user) > 50 || strlen($pass) > 128) {
    redirect('../login?error=1');
}

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/logger.php';

$stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ? LIMIT 1");
$stmt->bind_param('s', $user);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->num_rows === 1 ? $result->fetch_assoc() : null;
$stmt->close();

if ($row) {
    $hash = (string) ($row['password'] ?? '');

    if (password_verify($pass, $hash)) {
        session_regenerate_id(true);

        $_SESSION['login'] = true;
        $_SESSION['username'] = (string) $row['username'];
        $_SESSION['user_id'] = (int) $row['id'];
        $_SESSION['role'] = 'Admin Kolam';
        $_SESSION['last_activity'] = time();
        $_SESSION['ip'] = current_ip();
        $_SESSION['ua'] = current_user_agent();

        csrf_rotate();
        write_log($conn, 'LOGIN_SUCCESS', (int) $row['id'], 'username=' . $user);

        redirect('../dashboard');
    }
}

write_log($conn, 'LOGIN_FAILED', (int) ($row['id'] ?? 0), 'username=' . $user);
redirect('../login?error=1');
