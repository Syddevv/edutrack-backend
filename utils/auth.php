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
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params($cookieParams);

    session_start();
}

function auth_session_cookie_params(int $lifetime = 0): array
{
    $isHttps = is_https_request();

    return [
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => $isHttps ? 'None' : 'Lax',
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

    $sessionUserId = (int) ($_SESSION['user']['id'] ?? 0);

    if ($sessionUserId <= 0) {
        unset($_SESSION['user']);
        return null;
    }

    $user = find_user_by_id($sessionUserId);

    if ($user === null || ($user['status'] ?? 'active') !== 'active') {
        unset($_SESSION['user']);
        return null;
    }

    $_SESSION['user'] = $user;

    return $user;
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

    if ($storedPassword === '' || !password_verify($password, $storedPassword)) {
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

function find_user_by_id(int $userId): ?array
{
    $statement = database()->prepare(
        'SELECT id, name, email, role, status, created_at
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $userId]);

    $user = $statement->fetch();

    if (!$user) {
        return null;
    }

    return [
        'id' => (int) $user['id'],
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
        'status' => (string) $user['status'],
        'created_at' => $user['created_at'],
    ];
}
