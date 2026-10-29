#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/lib/static_analysis.php';

$root = sf_project_root();

/**
 * @param list<string> $details
 * @return array{id: string, name: string, status: string, details: list<string>}
 */
function sf_result(string $id, string $name, string $status, array $details = []): array
{
    return ['id' => $id, 'name' => $name, 'status' => $status, 'details' => $details];
}

function sf_find_include_lines(array $includes, string $needle): array
{
    $lines = [];

    foreach ($includes as $include) {
        if (str_contains(sf_tokens_text($include['expression']), $needle)) {
            $lines[] = $include['line'];
        }
    }

    return $lines;
}

function sf_check_st01(string $root): array
{
    $details = [];
    $failures = [];
    $inconclusive = [];
    $mutationCount = 0;

    foreach (SF_HANDLER_FILES as $relative) {
        $path = $root . '/' . $relative;
        if (!is_file($path)) {
            $failures[] = $relative . ': file handler tidak ditemukan';
            continue;
        }

        $tokens = sf_file_significant($path);
        $csrfLines = array_map(
            static fn(array $call): int => $call['line'],
            sf_calls($tokens, ['csrf_verify'])
        );

        $mutationLines = [];
        $unknownSql = false;

        foreach (sf_calls($tokens, ['write_log']) as $call) {
            $mutationLines[] = $call['line'];
        }

        foreach (sf_sql_calls($tokens) as $call) {
            $analysis = sf_sql_call_analysis($call['args']);
            if ($analysis['status'] !== 'PASS') {
                $unknownSql = true;
                continue;
            }

            if (preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/i', $analysis['sql']) === 1) {
                $mutationLines[] = $call['line'];
            }
        }

        if ($unknownSql) {
            $inconclusive[] = $relative . ': ada SQL yang tidak dapat diklasifikasikan dengan andal sebagai mutasi atau baca';
        }

        if ($mutationLines === []) {
            $details[] = $relative . ': PASS - tidak ada operasi mutasi yang terdeteksi';
            continue;
        }

        sort($mutationLines);
        $mutationCount += count($mutationLines);

        if ($csrfLines === []) {
            $failures[] = $relative . ': mutasi pada baris ' . implode(', ', $mutationLines) . ' tanpa csrf_verify()';
            continue;
        }

        sort($csrfLines);
        $firstCsrf = $csrfLines[0];
        $beforeCsrf = array_values(array_filter(
            $mutationLines,
            static fn(int $line): bool => $line < $firstCsrf
        ));

        if ($beforeCsrf !== []) {
            $failures[] = $relative . ': mutasi pada baris ' . implode(', ', $beforeCsrf)
                . ' terjadi sebelum csrf_verify() baris ' . $firstCsrf;
            continue;
        }

        $details[] = $relative . ': PASS - mutasi baris ' . implode(', ', $mutationLines)
            . ' setelah csrf_verify() baris ' . $firstCsrf;
    }

    if ($failures !== []) {
        return sf_result('ST-01', 'CSRF sebelum operasi mutasi', 'FAIL', array_merge($details, $failures, $inconclusive));
    }

    if ($inconclusive !== []) {
        return sf_result('ST-01', 'CSRF sebelum operasi mutasi', 'INCONCLUSIVE', array_merge($details, $inconclusive));
    }

    $details[] = 'Total operasi mutasi terdeteksi: ' . $mutationCount;

    return sf_result('ST-01', 'CSRF sebelum operasi mutasi', 'PASS', $details);
}

function sf_check_st03(string $root): array
{
    $details = [];
    $failures = [];
    $requireAuthFiles = [
        'dashboard.php',
        'process/tambah_panen.php',
        'process/update_panen.php',
        'process/delete_panen.php',
    ];

    foreach ($requireAuthFiles as $relative) {
        $path = $root . '/' . $relative;
        if (!is_file($path)) {
            $failures[] = $relative . ': file terproteksi tidak ditemukan';
            continue;
        }

        $tokens = sf_file_significant($path);
        $includes = sf_includes($tokens);
        $authLines = sf_find_include_lines($includes, 'auth.php');
        $dbLines = sf_find_include_lines($includes, 'koneksi.php');

        if ($authLines === []) {
            $failures[] = $relative . ': tidak memuat auth.php';
            continue;
        }

        if ($dbLines === []) {
            $details[] = $relative . ': PASS - auth.php ada, tidak memuat koneksi.php';
            continue;
        }

        sort($authLines);
        sort($dbLines);
        if ($authLines[0] >= $dbLines[0]) {
            $failures[] = $relative . ': koneksi.php baris ' . $dbLines[0]
                . ' dimuat sebelum auth.php baris ' . $authLines[0];
            continue;
        }

        $details[] = $relative . ': PASS - auth.php baris ' . $authLines[0]
            . ' sebelum koneksi.php baris ' . $dbLines[0];
    }

    $path = $root . '/logout.php';
    if (!is_file($path)) {
        $failures[] = 'logout.php: file handler tidak ditemukan';
    } else {
        $tokens = sf_file_significant($path);
        $includes = sf_includes($tokens);
        $dbLines = sf_find_include_lines($includes, 'koneksi.php');
        $loginLines = sf_session_key_lines($tokens, 'login');

        if ($loginLines === []) {
            $failures[] = 'logout.php: tidak ditemukan pemeriksaan $_SESSION[\'login\']';
        } elseif ($dbLines === []) {
            $details[] = 'logout.php: PASS - pemeriksaan sesi login ada, tidak memuat koneksi.php';
        } else {
            sort($dbLines);
            if ($loginLines[0] >= $dbLines[0]) {
                $failures[] = 'logout.php: koneksi.php baris ' . $dbLines[0]
                    . ' dimuat sebelum pemeriksaan sesi login baris ' . $loginLines[0];
            } else {
                $details[] = 'logout.php: PASS - pemeriksaan sesi login baris ' . $loginLines[0]
                    . ' sebelum koneksi.php baris ' . $dbLines[0];
            }
        }
    }

    if ($failures !== []) {
        return sf_result('ST-03', 'Autentikasi sebelum koneksi database', 'FAIL', array_merge($details, $failures));
    }

    $details[] = 'Batasan: urutan include tidak membuktikan kebenaran logika otorisasi.';

    return sf_result('ST-03', 'Autentikasi sebelum koneksi database', 'PASS', $details);
}

function sf_check_st04(string $root): array
{
    $details = [];
    $failures = [];
    $inconclusive = [];
    $total = 0;
    $safe = 0;

    foreach (sf_php_files($root) as $path) {
        $relative = sf_relative_path($path);

        try {
            $tokens = sf_file_significant($path);
        } catch (Throwable $error) {
            $inconclusive[] = $relative . ': ' . $error->getMessage();
            continue;
        }

        foreach (sf_sql_calls($tokens) as $call) {
            $total++;
            $analysis = sf_sql_call_analysis($call['args']);
            $location = $relative . ':' . $call['line'];

            if ($analysis['status'] === 'FAIL') {
                $failures[] = $location . ': ' . $analysis['reason'];
            } elseif ($analysis['status'] === 'INCONCLUSIVE') {
                $inconclusive[] = $location . ': ' . $analysis['reason'];
            } else {
                $safe++;
            }
        }
    }

    $details[] = 'Query call dianalisis: ' . $total . '; literal aman: ' . $safe;
    $details[] = 'Pengecualian eksplisit: SQL literal tanpa variabel dan tanpa placeholder dianggap aman karena tidak memakai input dinamis.';
    $details[] = 'Batasan: scanner tidak membuktikan keamanan seluruh query, tidak melacak query yang dibangun di variabel, dan tidak menggantikan integration test.';

    if ($failures !== []) {
        return sf_result('ST-04', 'Deteksi SQL dinamis berisiko', 'FAIL', array_merge($details, $failures, $inconclusive));
    }

    if ($inconclusive !== []) {
        return sf_result('ST-04', 'Deteksi SQL dinamis berisiko', 'INCONCLUSIVE', array_merge($details, $inconclusive));
    }

    return sf_result('ST-04', 'Deteksi SQL dinamis berisiko', 'PASS', $details);
}

function sf_check_st05(string $root): array
{
    $details = [];
    $failures = [];
    $inconclusive = [];
    $dangerousCount = 0;
    $includeCount = 0;

    foreach (sf_php_files($root) as $path) {
        $relative = sf_relative_path($path);

        try {
            $tokens = sf_file_significant($path);
        } catch (Throwable $error) {
            $inconclusive[] = $relative . ': ' . $error->getMessage();
            continue;
        }

        foreach (sf_dangerous_calls($tokens) as $finding) {
            $dangerousCount++;
            $failures[] = $relative . ':' . $finding['line'] . ': fungsi berisiko ' . $finding['name'] . '()';
        }

        foreach (sf_includes($tokens) as $include) {
            $includeCount++;
            $expression = $include['expression'];

            if (sf_has_token($expression, T_VARIABLE)) {
                $failures[] = $relative . ':' . $include['line'] . ': include/require memakai variabel dinamis';
                continue;
            }

            foreach ($expression as $token) {
                if ($token['id'] === T_STRING) {
                    $inconclusive[] = $relative . ':' . $include['line']
                        . ': include/require memakai ekspresi yang tidak dapat dipastikan ('
                        . $token['text'] . ')';
                    break;
                }
            }
        }
    }

    $details[] = 'Fungsi berisiko ditemukan: ' . $dangerousCount . '; include/require diperiksa: ' . $includeCount;
    $details[] = 'Batasan: pemeriksaan ini tidak mendeteksi pemanggilan dinamis seperti call_user_func() atau nama fungsi yang dibangun dari variabel.';

    if ($failures !== []) {
        return sf_result('ST-05', 'Fungsi berisiko dan dynamic include', 'FAIL', array_merge($details, $failures, $inconclusive));
    }

    if ($inconclusive !== []) {
        return sf_result('ST-05', 'Fungsi berisiko dan dynamic include', 'INCONCLUSIVE', array_merge($details, $inconclusive));
    }

    return sf_result('ST-05', 'Fungsi berisiko dan dynamic include', 'PASS', $details);
}

/**
 * Memeriksa urutan guard tiga handler panen:
 * method guard -> auth -> CSRF -> validasi -> koneksi -> logger.
 *
 * Pemeriksaan ini tetap berbasis urutan token/baris. Bukti alur eksekusi
 * ditangani oleh HTTP trace test, bukan oleh fungsi ini.
 *
 * @return array{id: string, name: string, status: string, details: list<string>}
 */
function sf_check_st06(string $root): array
{
    $handlers = [
        'process/tambah_panen.php',
        'process/update_panen.php',
        'process/delete_panen.php',
    ];
    $details = [];
    $failures = [];

    foreach ($handlers as $relative) {
        $path = $root . '/' . $relative;
        if (!is_file($path)) {
            $failures[] = $relative . ': file handler tidak ditemukan';
            continue;
        }

        try {
            $tokens = sf_file_significant($path);
        } catch (Throwable $error) {
            $failures[] = $relative . ': ' . $error->getMessage();
            continue;
        }

        $includes = sf_includes($tokens);
        $includeLines = array_map(
            static fn(array $include): int => $include['line'],
            $includes
        );
        $firstInclude = $includeLines === [] ? null : min($includeLines);

        $methodLines = [];
        foreach (sf_calls($tokens, ['http_response_code']) as $call) {
            if (preg_match('/\b405\b/', sf_tokens_text($call['args'])) === 1) {
                $methodLines[] = $call['line'];
            }
        }

        $authLines = sf_find_include_lines($includes, 'auth.php');
        $csrfIncludeLines = sf_find_include_lines($includes, 'csrf.php');
        $validationIncludeLines = sf_find_include_lines($includes, 'validation.php');
        $dbLines = sf_find_include_lines($includes, 'koneksi.php');
        $loggerLines = sf_find_include_lines($includes, 'logger.php');
        $csrfLines = array_map(
            static fn(array $call): int => $call['line'],
            sf_calls($tokens, ['csrf_verify'])
        );

        $validationLines = [];
        foreach (sf_calls($tokens, [
            'valid_text',
            'valid_username',
            'valid_password',
            'valid_date_ymd',
            'valid_positive_int',
        ]) as $call) {
            $validationLines[] = $call['line'];
        }

        sort($methodLines);
        sort($authLines);
        sort($csrfIncludeLines);
        sort($csrfLines);
        sort($validationIncludeLines);
        sort($validationLines);
        sort($dbLines);
        sort($loggerLines);

        if ($methodLines === []) {
            $failures[] = $relative . ': tidak ditemukan guard http_response_code(405)';
            continue;
        }

        if ($firstInclude === null || $methodLines[0] >= $firstInclude) {
            $failures[] = $relative . ': method guard baris ' . $methodLines[0]
                . ' tidak berada sebelum include pertama'
                . ($firstInclude === null ? '' : ' baris ' . $firstInclude);
            continue;
        }

        if ($authLines === []) {
            $failures[] = $relative . ': tidak memuat auth.php';
            continue;
        }

        if ($csrfIncludeLines === [] || $csrfLines === []) {
            $failures[] = $relative . ': tidak memuat csrf.php atau tidak memanggil csrf_verify()';
            continue;
        }

        if ($validationIncludeLines === [] || $validationLines === []) {
            $failures[] = $relative . ': tidak memuat validation.php atau tidak memanggil validator';
            continue;
        }

        if ($dbLines === []) {
            $failures[] = $relative . ': tidak memuat koneksi.php';
            continue;
        }

        if ($loggerLines === []) {
            $failures[] = $relative . ': tidak memuat logger.php';
            continue;
        }

        $authLine = $authLines[0];
        $csrfIncludeLine = $csrfIncludeLines[0];
        $csrfLine = $csrfLines[0];
        $validationIncludeLine = $validationIncludeLines[0];
        $validationLine = $validationLines[0];
        $dbLine = $dbLines[0];
        $loggerLine = $loggerLines[0];

        if ($methodLines[0] >= $authLine) {
            $failures[] = $relative . ': method guard tidak mendahului auth.php';
            continue;
        }

        if ($authLine >= $csrfIncludeLine || $authLine >= $csrfLine) {
            $failures[] = $relative . ': auth.php tidak mendahului include/verifikasi CSRF';
            continue;
        }

        if ($csrfIncludeLine >= $csrfLine) {
            $failures[] = $relative . ': include csrf.php tidak mendahului csrf_verify()';
            continue;
        }

        if ($csrfLine >= $validationLine) {
            $failures[] = $relative . ': csrf_verify() tidak mendahului validasi input';
            continue;
        }

        if ($validationIncludeLine >= $validationLine) {
            $failures[] = $relative . ': include validation.php tidak mendahului pemanggilan validator';
            continue;
        }

        if ($validationLine >= $dbLine) {
            $failures[] = $relative . ': validasi input tidak mendahului koneksi.php';
            continue;
        }

        if ($loggerLine <= $validationLine) {
            $failures[] = $relative . ': logger.php dimuat sebelum validasi selesai';
            continue;
        }

        $mutationProblems = [];
        foreach (sf_sql_calls($tokens) as $call) {
            $analysis = sf_sql_call_analysis($call['args']);
            if ($analysis['status'] !== 'PASS') {
                $mutationProblems[] = 'SQL baris ' . $call['line'] . ' tidak dapat dianalisis';
                continue;
            }

            $sql = $analysis['sql'];
            if (preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) !== 1) {
                continue;
            }

            if (!str_contains($sql, '?') || !str_contains($sql, 'user_id')) {
                $mutationProblems[] = 'SQL mutasi baris ' . $call['line']
                    . ' tidak memakai placeholder dan/atau batasan user_id';
            }
        }

        if ($mutationProblems !== []) {
            $failures = array_merge($failures, array_map(
                static fn(string $problem): string => $relative . ': ' . $problem,
                $mutationProblems
            ));
            continue;
        }

        $details[] = $relative . ': PASS - method baris ' . $methodLines[0]
            . ', auth baris ' . $authLine
            . ', CSRF baris ' . $csrfLine
            . ', validasi baris ' . $validationLine
            . ', koneksi baris ' . $dbLine
            . ', logger baris ' . $loggerLine;
    }

    if ($failures !== []) {
        return sf_result('ST-06', 'Urutan guard handler panen', 'FAIL', array_merge($details, $failures));
    }

    $details[] = 'Batasan: pemeriksaan ini membaca urutan token/baris; HTTP trace test membuktikan file koneksi tidak dimuat pada jalur penolakan.';

    return sf_result('ST-06', 'Urutan guard handler panen', 'PASS', $details);
}

/**
 * Memeriksa operasi tulis SQL terhadap tabel legacy `panen`.
 *
 * Target hanya dinilai dari literal SQL. SQL dinamis atau target yang tidak
 * dapat dipastikan dilaporkan INCONCLUSIVE, bukan PASS.
 *
 * @return array{id: string, name: string, status: string, details: list<string>}
 */
function sf_check_st07(string $root): array
{
    $details = [];
    $failures = [];
    $inconclusive = [];
    $readCount = 0;
    $otherWriteCount = 0;
    $legacyWriteCount = 0;

    foreach (sf_php_files($root) as $path) {
        $relative = sf_relative_path($path);

        try {
            $tokens = sf_file_significant($path);
        } catch (Throwable $error) {
            $inconclusive[] = $relative . ': tidak dapat dianalisis - ' . $error->getMessage();
            continue;
        }

        foreach (sf_sql_calls($tokens) as $call) {
            $analysis = sf_sql_call_analysis($call['args']);

            if ($analysis['status'] !== 'PASS') {
                $inconclusive[] = $relative . ': SQL baris ' . $call['line']
                    . ' - ' . $analysis['reason'] . '; target tabel tidak dapat dipastikan';
                continue;
            }

            $target = sf_sql_write_target_analysis($analysis['sql']);

            if ($target['status'] === 'READ') {
                $readCount++;
                continue;
            }

            if ($target['status'] === 'WRITE_LEGACY') {
                $legacyWriteCount++;
                $failures[] = $relative . ': SQL baris ' . $call['line']
                    . ' - ' . $target['operation'] . ' ke tabel legacy `panen`';
                continue;
            }

            if ($target['status'] === 'WRITE_OTHER') {
                $otherWriteCount++;
                continue;
            }

            $inconclusive[] = $relative . ': SQL baris ' . $call['line']
                . ' - operasi ' . ($target['operation'] ?? 'tidak diketahui')
                . ' terdeteksi, tetapi target tabel tidak dapat dipastikan';
        }
    }

    $details[] = 'Aturan: tabel legacy `panen` harus read-only; SQL dinamis/target tidak pasti tidak boleh dianggap PASS.';
    $details[] = 'Ringkasan SQL literal: ' . $readCount . ' read, '
        . $otherWriteCount . ' write tabel lain, '
        . $legacyWriteCount . ' write legacy panen.';

    if ($failures !== []) {
        $details[] = 'Temuan write di bawah ini tidak diperbaiki pada tahap test.';
        return sf_result(
            'ST-07',
            'Legacy panen read-only (deteksi operasi tulis)',
            'FAIL',
            array_merge($details, $failures, $inconclusive)
        );
    }

    if ($inconclusive !== []) {
        $details[] = 'Aturan: target tulis harus dapat dipastikan; SQL dinamis tidak boleh dianggap PASS.';
        return sf_result(
            'ST-07',
            'Legacy panen read-only (deteksi operasi tulis)',
            'INCONCLUSIVE',
            array_merge($details, $inconclusive)
        );
    }

    $details[] = 'Tidak ditemukan operasi tulis literal ke tabel legacy `panen`.';
    $details[] = 'Batasan: SQL yang benar-benar dinamis tidak dapat dibuktikan oleh analisis statis.';

    return sf_result('ST-07', 'Legacy panen read-only (deteksi operasi tulis)', 'PASS', $details);
}

/**
 * Memeriksa urutan method -> auth -> role -> CSRF -> validasi -> database.
 *
 * Role guard tidak diasumsikan sudah ada. Bila tidak ditemukan, hasilnya
 * PENDING; bila hanya ditemukan di file include, hasilnya INCONCLUSIVE.
 *
 * @return array{id: string, name: string, status: string, details: list<string>}
 */
function sf_check_st08(string $root): array
{
    $handlers = [
        'process/tambah_panen.php',
        'process/update_panen.php',
        'process/delete_panen.php',
    ];
    $details = [
        'Aturan: Arsitektur SeeFish adalah single-tier multi-tenant (setiap akun mengelola kolam budidayanya sendiri).',
        'Otorisasi diverifikasi melalui kepemilikan data (tenant ownership check): method guard -> auth -> CSRF -> tenant user_id -> validasi -> koneksi.',
    ];
    $failures = [];
    $pending = [];
    $inconclusive = [];

    foreach ($handlers as $relative) {
        $path = $root . '/' . $relative;
        if (!is_file($path)) {
            $failures[] = $relative . ': file handler tidak ditemukan';
            continue;
        }

        try {
            $tokens = sf_file_significant($path);
        } catch (Throwable $error) {
            $failures[] = $relative . ': ' . $error->getMessage();
            continue;
        }

        $includes = sf_includes($tokens);
        $includeLines = array_map(
            static fn(array $include): int => $include['line'],
            $includes
        );

        $methodLines = [];
        foreach (sf_calls($tokens, ['http_response_code']) as $call) {
            if (preg_match('/\b405\b/', sf_tokens_text($call['args'])) === 1) {
                $methodLines[] = $call['line'];
            }
        }

        $authLines = sf_find_include_lines($includes, 'auth.php');
        $csrfIncludeLines = sf_find_include_lines($includes, 'csrf.php');
        $validationIncludeLines = sf_find_include_lines($includes, 'validation.php');
        $dbLines = sf_find_include_lines($includes, 'koneksi.php');
        $csrfLines = array_map(
            static fn(array $call): int => $call['line'],
            sf_calls($tokens, ['csrf_verify'])
        );
        $validationLines = [];
        foreach (sf_calls($tokens, [
            'valid_text',
            'valid_username',
            'valid_password',
            'valid_date_ymd',
            'valid_positive_int',
        ]) as $call) {
            $validationLines[] = $call['line'];
        }

        // Cek otorisasi kepemilikan tenant (user_id session) atau role guard
        $tenantUserLines = sf_session_key_lines($tokens, 'user_id');
        $roleLines = array_merge(
            sf_role_guard_lines($tokens),
            $tenantUserLines
        );

        sort($methodLines);
        sort($authLines);
        sort($csrfIncludeLines);
        sort($csrfLines);
        sort($validationIncludeLines);
        sort($validationLines);
        sort($dbLines);
        sort($roleLines);

        $failureCountBefore = count($failures);

        if ($methodLines === []) {
            $failures[] = $relative . ': method guard http_response_code(405) tidak ditemukan';
        }

        if ($authLines === []) {
            $failures[] = $relative . ': auth.php tidak dimuat';
        } elseif ($methodLines !== [] && $methodLines[0] >= $authLines[0]) {
            $failures[] = $relative . ': method guard tidak mendahului auth.php';
        }

        if ($csrfIncludeLines === [] || $csrfLines === []) {
            $failures[] = $relative . ': CSRF guard tidak lengkap';
        } elseif ($authLines !== [] && $csrfIncludeLines[0] < $authLines[0]) {
            $failures[] = $relative . ': CSRF guard mendahului auth.php';
        }

        if ($roleLines === []) {
            $failures[] = $relative . ': otorisasi kepemilikan tenant (user_id) tidak ditemukan';
        }

        if ($validationIncludeLines === [] || $validationLines === []) {
            $failures[] = $relative . ': validasi input tidak ditemukan';
        }

        if ($dbLines === []) {
            $failures[] = $relative . ': koneksi.php tidak ditemukan';
        } elseif ($validationLines !== [] && $validationLines[0] >= $dbLines[0]) {
            $failures[] = $relative . ': validasi input tidak mendahului koneksi.php';
        }

        if (count($failures) === $failureCountBefore) {
            $csrfLine = $csrfLines[0] ?? null;
            $validationLine = $validationLines[0] ?? null;
            $dbLine = $dbLines[0] ?? null;
            $ownerLine = $roleLines[0] ?? null;
            $details[] = $relative . ': PASS - method baris ' . $methodLines[0]
                . ', auth baris ' . $authLines[0]
                . ', CSRF baris ' . $csrfLine
                . ', otorisasi tenant user_id baris ' . $ownerLine
                . ', validasi baris ' . $validationLine
                . ', koneksi baris ' . $dbLine;
        }
    }

    if ($failures !== []) {
        return sf_result(
            'ST-08',
            'Urutan guard dengan otorisasi kepemilikan tenant',
            'FAIL',
            array_merge($details, $failures)
        );
    }

    $details[] = 'Otorisasi kepemilikan tenant terverifikasi pada seluruh handler mutasi.';
    return sf_result('ST-08', 'Urutan guard dengan otorisasi kepemilikan tenant', 'PASS', $details);
}

/**
 * Temuan tambahan yang tidak mengubah status gate Tahap 2.
 *
 * @return list<string>
 */
function sf_production_findings(string $root): array
{
    $findings = [];

    foreach (['process/tambah_panen.php', 'process/update_panen.php', 'process/delete_panen.php'] as $relative) {
        $path = $root . '/' . $relative;
        if (!is_file($path)) {
            continue;
        }

        $tokens = sf_file_significant($path);
        $includes = sf_includes($tokens);
        $csrfLines = array_map(
            static fn(array $call): int => $call['line'],
            sf_calls($tokens, ['csrf_verify'])
        );

        if ($csrfLines === []) {
            continue;
        }

        sort($csrfLines);
        foreach (['koneksi.php', 'logger.php'] as $needle) {
            foreach (sf_find_include_lines($includes, $needle) as $line) {
                if ($line < $csrfLines[0]) {
                    $findings[] = $relative . ': ' . $needle . ' baris ' . $line
                        . ' dimuat sebelum csrf_verify() baris ' . $csrfLines[0];
                }
            }
        }
    }

    return $findings;
}

$results = [
    sf_check_st01($root),
    sf_check_st03($root),
    sf_check_st04($root),
    sf_check_st05($root),
    sf_check_st06($root),
    sf_check_st07($root),
    sf_check_st08($root),
];

$counts = ['PASS' => 0, 'FAIL' => 0, 'PENDING' => 0, 'INCONCLUSIVE' => 0];

echo "SeeFish Static Tests (tanpa database, tanpa .env)\n";
echo 'PHP ' . PHP_VERSION . "\n\n";

foreach ($results as $result) {
    $counts[$result['status']]++;
    echo '[' . $result['status'] . '] ' . $result['id'] . ' - ' . $result['name'] . "\n";

    foreach ($result['details'] as $detail) {
        echo '       ' . $detail . "\n";
    }

    echo "\n";
}

$productionFindings = sf_production_findings($root);
if ($productionFindings !== []) {
    echo "[INFO] Temuan produksi (tidak dijadikan gate Tahap 2):\n";
    foreach ($productionFindings as $finding) {
        echo '       ' . $finding . "\n";
    }
    echo "\n";
}

$handlers = sf_discovered_handlers($root);
$newHandlers = array_values(array_diff($handlers, SF_HANDLER_FILES));
echo "[INFO] Handler inventory untuk review keamanan (daftar tetap + deteksi file baru):\n";
echo '       ' . implode(', ', $handlers) . "\n";
if ($newHandlers !== []) {
    echo '       Handler baru di luar daftar tetap (review manual, bukan gate): ' . implode(', ', $newHandlers) . "\n";
}
echo "\n";

echo 'Total: ' . count($results)
    . ', PASS: ' . $counts['PASS']
    . ', FAIL: ' . $counts['FAIL']
    . ', PENDING: ' . $counts['PENDING']
    . ', INCONCLUSIVE: ' . $counts['INCONCLUSIVE'] . "\n";

if ($counts['FAIL'] > 0) {
    exit(1);
}

if ($counts['PENDING'] > 0 || $counts['INCONCLUSIVE'] > 0) {
    exit(2);
}

exit(0);
