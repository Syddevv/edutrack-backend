<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use OTPHP\TOTP;

const TWO_FACTOR_ISSUER = 'EduTrack';
const TWO_FACTOR_PENDING_SECRET_KEY = 'pending_two_factor_secret';
const TWO_FACTOR_CODE_LEEWAY_SECONDS = 29;

function ensure_two_factor_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_two_factor_authentications (
            user_id INT NOT NULL PRIMARY KEY,
            secret VARCHAR(255) NOT NULL,
            enabled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_user_two_factor_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function find_two_factor_record(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare(
        'SELECT user_id, secret, enabled_at, created_at, updated_at
         FROM user_two_factor_authentications
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $statement->execute(['user_id' => $userId]);

    $record = $statement->fetch();

    return $record ?: null;
}

function two_factor_is_enabled(PDO $pdo, int $userId): bool
{
    return find_two_factor_record($pdo, $userId) !== null;
}

function create_two_factor_totp(string $secret, string $email): TOTP
{
    $totp = TOTP::createFromSecret($secret);
    $totp->setLabel($email);
    $totp->setIssuer(TWO_FACTOR_ISSUER);

    return $totp;
}

function create_two_factor_setup(PDO $pdo, array $user): array
{
    $userId = (int) ($user['id'] ?? 0);
    $email = (string) ($user['email'] ?? '');

    if ($userId <= 0 || $email === '') {
        throw new RuntimeException('Invalid authenticated user.');
    }

    if (two_factor_is_enabled($pdo, $userId)) {
        throw new RuntimeException('Two-factor authentication is already enabled.');
    }

    start_auth_session();

    $totp = TOTP::generate();
    $totp->setLabel($email);
    $totp->setIssuer(TWO_FACTOR_ISSUER);

    $_SESSION[TWO_FACTOR_PENDING_SECRET_KEY] = $totp->getSecret();

    return [
        'secret' => $totp->getSecret(),
        'otpauthUrl' => $totp->getProvisioningUri(),
        'issuer' => TWO_FACTOR_ISSUER,
        'email' => $email,
    ];
}

function verify_two_factor_code(string $secret, string $email, string $code): bool
{
    $normalizedCode = preg_replace('/\D+/', '', $code) ?? '';

    if (strlen($normalizedCode) !== 6) {
        return false;
    }

    $totp = create_two_factor_totp($secret, $email);

    return $totp->verify($normalizedCode, null, TWO_FACTOR_CODE_LEEWAY_SECONDS);
}

function enable_two_factor(PDO $pdo, array $user, string $code): void
{
    $userId = (int) ($user['id'] ?? 0);
    $email = (string) ($user['email'] ?? '');

    start_auth_session();

    $secret = (string) ($_SESSION[TWO_FACTOR_PENDING_SECRET_KEY] ?? '');

    if ($secret === '') {
        throw new RuntimeException('Start setup again to generate a new QR code.');
    }

    if (!verify_two_factor_code($secret, $email, $code)) {
        throw new RuntimeException('The authentication code is invalid or expired.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO user_two_factor_authentications (user_id, secret)
         VALUES (:user_id, :secret)
         ON DUPLICATE KEY UPDATE
            secret = VALUES(secret),
            enabled_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([
        'user_id' => $userId,
        'secret' => $secret,
    ]);

    unset($_SESSION[TWO_FACTOR_PENDING_SECRET_KEY]);
}

function verify_user_password(PDO $pdo, int $userId, string $password): bool
{
    $statement = $pdo->prepare(
        'SELECT password
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $userId]);

    $storedPassword = (string) $statement->fetchColumn();

    if ($storedPassword === '') {
        return false;
    }

    return password_verify($password, $storedPassword) || hash_equals($storedPassword, $password);
}

function disable_two_factor(PDO $pdo, array $user, string $currentPassword, string $code): void
{
    $userId = (int) ($user['id'] ?? 0);
    $email = (string) ($user['email'] ?? '');

    if (!verify_user_password($pdo, $userId, $currentPassword)) {
        throw new RuntimeException('The current password is incorrect.');
    }

    $record = find_two_factor_record($pdo, $userId);

    if ($record === null) {
        throw new RuntimeException('Two-factor authentication is not enabled.');
    }

    if (!verify_two_factor_code((string) $record['secret'], $email, $code)) {
        throw new RuntimeException('The authentication code is invalid or expired.');
    }

    $statement = $pdo->prepare(
        'DELETE FROM user_two_factor_authentications
         WHERE user_id = :user_id'
    );
    $statement->execute(['user_id' => $userId]);

    start_auth_session();
    unset($_SESSION[TWO_FACTOR_PENDING_SECRET_KEY]);
}
