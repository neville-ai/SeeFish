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

csrf_verify('../pakan.php');

require_once __DIR__ . '/../config/validation.php';

$userId = (int) $_SESSION['user_id'];
$jadwalId = post_int('jadwal_id');
$alasanBatal = post_trimmed('alasan_batal');

if (!valid_positive_int($jadwalId) || !valid_text($alasanBatal, 3, 500)) {
    redirect('../pakan.php?error=invalid');
}

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/logger.php';

$conn->begin_transaction();

try {
    // 1. Verifikasi kepemilikan dan status jadwal
    $stmt = $conn->prepare(
        "SELECT j.id, j.status, j.catatan, j.siklus_id
         FROM jadwal_pakan j
         JOIN siklus_budidaya s ON j.siklus_id = s.id
         JOIN kolam k ON s.kolam_id = k.id
         WHERE j.id = ? AND k.user_id = ?
         LIMIT 1 FOR UPDATE"
    );
    if ($stmt === false) {
        $conn->rollback();
        redirect('../pakan.php?error=db_error');
    }
    $stmt->bind_param('ii', $jadwalId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $jadwal = $res->fetch_assoc();
    $stmt->close();

    if (!$jadwal) {
        $conn->rollback();
        redirect('../pakan.php?error=forbidden_schedule');
    }

    if ($jadwal['status'] !== 'direncanakan') {
        $conn->rollback();
        redirect('../pakan.php?error=cannot_cancel');
    }

    // 2. Format catatan pembatalan
    $existing = (string) ($jadwal['catatan'] ?? '');
    $prefix = "[DIBATALKAN: {$alasanBatal}]";
    $finalCatatan = $existing !== '' ? $prefix . ' ' . $existing : $prefix;
    if (function_exists('mb_substr')) {
        $finalCatatan = mb_substr($finalCatatan, 0, 1000, 'UTF-8');
    } else {
        $finalCatatan = substr($finalCatatan, 0, 1000);
    }

    // 3. Update status menjadi dibatalkan
    $stmtUpdate = $conn->prepare(
        "UPDATE jadwal_pakan
         SET status = 'dibatalkan', catatan = ?
         WHERE id = ? AND status = 'direncanakan'"
    );
    if ($stmtUpdate === false) {
        $conn->rollback();
        redirect('../pakan.php?error=db_error');
    }
    $stmtUpdate->bind_param('si', $finalCatatan, $jadwalId);
    $stmtUpdate->execute();
    $stmtUpdate->close();

    // 4. Catat aktivitas audit
    write_log(
        $conn,
        'BATAL_JADWAL_PAKAN',
        $userId,
        "jadwal_id={$jadwalId} alasan={$alasanBatal}",
        'jadwal_pakan',
        $jadwalId
    );

    $conn->commit();
    redirect('../pakan.php?success=schedule_cancelled');
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Batal jadwal pakan error: ' . $e->getMessage());
    redirect('../pakan.php?error=system_error');
}
