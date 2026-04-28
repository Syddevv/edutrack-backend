<?php

declare(strict_types=1);

require_once __DIR__ . '/utils/http.php';

handle_cors();

json_response([
    'name' => 'EduTrack Backend',
    'status' => 'ok',
    'auth_endpoints' => [
        'POST /api/auth/login.php',
        'POST /api/auth/logout.php',
        'GET /api/auth/me.php',
        'GET|POST /api/auth/two-factor.php',
    ],
]);
