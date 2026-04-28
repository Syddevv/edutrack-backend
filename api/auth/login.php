<?php

declare(strict_types=1);

require_once __DIR__ . '/../../utils/http.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/two_factor.php';

handle_cors();
require_method('POST');

$payload = json_input();
$email = strtolower(trim((string) ($payload['email'] ?? '')));
$password = (string) ($payload['password'] ?? '');
$rememberMe = (bool) ($payload['rememberMe'] ?? false);

if ($email === '' || $password === '') {
    json_response(['message' => 'Email and password are required.'], 422);
}

start_auth_session($rememberMe);

$user = attempt_login($email, $password);

if ($user === null) {
    json_response(['message' => 'Invalid email or password.'], 401);
}

session_regenerate_id(true);

if (
    strtolower((string) ($user['role'] ?? '')) === 'admin'
    && two_factor_is_enabled(database(), (int) $user['id'])
) {
    store_pending_two_factor_user($user);

    json_response([
        'message' => 'Two-factor verification required.',
        'requiresTwoFactor' => true,
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
    ]);
}

complete_login($user);

json_response([
    'message' => 'Login successful.',
    'user' => $user,
]);
