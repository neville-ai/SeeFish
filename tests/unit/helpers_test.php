<?php
declare(strict_types=1);

return [
    'e meng-escape HTML dan tanda kutip' => function (): void {
        test_assert_same('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'));
        test_assert_same('&lt;tag&gt;', e('<tag>'));
        test_assert_same('&amp;', e('&'));
        test_assert_same('&quot;', e('"'));
        test_assert_same('&#039;', e("'"));
        test_assert_same('', e(''));
        test_assert_same('123', e(123));
    },

    'e mempertahankan teks UTF-8 yang valid' => function (): void {
        test_assert_same('Ikan &amp; Udang', e('Ikan & Udang'));
        test_assert_same('é', e('é'));
    },

    'post_raw mengembalikan string apa adanya dan kosong untuk bukan string' => function (): void {
        $_POST = [
            'nama' => '  Ikan  ',
            'angka' => 5,
            'daftar' => ['x'],
        ];

        test_assert_same('  Ikan  ', post_raw('nama'));
        test_assert_same('', post_raw('angka'));
        test_assert_same('', post_raw('daftar'));
        test_assert_same('', post_raw('tidak_ada'));
    },

    'post_trimmed memangkas spasi dan menolak nilai bukan string' => function (): void {
        $_POST = [
            'nama' => " \tIkan\n",
            'angka' => 5,
        ];

        test_assert_same('Ikan', post_trimmed('nama'));
        test_assert_same('', post_trimmed('angka'));
        test_assert_same('', post_trimmed('tidak_ada'));
    },

    'post_int memvalidasi nilai integer dan mengembalikan null untuk nilai salah' => function (): void {
        $_POST = [
            'positif' => '5',
            'nol' => '0',
            'negatif' => '-5',
            'huruf' => 'abc',
            'desimal' => '1.5',
            'kosong' => '',
            'daftar' => ['1'],
        ];

        test_assert_same(5, post_int('positif'));
        test_assert_same(0, post_int('nol'));
        test_assert_same(-5, post_int('negatif'));
        test_assert_null(post_int('huruf'));
        test_assert_null(post_int('desimal'));
        test_assert_null(post_int('kosong'));
        test_assert_null(post_int('daftar'));
        test_assert_null(post_int('tidak_ada'));
    },

    'is_post hanya menerima metode POST persis' => function (): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        test_assert_true(is_post());

        $_SERVER['REQUEST_METHOD'] = 'post';
        test_assert_false(is_post());

        $_SERVER['REQUEST_METHOD'] = 'GET';
        test_assert_false(is_post());

        unset($_SERVER['REQUEST_METHOD']);
        test_assert_false(is_post());
    },

    'current_ip mempertahankan IP valid dan mengganti IP tidak valid dengan tanda minus' => function (): void {
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        test_assert_same('192.0.2.10', current_ip());

        $_SERVER['REMOTE_ADDR'] = '2001:db8::1';
        test_assert_same('2001:db8::1', current_ip());

        $_SERVER['REMOTE_ADDR'] = '999.1.1.1';
        test_assert_same('-', current_ip());

        unset($_SERVER['REMOTE_ADDR']);
        test_assert_same('-', current_ip());
    },

    'current_user_agent menghapus CR/LF dan membatasi panjang 255' => function (): void {
        unset($_SERVER['HTTP_USER_AGENT']);
        test_assert_same('-', current_user_agent());

        $_SERVER['HTTP_USER_AGENT'] = "Agent\r\nInjected";
        test_assert_same('Agent  Injected', current_user_agent());

        $_SERVER['HTTP_USER_AGENT'] = str_repeat('a', 300);
        test_assert_same(str_repeat('a', 255), current_user_agent());
    },

    'password_min_length memakai default 12 dan membatasi 8 sampai 128' => function (): void {
        test_env_set('PASSWORD_MIN_LENGTH', null);
        test_assert_same(12, password_min_length());

        test_env_set('PASSWORD_MIN_LENGTH', '20');
        test_assert_same(20, password_min_length());

        test_env_set('PASSWORD_MIN_LENGTH', '1');
        test_assert_same(8, password_min_length());

        test_env_set('PASSWORD_MIN_LENGTH', '200');
        test_assert_same(128, password_min_length());
    },

    'app_name memakai fallback SeeFish dan menghormati environment' => function (): void {
        test_env_set('APP_NAME', null);
        test_assert_same('SeeFish', app_name());

        test_env_set('APP_NAME', 'SeeFish Test');
        test_assert_same('SeeFish Test', app_name());

        test_env_set('APP_NAME', '');
        test_assert_same('SeeFish', app_name());
    },
];
