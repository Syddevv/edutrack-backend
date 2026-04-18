<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/http.php';

handle_cors();
require_method('POST');
require_auth();

function fetch_student_record(PDO $pdo, int $studentId): array
{
    $statement = $pdo->prepare(
        'SELECT
            s.id,
            s.student_id,
            s.first_name,
            s.last_name,
            s.email,
            s.created_at,
            c.id AS course_id,
            c.name AS course_name,
            c.code AS course_code,
            yl.id AS year_level_id,
            yl.name AS year_level_name,
            sec.id AS section_id,
            sec.name AS section_name,
            (
                SELECT a.status
                FROM attendance a
                WHERE a.student_id = s.id
                ORDER BY a.date DESC, a.id DESC
                LIMIT 1
            ) AS attendance_status
         FROM students s
         LEFT JOIN courses c ON c.id = s.course_id
         LEFT JOIN year_levels yl ON yl.id = s.year_level_id
         LEFT JOIN sections sec ON sec.id = s.section_id
         WHERE s.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $studentId]);
    $row = $statement->fetch();

    if (!$row) {
        json_response(['message' => 'Student not found.'], 404);
    }

    $firstName = (string) ($row['first_name'] ?? '');
    $lastName = (string) ($row['last_name'] ?? '');

    return [
        'id' => (int) $row['id'],
        'studentId' => (string) ($row['student_id'] ?? ''),
        'firstName' => $firstName,
        'lastName' => $lastName,
        'fullName' => trim($firstName . ' ' . $lastName),
        'email' => (string) ($row['email'] ?? ''),
        'course' => [
            'id' => (int) ($row['course_id'] ?? 0),
            'name' => (string) ($row['course_name'] ?? ''),
            'code' => (string) ($row['course_code'] ?? ''),
        ],
        'yearLevel' => [
            'id' => (int) ($row['year_level_id'] ?? 0),
            'name' => (string) ($row['year_level_name'] ?? ''),
        ],
        'section' => [
            'id' => (int) ($row['section_id'] ?? 0),
            'name' => (string) ($row['section_name'] ?? ''),
        ],
        'attendanceStatus' => (string) ($row['attendance_status'] ?? 'No Record'),
        'createdAt' => $row['created_at'],
    ];
}

$payload = json_input();
$studentId = (int) ($payload['studentId'] ?? 0);
$firstName = trim((string) ($payload['firstName'] ?? ''));
$lastName = trim((string) ($payload['lastName'] ?? ''));
$email = strtolower(trim((string) ($payload['email'] ?? '')));
$courseId = (int) ($payload['courseId'] ?? 0);
$yearLevelId = (int) ($payload['yearLevelId'] ?? 0);
$sectionId = (int) ($payload['sectionId'] ?? 0);

if ($studentId <= 0) {
    json_response(['message' => 'Student ID is required.'], 422);
}

if ($firstName === '' || $lastName === '' || $email === '') {
    json_response(['message' => 'First name, last name, and email are required.'], 422);
}

if ($courseId <= 0 || $yearLevelId <= 0 || $sectionId <= 0) {
    json_response(['message' => 'Course, year level, and section are required.'], 422);
}

$pdo = database();
$currentStudent = $pdo->prepare('SELECT id, email FROM students WHERE id = :id LIMIT 1');
$currentStudent->execute(['id' => $studentId]);
$existing = $currentStudent->fetch();

if (!$existing) {
    json_response(['message' => 'Student not found.'], 404);
}

$duplicate = $pdo->prepare('SELECT id FROM students WHERE email = :email AND id <> :id LIMIT 1');
$duplicate->execute([
    'email' => $email,
    'id' => $studentId,
]);

if ($duplicate->fetch()) {
    json_response(['message' => 'A student with that email already exists.'], 409);
}

$update = $pdo->prepare(
    'UPDATE students
     SET first_name = :first_name,
         last_name = :last_name,
         email = :email,
         course_id = :course_id,
         year_level_id = :year_level_id,
         section_id = :section_id
     WHERE id = :id'
);
$update->execute([
    'first_name' => $firstName,
    'last_name' => $lastName,
    'email' => $email,
    'course_id' => $courseId,
    'year_level_id' => $yearLevelId,
    'section_id' => $sectionId,
    'id' => $studentId,
]);

json_response([
    'message' => 'Student updated successfully.',
    'student' => fetch_student_record($pdo, $studentId),
]);