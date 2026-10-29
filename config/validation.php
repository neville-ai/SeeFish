<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function valid_text(string $value, int $min = 1, int $max = 255): bool
{
    $length = function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);

    return $length >= $min && $length <= $max;
}

function valid_username(string $value): bool
{
    return valid_text($value, 3, 50)
        && preg_match('/^[a-zA-Z0-9_]+$/', $value) === 1;
}

function valid_password(string $value): bool
{
    return strlen($value) >= password_min_length() && strlen($value) <= 128;
}

function valid_date_ymd(string $value): bool
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
        return false;
    }

    return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
}

function valid_positive_int(?int $value): bool
{
    return $value !== null && $value > 0;
}

function valid_positive_number(?float $value): bool
{
    return $value !== null && $value > 0;
}

function valid_time_hi(string $value): bool
{
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $value) !== 1) {
        return false;
    }

    return true;
}

