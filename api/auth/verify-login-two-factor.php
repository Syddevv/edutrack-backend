<?php

declare(strict_types=1);

require_once __DIR__ . '/../../utils/http.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/two_factor.php';

handle_cors();
require_method('POST');

$payload = json_input();
$code = (string) ($payload['code'] ?? '');

if ($code === '') {
    json_response(['message' => 'Authentication code is required.'], 422);
}

$pendingUser = pending_two_factor_user();

if ($pendingUser === null) {
    json_response([
        'message' => 'Your login verification session has expired. Please sign in again.',
    ], 401);
}

$userId = (int) ($pendingUser['id'] ?? 0);
$email = (string) ($pendingUser['email'] ?? '');
$record = find_two_factor_record(database(), $userId);

if ($record === null || !verify_two_factor_code((string) $record['secret'], $email, $code)) {
    json_response(['message' => 'The authentication code is invalid or expired.'], 422);
}

session_regenerate_id(true);
complete_login($pendingUser);

json_response([
    'message' => 'Login successful.',
    'user' => $pendingUser,
]);
