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

csrf_verify('../panen.php');

require_once __DIR__ . '/../config/validation.php';

$userId = (int) $_SESSION['user_id'];
$siklusId = post_int('siklus_id');
$tanggal = post_raw('tanggal');
$jumlahKg = post_float('jumlah_kg');
$jumlahEkor = post_int('jumlah_ekor');
$hargaPerKg = post_float('harga_per_kg');
$catatan = post_trimmed('catatan');

if (!valid_positive_int($siklusId)
    || !valid_date_ymd($tanggal)
    || !valid_positive_number($jumlahKg)
    || $jumlahKg > 999999999.99) {
    redirect('../panen.php?error=invalid');
}

if ($jumlahEkor !== null && !valid_positive_int($jumlahEkor)) {
    redirect('../panen.php?error=invalid');
}

if ($hargaPerKg !== null && ($hargaPerKg < 0 || $hargaPerKg > 999999999999.99)) {
    redirect('../panen.php?error=invalid');
}

if ($catatan !== '' && !valid_text($catatan, 1, 1000)) {
    redirect('../panen.php?error=invalid');
}

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/logger.php';

$conn->begin_transaction();

try {
    // 1. Verifikasi kepemilikan siklus dan kunci baris untuk serialisasi transaksi
    $stmt = $conn->prepare("SELECT s.id, s.status, s.tanggal_mulai, s.jumlah_tebar, k.nama AS nama_kolam FROM siklus_budidaya s JOIN kolam k ON s.kolam_id = k.id WHERE s.id = ? AND k.user_id = ? LIMIT 1 FOR UPDATE");
    if ($stmt === false) {
        $conn->rollback();
        redirect('../panen.php?error=db_error');
    }
    $stmt->bind_param('ii', $siklusId, $userId);
    $stmt->execute();
    $cycleRes = $stmt->get_result();
    $cycle = $cycleRes->fetch_assoc();
    $stmt->close();

    if (!$cycle) {
        $conn->rollback();
        redirect('../panen.php?error=forbidden_cycle');
    }

    if ($cycle['status'] !== 'berjalan') {
        $conn->rollback();
        redirect('../panen.php?error=cycle_not_running');
    }

    if ($tanggal < $cycle['tanggal_mulai']) {
        $conn->rollback();
        redirect('../panen.php?error=date_before_start');
    }

    $tebar = (int) $cycle['jumlah_tebar'];

    // 2. Jika ada jumlah ekor, pastikan tidak melebihi estimasi ikan tersisa
    if ($jumlahEkor !== null) {
        $stmtMort = $conn->prepare("SELECT COALESCE(SUM(jumlah_ekor), 0) AS total_mati FROM mortalitas WHERE siklus_id = ? AND status_catatan = 'aktif'");
        if ($stmtMort === false) {
            $conn->rollback();
            redirect('../panen.php?error=db_error');
        }
        $stmtMort->bind_param('i', $siklusId);
        $stmtMort->execute();
        $mortRow = $stmtMort->get_result()->fetch_assoc();
        $totalMati = (int) ($mortRow['total_mati'] ?? 0);
        $stmtMort->close();

        $stmtPanen = $conn->prepare("SELECT COALESCE(SUM(jumlah_ekor), 0) AS total_panen FROM panen WHERE siklus_id = ? AND status_catatan = 'aktif' AND jumlah_ekor IS NOT NULL");
        if ($stmtPanen === false) {
            $conn->rollback();
            redirect('../panen.php?error=db_error');
        }
        $stmtPanen->bind_param('i', $siklusId);
        $stmtPanen->execute();
        $panenRow = $stmtPanen->get_result()->fetch_assoc();
        $totalPanen = (int) ($panenRow['total_panen'] ?? 0);
        $stmtPanen->close();

        $sisaIkan = max(0, $tebar - $totalMati - $totalPanen);

        if ($jumlahEkor > $sisaIkan) {
            $conn->rollback();
            redirect('../panen.php?error=exceeds_stock&remaining=' . $sisaIkan);
        }
    }

    // 3. Simpan catatan panen relasional ke seefish_db.panen
    $stmt = $conn->prepare("INSERT INTO panen (siklus_id, tanggal, jumlah_kg, jumlah_ekor, harga_per_kg, catatan, status_catatan) VALUES (?, ?, ?, ?, ?, ?, 'aktif')");
    if ($stmt === false) {
        $conn->rollback();
        redirect('../panen.php?error=db_error');
    }

    $catatanVal = $catatan !== '' ? $catatan : null;
    $stmt->bind_param('isdids', $siklusId, $tanggal, $jumlahKg, $jumlahEkor, $hargaPerKg, $catatanVal);
    $stmt->execute();
    $insertId = (int) $conn->insert_id;
    $stmt->close();

    $conn->commit();

    // 4. Catat log audit
    $ekorInfo = $jumlahEkor !== null ? (string) $jumlahEkor : 'null';
    write_log(
        $conn,
        'TAMBAH_PANEN',
        $userId,
        "siklus_id={$siklusId} kg={$jumlahKg} ekor={$ekorInfo}",
        'panen',
        $insertId
    );

    redirect('../panen.php?success=added');
} catch (Throwable $e) {
    if ($conn->connect_errno === 0) {
        @$conn->rollback();
    }
    error_log('Catat panen error: ' . $e->getMessage());
    redirect('../panen.php?error=system_error');
}
