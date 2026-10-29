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
$siklusId = post_int('siklus_id');
$tanggalRencana = post_raw('tanggal_rencana');
$waktuRencana = post_trimmed('waktu_rencana');
$namaPakan = post_trimmed('nama_pakan');
$jumlahKgRencana = post_float('jumlah_kg_rencana');
$catatan = post_trimmed('catatan');

if (!valid_positive_int($siklusId)
    || !valid_date_ymd($tanggalRencana)
    || !valid_time_hi($waktuRencana)
    || !valid_text($namaPakan, 1, 100)
    || !valid_positive_number($jumlahKgRencana)
    || $jumlahKgRencana > 999999.99) {
    redirect('../pakan.php?error=invalid');
}

if ($catatan !== '' && !valid_text($catatan, 1, 1000)) {
    redirect('../pakan.php?error=invalid');
}

if (strlen($waktuRencana) === 5) {
    $waktuRencana .= ':00';
}

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/logger.php';

try {
    // 1. Verifikasi kepemilikan siklus budidaya oleh pengguna yang login
    $stmt = $conn->prepare(
        "SELECT s.id, s.status, s.tanggal_mulai, k.nama AS nama_kolam
         FROM siklus_budidaya s
         JOIN kolam k ON s.kolam_id = k.id
         WHERE s.id = ? AND k.user_id = ?
         LIMIT 1"
    );
    if ($stmt === false) {
        redirect('../pakan.php?error=db_error');
    }
    $stmt->bind_param('ii', $siklusId, $userId);
    $stmt->execute();
    $cycleRes = $stmt->get_result();
    $cycle = $cycleRes->fetch_assoc();
    $stmt->close();

    if (!$cycle) {
        redirect('../pakan.php?error=forbidden_cycle');
    }

    if ($cycle['status'] !== 'berjalan') {
        redirect('../pakan.php?error=cycle_not_running');
    }

    if ($tanggalRencana < $cycle['tanggal_mulai']) {
        redirect('../pakan.php?error=date_before_start');
    }

    // 2. Simpan jadwal pemberian pakan baru
    $stmt = $conn->prepare(
        "INSERT INTO jadwal_pakan
         (siklus_id, tanggal_rencana, waktu_rencana, nama_pakan, jumlah_kg_rencana, status, catatan)
         VALUES (?, ?, ?, ?, ?, 'direncanakan', ?)"
    );
    if ($stmt === false) {
        redirect('../pakan.php?error=db_error');
    }

    $catatanVal = $catatan !== '' ? $catatan : null;
    $stmt->bind_param('isssds', $siklusId, $tanggalRencana, $waktuRencana, $namaPakan, $jumlahKgRencana, $catatanVal);
    $stmt->execute();
    $insertId = (int) $conn->insert_id;
    $stmt->close();

    // 3. Catat jejak audit ke activity_log
    write_log(
        $conn,
        'TAMBAH_JADWAL_PAKAN',
        $userId,
        "jadwal_id={$insertId} siklus_id={$siklusId} tgl={$tanggalRencana} waktu={$waktuRencana} kg={$jumlahKgRencana}",
        'jadwal_pakan',
        $insertId
    );

    redirect('../pakan.php?success=schedule_added');
} catch (Throwable $e) {
    error_log('Tambah jadwal pakan error: ' . $e->getMessage());
    redirect('../pakan.php?error=system_error');
}
