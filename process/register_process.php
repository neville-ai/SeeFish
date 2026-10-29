<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Aksi ini hanya menerima metode POST.';
    exit;
}

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';

csrf_verify('../register.php');

require_once __DIR__ . '/../config/validation.php';

$user = post_trimmed('user');
$pass = post_raw('pass');
$confirm = post_raw('confirm');

if (!valid_username($user) || !valid_password($pass)) {
    redirect('../register.php?error=invalid&user=' . urlencode($user));
}

if ($pass !== $confirm) {
    redirect('../register.php?error=mismatch&user=' . urlencode($user));
}

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/logger.php';

try {
    // 1. Periksa apakah username sudah digunakan
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    if ($stmt === false) {
        redirect('../register.php?error=db_error');
    }
    $stmt->bind_param('s', $user);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res->num_rows > 0;
    $stmt->close();

    if ($exists) {
        redirect('../register.php?error=taken');
    }

    // 2. Buat hash password aman dengan BCRYPT cost 12
    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    if (!is_string($hash)) {
        redirect('../register.php?error=system_error');
    }

    // 3. Simpan akun pengguna baru ke tabel users
    $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
    if ($stmt === false) {
        redirect('../register.php?error=db_error');
    }
    $stmt->bind_param('ss', $user, $hash);
    if (!$stmt->execute()) {
        $stmt->close();
        redirect('../register.php?error=db_error');
    }
    $newId = (int) $conn->insert_id;
    $stmt->close();

    // 4. Catat aktivitas ke tabel audit log
    write_log($conn, 'REGISTER_SUCCESS', $newId, 'username=' . $user, 'user', $newId);

    // 5. Alihkan ke halaman login dengan pesan sukses
    redirect('../login?success=registered');
} catch (Throwable $e) {
    error_log('Register error: ' . $e->getMessage());
    redirect('../register.php?error=system_error');
}
