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

csrf_verify('../riwayat.php');

require_once __DIR__ . '/../config/validation.php';

$userId = (int) $_SESSION['user_id'];
$jenis = post_trimmed('jenis');
$recordId = post_int('record_id');
$alasan = post_trimmed('alasan');

if (!in_array($jenis, ['mortalitas', 'panen'], true)
    || !valid_positive_int($recordId)
    || !valid_text($alasan, 3, 500)) {
    redirect('../riwayat.php?error=invalid');
}

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/logger.php';

try {
    if ($jenis === 'mortalitas') {
        // 1. Verifikasi kepemilikan data mortalitas melalui relasi kolam.user_id
        $stmt = $conn->prepare(
            "SELECT m.id, m.siklus_id, m.jumlah_ekor, m.status_catatan, m.catatan
             FROM mortalitas m
             JOIN siklus_budidaya s ON m.siklus_id = s.id
             JOIN kolam k ON s.kolam_id = k.id
             WHERE m.id = ? AND k.user_id = ?
             LIMIT 1"
        );
        if ($stmt === false) {
            redirect('../riwayat.php?error=db_error');
        }
        $stmt->bind_param('ii', $recordId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            redirect('../riwayat.php?error=forbidden');
        }

        if ($row['status_catatan'] === 'dikoreksi') {
            redirect('../riwayat.php?error=already_corrected');
        }

        $existingCatatan = (string) ($row['catatan'] ?? '');
        $prefix = '[DIKOREKSI: ' . $alasan . ']';
        $newCatatan = $existingCatatan !== '' ? $prefix . ' ' . $existingCatatan : $prefix;
        if (function_exists('mb_substr')) {
            $newCatatan = mb_substr($newCatatan, 0, 1000, 'UTF-8');
        } else {
            $newCatatan = substr($newCatatan, 0, 1000);
        }

        // 2. Update status_catatan menjadi dikoreksi
        $stmt = $conn->prepare("UPDATE mortalitas SET status_catatan = 'dikoreksi', catatan = ? WHERE id = ?");
        if ($stmt === false) {
            redirect('../riwayat.php?error=db_error');
        }
        $stmt->bind_param('si', $newCatatan, $recordId);
        $stmt->execute();
        $stmt->close();

        // 3. Catat ke activity_log
        write_log(
            $conn,
            'KOREKSI_MORTALITAS',
            $userId,
            "mortalitas_id={$recordId} alasan={$alasan}",
            'mortalitas',
            $recordId
        );

    } else {
        // 1. Verifikasi kepemilikan data panen melalui relasi kolam.user_id
        $stmt = $conn->prepare(
            "SELECT p.id, p.siklus_id, p.jumlah_kg, p.jumlah_ekor, p.status_catatan, p.catatan
             FROM panen p
             JOIN siklus_budidaya s ON p.siklus_id = s.id
             JOIN kolam k ON s.kolam_id = k.id
             WHERE p.id = ? AND k.user_id = ?
             LIMIT 1"
        );
        if ($stmt === false) {
            redirect('../riwayat.php?error=db_error');
        }
        $stmt->bind_param('ii', $recordId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            redirect('../riwayat.php?error=forbidden');
        }

        if ($row['status_catatan'] === 'dikoreksi') {
            redirect('../riwayat.php?error=already_corrected');
        }

        $existingCatatan = (string) ($row['catatan'] ?? '');
        $prefix = '[DIKOREKSI: ' . $alasan . ']';
        $newCatatan = $existingCatatan !== '' ? $prefix . ' ' . $existingCatatan : $prefix;
        if (function_exists('mb_substr')) {
            $newCatatan = mb_substr($newCatatan, 0, 1000, 'UTF-8');
        } else {
            $newCatatan = substr($newCatatan, 0, 1000);
        }

        $siklusId = (int) $row['siklus_id'];

        // 2. Update status_catatan menjadi dikoreksi pada tabel panen
        $stmt = $conn->prepare("UPDATE panen SET status_catatan = 'dikoreksi', catatan = ? WHERE id = ? AND siklus_id = ?");
        if ($stmt === false) {
            redirect('../riwayat.php?error=db_error');
        }
        $stmt->bind_param('sii', $newCatatan, $recordId, $siklusId);
        $stmt->execute();
        $stmt->close();

        // 3. Catat ke activity_log
        write_log(
            $conn,
            'KOREKSI_PANEN',
            $userId,
            "panen_id={$recordId} alasan={$alasan}",
            'panen',
            $recordId
        );
    }

    redirect('../riwayat.php?success=corrected');
} catch (Throwable $e) {
    error_log('Koreksi catatan error: ' . $e->getMessage());
    redirect('../riwayat.php?error=system_error');
}
