<?php
declare(strict_types=1);

/**
 * Analisis statis SeeFish berbasis token PHP.
 *
 * Helper ini tidak memuat bootstrap aplikasi, tidak memakai session, dan
 * tidak membaca .env. Semua analisis hanya membaca file .php produksi.
 */

const SF_SKIP_TOKEN_IDS = [
    T_WHITESPACE,
    T_COMMENT,
    T_DOC_COMMENT,
    T_OPEN_TAG,
    T_CLOSE_TAG,
    T_OPEN_TAG_WITH_ECHO,
    T_INLINE_HTML,
];

const SF_HANDLER_FILES = [
    'login.php',
    'register.php',
    'logout.php',
    'dashboard.php',
    'index.php',
    '403.php',
    '404.php',
    'process/login_process.php',
    'process/register_process.php',
    'process/tambah_panen.php',
    'process/update_panen.php',
    'process/delete_panen.php',
];

function sf_project_root(): string
{
    return dirname(__DIR__, 2);
}

/**
 * Daftar informatif file entry point yang terlihat, bukan manifest gate.
 *
 * @return list<string>
 */
function sf_discovered_handlers(string $root): array
{
    $handlers = [];
    $patterns = [
        $root . DIRECTORY_SEPARATOR . '*.php',
        $root . DIRECTORY_SEPARATOR . 'process' . DIRECTORY_SEPARATOR . '*.php',
    ];

    foreach ($patterns as $pattern) {
        foreach (glob($pattern) ?: [] as $path) {
            if (is_file($path)) {
                $handlers[] = sf_relative_path($path);
            }
        }
    }

    $handlers = array_values(array_unique($handlers));
    sort($handlers);

    return $handlers;
}

function sf_relative_path(string $path): string
{
    $root = sf_project_root();
    $path = str_replace('\\', '/', $path);
    $root = rtrim(str_replace('\\', '/', $root), '/');

    return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
}

/**
 * @return list<string>
 */
function sf_php_files(string $root): array
{
    $exclude = ['tests', 'vendor', 'node_modules', 'logs', '.git', '.idea', '.vscode'];
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $relative = sf_relative_path($path);
        $first = explode('/', $relative)[0];

        if (in_array($first, $exclude, true)) {
            continue;
        }

        $files[] = $path;
    }

    sort($files);

    return $files;
}

/**
 * Token dengan nomor baris yang konsisten untuk token string tunggal.
 *
 * @return list<array{id: int|null, text: string, line: int}>
 */
function sf_tokens(string $code): array
{
    $tokens = [];
    $line = 1;

    foreach (token_get_all($code) as $token) {
        if (is_array($token)) {
            $id = $token[0];
            $text = $token[1];
            $line = $token[2];
        } else {
            $id = null;
            $text = $token;
        }

        $tokens[] = ['id' => $id, 'text' => $text, 'line' => $line];
        $line += substr_count($text, "\n");
    }

    return $tokens;
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 * @return list<array{id: int|null, text: string, line: int}>
 */
function sf_significant(array $tokens): array
{
    return array_values(array_filter(
        $tokens,
        static fn(array $token): bool => !in_array($token['id'], SF_SKIP_TOKEN_IDS, true)
    ));
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 * @return list<array{id: int|null, text: string, line: int}>
 */
function sf_file_significant(string $path): array
{
    $code = file_get_contents($path);
    if ($code === false) {
        throw new RuntimeException('Tidak dapat membaca ' . sf_relative_path($path));
    }

    return sf_significant(sf_tokens($code));
}

function sf_next_index(array $tokens, int $index): ?int
{
    return array_key_exists($index + 1, $tokens) ? $index + 1 : null;
}

function sf_previous_index(array $tokens, int $index): ?int
{
    return $index > 0 ? $index - 1 : null;
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 */
function sf_matching_paren(array $tokens, int $openIndex): ?int
{
    $depth = 0;
    $count = count($tokens);

    for ($i = $openIndex; $i < $count; $i++) {
        if ($tokens[$i]['text'] === '(') {
            $depth++;
        } elseif ($tokens[$i]['text'] === ')') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return null;
}

/**
 * Mencari pemanggilan fungsi atau metode berdasarkan nama.
 *
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 * @param list<string> $names
 * @return list<array{name: string, line: int, open: int, close: int, args: list<array{id: int|null, text: string, line: int}>}>
 */
function sf_calls(array $tokens, array $names): array
{
    $wanted = array_map('strtolower', $names);
    $calls = [];

    foreach ($tokens as $index => $token) {
        if ($token['id'] !== T_STRING) {
            continue;
        }

        $name = strtolower($token['text']);
        if (!in_array($name, $wanted, true)) {
            continue;
        }

        $next = sf_next_index($tokens, $index);
        if ($next === null || $tokens[$next]['text'] !== '(') {
            continue;
        }

        $previous = sf_previous_index($tokens, $index);
        if ($previous !== null && in_array($tokens[$previous]['id'], [T_FUNCTION, T_FN], true)) {
            continue;
        }

        // Deklarasi "function &nama(" memiliki token & di antara function dan nama.
        if ($previous !== null && $tokens[$previous]['text'] === '&') {
            $before = sf_previous_index($tokens, $previous);
            if ($before !== null && in_array($tokens[$before]['id'], [T_FUNCTION, T_FN], true)) {
                continue;
            }
        }

        $close = sf_matching_paren($tokens, $next);
        if ($close === null) {
            continue;
        }

        $calls[] = [
            'name' => $name,
            'line' => $token['line'],
            'open' => $next,
            'close' => $close,
            'args' => array_slice($tokens, $next + 1, $close - $next - 1),
        ];
    }

    return $calls;
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 */
function sf_tokens_text(array $tokens): string
{
    return implode('', array_column($tokens, 'text'));
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 */
function sf_has_token(array $tokens, int $id): bool
{
    foreach ($tokens as $token) {
        if ($token['id'] === $id) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 */
function sf_has_interpolated_string(array $tokens): bool
{
    foreach ($tokens as $token) {
        if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $quote = $token['text'][0] ?? '';
        if ($quote === '"' && (str_contains($token['text'], '$') || str_contains($token['text'], '{'))) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 * @return list<array{id: int|null, text: string, line: int}>
 */
function sf_include_expression(array $tokens, int $includeIndex): array
{
    $expression = [];
    $depth = 0;

    for ($i = $includeIndex + 1; $i < count($tokens); $i++) {
        $text = $tokens[$i]['text'];

        if ($text === '(') {
            $depth++;
        } elseif ($text === ')') {
            $depth = max(0, $depth - 1);
        } elseif ($text === ';' && $depth === 0) {
            break;
        }

        $expression[] = $tokens[$i];
    }

    return $expression;
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 * @return list<array{line: int, expression: list<array{id: int|null, text: string, line: int}>}>
 */
function sf_includes(array $tokens): array
{
    $includeIds = [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE];
    $includes = [];

    foreach ($tokens as $index => $token) {
        if (!in_array($token['id'], $includeIds, true)) {
            continue;
        }

        $includes[] = [
            'line' => $token['line'],
            'expression' => sf_include_expression($tokens, $index),
        ];
    }

    return $includes;
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 * @return list<int>
 */
function sf_session_key_lines(array $tokens, string $key): array
{
    $lines = [];

    foreach ($tokens as $index => $token) {
        if ($token['id'] !== T_VARIABLE || $token['text'] !== '$_SESSION') {
            continue;
        }

        $open = sf_next_index($tokens, $index);
        $keyIndex = $open === null ? null : sf_next_index($tokens, $open);
        $close = $keyIndex === null ? null : sf_next_index($tokens, $keyIndex);

        if ($open === null || $keyIndex === null || $close === null) {
            continue;
        }

        if ($tokens[$open]['text'] !== '[' || $tokens[$close]['text'] !== ']') {
            continue;
        }

        $rawKey = $tokens[$keyIndex]['text'];
        if (trim($rawKey, "'\"") === $key) {
            $lines[] = $token['line'];
        }
    }

    return $lines;
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 * @return list<array{name: string, line: int, args: list<array{id: int|null, text: string, line: int}>}>
 */
function sf_sql_calls(array $tokens): array
{
    $calls = sf_calls($tokens, ['prepare', 'query', 'mysqli_query', 'mysqli_prepare', 'exec']);
    $result = [];

    foreach ($calls as $call) {
        $result[] = [
            'name' => $call['name'],
            'line' => $call['line'],
            'args' => $call['args'],
        ];
    }

    return $result;
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $args
 * @return array{status: string, reason: string, sql: string}
 */
function sf_sql_call_analysis(array $args): array
{
    $text = sf_tokens_text($args);

    if (sf_has_token($args, T_VARIABLE) || sf_has_interpolated_string($args)) {
        return [
            'status' => 'FAIL',
            'reason' => 'SQL mengandung variabel atau interpolasi dinamis',
            'sql' => $text,
        ];
    }

    $strings = [];
    foreach ($args as $token) {
        if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
            $strings[] = trim($token['text'], "'\"");
        }
    }

    if ($strings === []) {
        return [
            'status' => 'INCONCLUSIVE',
            'reason' => 'Argumen SQL bukan literal string; tidak dapat ditelusuri',
            'sql' => $text,
        ];
    }

    $sql = implode('', $strings);
    if (str_contains($sql, '?')) {
        return [
            'status' => 'PASS',
            'reason' => 'Literal SQL dengan placeholder',
            'sql' => $sql,
        ];
    }

    return [
        'status' => 'PASS',
        'reason' => 'Literal SQL konstan tanpa input dinamis (pengecualian eksplisit)',
        'sql' => $sql,
    ];
}

/**
 * Menghapus komentar SQL dan literal string sebelum target tabel diperiksa.
 * Backtick dibiarkan agar identifier tabel tetap dapat dibaca.
 */
function sf_sql_strip_comments_and_strings(string $sql): string
{
    $result = '';
    $length = strlen($sql);
    $state = 'normal';

    for ($index = 0; $index < $length; $index++) {
        $char = $sql[$index];
        $next = $index + 1 < $length ? $sql[$index + 1] : '';

        if ($state === 'normal') {
            if ($char === '-' && $next === '-') {
                $state = 'line_comment';
                $result .= ' ';
                $index++;
                continue;
            }

            if ($char === '#') {
                $state = 'line_comment';
                $result .= ' ';
                continue;
            }

            if ($char === '/' && $next === '*') {
                $state = 'block_comment';
                $result .= ' ';
                $index++;
                continue;
            }

            if ($char === "'") {
                $state = 'single_quote';
                $result .= ' ';
                continue;
            }

            if ($char === '"') {
                $state = 'double_quote';
                $result .= ' ';
                continue;
            }

            if ($char === '`') {
                $state = 'backtick';
            }

            $result .= $char;
            continue;
        }

        if ($state === 'line_comment') {
            if ($char === "\n" || $char === "\r") {
                $state = 'normal';
                $result .= $char;
            }
            continue;
        }

        if ($state === 'block_comment') {
            if ($char === '*' && $next === '/') {
                $state = 'normal';
                $result .= ' ';
                $index++;
            }
            continue;
        }

        if ($state === 'single_quote' || $state === 'double_quote') {
            $quote = $state === 'single_quote' ? "'" : '"';

            if ($char === '\\') {
                $index++;
                continue;
            }

            if ($char === $quote) {
                if ($next === $quote) {
                    $index++;
                    continue;
                }

                $state = 'normal';
            }
            continue;
        }

        if ($state === 'backtick') {
            $result .= $char;
            if ($char === '`') {
                if ($next === '`') {
                    $result .= $next;
                    $index++;
                    continue;
                }

                $state = 'normal';
            }
        }
    }

    return $result;
}

/**
 * Menentukan apakah SQL adalah operasi tulis dan tabel targetnya.
 *
 * Status READ berarti bukan operasi tulis; UNKNOWN berarti operasi tulis
 * terdeteksi tetapi target tidak dapat dipastikan.
 *
 * @return array{status: string, operation: string|null, target: string|null, sql: string}
 */
function sf_sql_write_target_analysis(string $sql): array
{
    $clean = sf_sql_strip_comments_and_strings($sql);
    $trimmed = ltrim($clean);
    $operation = null;
    $target = null;

    if (preg_match(
        '/^(INSERT|REPLACE)\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*INTO\s+([`A-Za-z0-9_$.]+)/i',
        $trimmed,
        $match
    ) === 1) {
        $operation = strtoupper($match[1]);
        $target = $match[2];
    } elseif (preg_match(
        '/^(INSERT|REPLACE)\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*([`A-Za-z0-9_$.]+)/i',
        $trimmed,
        $match
    ) === 1) {
        $operation = strtoupper($match[1]);
        $target = strtoupper($match[2]) === 'INTO' ? null : $match[2];
    } elseif (preg_match(
        '/^UPDATE\s+(?:(?:LOW_PRIORITY|IGNORE)\s+)*([`A-Za-z0-9_$.]+)/i',
        $trimmed,
        $match
    ) === 1) {
        $operation = 'UPDATE';
        $target = $match[1];
    } elseif (preg_match(
        '/^DELETE\s+(?:(?:LOW_PRIORITY|QUICK|IGNORE)\s+)*FROM\s+([`A-Za-z0-9_$.]+)/i',
        $trimmed,
        $match
    ) === 1) {
        $operation = 'DELETE';
        $target = $match[1];
    } elseif (preg_match(
        '/^TRUNCATE\s+(?:TABLE\s+)?([`A-Za-z0-9_$.]+)/i',
        $trimmed,
        $match
    ) === 1) {
        $operation = 'TRUNCATE';
        $target = $match[1];
    } elseif (preg_match(
        '/^(ALTER|DROP|CREATE)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?([`A-Za-z0-9_$.]+)/i',
        $trimmed,
        $match
    ) === 1) {
        $operation = strtoupper($match[1]);
        $target = $match[2];
    } elseif (preg_match('/^(INSERT|REPLACE|UPDATE|DELETE|TRUNCATE|ALTER|DROP|CREATE)\b/i', $trimmed, $match) === 1) {
        $operation = strtoupper($match[1]);
    } elseif (preg_match('/^WITH\b/i', $trimmed) === 1
        && preg_match('/\b(INSERT|REPLACE|UPDATE|DELETE)\b/i', $trimmed) === 1) {
        $operation = 'WITH_WRITE';
    }

    if ($operation === null) {
        return [
            'status' => 'READ',
            'operation' => null,
            'target' => null,
            'sql' => $clean,
        ];
    }

    if ($target === null) {
        return [
            'status' => 'UNKNOWN',
            'operation' => $operation,
            'target' => null,
            'sql' => $clean,
        ];
    }

    $normalized = strtolower(trim($target, "` \t\n\r"));
    $parts = explode('.', $normalized);
    $table = trim((string) end($parts), '`');

    $isRelationalPanen = $table === 'panen'
        && (str_contains(strtolower($clean), 'siklus_id') || str_contains(strtolower($clean), 'jumlah_kg'));

    return [
        'status' => ($table === 'panen' && !$isRelationalPanen) ? 'WRITE_LEGACY' : 'WRITE_OTHER',
        'operation' => $operation,
        'target' => $table,
        'sql' => $clean,
    ];
}

/**
 * Mendeteksi pemanggilan yang eksplisit terlihat seperti role guard.
 *
 * Fungsi ini tidak berasumsi bahwa role guard sudah ada. Pemeriksaan urutan
 * tetap harus membuktikan posisi pemanggilan di handler.
 *
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 * @return list<int>
 */
function sf_role_guard_lines(array $tokens): array
{
    $names = [
        'require_role',
        'require_roles',
        'require_admin',
        'require_operator',
        'authorize_role',
        'authorize_roles',
        'assert_role',
        'has_role',
        'check_role',
        'enforce_role',
        'role_guard',
        'role_allows',
    ];

    $lines = [];
    foreach (sf_calls($tokens, $names) as $call) {
        $lines[] = $call['line'];
    }

    sort($lines);

    return array_values(array_unique($lines));
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 * @return list<array{name: string, line: int}>
 */
function sf_dangerous_calls(array $tokens): array
{
    $dangerous = [
        'extract',
        'unserialize',
        'shell_exec',
        'system',
        'exec',
        'passthru',
        'proc_open',
        'popen',
        'pcntl_exec',
    ];

    $findings = [];

    foreach ($tokens as $index => $token) {
        if ($token['id'] === T_EVAL) {
            $findings[] = ['name' => 'eval', 'line' => $token['line']];
            continue;
        }

        if ($token['id'] !== T_STRING) {
            continue;
        }

        $name = strtolower($token['text']);
        if (!in_array($name, $dangerous, true)) {
            continue;
        }

        $next = sf_next_index($tokens, $index);
        if ($next === null || $tokens[$next]['text'] !== '(') {
            continue;
        }

        $previous = sf_previous_index($tokens, $index);
        if ($previous !== null && in_array($tokens[$previous]['id'], [T_FUNCTION, T_FN], true)) {
            continue;
        }

        $findings[] = ['name' => $name, 'line' => $token['line']];
    }

    return $findings;
}
