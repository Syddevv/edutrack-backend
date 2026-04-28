<?php

declare(strict_types=1);

require_once __DIR__ . '/../../utils/http.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/two_factor.php';

handle_cors();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$user = require_auth();

if (strtolower((string) ($user['role'] ?? '')) !== 'admin') {
    json_response(['message' => 'Only admins can manage two-factor authentication.'], 403);
}

$pdo = database();
ensure_two_factor_table($pdo);

if ($method === 'GET') {
    json_response([
        'enabled' => two_factor_is_enabled($pdo, (int) $user['id']),
        'email' => (string) $user['email'],
        'issuer' => TWO_FACTOR_ISSUER,
    ]);
}

if ($method !== 'POST') {
    json_response(['message' => 'Method not allowed.'], 405);
}

$payload = json_input();
$action = strtolower(trim((string) ($payload['action'] ?? '')));

try {
    if ($action === 'prepare') {
        json_response([
            'message' => 'Two-factor setup created.',
            ...create_two_factor_setup($pdo, $user),
        ]);
    }

    if ($action === 'enable') {
        $code = (string) ($payload['code'] ?? '');

        if ($code === '') {
            json_response(['message' => 'Authentication code is required.'], 422);
        }

        enable_two_factor($pdo, $user, $code);

        json_response([
            'message' => 'Two-factor authentication enabled.',
            'enabled' => true,
        ]);
    }

    if ($action === 'disable') {
        $currentPassword = (string) ($payload['currentPassword'] ?? '');
        $code = (string) ($payload['code'] ?? '');

        if ($currentPassword === '' || $code === '') {
            json_response([
                'message' => 'Current password and authentication code are required.',
            ], 422);
        }

        disable_two_factor($pdo, $user, $currentPassword, $code);

        json_response([
            'message' => 'Two-factor authentication disabled.',
            'enabled' => false,
        ]);
    }
} catch (RuntimeException $exception) {
    json_response(['message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    json_response([
        'message' => 'Two-factor authentication could not be processed right now.',
    ], 500);
}

json_response(['message' => 'Invalid two-factor action.'], 422);
