<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function ensure_password_reset_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS password_reset_codes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            email VARCHAR(255) NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_password_reset_email (email),
            KEY password_reset_user_idx (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function find_password_reset_user(PDO $pdo, string $email): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, email, role, status
         FROM users
         WHERE email = :email
         LIMIT 1'
    );
    $statement->execute(['email' => $email]);

    $user = $statement->fetch();

    return $user ?: null;
}

function store_password_reset_code(
    PDO $pdo,
    int $userId,
    string $email,
    string $codeHash,
    string $expiresAt
): void {
    $statement = $pdo->prepare(
        'INSERT INTO password_reset_codes (user_id, email, code_hash, expires_at)
         VALUES (:user_id, :email, :code_hash, :expires_at)
         ON DUPLICATE KEY UPDATE
           user_id = VALUES(user_id),
           code_hash = VALUES(code_hash),
           expires_at = VALUES(expires_at),
           created_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([
        'user_id' => $userId,
        'email' => $email,
        'code_hash' => $codeHash,
        'expires_at' => $expiresAt,
    ]);
}

function find_password_reset_code(PDO $pdo, string $email): ?array
{
    $statement = $pdo->prepare(
        'SELECT user_id, email, code_hash, expires_at
         FROM password_reset_codes
         WHERE email = :email
         LIMIT 1'
    );
    $statement->execute(['email' => $email]);

    $record = $statement->fetch();

    return $record ?: null;
}

function delete_password_reset_code(PDO $pdo, string $email): void
{
    $statement = $pdo->prepare('DELETE FROM password_reset_codes WHERE email = :email');
    $statement->execute(['email' => $email]);
}

function is_password_reset_code_expired(string $expiresAt): bool
{
    $expiresAtTime = strtotime($expiresAt);

    if ($expiresAtTime === false) {
        return true;
    }

    return $expiresAtTime < time();
}

function update_password_for_email(PDO $pdo, string $email, string $passwordHash): void
{
    $updateUser = $pdo->prepare(
        'UPDATE users
         SET password = :password
         WHERE email = :email'
    );
    $updateUser->execute([
        'password' => $passwordHash,
        'email' => $email,
    ]);

    if ($updateUser->rowCount() === 0) {
        throw new RuntimeException('No matching account was found.');
    }

    $updateTeacher = $pdo->prepare(
        'UPDATE teachers
         SET password = :password
         WHERE email = :email'
    );
    $updateTeacher->execute([
        'password' => $passwordHash,
        'email' => $email,
    ]);
}
