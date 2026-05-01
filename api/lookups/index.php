<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/http.php';

handle_cors();
$user = require_auth();

if (strtolower((string) ($user['role'] ?? '')) === 'teacher') {
    json_response(['message' => 'You are not allowed to manage lookup records.'], 403);
}

function request_payload(): array
{
    return json_input();
}

function require_supported_method(): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
        json_response(['message' => 'Method not allowed.'], 405);
    }
}

function normalize_type(mixed $value): string
{
    $type = trim((string) $value);

    if (!in_array($type, ['subjects', 'courses'], true)) {
        json_response(['message' => 'Invalid lookup type.'], 422);
    }

    return $type;
}

function normalize_name(mixed $value, string $label): string
{
    $name = trim((string) $value);

    if ($name === '') {
        json_response(['message' => $label . ' name is required.'], 422);
    }

    return $name;
}

function normalize_code(mixed $value): ?string
{
    $code = trim((string) $value);
    return $code === '' ? null : $code;
}

function fetch_subject_row(PDO $pdo, int $subjectId): array
{
    $statement = $pdo->prepare(
        'SELECT id, name, code, created_at
         FROM subjects
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $subjectId]);
    $row = $statement->fetch();

    if (!$row) {
        json_response(['message' => 'Subject not found.'], 404);
    }

    return $row;
}

function fetch_course_row(PDO $pdo, int $courseId): array
{
    $statement = $pdo->prepare(
        'SELECT id, name, code
         FROM courses
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $courseId]);
    $row = $statement->fetch();

    if (!$row) {
        json_response(['message' => 'Course not found.'], 404);
    }

    return $row;
}

function assert_lookup_uniqueness(PDO $pdo, string $type, string $name, ?string $code, ?int $excludeId = null): void
{
    $table = $type === 'subjects' ? 'subjects' : 'courses';

    $nameSql = "SELECT id FROM {$table} WHERE name = :name";
    $nameParams = ['name' => $name];

    if ($excludeId !== null) {
        $nameSql .= ' AND id <> :exclude_id';
        $nameParams['exclude_id'] = $excludeId;
    }

    $nameSql .= ' LIMIT 1';
    $nameStatement = $pdo->prepare($nameSql);
    $nameStatement->execute($nameParams);

    if ($nameStatement->fetch()) {
        json_response(['message' => 'A ' . rtrim($type, 's') . ' with that name already exists.'], 409);
    }

    if ($code === null) {
        return;
    }

    $codeSql = "SELECT id FROM {$table} WHERE code = :code";
    $codeParams = ['code' => $code];

    if ($excludeId !== null) {
        $codeSql .= ' AND id <> :exclude_id';
        $codeParams['exclude_id'] = $excludeId;
    }

    $codeSql .= ' LIMIT 1';
    $codeStatement = $pdo->prepare($codeSql);
    $codeStatement->execute($codeParams);

    if ($codeStatement->fetch()) {
        json_response(['message' => 'A ' . rtrim($type, 's') . ' with that code already exists.'], 409);
    }
}

function fetch_overview(PDO $pdo): array
{
    $subjects = $pdo->query(
        'SELECT
            s.id,
            s.name,
            s.code,
            s.created_at,
            COUNT(cls.id) AS class_count
         FROM subjects s
         LEFT JOIN classes cls ON cls.subject = s.name
         GROUP BY s.id, s.name, s.code, s.created_at
         ORDER BY s.name ASC'
    )->fetchAll();

    $courses = $pdo->query(
        'SELECT
            c.id,
            c.name,
            c.code,
            (SELECT COUNT(*) FROM students s WHERE s.course_id = c.id) AS student_count,
            (SELECT COUNT(*) FROM classes cls WHERE cls.course_id = c.id) AS class_count
         FROM courses c
         ORDER BY c.name ASC'
    )->fetchAll();

    return [
        'subjects' => array_map(
            static fn(array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'code' => (string) ($row['code'] ?? ''),
                'createdAt' => $row['created_at'],
                'classCount' => (int) ($row['class_count'] ?? 0),
            ],
            $subjects
        ),
        'courses' => array_map(
            static fn(array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'code' => (string) ($row['code'] ?? ''),
                'studentCount' => (int) ($row['student_count'] ?? 0),
                'classCount' => (int) ($row['class_count'] ?? 0),
            ],
            $courses
        ),
        'summary' => [
            'subjects' => (int) $pdo->query('SELECT COUNT(*) FROM subjects')->fetchColumn(),
            'courses' => (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn(),
            'classes' => (int) $pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn(),
            'students' => (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),
        ],
    ];
}

require_supported_method();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$pdo = database();

if ($method === 'GET') {
    json_response(fetch_overview($pdo));
}

$payload = request_payload();
$type = normalize_type($payload['type'] ?? null);

if ($method === 'POST') {
    $name = normalize_name($payload['name'] ?? null, rtrim(ucfirst($type), 's'));
    $code = normalize_code($payload['code'] ?? null);

    assert_lookup_uniqueness($pdo, $type, $name, $code);

    $table = $type === 'subjects' ? 'subjects' : 'courses';
    $statement = $pdo->prepare("INSERT INTO {$table} (name, code) VALUES (:name, :code)");
    $statement->execute([
        'name' => $name,
        'code' => $code,
    ]);

    json_response(['message' => rtrim(ucfirst($type), 's') . ' created successfully.'], 201);
}

$itemId = (int) ($payload['id'] ?? 0);

if ($itemId <= 0) {
    json_response(['message' => 'A valid record id is required.'], 422);
}

if ($method === 'PUT') {
    $name = normalize_name($payload['name'] ?? null, rtrim(ucfirst($type), 's'));
    $code = normalize_code($payload['code'] ?? null);

    assert_lookup_uniqueness($pdo, $type, $name, $code, $itemId);

    try {
        $pdo->beginTransaction();

        if ($type === 'subjects') {
            $existing = fetch_subject_row($pdo, $itemId);
            $update = $pdo->prepare('UPDATE subjects SET name = :name, code = :code WHERE id = :id');
            $update->execute([
                'id' => $itemId,
                'name' => $name,
                'code' => $code,
            ]);

            if ((string) ($existing['name'] ?? '') !== $name) {
                $propagate = $pdo->prepare('UPDATE classes SET subject = :new_name WHERE subject = :old_name');
                $propagate->execute([
                    'new_name' => $name,
                    'old_name' => (string) ($existing['name'] ?? ''),
                ]);
            }
        } else {
            fetch_course_row($pdo, $itemId);
            $update = $pdo->prepare('UPDATE courses SET name = :name, code = :code WHERE id = :id');
            $update->execute([
                'id' => $itemId,
                'name' => $name,
                'code' => $code,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        json_response(['message' => 'Failed to update the record.'], 500);
    }

    json_response(['message' => rtrim(ucfirst($type), 's') . ' updated successfully.']);
}

if ($type === 'subjects') {
    $existing = fetch_subject_row($pdo, $itemId);
    $usageStatement = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE subject = :subject');
    $usageStatement->execute(['subject' => (string) ($existing['name'] ?? '')]);
    $classCount = (int) $usageStatement->fetchColumn();

    if ($classCount > 0) {
        json_response([
            'message' => 'This subject is still used by ' . $classCount . ' class(es) and cannot be deleted.'
        ], 409);
    }

    $delete = $pdo->prepare('DELETE FROM subjects WHERE id = :id');
    $delete->execute(['id' => $itemId]);
    json_response(['message' => 'Subject deleted successfully.']);
}

fetch_course_row($pdo, $itemId);
$studentUsage = $pdo->prepare('SELECT COUNT(*) FROM students WHERE course_id = :course_id');
$studentUsage->execute(['course_id' => $itemId]);
$studentCount = (int) $studentUsage->fetchColumn();

$classUsage = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE course_id = :course_id');
$classUsage->execute(['course_id' => $itemId]);
$classCount = (int) $classUsage->fetchColumn();

if ($studentCount > 0 || $classCount > 0) {
    $parts = [];

    if ($studentCount > 0) {
        $parts[] = $studentCount . ' student record(s)';
    }

    if ($classCount > 0) {
        $parts[] = $classCount . ' class(es)';
    }

    json_response([
        'message' => 'This course is still used by ' . implode(' and ', $parts) . ' and cannot be deleted.'
    ], 409);
}

$delete = $pdo->prepare('DELETE FROM courses WHERE id = :id');
$delete->execute(['id' => $itemId]);
json_response(['message' => 'Course deleted successfully.']);