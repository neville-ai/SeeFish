<?php
declare(strict_types=1);

// Endpoint autentikasi hanya menerima POST.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}


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
require_once __DIR__ . '/../config/login_limiter.php';

$ip = current_ip();

// Cek pembatasan sebelum memproses kredensial.
if (login_limiter_is_blocked($conn, $user, $ip)) {
    write_log(
        $conn,
        'LOGIN_RATE_LIMITED',
        0,
        'Login temporarily blocked',
        'security',
        null
    );

    redirect('../login?error=1');
}

$stmt = $conn->prepare(
    "SELECT id, username, password, role FROM users
     WHERE username = ?
     LIMIT 1"
);
$stmt->bind_param('s', $user);
$stmt->execute();

$result = $stmt->get_result();
$row = $result->num_rows === 1 ? $result->fetch_assoc() : null;
$stmt->close();

if ($row !== null) {
    $hash = (string) ($row['password'] ?? '');

    if (password_verify($pass, $hash)) {
        login_limiter_clear_user($conn, $user);

        session_regenerate_id(true);

        $_SESSION['login'] = true;
        $_SESSION['username'] = (string) $row['username'];
        $_SESSION['user_id'] = (int) $row['id'];
        $role = trim((string) ($row['role'] ?? ''));
        $allowedRoles = ['Admin Kolam'];

        if (!in_array($role, $allowedRoles, true)) {
            write_log(
                $conn,
                'LOGIN_REJECTED_INVALID_ROLE',
                (int) $row['id'],
                'Invalid or unsupported account role',
                'security',
                (int) $row['id']
            );
            redirect('../login?error=1');
        }

        $_SESSION['role'] = $role;
        $_SESSION['last_activity'] = time();
        $_SESSION['ip'] = $ip;
        $_SESSION['ua'] = current_user_agent();

        csrf_rotate();

        write_log(
            $conn,
            'LOGIN_SUCCESS',
            (int) $row['id'],
            'username=' . $user,
            'user',
            (int) $row['id']
        );

        redirect('../dashboard');
    }
}

// Catat kegagalan untuk username dan IP.
$newlyLocked = login_limiter_record_failure($conn, $user, $ip);

write_log(
    $conn,
    'LOGIN_FAILED',
    (int) ($row['id'] ?? 0),
    'username=' . $user,
    'user',
    $row['id'] ?? null
);

if ($newlyLocked !== []) {
    write_log(
        $conn,
        'LOGIN_RATE_LIMIT_TRIGGERED',
        0,
        'scope=' . implode(',', $newlyLocked),
        'security',
        null
    );
}

redirect('../login?error=1');
