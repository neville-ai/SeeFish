<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/validation.php';
require_once __DIR__ . '/../config/helpers.php';

/**
 * tools/create_admin.php
 * Membuat Admin Kolam pertama. Hanya dijalankan via CLI.
 * Menolak jika tabel users sudah berisi akun.
 * Tidak membaca .env atau menampilkan kredensial.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Perintah ini hanya dapat dijalankan melalui CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../config/koneksi.php';

function cli_prompt_hidden(string $prompt): string
{
    if (function_exists('readline') && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        // di Linux/macOS readline bisa digunakan; untuk Windows fallback ke input biasa
        $value = readline($prompt);
        if ($value === false) {
            $value = '';
        }
        return trim((string) $value);
    }

    // Fallback umum: input biasa (Windows cmd biasanya menampilkan; sesuai batasan proyek)
    fwrite(STDOUT, $prompt);
    $value = fgets(STDIN);
    if ($value === false) {
        $value = '';
    }
    // hapus newline
    $value = rtrim($value, "\r\n");
    return $value;
}

function cli_prompt(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $value = fgets(STDIN);
    if ($value === false) {
        $value = '';
    }
    $value = rtrim($value, "\r\n");
    return trim((string) $value);
}

// Cek apakah sudah ada user
try {
    $res = $conn->query("SELECT COUNT(*) as c FROM users");
    if (!$res) {
        throw new RuntimeException('Gagal mengecek tabel users');
    }
    $row = $res->fetch_assoc();
    $res->free();
    if ((int) ($row['c'] ?? 0) > 0) {
        fwrite(STDERR, "Setup gagal: tabel users sudah berisi akun. Tambahan akun dilakukan melalui proses administratif terpisah.\n");
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Setup gagal: tidak dapat mengakses tabel users. Pastikan database telah dibuat dan skema telah diterapkan.\n");
    exit(1);
}

$username = cli_prompt('Username Admin Kolam: ');
if (!valid_username($username)) {
    fwrite(STDERR, "Setup gagal: username minimal 3 karakter, hanya huruf, angka, dan underscore.\n");
    exit(1);
}

// cek duplikasi
$stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
$stmt->bind_param('s', $username);
$stmt->execute();
$ex = $stmt->get_result();
if ($ex->num_rows > 0) {
    $stmt->close();
    fwrite(STDERR, "Setup gagal: username sudah digunakan.\n");
    exit(1);
}
$stmt->close();

$password = cli_prompt_hidden('Password: ');
$password2 = cli_prompt_hidden('Konfirmasi Password: ');

if ($password === '' || $password2 === '') {
    fwrite(STDERR, "Setup gagal: password tidak boleh kosong.\n");
    exit(1);
}
if ($password !== $password2) {
    fwrite(STDERR, "Setup gagal: konfirmasi password tidak cocok.\n");
    exit(1);
}
if (!valid_password($password)) {
    $min = password_min_length();
    fwrite(STDERR, "Setup gagal: password minimal {$min} karakter dan maksimal 128 karakter.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
if (!is_string($hash)) {
    fwrite(STDERR, "Setup gagal: tidak dapat membuat hash password.\n");
    exit(1);
}

try {
    $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
    if ($stmt === false) {
        fwrite(STDERR, "Setup gagal: gagal menyiapkan pernyataan SQL.\n");
        exit(1);
    }
    $stmt->bind_param('ss', $username, $hash);
    $stmt->execute();
    $newId = (int) $conn->insert_id;
    $stmt->close();
} catch (Throwable $e) {
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    fwrite(STDERR, "Setup gagal: gagal membuat akun Admin Kolam.\n");
    exit(1);
}

try {
    if (function_exists('write_log')) {
        write_log($conn, 'ADMIN_SETUP', $newId, 'username=' . $username);
    }
} catch (Throwable $e) {
    // best effort
}

echo "Berhasil: Admin Kolam '{$username}' berhasil dibuat.\n";
exit(0);
