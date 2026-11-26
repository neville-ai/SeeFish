<?php
declare(strict_types=1);

function csrf_test_verify_subprocess(array $posted, string $stored): string
{
    $csrfFile = var_export(dirname(__DIR__, 2) . '/config/csrf.php', true);
    $session = var_export(['csrf_token' => $stored], true);
    $post = var_export($posted, true);

    $code = "
        require {$csrfFile};
        \$_SESSION = {$session};
        \$_POST = {$post};

        register_shutdown_function(static function (): void {
            echo \"\\n__HTTP_STATUS__=\" . http_response_code();
        });

        csrf_verify();
        echo 'ACCEPTED';
    ";

    $process = proc_open(
        [PHP_BINARY, '-r', $code],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Gagal menjalankan proses pengujian CSRF.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $stdout . $stderr;
}

return [
    'csrf_generate menghasilkan token hex 64 karakter dan stabil' => function (): void {
        $_SESSION = [];

        $token = csrf_generate();

        test_assert_matches('/^[a-f0-9]{64}$/', $token);
        test_assert_same($token, csrf_generate());
        test_assert_same($token, $_SESSION['csrf_token'] ?? null);
    },

    'csrf_rotate menghasilkan token hex baru' => function (): void {
        $oldToken = csrf_generate();

        csrf_rotate();

        $newToken = $_SESSION['csrf_token'] ?? null;
        test_assert_true(is_string($newToken));
        test_assert_matches('/^[a-f0-9]{64}$/', $newToken);
        test_assert_not_same($oldToken, $newToken);
    },

    'csrf_input menghasilkan hidden input dengan token yang di-escape' => function (): void {
        $_SESSION['csrf_token'] = 'token"<&';

        ob_start();
        csrf_input();
        $output = ob_get_clean();

        test_assert_same(
            '<input type="hidden" name="csrf_token" value="token&quot;&lt;&amp;">',
            $output
        );
    },

    'csrf_verify menerima token valid' => function (): void {
        $token = str_repeat('a', 64);
        $output = csrf_test_verify_subprocess(
            ['csrf_token' => $token],
            $token
        );

        test_assert_true(str_contains($output, 'ACCEPTED'));
    },

    'csrf_verify menolak token salah dengan status 403' => function (): void {
        $output = csrf_test_verify_subprocess(
            ['csrf_token' => str_repeat('b', 64)],
            str_repeat('a', 64)
        );

        test_assert_true(!str_contains($output, 'ACCEPTED'));
        test_assert_true(str_contains($output, '__HTTP_STATUS__=403'));
    },

    'csrf_verify menolak token kosong atau tidak ada' => function (): void {
        $output = csrf_test_verify_subprocess(
            [],
            str_repeat('a', 64)
        );

        test_assert_true(str_contains($output, '__HTTP_STATUS__=403'));
    },

    'csrf_verify menolak token berbentuk array' => function (): void {
        $output = csrf_test_verify_subprocess(
            ['csrf_token' => ['invalid']],
            str_repeat('a', 64)
        );

        test_assert_true(str_contains($output, '__HTTP_STATUS__=403'));
    },

    'csrf_verify menolak token lama setelah rotasi session' => function (): void {
        $oldToken = str_repeat('a', 64);
        $newToken = str_repeat('b', 64);

        $output = csrf_test_verify_subprocess(
            ['csrf_token' => $oldToken],
            $newToken
        );

        test_assert_true(str_contains($output, '__HTTP_STATUS__=403'));
    },
];
