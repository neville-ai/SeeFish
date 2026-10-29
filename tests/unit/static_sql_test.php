<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/static_analysis.php';

/**
 * Test unit untuk scanner SQL statis. Tidak ada koneksi database; input hanya
 * string SQL sintetis.
 */
return [
    'scanner SQL: tulis ke panen terdeteksi' => function (): void {
        $cases = [
            'INSERT INTO panen (nama_petani) VALUES (?)' => 'INSERT',
            'UPDATE `panen` SET jumlah = ? WHERE id = ?' => 'UPDATE',
            'DELETE FROM seefish.panen WHERE id = ?' => 'DELETE',
            'TRUNCATE TABLE panen' => 'TRUNCATE',
            'ALTER TABLE panen ADD COLUMN catatan TEXT' => 'ALTER',
            'DROP TABLE IF EXISTS panen' => 'DROP',
        ];

        foreach ($cases as $sql => $operation) {
            $result = sf_sql_write_target_analysis($sql);
            test_assert_same('WRITE_LEGACY', $result['status'], $sql);
            test_assert_same($operation, $result['operation'], $sql);
        }
    },

    'scanner SQL: SELECT panen bukan operasi tulis' => function (): void {
        $result = sf_sql_write_target_analysis('SELECT * FROM panen WHERE user_id = ?');

        test_assert_same('READ', $result['status']);
        test_assert_same(null, $result['operation']);
    },

    'scanner SQL: komentar dan literal string tidak menimbulkan false positive' => function (): void {
        $cases = [
            "-- DELETE FROM panen\nSELECT 1",
            "# UPDATE panen SET jumlah = 0\nSELECT 1",
            "/* INSERT INTO panen VALUES (1) */ SELECT 1",
            "SELECT 'DELETE FROM panen' AS contoh",
            'SELECT "UPDATE panen SET jumlah = 0" AS contoh',
        ];

        foreach ($cases as $sql) {
            $result = sf_sql_write_target_analysis($sql);
            test_assert_same('READ', $result['status'], $sql);
            test_assert_same(null, $result['operation'], $sql);
        }
    },

    'scanner SQL: tulis ke tabel lain dan SQL yang tidak pasti dibedakan' => function (): void {
        $other = sf_sql_write_target_analysis('INSERT INTO activity_log (action) VALUES (?)');
        test_assert_same('WRITE_OTHER', $other['status']);
        test_assert_same('activity_log', $other['target']);

        $unknown = sf_sql_write_target_analysis('INSERT INTO');
        test_assert_same('UNKNOWN', $unknown['status']);

        $cte = sf_sql_write_target_analysis('WITH x AS (SELECT 1) UPDATE panen SET jumlah = 0');
        test_assert_same('UNKNOWN', $cte['status']);
    },
];
