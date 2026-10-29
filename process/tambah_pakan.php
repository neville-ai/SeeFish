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
$tanggal = post_raw('tanggal');
$namaPakan = post_trimmed('nama_pakan');
$jumlahKg = post_float('jumlah_kg');
$catatan = post_trimmed('catatan');

if (!valid_positive_int($siklusId)
    || !valid_date_ymd($tanggal)
    || !valid_text($namaPakan, 1, 100)
    || !valid_positive_number($jumlahKg)
    || $jumlahKg > 999999.99) {
    redirect('../pakan.php?error=invalid');
}

if ($catatan !== '' && !valid_text($catatan, 1, 1000)) {
    redirect('../pakan.php?error=invalid');
}

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/logger.php';

try {
    // 1. Verifikasi kepemilikan siklus melalui relasi kolam.user_id
    $stmt = $conn->prepare("SELECT s.id, s.status, s.tanggal_mulai, k.nama AS nama_kolam FROM siklus_budidaya s JOIN kolam k ON s.kolam_id = k.id WHERE s.id = ? AND k.user_id = ? LIMIT 1");
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

    if ($tanggal < $cycle['tanggal_mulai']) {
        redirect('../pakan.php?error=date_before_start');
    }

    // 2. Simpan catatan pakan
    $stmt = $conn->prepare("INSERT INTO pemberian_pakan (siklus_id, tanggal, nama_pakan, jumlah_kg, catatan) VALUES (?, ?, ?, ?, ?)");
    if ($stmt === false) {
        redirect('../pakan.php?error=db_error');
    }

    $catatanVal = $catatan !== '' ? $catatan : null;
    $stmt->bind_param('issds', $siklusId, $tanggal, $namaPakan, $jumlahKg, $catatanVal);
    $stmt->execute();
    $insertId = (int) $conn->insert_id;
    $stmt->close();

    // 3. Catat ke activity_log
    write_log(
        $conn,
        'TAMBAH_PAKAN',
        $userId,
        "siklus_id={$siklusId} pakan={$namaPakan} kg={$jumlahKg}",
        'pemberian_pakan',
        $insertId
    );

    redirect('../pakan.php?success=added');
} catch (Throwable $e) {
    error_log('Tambah pakan error: ' . $e->getMessage());
    redirect('../pakan.php?error=system_error');
}
