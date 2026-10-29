<?php
declare(strict_types=1);

/**
 * Assertion native untuk test SeeFish.
 *
 * Tidak memakai assert() bawaan PHP karena perilakunya bergantung pada
 * konfigurasi zend.assertions.
 */

class TestAssertionFailure extends RuntimeException
{
}

class TestSkipped extends RuntimeException
{
}

function test_skip(string $message): never
{
    throw new TestSkipped($message);
}

function test_describe(mixed $value): string
{
    $text = var_export($value, true);
    $text = str_replace(["\r", "\n"], ['\\r', '\\n'], $text);

    return strlen($text) > 160 ? substr($text, 0, 157) . '...' : $text;
}

function test_assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected === $actual) {
        return;
    }

    $prefix = $message === '' ? '' : $message . ': ';
    throw new TestAssertionFailure(
        $prefix . 'diharapkan ' . test_describe($expected) . ', diperoleh ' . test_describe($actual)
    );
}

function test_assert_not_same(mixed $unexpected, mixed $actual, string $message = ''): void
{
    if ($unexpected !== $actual) {
        return;
    }

    $prefix = $message === '' ? '' : $message . ': ';
    throw new TestAssertionFailure(
        $prefix . 'nilai tidak boleh sama dengan ' . test_describe($unexpected)
    );
}

function test_assert_true(bool $condition, string $message = ''): void
{
    if ($condition === true) {
        return;
    }

    throw new TestAssertionFailure($message === '' ? 'diharapkan true.' : $message);
}

function test_assert_false(bool $condition, string $message = ''): void
{
    if ($condition === false) {
        return;
    }

    throw new TestAssertionFailure($message === '' ? 'diharapkan false.' : $message);
}

function test_assert_null(mixed $value, string $message = ''): void
{
    if ($value === null) {
        return;
    }

    $prefix = $message === '' ? '' : $message . ': ';
    throw new TestAssertionFailure($prefix . 'diharapkan null, diperoleh ' . test_describe($value));
}

function test_assert_matches(string $pattern, string $value, string $message = ''): void
{
    if (preg_match($pattern, $value) === 1) {
        return;
    }

    $prefix = $message === '' ? '' : $message . ': ';
    throw new TestAssertionFailure(
        $prefix . 'nilai ' . test_describe($value) . ' tidak cocok dengan pola ' . $pattern
    );
}
