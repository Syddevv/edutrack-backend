<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/http.php';

const AUTH_SESSION_LIFETIME = 60 * 60 * 24 * 30;

function start_auth_session(bool $rememberMe = false): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $lifetime = $rememberMe ? AUTH_SESSION_LIFETIME : 0;
    $cookieParams = auth_session_cookie_params($lifetime);

    session_name('edutrack_session');
    ini_set('session.gc_maxlifetime', (string) AUTH_SESSION_LIFETIME);
    session_set_cookie_params($cookieParams);

    session_start();
}

function auth_session_cookie_params(int $lifetime = 0): array
{
    return [
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => true,        // ALWAYS true (Render is HTTPS)
        'httponly' => true,
        'samesite' => 'None',    // REQUIRED for Vercel → Render
    ];
}

function is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (($_SERVER['SERVER_PORT'] ?? null) === '443') {
        return true;
    }

    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

    return $forwardedProto === 'https';
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
