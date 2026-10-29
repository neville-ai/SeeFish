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
$kolamId = post_int('kolam_id');
$jenisIkanId = post_int('jenis_ikan_id');
$tanggalMulai = post_raw('tanggal_mulai');
$jumlahTebar = post_int('jumlah_tebar');
$targetPanenKg = post_float('target_panen_kg');
$catatan = post_trimmed('catatan');

if (!valid_positive_int($kolamId)
    || !valid_positive_int($jenisIkanId)
    || !valid_date_ymd($tanggalMulai)
    || !valid_positive_int($jumlahTebar)) {
    redirect('../siklus.php?error=invalid');
}

if ($targetPanenKg !== null && (!valid_positive_number($targetPanenKg) || $targetPanenKg > 999999999.99)) {
    redirect('../siklus.php?error=invalid');
}

if ($catatan !== '' && !valid_text($catatan, 1, 1000)) {
    redirect('../siklus.php?error=invalid');
}

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/logger.php';

try {
    // 1. Verifikasi kepemilikan kolam dan statusnya
    $stmt = $conn->prepare("SELECT id, status FROM kolam WHERE id = ? AND user_id = ? LIMIT 1");
    if ($stmt === false) {
        redirect('../siklus.php?error=db_error');
    }
    $stmt->bind_param('ii', $kolamId, $userId);
    $stmt->execute();
    $kolamRes = $stmt->get_result();
    $kolamRow = $kolamRes->fetch_assoc();
    $stmt->close();

    if (!$kolamRow) {
        redirect('../siklus.php?error=forbidden_pond');
    }

    if ($kolamRow['status'] !== 'aktif') {
        redirect('../siklus.php?error=pond_inactive');
    }

    // 2. Verifikasi jenis ikan
    $stmt = $conn->prepare("SELECT id FROM jenis_ikan WHERE id = ? AND aktif = 1 LIMIT 1");
    if ($stmt === false) {
        redirect('../siklus.php?error=db_error');
    }
    $stmt->bind_param('i', $jenisIkanId);
    $stmt->execute();
    $ikanRes = $stmt->get_result();
    $ikanValid = $ikanRes->num_rows > 0;
    $stmt->close();

    if (!$ikanValid) {
        redirect('../siklus.php?error=invalid_species');
    }

    // 3. Cek apakah sudah ada siklus berstatus 'berjalan' pada kolam ini
    $stmt = $conn->prepare("SELECT id FROM siklus_budidaya WHERE kolam_id = ? AND status = 'berjalan' LIMIT 1");
    if ($stmt === false) {
        redirect('../siklus.php?error=db_error');
    }
    $stmt->bind_param('i', $kolamId);
    $stmt->execute();
    $runningRes = $stmt->get_result();
    $alreadyRunning = $runningRes->num_rows > 0;
    $stmt->close();

    if ($alreadyRunning) {
        redirect('../siklus.php?error=cycle_running');
    }

    // 4. Insert siklus baru
    $stmt = $conn->prepare(
        "INSERT INTO siklus_budidaya (kolam_id, jenis_ikan_id, tanggal_mulai, status, jumlah_tebar, target_panen_kg, catatan)
         VALUES (?, ?, ?, 'berjalan', ?, ?, ?)"
    );
    if ($stmt === false) {
        redirect('../siklus.php?error=db_error');
    }

    $catatanVal = $catatan !== '' ? $catatan : null;
    $stmt->bind_param('iisids', $kolamId, $jenisIkanId, $tanggalMulai, $jumlahTebar, $targetPanenKg, $catatanVal);
    $stmt->execute();
    $newId = (int) $conn->insert_id;
    $stmt->close();

    write_log($conn, 'TAMBAH_SIKLUS', $userId, 'kolam_id=' . $kolamId . ' tebar=' . $jumlahTebar . ' id=' . $newId, 'siklus', $newId);
} catch (Throwable $error) {
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    // Jika ada pelanggaran constraint uq_one_running_cycle_per_pond
    if (str_contains($error->getMessage(), 'uq_one_running_cycle_per_pond')) {
        redirect('../siklus.php?error=cycle_running');
    }
    error_log('Tambah siklus error: ' . get_class($error) . ': ' . $error->getMessage());
    redirect('../siklus.php?error=db_error');
}

redirect('../siklus.php?success=added');
