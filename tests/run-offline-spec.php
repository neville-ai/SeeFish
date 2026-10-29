#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Runner test spesifikasi offline.
 *
 * Runner ini sengaja TIDAK memuat tests/bootstrap.php, config/bootstrap.php,
 * aplikasi, session, atau koneksi database. Hanya assertion test dan helper
 * spesifikasi murni di folder tests yang dimuat.
 */

require __DIR__ . '/lib/assertions.php';

$testFiles = [
    __DIR__ . '/unit/estimation_test.php',
    __DIR__ . '/unit/csv_test.php',
    __DIR__ . '/unit/static_sql_test.php',
];

$counts = [
    'PASS' => 0,
    'FAIL' => 0,
    'PENDING' => 0,
    'INCONCLUSIVE' => 0,
];

echo "SeeFish Offline Specification Tests (tanpa bootstrap aplikasi/database)\n";
echo 'PHP ' . PHP_VERSION . "\n\n";

foreach ($testFiles as $file) {
    echo '== ' . basename($file) . " ==\n";

    try {
        $tests = require $file;
    } catch (Throwable $error) {
        $counts['FAIL']++;
        echo '[FAIL] ' . basename($file) . ': ' . $error->getMessage() . "\n\n";
        continue;
    }

    if (!is_array($tests) || $tests === []) {
        $counts['FAIL']++;
        echo '[FAIL] ' . basename($file) . ": tidak mengembalikan daftar test.\n\n";
        continue;
    }

    foreach ($tests as $name => $test) {
        try {
            if (!is_callable($test)) {
                throw new TestAssertionFailure('Test bukan callable.');
            }

            $test();
            $counts['PASS']++;
            echo '[PASS] ' . $name . "\n";
        } catch (TestSkipped $error) {
            $counts['PENDING']++;
            echo '[PENDING] ' . $name . ': ' . $error->getMessage() . "\n";
        } catch (Throwable $error) {
            $counts['FAIL']++;
            echo '[FAIL] ' . $name . ': ' . $error->getMessage() . "\n";
        }
    }

    echo "\n";
}

$total = array_sum($counts);

echo "Total: {$total} | PASS: {$counts['PASS']} | FAIL: {$counts['FAIL']} | "
    . "PENDING: {$counts['PENDING']} | INCONCLUSIVE: {$counts['INCONCLUSIVE']}\n";

if ($counts['FAIL'] > 0) {
    exit(1);
}

if ($counts['PENDING'] > 0 || $counts['INCONCLUSIVE'] > 0) {
    exit(2);
}

exit(0);
