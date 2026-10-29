#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/lib/http_client.php';
require __DIR__ . '/lib/static_analysis.php';

final class HttpSmokeException extends RuntimeException
{
}

function http_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new HttpSmokeException($message);
    }
}

function http_assert_status(TestHttpResponse $response, int $expected, string $context): void
{
    if ($response->status !== $expected) {
        throw new HttpSmokeException(
            $context . ': status ' . $response->status . ', diharapkan ' . $expected
        );
    }
}

function http_assert_header(TestHttpResponse $response, string $name, string $expected, string $context): void
{
    $actual = $response->header($name);
    if ($actual !== $expected) {
        throw new HttpSmokeException(
            $context . ': header ' . $name . ' = ' . var_export($actual, true)
            . ', diharapkan ' . var_export($expected, true)
        );
    }
}

function http_assert_contains(string $haystack, string $needle, string $context): void
{
    if (!str_contains($haystack, $needle)) {
        throw new HttpSmokeException($context . ': respons tidak memuat ' . var_export($needle, true));
    }
}

function http_extract_csrf_token(string $body, string $context): string
{
    if (preg_match('/<input type="hidden" name="csrf_token" value="([a-f0-9]{64})">/', $body, $matches) !== 1) {
        throw new HttpSmokeException($context . ': token CSRF tidak ditemukan atau formatnya tidak valid');
    }

    return $matches[1];
}

function http_form_body(array $fields): string
{
    return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
}

/**
 * @param array<string, int> $counts
 */
function http_report_inconclusive(string $id, string $name, string $reason, array &$counts): void
{
    $counts['INCONCLUSIVE']++;
    echo '[INCONCLUSIVE] ' . $id . ' - ' . $name . "\n";
    echo '       ' . $reason . "\n";
}

function http_trace_reset(string $traceFile): void
{
    if (@file_put_contents($traceFile, '') === false) {
        throw new HttpSmokeException('Tidak dapat mengosongkan trace file temporer.');
    }
}

/**
 * @return array{method: string, uri: string, included: list<string>}
 */
function http_trace_read(string $traceFile): array
{
    $deadline = microtime(true) + 2.0;

    do {
        if (is_file($traceFile)) {
            $contents = @file_get_contents($traceFile);
            if (is_string($contents) && trim($contents) !== '') {
                $lines = preg_split('/\R/', trim($contents));
                $last = is_array($lines) ? end($lines) : null;

                if (is_string($last)) {
                    $data = json_decode($last, true);
                    if (is_array($data)
                        && is_string($data['method'] ?? null)
                        && is_string($data['uri'] ?? null)
                        && is_array($data['included'] ?? null)) {
                        return [
                            'method' => $data['method'],
                            'uri' => $data['uri'],
                            'included' => array_values(array_map('strval', $data['included'])),
                        ];
                    }
                }
            }
        }

        usleep(50000);
    } while (microtime(true) < $deadline);

    throw new HttpSmokeException('Trace include runtime tidak terbaca atau tidak valid.');
}

/**
 * @param array{method: string, uri: string, included: list<string>} $trace
 */
function http_trace_contains(array $trace, string $needle): bool
{
    $needle = str_replace('\\', '/', $needle);

    foreach ($trace['included'] as $included) {
        $included = str_replace('\\', '/', $included);
        if ($included === $needle || str_ends_with($included, '/' . $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array{method: string, uri: string, included: list<string>} $trace
 * @param list<string> $needles
 */
function http_assert_trace_excludes(array $trace, array $needles, string $context): void
{
    foreach ($needles as $needle) {
        if (http_trace_contains($trace, $needle)) {
            throw new HttpSmokeException($context . ': trace memuat ' . $needle);
        }
    }
}

/**
 * Preflight statis untuk menjaga test HTTP tidak mengirim request yang dapat
 * membuka database pada kode yang belum diperbaiki.
 *
 * @return array{method: bool, anonymous: bool, csrf: bool, validation: bool, reason: string}
 */
function http_preflight_handler(string $root, string $relative): array
{
    $result = [
        'method' => false,
        'anonymous' => false,
        'csrf' => false,
        'validation' => false,
        'reason' => '',
    ];

    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($path)) {
        $result['reason'] = $relative . ': file tidak ditemukan';
        return $result;
    }

    try {
        $tokens = sf_file_significant($path);
    } catch (Throwable $error) {
        $result['reason'] = $relative . ': ' . $error->getMessage();
        return $result;
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

    $includeLinesFor = static function (string $needle) use ($includes): array {
        $lines = [];
        foreach ($includes as $include) {
            if (str_contains(sf_tokens_text($include['expression']), $needle)) {
                $lines[] = $include['line'];
            }
        }
        return $lines;
    };

    $authLines = $includeLinesFor('auth.php');
    $dbLines = $includeLinesFor('koneksi.php');
    $validationIncludeLines = $includeLinesFor('validation.php');
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
    sort($dbLines);
    sort($validationIncludeLines);
    sort($csrfLines);
    sort($validationLines);

    $methodLine = $methodLines[0] ?? null;
    $authLine = $authLines[0] ?? null;
    $dbLine = $dbLines[0] ?? null;
    $csrfLine = $csrfLines[0] ?? null;
    $validationLine = $validationLines[0] ?? null;

    $result['method'] = $methodLine !== null
        && $firstInclude !== null
        && $methodLine < $firstInclude
        && str_contains(sf_tokens_text($tokens), 'REQUEST_METHOD');

    $result['anonymous'] = $authLine !== null
        && $dbLine !== null
        && $authLine < $dbLine;

    $result['csrf'] = $authLine !== null
        && $dbLine !== null
        && $csrfLine !== null
        && $authLine < $csrfLine
        && $csrfLine < $dbLine;

    $result['validation'] = $result['csrf']
        && $validationIncludeLines !== []
        && $validationLine !== null
        && $dbLine !== null
        && $csrfLine < $validationLine
        && $validationLine < $dbLine;

    $reasons = [];
    if (!$result['method']) {
        $reasons[] = 'method guard 405 tidak terbukti sebelum include pertama';
    }
    if (!$result['anonymous']) {
        $reasons[] = 'auth.php tidak terbukti sebelum koneksi.php';
    }
    if (!$result['csrf']) {
        $reasons[] = 'csrf_verify() tidak terbukti setelah auth dan sebelum koneksi.php';
    }
    if (!$result['validation']) {
        $reasons[] = 'validasi tidak terbukti setelah CSRF dan sebelum koneksi.php';
    }

    if ($reasons !== []) {
        $result['reason'] = $relative . ': ' . implode('; ', $reasons);
    }

    return $result;
}

/**
 * @return array{id: string, token: string, cookie: string}
 */
function http_create_auth_session(string $sessionDir): array
{
    $sessionId = bin2hex(random_bytes(16));
    $csrfToken = bin2hex(random_bytes(32));
    $username = 'smoke-user';
    $userId = 4242;

    $data = 'login|b:1;'
        . 'user_id|i:' . $userId . ';'
        . 'username|s:' . strlen($username) . ':"' . $username . '";'
        . 'last_activity|i:' . time() . ';'
        . 'csrf_token|s:' . strlen($csrfToken) . ':"' . $csrfToken . '";';

    $path = $sessionDir . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
    if (@file_put_contents($path, $data, LOCK_EX) === false) {
        throw new HttpSmokeException('Tidak dapat membuat session sintetis temporer.');
    }
    @chmod($path, 0600);

    return [
        'id' => $sessionId,
        'token' => $csrfToken,
        'cookie' => 'SEEFISH_TEST_SESSID=' . $sessionId,
    ];
}

/**
 * Request ke endpoint aplikasi. Status 5xx menghentikan suite karena dapat
 * menandakan endpoint mencoba mengakses database atau gagal sebelum redirect.
 */
function http_app_request(
    TestHttpClient $client,
    string $method,
    string $path,
    array $headers = [],
    ?string $body = null
): TestHttpResponse {
    $response = $client->request($method, $path, $headers, $body);

    if (in_array($response->status, [500, 502, 503, 504], true)) {
        throw new HttpSmokeException(
            $path . ': status ' . $response->status
            . '; pengujian dihentikan agar tidak melanjutkan endpoint yang mungkin mengakses database'
        );
    }

    return $response;
}

/**
 * @param array<string, int> $counts
 */
function http_report_test(string $id, string $name, callable $test, array &$counts): void
{
    try {
        $test();
    } catch (Throwable $error) {
        $counts['FAIL']++;
        echo '[FAIL] ' . $id . ' - ' . $name . "\n";
        echo '       ' . $error->getMessage() . "\n";
        throw new HttpSmokeException('Suite dihentikan setelah kegagalan ' . $id, 0, $error);
    }

    $counts['PASS']++;
    echo '[PASS] ' . $id . ' - ' . $name . "\n";
}

function http_find_free_port(): int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new HttpSmokeException('Tidak dapat memilih port loopback: ' . $error . ' (' . $errno . ')');
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    if (!is_string($name) || preg_match('/:(\d+)$/', $name, $matches) !== 1) {
        throw new HttpSmokeException('Tidak dapat membaca port loopback yang dipilih.');
    }

    $port = (int) $matches[1];
    if ($port < 1 || $port > 65535) {
        throw new HttpSmokeException('Port loopback tidak valid: ' . $port);
    }

    return $port;
}

/**
 * @return array{process: resource, pid: int, pipes: array<int, resource>}
 */
function http_start_server(
    string $root,
    int $port,
    string $sessionDir,
    string $logFile,
    string $traceFile
): array {
    $prepend = __DIR__ . '/http/server_prepend.php';
    if (!is_file($prepend)) {
        throw new HttpSmokeException('File auto_prepend tidak ditemukan: tests/http/server_prepend.php');
    }

    $command = [
        PHP_BINARY,
        '-d',
        'auto_prepend_file=' . $prepend,
        '-d',
        'session.save_handler=files',
        '-d',
        'session.save_path=' . $sessionDir,
        '-d',
        'session.serialize_handler=php',
        '-d',
        'log_errors=1',
        '-d',
        'error_log=' . $logFile,
        '-S',
        '127.0.0.1:' . $port,
        '-t',
        $root,
    ];

    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }

    // Nilai test yang tidak sensitif; jangan mengisi kredensial database.
    $environment['APP_ENV'] = 'test';
    $environment['APP_TIMEZONE'] = 'UTC';
    $environment['PASSWORD_MIN_LENGTH'] = '12';
    $environment['SESSION_NAME'] = 'SEEFISH_TEST_SESSID';
    $environment['SESSION_SECURE'] = '0';
    $environment['SEE_FISH_HTTP_TRACE_FILE'] = $traceFile;

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $logFile, 'a'],
        2 => ['file', $logFile, 'a'],
    ];

    $pipes = [];
    $process = @proc_open($command, $descriptors, $pipes, $root, $environment);
    if (!is_resource($process)) {
        throw new HttpSmokeException('proc_open() gagal memulai PHP built-in server.');
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
        unset($pipes[0]);
    }

    $status = proc_get_status($process);
    $pid = (int) ($status['pid'] ?? 0);

    return ['process' => $process, 'pid' => $pid, 'pipes' => $pipes];
}

function http_log_tail(string $logFile, int $maxBytes = 2000): string
{
    if (!is_file($logFile)) {
        return '';
    }

    $size = filesize($logFile);
    if ($size === false || $size === 0) {
        return '';
    }

    $handle = @fopen($logFile, 'rb');
    if ($handle === false) {
        return '';
    }

    $start = max(0, $size - $maxBytes);
    fseek($handle, $start);
    $contents = stream_get_contents($handle);
    fclose($handle);

    return is_string($contents) ? trim($contents) : '';
}

function http_wait_for_server(array $server, int $port, string $logFile): void
{
    $deadline = microtime(true) + 5.0;

    while (microtime(true) < $deadline) {
        $status = proc_get_status($server['process']);
        if (!($status['running'] ?? false)) {
            $tail = http_log_tail($logFile);
            throw new HttpSmokeException(
                'PHP built-in server berhenti sebelum siap.'
                . ($tail === '' ? '' : "\n" . $tail)
            );
        }

        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            return;
        }

        usleep(100000);
    }

    $tail = http_log_tail($logFile);
    throw new HttpSmokeException(
        'PHP built-in server tidak siap pada 127.0.0.1:' . $port . ' dalam 5 detik.'
        . ($tail === '' ? '' : "\n" . $tail)
    );
}

function http_taskkill(int $pid): void
{
    if ($pid < 1) {
        return;
    }

    $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $pipes = [];
    $process = @proc_open(
        ['taskkill', '/F', '/T', '/PID', (string) $pid],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $nullDevice, 'a'],
            2 => ['file', $nullDevice, 'a'],
        ],
        $pipes,
        dirname(__DIR__)
    );

    if (!is_resource($process)) {
        return;
    }

    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    @proc_close($process);
}

function http_stop_server(array $server): void
{
    $process = $server['process'];
    $pid = (int) ($server['pid'] ?? 0);

    if (!is_resource($process)) {
        return;
    }

    $status = proc_get_status($process);

    if (($status['running'] ?? false)) {
        @proc_terminate($process, 9);

        $deadline = microtime(true) + 2.0;
        do {
            usleep(100000);
            $status = proc_get_status($process);
        } while (($status['running'] ?? false) && microtime(true) < $deadline);

        if (($status['running'] ?? false)) {
            if (DIRECTORY_SEPARATOR === '\\') {
                http_taskkill($pid);
            } else {
                @proc_terminate($process, 9);
            }

            $deadline = microtime(true) + 2.0;
            do {
                usleep(100000);
                $status = proc_get_status($process);
            } while (($status['running'] ?? false) && microtime(true) < $deadline);
        }
    }

    foreach (($server['pipes'] ?? []) as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    @proc_close($process);
}

function http_remove_tree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (!is_dir($path)) {
        @unlink($path);
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $itemPath = $item->getPathname();
        if ($item->isDir()) {
            @rmdir($itemPath);
        } else {
            @unlink($itemPath);
        }
    }

    @rmdir($path);
}

function http_path_within(string $path, string $directory): bool
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $directory = rtrim(str_replace('\\', '/', $directory), '/');

    return $path === $directory || str_starts_with($path, $directory . '/');
}

$counts = ['PASS' => 0, 'FAIL' => 0, 'INCONCLUSIVE' => 0];
$exitCode = 0;
$tempRoot = null;
$server = null;
$port = 0;

echo "SeeFish HTTP Smoke Tests (loopback 127.0.0.1, tanpa database)\n";

if (!function_exists('proc_open') || !function_exists('proc_get_status') || !function_exists('proc_terminate')) {
    fwrite(STDERR, "[INCONCLUSIVE] HTTP-ISO - proc_open/proc_get_status/proc_terminate tidak tersedia; smoke test tidak dijalankan.\n");
    exit(2);
}

if (!function_exists('stream_socket_server') || !function_exists('fsockopen')) {
    fwrite(STDERR, "[INCONCLUSIVE] HTTP-ISO - fungsi socket stream tidak tersedia; smoke test tidak dijalankan.\n");
    exit(2);
}

$allowUrlFopen = filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
if ($allowUrlFopen !== true) {
    fwrite(STDERR, "[INCONCLUSIVE] HTTP-ISO - allow_url_fopen nonaktif; HTTP client native tidak dapat dipakai.\n");
    exit(2);
}

if (!is_file(PHP_BINARY) || !is_executable(PHP_BINARY)) {
    fwrite(STDERR, "[INCONCLUSIVE] HTTP-ISO - PHP_BINARY tidak dapat dieksekusi: " . PHP_BINARY . "\n");
    exit(2);
}

try {
    $root = dirname(__DIR__);
    $tempBase = realpath(sys_get_temp_dir());
    if ($tempBase === false || !is_dir($tempBase) || !is_writable($tempBase)) {
        throw new HttpSmokeException('Direktori temporary sistem tidak tersedia atau tidak writable.');
    }

    if (http_path_within($tempBase, $root)) {
        throw new HttpSmokeException('Direktori temporary sistem berada di dalam workspace; isolasi artefak tidak dapat dibuktikan.');
    }

    $tempRoot = $tempBase . DIRECTORY_SEPARATOR . 'seefish-http-' . bin2hex(random_bytes(8));
    $sessionDir = $tempRoot . DIRECTORY_SEPARATOR . 'sessions';
    $logFile = $tempRoot . DIRECTORY_SEPARATOR . 'server.log';
    $traceFile = $tempRoot . DIRECTORY_SEPARATOR . 'trace.jsonl';

    if (!mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
        throw new HttpSmokeException('Tidak dapat membuat direktori sesi temporer di luar workspace.');
    }

    if (@file_put_contents($logFile, '') === false) {
        throw new HttpSmokeException('Tidak dapat membuat log server temporer di luar workspace.');
    }

    if (@file_put_contents($traceFile, '') === false) {
        throw new HttpSmokeException('Tidak dapat membuat trace file temporer di luar workspace.');
    }

    $port = http_find_free_port();
    $server = http_start_server($root, $port, $sessionDir, $logFile, $traceFile);
    http_wait_for_server($server, $port, $logFile);

    $baseUrl = 'http://127.0.0.1:' . $port;
    $probeClient = new TestHttpClient($baseUrl);

    http_report_test('HTTP-ISO', 'Isolasi APP_ROOT dan .env terverifikasi sebelum endpoint aplikasi', function () use ($probeClient): void {
        $response = $probeClient->request('GET', '/tests/http/isolation_probe.php');
        http_assert_status($response, 200, 'Probe isolasi');

        $data = json_decode($response->body, true);
        if (!is_array($data)) {
            throw new HttpSmokeException('Probe isolasi tidak mengembalikan JSON yang valid.');
        }

        if (($data['ok'] ?? false) !== true) {
            $reasons = $data['reasons'] ?? [];
            $reasonText = is_array($reasons) ? implode('; ', array_map('strval', $reasons)) : 'tidak ada alasan';
            throw new HttpSmokeException('Isolasi APP_ROOT tidak terbukti: ' . $reasonText);
        }

        http_assert_true(($data['mechanism'] ?? '') === 'auto_prepend_file', 'Mekanisme auto_prepend_file tidak terbukti.');
        http_assert_true(($data['env_file_present'] ?? true) === false, 'Root isolasi memiliki .env.');
    }, $counts);

    $client = new TestHttpClient($baseUrl);

    $harvestHandlers = [
        'process/tambah_panen.php' => 'tambah',
        'process/update_panen.php' => 'update',
        'process/delete_panen.php' => 'delete',
    ];
    $preflight = [];
    echo "[INFO] Preflight guard handler panen:\n";
    foreach ($harvestHandlers as $path => $label) {
        $preflight[$path] = http_preflight_handler($root, $path);
        $status = [
            'method' => $preflight[$path]['method'],
            'anonymous' => $preflight[$path]['anonymous'],
            'csrf' => $preflight[$path]['csrf'],
            'validation' => $preflight[$path]['validation'],
        ];
        echo '       ' . $path . ': ' . json_encode($status) . "\n";
        if ($preflight[$path]['reason'] !== '') {
            echo '       ' . $preflight[$path]['reason'] . "\n";
        }
    }
    echo "\n";

    http_report_test('HTTP-01', 'GET login.php: status, form, dan token CSRF', function () use ($client): void {
        $response = http_app_request($client, 'GET', '/login.php');
        http_assert_status($response, 200, 'GET login.php');
        http_assert_contains($response->body, '<form action="process/login_process" method="POST"', 'GET login.php');
        http_assert_contains($response->body, 'name="user"', 'GET login.php');
        http_assert_contains($response->body, 'name="pass"', 'GET login.php');
        http_extract_csrf_token($response->body, 'GET login.php');
        http_assert_true($client->hasCookie('SEEFISH_TEST_SESSID'), 'Cookie sesi test tidak diterima dari login.php.');
    }, $counts);

    http_report_test('HTTP-02', 'GET register.php: status, form, password policy, dan token CSRF', function () use ($client): void {
        $response = http_app_request($client, 'GET', '/register.php');
        http_assert_status($response, 200, 'GET register.php');
        http_assert_contains($response->body, '<form action="process/register_process" method="POST"', 'GET register.php');
        http_assert_contains($response->body, 'name="user"', 'GET register.php');
        http_assert_contains($response->body, 'name="pass"', 'GET register.php');
        http_assert_contains($response->body, 'name="confirm"', 'GET register.php');
        http_assert_contains($response->body, 'minlength="12"', 'GET register.php');
        http_extract_csrf_token($response->body, 'GET register.php');
    }, $counts);

    http_report_test('HTTP-03', 'GET logout.php: metode selain POST ditolak 405', function () use ($client): void {
        $response = http_app_request($client, 'GET', '/logout.php');
        http_assert_status($response, 405, 'GET logout.php');
        http_assert_header($response, 'allow', 'POST', 'GET logout.php');
        http_assert_header($response, 'content-type', 'text/plain; charset=UTF-8', 'GET logout.php');
        http_assert_true(
            trim($response->body) === 'Logout harus dilakukan melalui formulir.',
            'GET logout.php: body 405 tidak sesuai implementasi.'
        );
    }, $counts);

    http_report_test('HTTP-04', 'POST logout.php tanpa sesi: redirect tanpa diikuti', function () use ($baseUrl): void {
        $anonymous = new TestHttpClient($baseUrl);
        $response = http_app_request($anonymous, 'POST', '/logout.php', [], '');
        http_assert_status($response, 303, 'POST logout.php tanpa sesi');
        http_assert_header($response, 'location', 'login', 'POST logout.php tanpa sesi');
    }, $counts);

    $invalidLoginBody = http_form_body([
        'csrf_token' => 'invalid',
        'user' => 'smoke-user',
        'pass' => 'smoke-password',
    ]);

    http_report_test('HTTP-05', 'POST login_process.php dengan token salah: CSRF ditolak sebelum database', function () use ($client, $invalidLoginBody): void {
        $response = http_app_request(
            $client,
            'POST',
            '/process/login_process.php',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Content-Length' => (string) strlen($invalidLoginBody)],
            $invalidLoginBody
        );
        http_assert_status($response, 303, 'POST login_process.php token salah');
        http_assert_header($response, 'location', '../login.php?error=csrf', 'POST login_process.php token salah');
    }, $counts);

    $missingLoginBody = http_form_body([
        'user' => 'smoke-user',
        'pass' => 'smoke-password',
    ]);

    http_report_test('HTTP-06', 'POST login_process.php tanpa token: CSRF ditolak sebelum database', function () use ($client, $missingLoginBody): void {
        $response = http_app_request(
            $client,
            'POST',
            '/process/login_process.php',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Content-Length' => (string) strlen($missingLoginBody)],
            $missingLoginBody
        );
        http_assert_status($response, 303, 'POST login_process.php tanpa token');
        http_assert_header($response, 'location', '../login.php?error=csrf', 'POST login_process.php tanpa token');
    }, $counts);

    $invalidRegisterBody = http_form_body([
        'csrf_token' => 'invalid',
        'user' => 'smokeuser',
        'pass' => 'smoke-password',
        'confirm' => 'smoke-password',
    ]);

    http_report_test('HTTP-07', 'POST register_process.php dengan token salah: CSRF ditolak sebelum database', function () use ($client, $invalidRegisterBody): void {
        $response = http_app_request(
            $client,
            'POST',
            '/process/register_process.php',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Content-Length' => (string) strlen($invalidRegisterBody)],
            $invalidRegisterBody
        );
        http_assert_status($response, 303, 'POST register_process.php token salah');
        http_assert_header($response, 'location', '../register.php?error=csrf', 'POST register_process.php token salah');
    }, $counts);

    $missingRegisterBody = http_form_body([
        'user' => 'smokeuser',
        'pass' => 'smoke-password',
        'confirm' => 'smoke-password',
    ]);

    http_report_test('HTTP-08', 'POST register_process.php tanpa token: CSRF ditolak sebelum database', function () use ($client, $missingRegisterBody): void {
        $response = http_app_request(
            $client,
            'POST',
            '/process/register_process.php',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Content-Length' => (string) strlen($missingRegisterBody)],
            $missingRegisterBody
        );
        http_assert_status($response, 303, 'POST register_process.php tanpa token');
        http_assert_header($response, 'location', '../register.php?error=csrf', 'POST register_process.php tanpa token');
    }, $counts);

    foreach ([
        'HTTP-09' => ['process/tambah_panen.php', 'POST tambah_panen.php tanpa sesi'],
        'HTTP-10' => ['process/update_panen.php', 'POST update_panen.php tanpa sesi'],
        'HTTP-11' => ['process/delete_panen.php', 'POST delete_panen.php tanpa sesi'],
    ] as $id => [$path, $description]) {
        if (($preflight[$path]['anonymous'] ?? false) !== true) {
            http_report_inconclusive(
                $id,
                $description . ': autentikasi menghentikan request sebelum database',
                $preflight[$path]['reason'],
                $counts
            );
            continue;
        }

        http_report_test($id, $description . ': autentikasi menghentikan request sebelum database', function () use ($baseUrl, $path, $traceFile): void {
            http_trace_reset($traceFile);
            $anonymous = new TestHttpClient($baseUrl);
            $response = http_app_request(
                $anonymous,
                'POST',
                '/' . $path,
                ['X-SeeFish-Test-Trace' => '1'],
                ''
            );
            http_assert_status($response, 303, 'POST ' . $path . ' tanpa sesi');
            http_assert_header($response, 'location', '../login', 'POST ' . $path . ' tanpa sesi');
            http_assert_trace_excludes(
                http_trace_read($traceFile),
                ['config/koneksi.php', 'config/logger.php'],
                'POST ' . $path . ' tanpa sesi'
            );
        }, $counts);
    }

    $methodIds = [
        'process/tambah_panen.php' => 12,
        'process/update_panen.php' => 16,
        'process/delete_panen.php' => 20,
    ];

    foreach ($harvestHandlers as $path => $label) {
        foreach (['GET', 'PUT', 'DELETE', 'HEAD'] as $index => $method) {
            $id = 'HTTP-' . ($methodIds[$path] + $index);
            $description = $method . ' ' . $path . ': metode selain POST ditolak 405';

            if (($preflight[$path]['method'] ?? false) !== true) {
                http_report_inconclusive(
                    $id,
                    $description,
                    $preflight[$path]['reason'],
                    $counts
                );
                continue;
            }

            http_report_test($id, $description . ' sebelum session/auth/database', function () use ($baseUrl, $path, $method, $traceFile): void {
                http_trace_reset($traceFile);
                $methodClient = new TestHttpClient($baseUrl);
                $response = http_app_request(
                    $methodClient,
                    $method,
                    '/' . $path,
                    ['X-SeeFish-Test-Trace' => '1']
                );

                http_assert_status($response, 405, $method . ' ' . $path);
                http_assert_header($response, 'allow', 'POST', $method . ' ' . $path);
                http_assert_header($response, 'content-type', 'text/plain; charset=UTF-8', $method . ' ' . $path);
                if ($method !== 'HEAD') {
                    http_assert_true(
                        trim($response->body) === 'Aksi ini hanya menerima metode POST.',
                        $method . ' ' . $path . ': body 405 tidak sesuai.'
                    );
                }
                http_assert_true(
                    $response->header('set-cookie') === null,
                    $method . ' ' . $path . ': method guard seharusnya belum memulai session.'
                );
                http_assert_trace_excludes(
                    http_trace_read($traceFile),
                    [
                        'config/session.php',
                        'config/auth.php',
                        'config/csrf.php',
                        'config/koneksi.php',
                        'config/logger.php',
                    ],
                    $method . ' ' . $path
                );
            }, $counts);
        }
    }

    foreach ([
        'HTTP-24' => ['process/tambah_panen.php', 'POST tambah_panen.php dengan sesi tetapi CSRF salah'],
        'HTTP-25' => ['process/update_panen.php', 'POST update_panen.php dengan sesi tetapi CSRF salah'],
        'HTTP-26' => ['process/delete_panen.php', 'POST delete_panen.php dengan sesi tetapi CSRF salah'],
    ] as $id => [$path, $description]) {
        if (($preflight[$path]['csrf'] ?? false) !== true) {
            http_report_inconclusive($id, $description, $preflight[$path]['reason'], $counts);
            continue;
        }

        http_report_test($id, $description . ': ditolak sebelum database', function () use ($baseUrl, $path, $sessionDir, $traceFile): void {
            $session = http_create_auth_session($sessionDir);
            $body = http_form_body(['csrf_token' => 'invalid']);
            http_trace_reset($traceFile);

            $authenticated = new TestHttpClient($baseUrl);
            $response = http_app_request(
                $authenticated,
                'POST',
                '/' . $path,
                [
                    'Cookie' => $session['cookie'],
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Content-Length' => (string) strlen($body),
                    'X-SeeFish-Test-Trace' => '1',
                ],
                $body
            );

            http_assert_status($response, 303, 'POST ' . $path . ' CSRF salah');
            http_assert_header($response, 'location', '../dashboard?error=csrf', 'POST ' . $path . ' CSRF salah');
            http_assert_trace_excludes(
                http_trace_read($traceFile),
                ['config/koneksi.php', 'config/logger.php'],
                'POST ' . $path . ' CSRF salah'
            );
        }, $counts);
    }

    foreach ([
        'HTTP-27' => ['process/tambah_panen.php', 'POST tambah_panen.php dengan CSRF valid dan input invalid'],
        'HTTP-28' => ['process/update_panen.php', 'POST update_panen.php dengan CSRF valid dan input invalid'],
        'HTTP-29' => ['process/delete_panen.php', 'POST delete_panen.php dengan CSRF valid dan input invalid'],
    ] as $id => [$path, $description]) {
        if (($preflight[$path]['validation'] ?? false) !== true) {
            http_report_inconclusive($id, $description, $preflight[$path]['reason'], $counts);
            continue;
        }

        http_report_test($id, $description . ': validasi menolak sebelum database', function () use ($baseUrl, $path, $sessionDir, $traceFile): void {
            $session = http_create_auth_session($sessionDir);
            $body = http_form_body(['csrf_token' => $session['token']]);
            http_trace_reset($traceFile);

            $authenticated = new TestHttpClient($baseUrl);
            $response = http_app_request(
                $authenticated,
                'POST',
                '/' . $path,
                [
                    'Cookie' => $session['cookie'],
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Content-Length' => (string) strlen($body),
                    'X-SeeFish-Test-Trace' => '1',
                ],
                $body
            );

            http_assert_status($response, 303, 'POST ' . $path . ' CSRF valid input invalid');
            http_assert_header($response, 'location', '../dashboard?error=invalid', 'POST ' . $path . ' CSRF valid input invalid');
            http_assert_trace_excludes(
                http_trace_read($traceFile),
                ['config/koneksi.php', 'config/logger.php'],
                'POST ' . $path . ' CSRF valid input invalid'
            );
        }, $counts);
    }

} catch (HttpSmokeException $error) {
    $exitCode = 1;
    echo "\n[ABORT] " . $error->getMessage() . "\n";
} catch (Throwable $error) {
    $exitCode = 1;
    echo "\n[FAIL] " . $error->getMessage() . "\n";
} finally {
    if (is_array($server)) {
        http_stop_server($server);
    }

    if (is_string($tempRoot) && $tempRoot !== '') {
        http_remove_tree($tempRoot);

        if (file_exists($tempRoot)) {
            fwrite(STDERR, "[WARN] Artefak temporer belum sepenuhnya dibersihkan: " . $tempRoot . "\n");
            $exitCode = 1;
        }
    }
}

$total = array_sum($counts);
echo "\nTotal: " . $total
    . ', PASS: ' . $counts['PASS']
    . ', FAIL: ' . $counts['FAIL']
    . ', INCONCLUSIVE: ' . $counts['INCONCLUSIVE'] . "\n";

if ($counts['INCONCLUSIVE'] > 0 && $exitCode === 0) {
    $exitCode = 2;
}

exit($exitCode);
