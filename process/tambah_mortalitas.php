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

csrf_verify('../mortalitas.php');

require_once __DIR__ . '/../config/validation.php';

$userId = (int) $_SESSION['user_id'];
$siklusId = post_int('siklus_id');
$tanggal = post_raw('tanggal');
$jumlahEkor = post_int('jumlah_ekor');
$penyebab = post_trimmed('penyebab');
$catatan = post_trimmed('catatan');

if (!valid_positive_int($siklusId)
    || !valid_date_ymd($tanggal)
    || !valid_positive_int($jumlahEkor)) {
    redirect('../mortalitas.php?error=invalid');
}

if ($penyebab !== '' && !valid_text($penyebab, 1, 150)) {
    redirect('../mortalitas.php?error=invalid');
}

if ($catatan !== '' && !valid_text($catatan, 1, 1000)) {
    redirect('../mortalitas.php?error=invalid');
}

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/logger.php';

$conn->begin_transaction();

try {
    // 1. Verifikasi kepemilikan siklus dan kunci baris untuk mencegah race condition
    $stmt = $conn->prepare("SELECT s.id, s.status, s.tanggal_mulai, s.jumlah_tebar, k.nama AS nama_kolam FROM siklus_budidaya s JOIN kolam k ON s.kolam_id = k.id WHERE s.id = ? AND k.user_id = ? LIMIT 1 FOR UPDATE");
    if ($stmt === false) {
        $conn->rollback();
        redirect('../mortalitas.php?error=db_error');
    }
    $stmt->bind_param('ii', $siklusId, $userId);
    $stmt->execute();
    $cycleRes = $stmt->get_result();
    $cycle = $cycleRes->fetch_assoc();
    $stmt->close();

    if (!$cycle) {
        $conn->rollback();
        redirect('../mortalitas.php?error=forbidden_cycle');
    }

    if ($cycle['status'] !== 'berjalan') {
        $conn->rollback();
        redirect('../mortalitas.php?error=cycle_not_running');
    }

    if ($tanggal < $cycle['tanggal_mulai']) {
        $conn->rollback();
        redirect('../mortalitas.php?error=date_before_start');
    }

    $tebar = (int) $cycle['jumlah_tebar'];

    // 2. Hitung total mortalitas aktif yang sudah tercatat
    $stmtMort = $conn->prepare("SELECT COALESCE(SUM(jumlah_ekor), 0) AS total_mati FROM mortalitas WHERE siklus_id = ? AND status_catatan = 'aktif'");
    if ($stmtMort === false) {
        $conn->rollback();
        redirect('../mortalitas.php?error=db_error');
    }
    $stmtMort->bind_param('i', $siklusId);
    $stmtMort->execute();
    $mortRow = $stmtMort->get_result()->fetch_assoc();
    $totalMati = (int) ($mortRow['total_mati'] ?? 0);
    $stmtMort->close();

    // 3. Hitung total panen ekor aktif yang sudah tercatat
    $stmtPanen = $conn->prepare("SELECT COALESCE(SUM(jumlah_ekor), 0) AS total_panen FROM panen WHERE siklus_id = ? AND status_catatan = 'aktif' AND jumlah_ekor IS NOT NULL");
    if ($stmtPanen === false) {
        $conn->rollback();
        redirect('../mortalitas.php?error=db_error');
    }
    $stmtPanen->bind_param('i', $siklusId);
    $stmtPanen->execute();
    $panenRow = $stmtPanen->get_result()->fetch_assoc();
    $totalPanen = (int) ($panenRow['total_panen'] ?? 0);
    $stmtPanen->close();

    $sisaIkan = max(0, $tebar - $totalMati - $totalPanen);

    // 4. Validasi bisnis: jumlah kematian tidak boleh melebihi sisa ikan
    if ($jumlahEkor > $sisaIkan) {
        $conn->rollback();
        redirect('../mortalitas.php?error=exceeds_stock&remaining=' . $sisaIkan);
    }

    // 5. Simpan catatan mortalitas
    $stmt = $conn->prepare("INSERT INTO mortalitas (siklus_id, tanggal, jumlah_ekor, penyebab, catatan, status_catatan) VALUES (?, ?, ?, ?, ?, 'aktif')");
    if ($stmt === false) {
        $conn->rollback();
        redirect('../mortalitas.php?error=db_error');
    }

    $penyebabVal = $penyebab !== '' ? $penyebab : null;
    $catatanVal = $catatan !== '' ? $catatan : null;
    $stmt->bind_param('isisss', $siklusId, $tanggal, $jumlahEkor, $penyebabVal, $catatanVal);
    $stmt->execute();
    $insertId = (int) $conn->insert_id;
    $stmt->close();

    $conn->commit();

    // 6. Catat log audit
    $sisaBaru = $sisaIkan - $jumlahEkor;
    write_log(
        $conn,
        'TAMBAH_MORTALITAS',
        $userId,
        "siklus_id={$siklusId} ekor={$jumlahEkor} sisa_sebelum={$sisaIkan} sisa_sesudah={$sisaBaru}",
        'mortalitas',
        $insertId
    );

    redirect('../mortalitas.php?success=added');
} catch (Throwable $e) {
    if ($conn->connect_errno === 0) {
        @$conn->rollback();
    }
    error_log('Tambah mortalitas error: ' . $e->getMessage());
    redirect('../mortalitas.php?error=system_error');
}
