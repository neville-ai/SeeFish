<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Tulis entri aktivitas (best effort). Gagal saat menulis ke database tidak
 * akan membocorkan error database ke pengguna; error dicatat ke error_log.
 */
function write_log(mysqli $conn, string $action, int $user_id = 0, string $info = '', ?string $entity_type = null, ?int $entity_id = null): void
{
    try {
        write_log_strict($conn, $action, $user_id, $info, $entity_type, $entity_id);
    } catch (Throwable $error) {
        error_log('SeeFish audit database write failed: ' . get_class($error));
    }

    write_log_file($action, $user_id, $info);
}

/**
 * Tulis entri aktivitas secara ketat.
 *
 * Untuk operasi yang memerlukan audit, fungsi ini harus dipanggil dalam
 * transaksi database yang sama. Jika INSERT ke activity_log gagal,
 * pengecualian dilemparkan sehingga pemanggil dapat melakukan rollback.
 *
 * @param string|null $entity_type jenis entitas (mis. 'pond', 'cycle', 'stocking')
 * @param int|null $entity_id ID entitas terkait
 * @throws Throwable saat penulisan ke database gagal
 */
function write_log_strict(mysqli $conn, string $action, int $user_id = 0, string $info = '', ?string $entity_type = null, ?int $entity_id = null): void
{
    $action = strtoupper(preg_replace('/[^a-zA-Z0-9_-]/', '_', $action) ?? 'UNKNOWN');
    if ($action === '') {
        $action = 'UNKNOWN';
    }

    $info = str_replace(["\r", "\n"], ' ', $info);
    if (function_exists('mb_substr')) {
        $info = mb_substr($info, 0, 1000, 'UTF-8');
    } else {
        $info = substr($info, 0, 1000);
    }

    $userId = $user_id > 0 ? $user_id : null;
    $entityStr = $entity_type !== null ? (string) $entity_type : '';
    if (function_exists('mb_substr')) {
        $entityStr = mb_substr($entityStr, 0, 60, 'UTF-8');
    } else {
        $entityStr = substr($entityStr, 0, 60);
    }

    $ip = current_ip();
    $userAgent = current_user_agent();
    $timestamp = date('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "INSERT INTO activity_log (user_id, action, info, entity, entity_id, ip_address, user_agent, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if ($stmt === false) {
        throw new RuntimeException('SeeFish audit: gagal menyiapkan pernyataan activity_log');
    }

    $stmt->bind_param('issssiss', $userId, $action, $info, $entityStr, $entity_id, $ip, $userAgent, $timestamp);
    $stmt->execute();
    $stmt->close();

    write_log_file($action, $userId ?? 0, $info);
}

function write_log_file(string $action, int $userId, string $info): void
{
    $logDir = dirname(__DIR__) . '/logs';
    $logFile = $logDir . '/activity_' . date('Y-m') . '.log';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0700, true);
    }

    $htaccess = $logDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        $rules = "Options -Indexes\n"
            . "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    Deny from all\n"
            . "</IfModule>\n";
        @file_put_contents($htaccess, $rules, LOCK_EX);
    }

    $line = sprintf(
        "[%s] [%s] user_id=%d ip=%s info=%s\n",
        date('Y-m-d H:i:s'),
        $action,
        $userId,
        current_ip(),
        $info !== '' ? $info : '-'
    );

    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    @chmod($logFile, 0600);
}
