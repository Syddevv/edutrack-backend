<?php

declare(strict_types=1);

require_once __DIR__ . '/../../utils/http.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/password_reset.php';

handle_cors();
require_method('POST');

$payload = json_input();
$email = strtolower(trim((string) ($payload['email'] ?? '')));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['message' => 'Enter a valid email address.'], 422);
}

$pdo = database();
ensure_password_reset_table($pdo);

$user = find_password_reset_user($pdo, $email);

if ($user === null) {
    json_response(['message' => 'We could not find an account with that email address.'], 404);
}

if (strtolower((string) ($user['status'] ?? 'active')) !== 'active') {
    json_response(['message' => 'This account is not active.'], 403);
}

$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash = password_hash($code, PASSWORD_DEFAULT);
$expiresAt = (new DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');

store_password_reset_code($pdo, (int) $user['id'], $email, $codeHash, $expiresAt);

try {
    send_password_reset_code_email($email, $code);
} catch (Throwable $exception) {
    delete_password_reset_code($pdo, $email);
    json_response(['message' => 'Unable to send the verification code right now.'], 500);
}

json_response([
    'message' => 'We sent a 6-digit verification code to your email.',
]);
