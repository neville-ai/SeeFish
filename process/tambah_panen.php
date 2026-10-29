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

csrf_verify('../dashboard');

require_once __DIR__ . '/../config/validation.php';

$userId = (int) $_SESSION['user_id'];
$namaPetani = post_trimmed('nama_petani');
$tanggal = post_raw('tanggal');
$komoditas = post_trimmed('komoditas');
$jumlah = post_int('jumlah');

if (!valid_text($namaPetani, 1, 100)
    || !valid_text($komoditas, 1, 100)
    || !valid_date_ymd($tanggal)
    || !valid_positive_int($jumlah)) {
    redirect('../dashboard?error=invalid');
}

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/logger.php';

// Operasi tulis ke tabel legacy panen dinonaktifkan (read-only) pada SeeFish Build Mode 2.
write_log($conn, 'TAMBAH_PANEN_DISABLED', $userId, 'komoditas=' . $komoditas . ' status=disabled');

redirect('../dashboard?error=disabled');
