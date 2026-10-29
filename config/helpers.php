<?php
declare(strict_types=1);

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): void
{
    $path = str_replace(["\r", "\n"], '', $path);
    header('Location: ' . $path, true, 303);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function post_raw(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

function post_trimmed(string $key): string
{
    return trim(post_raw($key));
}

function post_int(string $key): ?int
{
    $value = $_POST[$key] ?? null;
    if ($value === null || is_array($value)) {
        return null;
    }

    $validated = filter_var($value, FILTER_VALIDATE_INT);
    return $validated === false ? null : (int) $validated;
}

function post_float(string $key): ?float
{
    $value = $_POST[$key] ?? null;
    if ($value === null || is_array($value) || trim((string) $value) === '') {
        return null;
    }

    $validated = filter_var($value, FILTER_VALIDATE_FLOAT);
    return $validated === false ? null : (float) $validated;
}

function password_min_length(): int
{
    $configured = (int) (env('PASSWORD_MIN_LENGTH', '12') ?? '12');
    return max(8, min(128, $configured));
}

function current_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $validated = filter_var($ip, FILTER_VALIDATE_IP);
    return $validated === false ? '-' : $validated;
}

function current_user_agent(): string
{
    $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '-');
    $agent = str_replace(["\r", "\n"], ' ', $agent);
    return function_exists('mb_substr')
        ? mb_substr($agent, 0, 255, 'UTF-8')
        : substr($agent, 0, 255);
}

function app_name(): string
{
    $name = env('APP_NAME', 'SeeFish');
    return ($name === null || $name === '') ? 'SeeFish' : $name;
}

/**
 * Menghitung sisa ikan hidup dan ringkasan metrik suatu siklus budidaya.
 * Formula: sisa_ikan = jumlah_tebar - total_mortalitas_aktif - total_panen_ekor_aktif.
 * Record berstatus 'dikoreksi' diabaikan. Panen berat tanpa ekor tidak mengurangi hitungan ekor.
 *
 * @param mysqli $conn
 * @param int $siklusId
 * @param bool $forUpdate Apakah baris siklus di-lock dengan FOR UPDATE dalam transaksi
 * @return array{
 *     ok: bool,
 *     siklus_id: int,
 *     jumlah_tebar: int,
 *     total_mortalitas: int,
 *     total_panen_ekor: int,
 *     total_panen_kg: float,
 *     total_pakan_kg: float,
 *     sisa_ikan: int,
 *     status_siklus: string,
 *     tanggal_mulai: string
 * }|null
 */
function get_siklus_stock_stats(mysqli $conn, int $siklusId, bool $forUpdate = false): ?array
{
    if ($forUpdate) {
        $stmt = $conn->prepare("SELECT id, kolam_id, jenis_ikan_id, tanggal_mulai, status, jumlah_tebar FROM siklus_budidaya WHERE id = ? LIMIT 1 FOR UPDATE");
    } else {
        $stmt = $conn->prepare("SELECT id, kolam_id, jenis_ikan_id, tanggal_mulai, status, jumlah_tebar FROM siklus_budidaya WHERE id = ? LIMIT 1");
    }

    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('i', $siklusId);
    $stmt->execute();
    $siklus = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$siklus) {
        return null;
    }

    $tebar = (int) $siklus['jumlah_tebar'];

    // Total mortalitas aktif
    $stmtMort = $conn->prepare("SELECT COALESCE(SUM(jumlah_ekor), 0) AS total_mati FROM mortalitas WHERE siklus_id = ? AND status_catatan = 'aktif'");
    $totalMati = 0;
    if ($stmtMort !== false) {
        $stmtMort->bind_param('i', $siklusId);
        $stmtMort->execute();
        $row = $stmtMort->get_result()->fetch_assoc();
        $totalMati = (int) ($row['total_mati'] ?? 0);
        $stmtMort->close();
    }

    // Total panen ekor dan panen kg aktif
    $stmtPanen = $conn->prepare("SELECT COALESCE(SUM(jumlah_ekor), 0) AS total_ekor, COALESCE(SUM(jumlah_kg), 0.0) AS total_kg FROM panen WHERE siklus_id = ? AND status_catatan = 'aktif'");
    $totalPanenEkor = 0;
    $totalPanenKg = 0.0;
    if ($stmtPanen !== false) {
        $stmtPanen->bind_param('i', $siklusId);
        $stmtPanen->execute();
        $row = $stmtPanen->get_result()->fetch_assoc();
        $totalPanenEkor = (int) ($row['total_ekor'] ?? 0);
        $totalPanenKg = (float) ($row['total_kg'] ?? 0.0);
        $stmtPanen->close();
    }

    // Total pakan kg
    $stmtPakan = $conn->prepare("SELECT COALESCE(SUM(jumlah_kg), 0.0) AS total_kg FROM pemberian_pakan WHERE siklus_id = ?");
    $totalPakanKg = 0.0;
    if ($stmtPakan !== false) {
        $stmtPakan->bind_param('i', $siklusId);
        $stmtPakan->execute();
        $row = $stmtPakan->get_result()->fetch_assoc();
        $totalPakanKg = (float) ($row['total_kg'] ?? 0.0);
        $stmtPakan->close();
    }

    $sisa = max(0, $tebar - $totalMati - $totalPanenEkor);

    return [
        'ok' => true,
        'siklus_id' => $siklusId,
        'jumlah_tebar' => $tebar,
        'total_mortalitas' => $totalMati,
        'total_panen_ekor' => $totalPanenEkor,
        'total_panen_kg' => $totalPanenKg,
        'total_pakan_kg' => $totalPakanKg,
        'sisa_ikan' => $sisa,
        'status_siklus' => (string) $siklus['status'],
        'tanggal_mulai' => (string) $siklus['tanggal_mulai'],
    ];
}

/**
 * Menetralkan formula injection pada sel CSV (nilai yang diawali =, +, -, atau @ diberi awalan apostrof).
 */
function csv_safe_cell(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        $text = $value ? '1' : '0';
    } elseif (is_scalar($value)) {
        $text = (string) $value;
    } else {
        return '';
    }

    $trimmed = ltrim($text, " \t\n\r\v\f");
    if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
        return "'" . $text;
    }

    return $text;
}

/**
 * Merender header dan baris data menjadi string CSV aman dengan pemisahan koma dan penanganan UTF-8.
 *
 * @param array<int, mixed> $headers
 * @param array<int, mixed> $rows
 */
function csv_render(array $headers, array $rows): string
{
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        return '';
    }

    try {
        $writeRow = static function (array $fields) use ($stream): void {
            $safeFields = [];
            foreach ($fields as $field) {
                $safeFields[] = csv_safe_cell($field);
            }
            fputcsv($stream, $safeFields, ',', '"', "\\");
        };

        $writeRow($headers);
        foreach ($rows as $row) {
            if (is_array($row)) {
                $writeRow(array_values($row));
            }
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        return $contents !== false ? $contents : '';
    } finally {
        fclose($stream);
    }
}
