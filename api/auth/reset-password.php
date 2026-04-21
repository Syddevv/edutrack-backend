<?php

declare(strict_types=1);

require_once __DIR__ . '/../../utils/http.php';
require_once __DIR__ . '/../../utils/password_reset.php';

handle_cors();
require_method('POST');

$payload = json_input();
$email = strtolower(trim((string) ($payload['email'] ?? '')));
$code = preg_replace('/\D+/', '', (string) ($payload['code'] ?? ''));
$newPassword = (string) ($payload['newPassword'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['message' => 'Enter a valid email address.'], 422);
}

if ($code === '' || strlen($code) !== 6) {
    json_response(['message' => 'Enter the 6-digit verification code.'], 422);
}

if (strlen($newPassword) < 8) {
    json_response(['message' => 'Your new password must be at least 8 characters long.'], 422);
}

$pdo = database();
ensure_password_reset_table($pdo);

$user = find_password_reset_user($pdo, $email);
$resetRecord = find_password_reset_code($pdo, $email);

if ($user === null || $resetRecord === null) {
    json_response(['message' => 'This reset request is invalid or has expired.'], 422);
}

if (is_password_reset_code_expired((string) $resetRecord['expires_at'])) {
    delete_password_reset_code($pdo, $email);
    json_response(['message' => 'The verification code has expired. Request a new one.'], 422);
}

if (!password_verify($code, (string) $resetRecord['code_hash'])) {
    json_response(['message' => 'The verification code is incorrect.'], 422);
}

$passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

try {
    $pdo->beginTransaction();
    update_password_for_email($pdo, $email, $passwordHash);
    delete_password_reset_code($pdo, $email);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response(['message' => 'Unable to reset the password right now.'], 500);
}

json_response([
    'message' => 'Password reset successful. You can now sign in with your new password.',
]);
