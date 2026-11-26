<?php
declare(strict_types=1);

// Session token generation follows core/csrf.php in Hospital-Management-System.
// Copyright (c) 2026 Ahmed Senan Al-Aini. MIT, see reference/UPSTREAM-LICENSE.txt.
function csrf_token(): string
{
    return $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function check_csrf(): void
{
    if (!hash_equals(csrf_token(), post('csrf_token'))) {
        http_response_code(403);
        exit('The form expired. Return to the application and open the form again.');
    }
}

function sign_in(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = ['account_id' => (int)$user['id'], 'csrf_token' => bin2hex(random_bytes(32))];
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function query(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function redirect(string $page, array $values = []): never
{
    header('Location: ?' . http_build_query(['page' => $page] + $values), true, 303);
    exit;
}

function flash(string $message, string $tone = 'notice'): void
{
    $_SESSION['flash'] = [$message, $tone];
}

function display_date(string $iso): string
{
    return (new DateTimeImmutable($iso))->format('d M Y');
}

function valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}
