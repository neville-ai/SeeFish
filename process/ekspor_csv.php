<?php
declare(strict_types=1);

if (!defined('AUTH_LOGIN_REDIRECT')) {
    define('AUTH_LOGIN_REDIRECT', '../login');
}

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/validation.php';
require_once __DIR__ . '/../config/logger.php';

$userId = (int) $_SESSION['user_id'];
$tipe = (string) ($_GET['tipe'] ?? 'siklus');
$validTipe = ['siklus', 'pakan', 'mortalitas', 'panen'];
if (!in_array($tipe, $validTipe, true)) {
    $tipe = 'siklus';
}

$siklusId = isset($_GET['siklus_id']) && $_GET['siklus_id'] !== '' ? (int) $_GET['siklus_id'] : null;
$dariTanggal = isset($_GET['dari_tanggal']) && is_string($_GET['dari_tanggal']) && valid_date_ymd($_GET['dari_tanggal']) ? $_GET['dari_tanggal'] : null;
$sampaiTanggal = isset($_GET['sampai_tanggal']) && is_string($_GET['sampai_tanggal']) && valid_date_ymd($_GET['sampai_tanggal']) ? $_GET['sampai_tanggal'] : null;

// Jika siklus_id ditentukan, verifikasi kepemilikan oleh user yang login (Tenant Isolation)
if ($siklusId !== null) {
    $stmt = $conn->prepare(
        "SELECT s.id FROM siklus_budidaya s
         JOIN kolam k ON s.kolam_id = k.id
         WHERE s.id = ? AND k.user_id = ? LIMIT 1"
    );
    if ($stmt === false) {
        http_response_code(500);
        exit('Database error.');
    }
    $stmt->bind_param('ii', $siklusId, $userId);
    $stmt->execute();
    $ownRes = $stmt->get_result();
    if ($ownRes->num_rows === 0) {
        $stmt->close();
        http_response_code(403);
        exit('Akses ditolak: Anda tidak memiliki akses ke siklus ini.');
    }
    $stmt->close();
}

$headers = [];
$rows = [];

if ($tipe === 'siklus') {
    $headers = [
        'ID Siklus', 'Nama Kolam', 'Jenis Ikan', 'Tanggal Mulai', 'Tanggal Selesai',
        'Status Siklus', 'Tebar Awal (Ekor)', 'Total Kematian Terverifikasi (Ekor)',
        'Total Panen Terverifikasi (Ekor)', 'Total Panen (kg)', 'Estimasi Ikan Aktif (Ekor)',
        'Total Pakan (kg)', 'FCR', 'Catatan'
    ];

    $sql = "SELECT s.id, k.nama AS nama_kolam, j.nama AS nama_ikan, s.tanggal_mulai, s.tanggal_selesai,
                   s.status, s.jumlah_tebar, s.catatan
            FROM siklus_budidaya s
            JOIN kolam k ON s.kolam_id = k.id
            JOIN jenis_ikan j ON s.jenis_ikan_id = j.id
            WHERE k.user_id = ?";
    $params = [$userId];
    $types = 'i';

    if ($siklusId !== null) {
        $sql .= " AND s.id = ?";
        $params[] = $siklusId;
        $types .= 'i';
    }
    if ($dariTanggal !== null) {
        $sql .= " AND s.tanggal_mulai >= ?";
        $params[] = $dariTanggal;
        $types .= 's';
    }
    if ($sampaiTanggal !== null) {
        $sql .= " AND s.tanggal_mulai <= ?";
        $params[] = $sampaiTanggal;
        $types .= 's';
    }

    $sql .= " ORDER BY (s.status = 'berjalan') DESC, s.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($cycle = $res->fetch_assoc()) {
        $cid = (int) $cycle['id'];
        $stats = get_siklus_stock_stats($conn, $cid);
        $tebar = (int) $cycle['jumlah_tebar'];
        $mati = (int) ($stats['total_mortalitas'] ?? 0);
        $panenEkor = (int) ($stats['total_panen_ekor'] ?? 0);
        $panenKg = (float) ($stats['total_panen_kg'] ?? 0.0);
        $sisa = (int) ($stats['sisa_ikan'] ?? max(0, $tebar - $mati - $panenEkor));
        $pakanKg = (float) ($stats['total_pakan_kg'] ?? 0.0);
        $fcr = $panenKg > 0 ? round($pakanKg / $panenKg, 2) : '-';

        $rows[] = [
            $cid,
            $cycle['nama_kolam'],
            $cycle['nama_ikan'],
            $cycle['tanggal_mulai'],
            $cycle['tanggal_selesai'] ?? '-',
            ucfirst((string) $cycle['status']),
            $tebar,
            $mati,
            $panenEkor,
            $panenKg,
            $sisa,
            $pakanKg,
            $fcr,
            $cycle['catatan'] ?? '-'
        ];
    }
    $stmt->close();

} elseif ($tipe === 'pakan') {
    $headers = [
        'ID Catatan', 'ID Siklus', 'Nama Kolam', 'Jenis Ikan', 'Tanggal Pemberian',
        'Nama Pakan', 'Jumlah Pakan (kg)', 'Catatan Lapangan', 'Waktu Pencatatan'
    ];

    $sql = "SELECT p.id, p.siklus_id, k.nama AS nama_kolam, j.nama AS nama_ikan, p.tanggal,
                   p.nama_pakan, p.jumlah_kg, p.catatan, p.created_at
            FROM pemberian_pakan p
            JOIN siklus_budidaya s ON p.siklus_id = s.id
            JOIN kolam k ON s.kolam_id = k.id
            JOIN jenis_ikan j ON s.jenis_ikan_id = j.id
            WHERE k.user_id = ?";
    $params = [$userId];
    $types = 'i';

    if ($siklusId !== null) {
        $sql .= " AND p.siklus_id = ?";
        $params[] = $siklusId;
        $types .= 'i';
    }
    if ($dariTanggal !== null) {
        $sql .= " AND p.tanggal >= ?";
        $params[] = $dariTanggal;
        $types .= 's';
    }
    if ($sampaiTanggal !== null) {
        $sql .= " AND p.tanggal <= ?";
        $params[] = $sampaiTanggal;
        $types .= 's';
    }

    $sql .= " ORDER BY p.tanggal DESC, p.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($p = $res->fetch_assoc()) {
        $rows[] = [
            (int) $p['id'],
            (int) $p['siklus_id'],
            $p['nama_kolam'],
            $p['nama_ikan'],
            $p['tanggal'],
            $p['nama_pakan'],
            (float) $p['jumlah_kg'],
            $p['catatan'] ?? '-',
            $p['created_at']
        ];
    }
    $stmt->close();

} elseif ($tipe === 'mortalitas') {
    $headers = [
        'ID Catatan', 'ID Siklus', 'Nama Kolam', 'Jenis Ikan', 'Tanggal Kejadian',
        'Jumlah Kematian (Ekor)', 'Penyebab', 'Catatan', 'Status Catatan', 'Waktu Pencatatan'
    ];

    $sql = "SELECT m.id, m.siklus_id, k.nama AS nama_kolam, j.nama AS nama_ikan, m.tanggal,
                   m.jumlah_ekor, m.penyebab, m.catatan, m.status_catatan, m.created_at
            FROM mortalitas m
            JOIN siklus_budidaya s ON m.siklus_id = s.id
            JOIN kolam k ON s.kolam_id = k.id
            JOIN jenis_ikan j ON s.jenis_ikan_id = j.id
            WHERE k.user_id = ?";
    $params = [$userId];
    $types = 'i';

    if ($siklusId !== null) {
        $sql .= " AND m.siklus_id = ?";
        $params[] = $siklusId;
        $types .= 'i';
    }
    if ($dariTanggal !== null) {
        $sql .= " AND m.tanggal >= ?";
        $params[] = $dariTanggal;
        $types .= 's';
    }
    if ($sampaiTanggal !== null) {
        $sql .= " AND m.tanggal <= ?";
        $params[] = $sampaiTanggal;
        $types .= 's';
    }

    $sql .= " ORDER BY m.tanggal DESC, m.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($m = $res->fetch_assoc()) {
        $rows[] = [
            (int) $m['id'],
            (int) $m['siklus_id'],
            $m['nama_kolam'],
            $m['nama_ikan'],
            $m['tanggal'],
            (int) $m['jumlah_ekor'],
            $m['penyebab'] ?? '-',
            $m['catatan'] ?? '-',
            ucfirst((string) $m['status_catatan']),
            $m['created_at']
        ];
    }
    $stmt->close();

} elseif ($tipe === 'panen') {
    $headers = [
        'ID Catatan', 'ID Siklus', 'Nama Kolam', 'Jenis Ikan', 'Tanggal Panen',
        'Berat Panen (kg)', 'Jumlah Panen (Ekor)', 'Harga per kg (Rp)',
        'Total Nilai Estimasi (Rp)', 'Catatan', 'Status Catatan', 'Waktu Pencatatan'
    ];

    $sql = "SELECT p.id, p.siklus_id, k.nama AS nama_kolam, j.nama AS nama_ikan, p.tanggal,
                   p.jumlah_kg, p.jumlah_ekor, p.harga_per_kg, p.catatan, p.status_catatan, p.created_at
            FROM panen p
            JOIN siklus_budidaya s ON p.siklus_id = s.id
            JOIN kolam k ON s.kolam_id = k.id
            JOIN jenis_ikan j ON s.jenis_ikan_id = j.id
            WHERE k.user_id = ?";
    $params = [$userId];
    $types = 'i';

    if ($siklusId !== null) {
        $sql .= " AND p.siklus_id = ?";
        $params[] = $siklusId;
        $types .= 'i';
    }
    if ($dariTanggal !== null) {
        $sql .= " AND p.tanggal >= ?";
        $params[] = $dariTanggal;
        $types .= 's';
    }
    if ($sampaiTanggal !== null) {
        $sql .= " AND p.tanggal <= ?";
        $params[] = $sampaiTanggal;
        $types .= 's';
    }

    $sql .= " ORDER BY p.tanggal DESC, p.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($p = $res->fetch_assoc()) {
        $kg = (float) $p['jumlah_kg'];
        $harga = $p['harga_per_kg'] !== null ? (float) $p['harga_per_kg'] : null;
        $totalNilai = $harga !== null ? $kg * $harga : null;

        $rows[] = [
            (int) $p['id'],
            (int) $p['siklus_id'],
            $p['nama_kolam'],
            $p['nama_ikan'],
            $p['tanggal'],
            $kg,
            $p['jumlah_ekor'] !== null ? (int) $p['jumlah_ekor'] : '-',
            $harga !== null ? $harga : '-',
            $totalNilai !== null ? $totalNilai : '-',
            $p['catatan'] ?? '-',
            ucfirst((string) $p['status_catatan']),
            $p['created_at']
        ];
    }
    $stmt->close();
}

// Log aktivitas ekspor ke audit trail
$infoLog = "tipe={$tipe};siklus_id=" . ($siklusId !== null ? (string) $siklusId : 'all');
if ($dariTanggal !== null) $infoLog .= ";dari=" . $dariTanggal;
if ($sampaiTanggal !== null) $infoLog .= ";sampai=" . $sampaiTanggal;
$infoLog .= ";total_baris=" . count($rows);

write_log(
    $conn,
    'EKSPOR_CSV',
    $userId,
    $infoLog,
    'laporan',
    $siklusId
);

// Kirim header file download
$filenameParts = ['seefish', $tipe];
if ($siklusId !== null) {
    $filenameParts[] = 'siklus' . $siklusId;
}
$filenameParts[] = date('Ymd_His');
$filename = implode('_', $filenameParts) . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Output UTF-8 BOM untuk kompatibilitas Microsoft Excel & LibreOffice Calc
echo "\xEF\xBB\xBF";
echo csv_render($headers, $rows);
exit;
