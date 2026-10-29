<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Aksi ini hanya menerima metode POST.';
    exit;
}

if (!defined('AUTH_LOGIN_REDIRECT')) {
    define('AUTH_LOGIN_REDIRECT', '../login');
}

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

csrf_verify('../siklus.php');

require_once __DIR__ . '/../config/validation.php';

$userId = (int) $_SESSION['user_id'];
$id = post_int('id');
$tanggalSelesai = post_raw('tanggal_selesai');
$status = post_trimmed('status');

if (!valid_positive_int($id) || !valid_date_ymd($tanggalSelesai)) {
    redirect('../siklus.php?error=invalid');
}

if (!in_array($status, ['selesai', 'dibatalkan'], true)) {
    $status = 'selesai';
}

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/logger.php';

try {
    // Verifikasi kepemilikan siklus melalui relasi kolam.user_id
    $stmt = $conn->prepare(
        "SELECT s.id, s.tanggal_mulai, s.status
         FROM siklus_budidaya s
         JOIN kolam k ON s.kolam_id = k.id
         WHERE s.id = ? AND k.user_id = ?
         LIMIT 1"
    );
    if ($stmt === false) {
        redirect('../siklus.php?error=db_error');
    }
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if (!$row) {
        redirect('../siklus.php?error=not_found');
    }

    if ($row['status'] !== 'berjalan') {
        redirect('../siklus.php?error=already_closed');
    }

    if ($tanggalSelesai < $row['tanggal_mulai']) {
        redirect('../siklus.php?error=invalid_dates');
    }

    $stmt = $conn->prepare("UPDATE siklus_budidaya SET tanggal_selesai = ?, status = ? WHERE id = ?");
    if ($stmt === false) {
        redirect('../siklus.php?error=db_error');
    }
    $stmt->bind_param('ssi', $tanggalSelesai, $status, $id);
    $stmt->execute();
    $stmt->close();

    write_log($conn, 'TUTUP_SIKLUS', $userId, 'siklus_id=' . $id . ' status=' . $status, 'siklus', $id);
} catch (Throwable $error) {
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    error_log('Tutup siklus error: ' . get_class($error));
    redirect('../siklus.php?error=db_error');
}

redirect('../siklus.php?success=closed');
