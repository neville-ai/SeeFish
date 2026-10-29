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

csrf_verify('../kolam.php');

require_once __DIR__ . '/../config/validation.php';

$userId = (int) $_SESSION['user_id'];
$id = post_int('id');

if (!valid_positive_int($id)) {
    redirect('../kolam.php?error=invalid');
}

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/logger.php';

try {
    // 1. Verifikasi kepemilikan kolam
    $stmt = $conn->prepare(
        "SELECT id, nama FROM kolam WHERE id = ? AND user_id = ? LIMIT 1"
    );
    if ($stmt === false) {
        redirect('../kolam.php?error=db_error');
    }
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $pond = $res->fetch_assoc();
    $stmt->close();

    if (!$pond) {
        redirect('../kolam.php?error=not_found');
    }

    // 2. Cegah hapus jika masih memiliki siklus berjalan
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM siklus_budidaya WHERE kolam_id = ? AND status = 'berjalan' LIMIT 1"
    );
    if ($stmt === false) {
        redirect('../kolam.php?error=db_error');
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $cntRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((int) ($cntRow['cnt'] ?? 0) > 0) {
        redirect('../kolam.php?error=has_active_cycle');
    }

    // 3. Hapus kolam
    $stmt = $conn->prepare("DELETE FROM kolam WHERE id = ? AND user_id = ?");
    if ($stmt === false) {
        redirect('../kolam.php?error=db_error');
    }
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $stmt->close();

    write_log(
        $conn,
        'HAPUS_KOLAM',
        $userId,
        'kolam_id=' . $id . ' nama=' . (string) ($pond['nama'] ?? ''),
        'kolam',
        $id
    );

    redirect('../kolam.php?success=deleted');
} catch (Throwable $e) {
    error_log('Hapus kolam error: ' . $e->getMessage());
    redirect('../kolam.php?error=system_error');
}