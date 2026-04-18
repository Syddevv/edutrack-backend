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
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
}

session_destroy();

json_response(['message' => 'Logout successful.']);
