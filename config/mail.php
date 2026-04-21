<?php

declare(strict_types=1);

function mail_config(): array
{
    return [
        'host' => getenv('MAIL_HOST') ?: 'smtp.gmail.com',
        'port' => (int) (getenv('MAIL_PORT') ?: '587'),
        'username' => getenv('MAIL_USERNAME') ?: 'edutrack510@gmail.com',
        'password' => preg_replace('/\s+/', '', getenv('MAIL_PASSWORD') ?: 'wodx fmel zhiz vcuy'),
        'encryption' => getenv('MAIL_ENCRYPTION') ?: 'tls',
        'from_email' => getenv('MAIL_FROM_EMAIL') ?: 'edutrack510@gmail.com',
        'from_name' => getenv('MAIL_FROM_NAME') ?: 'EduTrack',
    ];
}
