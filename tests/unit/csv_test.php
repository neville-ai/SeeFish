<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/csv_spec.php';

/**
 * Test spesifikasi offline. Tidak memuat bootstrap aplikasi dan tidak
 * membuktikan bahwa fitur ekspor CSV produksi sudah tersedia.
 */
return [
    'spesifikasi CSV: formula injection =, +, -, @ dinetralkan' => function (): void {
        $cases = [
            '=1+1' => "'=1+1",
            '+1+1' => "'+1+1",
            '-1+1' => "'-1+1",
            '@SUM(A1:A2)' => "'@SUM(A1:A2)",
        ];

        foreach ($cases as $input => $expected) {
            test_assert_same($expected, csv_spec_cell($input));
        }
    },

    'spesifikasi CSV: formula setelah whitespace atau tab tetap dinetralkan' => function (): void {
        test_assert_same("' =1+1", csv_spec_cell(' =1+1'));
        test_assert_same("'\t=1+1", csv_spec_cell("\t=1+1"));
        test_assert_same("'  @SUM(A1)", csv_spec_cell('  @SUM(A1)'));
        test_assert_same("'\n-1+1", csv_spec_cell("\n-1+1"));
        test_assert_same("' \t+1+1", csv_spec_cell(" \t+1+1"));
    },

    'spesifikasi CSV: teks aman, kosong, dan null tidak diubah' => function (): void {
        test_assert_same('Ikan Nila', csv_spec_cell('Ikan Nila'));
        test_assert_same('', csv_spec_cell(''));
        test_assert_same('', csv_spec_cell(null));
        test_assert_same('0', csv_spec_cell(false));
        test_assert_same('1', csv_spec_cell(true));
    },

    'spesifikasi CSV: tanda kutip, delimiter, dan newline di-escape' => function (): void {
        $csv = csv_spec_render(
            ['Nama', 'Jumlah'],
            [
                ['Ikan "Nila"', 10],
                ['Ikan, Mas', "baris1\nbaris2"],
            ]
        );

        $expected = implode("\n", [
            'Nama,Jumlah',
            '"Ikan ""Nila""",10',
            '"Ikan, Mas","baris1',
            'baris2"',
        ]) . "\n";

        test_assert_same($expected, $csv);
    },

    'spesifikasi CSV: UTF-8 dan karakter Indonesia dipertahankan' => function (): void {
        $csv = csv_spec_render(
            ['Nama Ikan', 'Keterangan'],
            [['Nila Merah é', 'Udang 日本語']]
        );

        test_assert_same("\"Nama Ikan\",Keterangan\n\"Nila Merah é\",\"Udang 日本語\"\n", $csv);
        test_assert_true(str_contains($csv, 'é'));
        test_assert_true(str_contains($csv, '日本語'));
    },

    'spesifikasi CSV: dataset kosong menghasilkan header saja' => function (): void {
        test_assert_same("Nama,Jumlah\n", csv_spec_render(['Nama', 'Jumlah'], []));
    },

    'spesifikasi CSV: formula injection juga dinetralkan saat render' => function (): void {
        test_assert_same("x\n'=1+1\n", csv_spec_render(['x'], [['=1+1']]));
        test_assert_same("x\n\"' =1+1\"\n", csv_spec_render(['x'], [[' =1+1']]));
    },
];
