<?php

declare(strict_types=1);

require_once __DIR__ . '/../../utils/http.php';
require_once __DIR__ . '/../../utils/auth.php';

handle_cors();
require_method('POST');
start_auth_session();

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => $params['secure'] ?? false,
        'httponly' => $params['httponly'] ?? true,
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

session_destroy();

json_response(['message' => 'Logout successful.']);
