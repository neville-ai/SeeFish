<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function login_limiter_key(): string
{
    $key = (string) (env('LOGIN_LIMITER_KEY', '') ?? '');

    if (strlen($key) < 64) {
        throw new RuntimeException('Login limiter key is missing or invalid.');
    }

    return $key;
}

function login_limiter_hash(string $scope, string $value): string
{
    return hash_hmac(
        'sha256',
        $scope . '|' . strtolower(trim($value)),
        login_limiter_key()
    );
}

/**
 * Mengembalikan true jika username atau IP sedang dibatasi.
 */
function login_limiter_is_blocked(mysqli $conn, string $username, string $ip): bool
{
    $identities = [
        ['user', login_limiter_hash('user', $username), 5],
        ['ip', login_limiter_hash('ip', $ip), 30],
    ];

    $stmt = $conn->prepare(
        "SELECT
            attempts,
            (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked,
            (window_started > DATE_SUB(NOW(), INTERVAL 15 MINUTE)) AS within_window
         FROM login_attempts
         WHERE scope = ? AND identifier_hash = ?
         LIMIT 1"
    );

    foreach ($identities as [$scope, $hash, $limit]) {
        $stmt->bind_param('ss', $scope, $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            continue;
        }

        if ((int) $row['is_locked'] === 1
            || ((int) $row['within_window'] === 1
                && (int) $row['attempts'] >= $limit)) {
            $stmt->close();
            return true;
        }
    }

    $stmt->close();
    return false;
}

/**
 * Mencatat satu kegagalan secara atomik untuk username dan IP.
 * Mengembalikan daftar scope yang baru mencapai batas.
 */
function login_limiter_record_failure(
    mysqli $conn,
    string $username,
    string $ip
): array {
    $identities = [
        ['user', login_limiter_hash('user', $username), 5],
        ['ip', login_limiter_hash('ip', $ip), 30],
    ];

    $newlyLocked = [];
    $conn->begin_transaction();

    try {
        foreach ($identities as [$scope, $hash, $limit]) {
            $insert = $conn->prepare(
                "INSERT IGNORE INTO login_attempts
                    (scope, identifier_hash, attempts, window_started)
                 VALUES (?, ?, 0, NOW())"
            );
            $insert->bind_param('ss', $scope, $hash);
            $insert->execute();
            $insert->close();

            $select = $conn->prepare(
                "SELECT
                    attempts,
                    (window_started <= DATE_SUB(NOW(), INTERVAL 15 MINUTE)) AS window_expired
                 FROM login_attempts
                 WHERE scope = ? AND identifier_hash = ?
                 FOR UPDATE"
            );
            $select->bind_param('ss', $scope, $hash);
            $select->execute();
            $row = $select->get_result()->fetch_assoc();
            $select->close();

            if (!$row) {
                throw new RuntimeException('Unable to lock login attempt row.');
            }

            // Keputusan kedaluwarsa dihitung MySQL, bukan timezone PHP.
            $expired = (int) $row['window_expired'] === 1;
            $attempts = $expired ? 1 : ((int) $row['attempts'] + 1);
            $isLocked = $attempts >= $limit;

            $update = $conn->prepare(
                "UPDATE login_attempts
                 SET locked_until = IF(
                        ? = 1,
                        DATE_ADD(NOW(), INTERVAL 15 MINUTE),
                        NULL
                     ),
                     window_started = IF(? = 1, NOW(), window_started),
                     attempts = ?
                 WHERE scope = ? AND identifier_hash = ?"
            );

            $lockedFlag = $isLocked ? 1 : 0;
            $expiredFlag = $expired ? 1 : 0;

            $update->bind_param(
                'iiiss',
                $lockedFlag,
                $expiredFlag,
                $attempts,
                $scope,
                $hash
            );
            $update->execute();
            $update->close();

            if ($isLocked) {
                $newlyLocked[] = $scope;
            }
        }

        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return $newlyLocked;
}

/**
 * Hapus hitungan username setelah autentikasi berhasil.
 * Hitungan IP sengaja tetap ada untuk mencegah password spraying.
 */
function login_limiter_clear_user(mysqli $conn, string $username): void
{
    $scope = 'user';
    $hash = login_limiter_hash('user', $username);

    $stmt = $conn->prepare(
        "DELETE FROM login_attempts
         WHERE scope = ? AND identifier_hash = ?"
    );
    $stmt->bind_param('ss', $scope, $hash);
    $stmt->execute();
    $stmt->close();
}
