#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$testFiles = [
    __DIR__ . '/unit/validation_test.php',
    __DIR__ . '/unit/helpers_test.php',
    __DIR__ . '/unit/csrf_test.php',
];

$total = 0;
$passed = 0;
$failed = 0;
$skipped = 0;

echo "SeeFish Unit Tests (tanpa database)\n";
echo 'PHP ' . PHP_VERSION . "\n\n";

foreach ($testFiles as $file) {
    echo '== ' . basename($file) . " ==\n";

    try {
        $tests = require $file;
    } catch (Throwable $error) {
        $total++;
        $failed++;
        echo '[FAIL] ' . basename($file) . ': ' . $error->getMessage() . "\n\n";
        continue;
    }

    if (!is_array($tests) || $tests === []) {
        $total++;
        $failed++;
        echo '[FAIL] ' . basename($file) . ": tidak mengembalikan daftar test.\n\n";
        continue;
    }

    foreach ($tests as $name => $test) {
        $total++;
        test_reset_state();

        try {
            if (!is_callable($test)) {
                throw new TestAssertionFailure('Test bukan callable.');
            }

            $test();
            $passed++;
            echo '[PASS] ' . $name . "\n";
        } catch (TestSkipped $skip) {
            $skipped++;
            echo '[SKIP] ' . $name . ' - ' . $skip->getMessage() . "\n";
        } catch (Throwable $error) {
            $failed++;
            echo '[FAIL] ' . $name . ' - ' . $error->getMessage() . "\n";
        } finally {
            test_reset_state();
        }
    }

    echo "\n";
}

echo "Total: {$total}, Lulus: {$passed}, Gagal: {$failed}";
if ($skipped > 0) {
    echo ", Dilewati: {$skipped}";
}
echo "\n";

exit($failed === 0 ? 0 : 1);
