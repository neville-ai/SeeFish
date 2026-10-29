<?php
declare(strict_types=1);

return [
    'valid_text menerima panjang 1 sampai 255' => function (): void {
        test_assert_true(valid_text('a'));
        test_assert_true(valid_text(str_repeat('a', 255)));
    },

    'valid_text menolak kosong dan 256 karakter' => function (): void {
        test_assert_false(valid_text(''));
        test_assert_false(valid_text(str_repeat('a', 256)));
    },

    'valid_text menghormati batas kustom' => function (): void {
        test_assert_true(valid_text('abc', 2, 3));
        test_assert_false(valid_text('a', 2, 3));
        test_assert_false(valid_text('abcd', 2, 3));
    },

    'valid_text menghitung karakter multibyte jika mbstring tersedia' => function (): void {
        if (!function_exists('mb_strlen')) {
            test_skip('ekstensi mbstring tidak tersedia');
        }

        test_assert_true(valid_text(str_repeat('é', 255), 1, 255));
    },

    'valid_username menerima huruf, angka, dan underscore' => function (): void {
        test_assert_true(valid_username('abc'));
        test_assert_true(valid_username('operator_01'));
        test_assert_true(valid_username('ABC_123'));
        test_assert_true(valid_username(str_repeat('a', 50)));
    },

    'valid_username menolak bentuk dan panjang yang tidak valid' => function (): void {
        test_assert_false(valid_username(''));
        test_assert_false(valid_username('ab'));
        test_assert_false(valid_username(str_repeat('a', 51)));
        test_assert_false(valid_username('operator-01'));
        test_assert_false(valid_username('operator 01'));
        test_assert_false(valid_username('operator.01'));
        test_assert_false(valid_username('pengguna_é'));
    },

    'valid_password memakai batas default 12 sampai 128' => function (): void {
        test_env_set('PASSWORD_MIN_LENGTH', null);

        test_assert_false(valid_password(str_repeat('a', 11)));
        test_assert_true(valid_password(str_repeat('a', 12)));
        test_assert_true(valid_password(str_repeat('a', 128)));
        test_assert_false(valid_password(str_repeat('a', 129)));
    },

    'valid_password menghormati minimum yang dikonfigurasi' => function (): void {
        test_env_set('PASSWORD_MIN_LENGTH', '8');

        test_assert_false(valid_password(str_repeat('a', 7)));
        test_assert_true(valid_password(str_repeat('a', 8)));
    },

    'valid_password membatasi minimum di bawah 8 menjadi 8' => function (): void {
        test_env_set('PASSWORD_MIN_LENGTH', '1');

        test_assert_false(valid_password(str_repeat('a', 7)));
        test_assert_true(valid_password(str_repeat('a', 8)));
    },

    'valid_password membatasi minimum di atas 128 menjadi 128' => function (): void {
        test_env_set('PASSWORD_MIN_LENGTH', '200');

        test_assert_false(valid_password(str_repeat('a', 127)));
        test_assert_true(valid_password(str_repeat('a', 128)));
    },

    'valid_date_ymd menerima tanggal valid termasuk tahun kabisat' => function (): void {
        test_assert_true(valid_date_ymd('2024-02-29'));
        test_assert_true(valid_date_ymd('2000-02-29'));
        test_assert_true(valid_date_ymd('2026-10-22'));
    },

    'valid_date_ymd menolak format dan tanggal yang tidak valid' => function (): void {
        test_assert_false(valid_date_ymd(''));
        test_assert_false(valid_date_ymd('2023-02-29'));
        test_assert_false(valid_date_ymd('1900-02-29'));
        test_assert_false(valid_date_ymd('2026-13-01'));
        test_assert_false(valid_date_ymd('2026-00-10'));
        test_assert_false(valid_date_ymd('2026-01-32'));
        test_assert_false(valid_date_ymd('26-01-01'));
        test_assert_false(valid_date_ymd('2026-1-1'));
        test_assert_false(valid_date_ymd('2026-01-01 '));
    },

    'valid_positive_int hanya menerima bilangan bulat positif' => function (): void {
        test_assert_true(valid_positive_int(1));
        test_assert_true(valid_positive_int(PHP_INT_MAX));
        test_assert_false(valid_positive_int(0));
        test_assert_false(valid_positive_int(-1));
        test_assert_false(valid_positive_int(null));
    },

    'valid_time_hi menerima jam dan menit yang valid' => function (): void {
        test_assert_true(valid_time_hi('00:00'));
        test_assert_true(valid_time_hi('08:00'));
        test_assert_true(valid_time_hi('16:30'));
        test_assert_true(valid_time_hi('23:59'));
        test_assert_true(valid_time_hi('08:00:00'));
        test_assert_true(valid_time_hi('23:59:59'));
    },

    'valid_time_hi menolak jam dan menit yang tidak valid' => function (): void {
        test_assert_false(valid_time_hi(''));
        test_assert_false(valid_time_hi('24:00'));
        test_assert_false(valid_time_hi('25:00'));
        test_assert_false(valid_time_hi('12:60'));
        test_assert_false(valid_time_hi('08:61'));
        test_assert_false(valid_time_hi('8:00'));
        test_assert_false(valid_time_hi('08:0'));
        test_assert_false(valid_time_hi('abc'));
        test_assert_false(valid_time_hi(' 08:00 '));
    },
];
