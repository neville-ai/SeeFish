<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/estimation_spec.php';

/**
 * Test spesifikasi offline. Tidak memuat bootstrap aplikasi dan tidak
 * membuktikan bahwa perhitungan produksi sudah diimplementasikan.
 */
return [
    'spesifikasi offline: 1000 tebar - 50 kematian aktif - 100 panen ekor = 850' => function (): void {
        $result = fish_estimate_spec(
            1000,
            [['status' => 'active', 'count' => 50]],
            [['status' => 'active', 'kind' => 'count', 'count' => 100]]
        );

        test_assert_same(true, $result['ok']);
        test_assert_same(850, $result['estimate']);
        test_assert_same(1000, $result['initial']);
        test_assert_same(50, $result['mortality']);
        test_assert_same(100, $result['harvest_count']);
        test_assert_same(null, $result['reason']);
    },

    'spesifikasi offline: panen berdasarkan berat tidak mengurangi estimasi' => function (): void {
        $result = fish_estimate_spec(
            1000,
            [['status' => 'active', 'count' => 50]],
            [['status' => 'active', 'kind' => 'weight', 'weight_kg' => 30.5]]
        );

        test_assert_same(true, $result['ok']);
        test_assert_same(950, $result['estimate']);
        test_assert_same(0, $result['harvest_count']);
    },

    'spesifikasi offline: kematian yang dibatalkan tidak mengurangi estimasi' => function (): void {
        $result = fish_estimate_spec(
            1000,
            [['status' => 'cancelled', 'count' => 50, 'reason' => 'salah input']],
            [['status' => 'active', 'kind' => 'count', 'count' => 100]]
        );

        test_assert_same(true, $result['ok']);
        test_assert_same(900, $result['estimate']);
        test_assert_same(0, $result['mortality']);
        test_assert_same(100, $result['harvest_count']);
    },

    'spesifikasi offline: panen ekor yang dibatalkan tidak mengurangi estimasi' => function (): void {
        $result = fish_estimate_spec(
            1000,
            [['status' => 'active', 'count' => 50]],
            [
                ['status' => 'cancelled', 'kind' => 'count', 'count' => 100, 'reason' => 'salah input'],
                ['status' => 'cancelled', 'kind' => 'weight', 'weight_kg' => 10],
            ]
        );

        test_assert_same(true, $result['ok']);
        test_assert_same(950, $result['estimate']);
        test_assert_same(50, $result['mortality']);
        test_assert_same(0, $result['harvest_count']);
    },

    'spesifikasi offline: beberapa kematian dan panen aktif dijumlahkan' => function (): void {
        $result = fish_estimate_spec(
            1000,
            [
                ['status' => 'active', 'count' => 20],
                ['status' => 'cancelled', 'count' => 999],
                ['status' => 'active', 'count' => 30],
            ],
            [
                ['status' => 'active', 'kind' => 'count', 'count' => 100],
                ['status' => 'active', 'kind' => 'weight', 'weight_kg' => 12],
                ['status' => 'active', 'kind' => 'count', 'count' => 50],
            ]
        );

        test_assert_same(true, $result['ok']);
        test_assert_same(800, $result['estimate']);
        test_assert_same(50, $result['mortality']);
        test_assert_same(150, $result['harvest_count']);
    },

    'spesifikasi offline: data kosong menghasilkan estimasi sama dengan stok awal' => function (): void {
        $result = fish_estimate_spec(1000, [], []);

        test_assert_same(true, $result['ok']);
        test_assert_same(1000, $result['estimate']);
        test_assert_same(0, $result['mortality']);
        test_assert_same(0, $result['harvest_count']);
        test_assert_same(null, $result['reason']);

        $zero = fish_estimate_spec(0, [], []);
        test_assert_same(true, $zero['ok']);
        test_assert_same(0, $zero['estimate']);
    },

    'spesifikasi offline: estimasi di bawah nol dilaporkan eksplisit' => function (): void {
        $result = fish_estimate_spec(
            10,
            [['status' => 'active', 'count' => 15]],
            []
        );

        test_assert_same(false, $result['ok']);
        test_assert_same(-5, $result['estimate']);
        test_assert_same('negative_estimate', $result['reason']);
    },

    'spesifikasi offline: nilai negatif dan tipe data tidak valid ditolak' => function (): void {
        $expectInvalid = static function (callable $call): void {
            try {
                $call();
            } catch (InvalidArgumentException) {
                return;
            }

            throw new TestAssertionFailure('Input tidak valid seharusnya melempar InvalidArgumentException.');
        };

        $expectInvalid(static fn () => fish_estimate_spec(-1, [], []));
        $expectInvalid(static fn () => fish_estimate_spec(
            1000,
            [['status' => 'active', 'count' => -1]],
            []
        ));
        $expectInvalid(static fn () => fish_estimate_spec(
            1000,
            [['status' => 'active', 'count' => '50']],
            []
        ));
        $expectInvalid(static fn () => fish_estimate_spec(
            1000,
            [],
            [['status' => 'active', 'kind' => 'count', 'count' => 1.5]]
        ));
        $expectInvalid(static fn () => fish_estimate_spec(
            1000,
            [['status' => 'deleted', 'count' => 10]],
            []
        ));
        $expectInvalid(static fn () => fish_estimate_spec(
            1000,
            [],
            [['status' => 'active', 'kind' => 'mixed', 'count' => 10]]
        ));
    },
];
