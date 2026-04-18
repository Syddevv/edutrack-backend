<?php

declare(strict_types=1);

require_once __DIR__ . '/../../utils/http.php';
require_once __DIR__ . '/../../utils/auth.php';

handle_cors();
require_method('GET');

$user = require_auth();

json_response(['user' => $user]);
