<?php

declare(strict_types=1);

require_once __DIR__ . '/../../utils/http.php';
require_once __DIR__ . '/../../utils/auth.php';

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
    unset($_SESSION['user']);
    session_regenerate_id(true);
    json_response(['message' => 'Invalid email or password.'], 401);
}

session_regenerate_id(true);
$_SESSION['user'] = $user;

json_response([
    'message' => 'Login successful.',
    'user' => $user,
]);
