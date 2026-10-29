<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';

function csrf_generate(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_rotate(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_input(): void
{
    echo '<input type="hidden" name="csrf_token" value="' . e(csrf_generate()) . '">';
}

function csrf_verify(?string $redirectPath = null): void
{
    $posted = $_POST['csrf_token'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';

    $valid = is_string($posted)
        && is_string($stored)
        && $posted !== ''
        && $stored !== ''
        && hash_equals($stored, $posted);

    if ($valid) {
        return;
    }

    if ($redirectPath !== null) {
        $separator = strpos($redirectPath, '?') === false ? '?' : '&';
        redirect($redirectPath . $separator . 'error=csrf');
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Permintaan ditolak karena token keamanan tidak valid.';
    exit;
}
