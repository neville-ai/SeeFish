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
$nama = post_trimmed('nama');
$lokasi = post_trimmed('lokasi');
$luasM2 = post_float('luas_m2');
$kedalamanM = post_float('kedalaman_m');
$status = post_trimmed('status');

if (!valid_positive_int($id) || !valid_text($nama, 1, 100)) {
    redirect('../kolam.php?error=invalid');
}

if ($lokasi !== '' && !valid_text($lokasi, 1, 150)) {
    redirect('../kolam.php?error=invalid');
}

if ($luasM2 !== null && (!valid_positive_number($luasM2) || $luasM2 > 99999999.99)) {
    redirect('../kolam.php?error=invalid');
}

if ($kedalamanM !== null && (!valid_positive_number($kedalamanM) || $kedalamanM > 9999.99)) {
    redirect('../kolam.php?error=invalid');
}

if (!in_array($status, ['aktif', 'nonaktif'], true)) {
    $status = 'aktif';
}

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/logger.php';

try {
    // Verifikasi kepemilikan
    $stmt = $conn->prepare("SELECT id FROM kolam WHERE id = ? AND user_id = ? LIMIT 1");
    if ($stmt === false) {
        redirect('../kolam.php?error=db_error');
    }
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $ownerRes = $stmt->get_result();
    $found = $ownerRes->num_rows > 0;
    $stmt->close();

    if (!$found) {
        redirect('../kolam.php?error=not_found');
    }

    // Cek duplikasi nama jika nama diubah
    $stmt = $conn->prepare("SELECT id FROM kolam WHERE user_id = ? AND nama = ? AND id != ? LIMIT 1");
    if ($stmt === false) {
        redirect('../kolam.php?error=db_error');
    }
    $stmt->bind_param('isi', $userId, $nama, $id);
    $stmt->execute();
    $dupRes = $stmt->get_result();
    $duplicate = $dupRes->num_rows > 0;
    $stmt->close();

    if ($duplicate) {
        redirect('../kolam.php?error=duplicate_name');
    }

    $stmt = $conn->prepare(
        "UPDATE kolam SET nama = ?, lokasi = ?, luas_m2 = ?, kedalaman_m = ?, status = ? WHERE id = ? AND user_id = ?"
    );
    if ($stmt === false) {
        redirect('../kolam.php?error=db_error');
    }

    $lokasiVal = $lokasi !== '' ? $lokasi : null;
    $stmt->bind_param('ssddsii', $nama, $lokasiVal, $luasM2, $kedalamanM, $status, $id, $userId);
    $stmt->execute();
    $stmt->close();

    write_log($conn, 'UPDATE_KOLAM', $userId, 'id=' . $id . ' nama=' . $nama, 'kolam', $id);
} catch (Throwable $error) {
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    error_log('Update kolam error: ' . get_class($error));
    redirect('../kolam.php?error=db_error');
}

redirect('../kolam.php?success=updated');
