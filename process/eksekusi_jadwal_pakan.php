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
$tanggalAktual = post_raw('tanggal_aktual');
$jumlahKgAktual = post_float('jumlah_kg_aktual');
$catatanAktual = post_trimmed('catatan_aktual');

if (!valid_positive_int($jadwalId)
    || !valid_date_ymd($tanggalAktual)
    || !valid_positive_number($jumlahKgAktual)
    || $jumlahKgAktual > 999999.99) {
    redirect('../pakan.php?error=invalid');
}

if ($catatanAktual !== '' && !valid_text($catatanAktual, 1, 1000)) {
    redirect('../pakan.php?error=invalid');
}

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/logger.php';

$conn->begin_transaction();

try {
    // 1. Kunci baris jadwal dan verifikasi kepemilikan oleh user yang login
    $stmt = $conn->prepare(
        "SELECT j.id, j.siklus_id, j.nama_pakan, j.status, j.pemberian_pakan_id,
                s.tanggal_mulai, s.status AS status_siklus, k.nama AS nama_kolam
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

    // 2. Cegah eksekusi ganda / idempotensi
    if ($jadwal['status'] !== 'direncanakan' || $jadwal['pemberian_pakan_id'] !== null) {
        $conn->rollback();
        redirect('../pakan.php?error=already_executed');
    }

    if ($jadwal['status_siklus'] !== 'berjalan') {
        $conn->rollback();
        redirect('../pakan.php?error=cycle_not_running');
    }

    if ($tanggalAktual < $jadwal['tanggal_mulai']) {
        $conn->rollback();
        redirect('../pakan.php?error=date_before_start');
    }

    // 3. Masukkan catatan aktual ke pemberian_pakan
    $prefix = "[Realisasi Jadwal #{$jadwalId}]";
    $finalCatatan = $catatanAktual !== '' ? $prefix . ' ' . $catatanAktual : $prefix;
    if (function_exists('mb_substr')) {
        $finalCatatan = mb_substr($finalCatatan, 0, 1000, 'UTF-8');
    } else {
        $finalCatatan = substr($finalCatatan, 0, 1000);
    }

    $stmtInsert = $conn->prepare(
        "INSERT INTO pemberian_pakan (siklus_id, tanggal, nama_pakan, jumlah_kg, catatan)
         VALUES (?, ?, ?, ?, ?)"
    );
    if ($stmtInsert === false) {
        $conn->rollback();
        redirect('../pakan.php?error=db_error');
    }

    $siklusId = (int) $jadwal['siklus_id'];
    $namaPakan = (string) $jadwal['nama_pakan'];
    $stmtInsert->bind_param('issds', $siklusId, $tanggalAktual, $namaPakan, $jumlahKgAktual, $finalCatatan);
    $stmtInsert->execute();
    $feedLogId = (int) $conn->insert_id;
    $stmtInsert->close();

    // 4. Update status jadwal_pakan menjadi terlaksana dengan relasi unik
    $stmtUpdate = $conn->prepare(
        "UPDATE jadwal_pakan
         SET status = 'terlaksana', pemberian_pakan_id = ?
         WHERE id = ? AND status = 'direncanakan'"
    );
    if ($stmtUpdate === false) {
        $conn->rollback();
        redirect('../pakan.php?error=db_error');
    }
    $stmtUpdate->bind_param('ii', $feedLogId, $jadwalId);
    $stmtUpdate->execute();
    $stmtUpdate->close();

    // 5. Catat jejak audit aktivitas
    write_log(
        $conn,
        'EKSEKUSI_JADWAL_PAKAN',
        $userId,
        "jadwal_id={$jadwalId} feed_log_id={$feedLogId} kg={$jumlahKgAktual}",
        'jadwal_pakan',
        $jadwalId
    );

    $conn->commit();
    redirect('../pakan.php?success=schedule_executed');
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Eksekusi jadwal pakan error: ' . $e->getMessage());
    redirect('../pakan.php?error=system_error');
}
