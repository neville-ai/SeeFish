<?php
declare(strict_types=1);

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
];
