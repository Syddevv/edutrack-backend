<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/http.php';

function start_auth_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('edutrack_session');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'path' => '/',
    ]);

    session_start();
}

function current_user(): ?array
{
    start_auth_session();

    if (empty($_SESSION['user'])) {
        return null;
    }

    return $_SESSION['user'];
}

function require_auth(): array
{
    $user = current_user();

    if ($user === null) {
        json_response(['message' => 'Unauthenticated.'], 401);
    }

    return $user;
}

function attempt_login(string $email, string $password): ?array
{
    $statement = database()->prepare(
        'SELECT id, name, email, password, role, status, created_at
         FROM users
         WHERE email = :email
         LIMIT 1'
    );
    $statement->execute(['email' => $email]);

    $user = $statement->fetch();

    if (!$user) {
        return null;
    }

    $storedPassword = (string) ($user['password'] ?? '');

    $passwordMatches = password_verify($password, $storedPassword) || hash_equals($storedPassword, $password);

    if (!$passwordMatches) {
        return null;
    }

    if (($user['status'] ?? 'active') !== 'active') {
        json_response(['message' => 'This account is not active.'], 403);
    }

    unset($user['password']);

    return [
        'id' => (int) $user['id'],
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
        'status' => (string) $user['status'],
        'created_at' => $user['created_at'],
    ];
}
