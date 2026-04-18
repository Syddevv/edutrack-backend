<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/http.php';

handle_cors();
require_method('POST');
require_auth();

function normalize_time(string $value): string
{
    $trimmed = trim($value);

    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $trimmed)) {
        json_response(['message' => 'Schedule times must use HH:MM format.'], 422);
    }

    return $trimmed . ':00';
}

function normalize_day_of_week(string $value): string
{
    $trimmed = trim($value);

    if (!in_array($trimmed, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], true)) {
        json_response(['message' => 'Assigned class day must be Monday through Saturday.'], 422);
    }

    return $trimmed;
}

function generate_teacher_code(PDO $pdo): string
{
    $statement = $pdo->query("SELECT teacher_id FROM teachers WHERE teacher_id LIKE 'TCH-%' ORDER BY id DESC LIMIT 1");
    $lastCode = (string) ($statement->fetchColumn() ?: 'TCH-000');
    $lastNumber = (int) preg_replace('/[^0-9]/', '', $lastCode);

    return 'TCH-' . str_pad((string) ($lastNumber + 1), 3, '0', STR_PAD_LEFT);
}

function find_or_create_class(PDO $pdo, array $assignment): int
{
    $lookup = $pdo->prepare(
        'SELECT id
         FROM classes
         WHERE course_id = :course_id
           AND year_level_id = :year_level_id
           AND section_id = :section_id
           AND subject = :subject
         LIMIT 1'
    );
    $lookup->execute([
        'course_id' => $assignment['courseId'],
        'year_level_id' => $assignment['yearLevelId'],
        'section_id' => $assignment['sectionId'],
        'subject' => $assignment['subject'],
    ]);

    $existingId = $lookup->fetchColumn();

    if ($existingId !== false) {
        return (int) $existingId;
    }

    $insert = $pdo->prepare(
        'INSERT INTO classes (course_id, year_level_id, section_id, subject)
         VALUES (:course_id, :year_level_id, :section_id, :subject)'
    );
    $insert->execute([
        'course_id' => $assignment['courseId'],
        'year_level_id' => $assignment['yearLevelId'],
        'section_id' => $assignment['sectionId'],
        'subject' => $assignment['subject'],
    ]);

    return (int) $pdo->lastInsertId();
}

function fetch_teacher_record(PDO $pdo, int $teacherId): array
{
    $statement = $pdo->prepare(
        'SELECT
            t.id,
            t.teacher_id,
            t.full_name,
            t.email,
            t.status,
            t.created_at,
            tc.id AS teacher_class_id,
            tc.class_id,
            tc.start_time,
            tc.end_time,
            tc.day_of_week,
            c.subject,
            course.id AS course_id,
            course.name AS course_name,
            course.code AS course_code,
            yl.id AS year_level_id,
            yl.name AS year_level_name,
            s.id AS section_id,
            s.name AS section_name
         FROM teachers t
         LEFT JOIN teacher_classes tc ON tc.teacher_id = t.id
         LEFT JOIN classes c ON c.id = tc.class_id
         LEFT JOIN courses course ON course.id = c.course_id
         LEFT JOIN year_levels yl ON yl.id = c.year_level_id
         LEFT JOIN sections s ON s.id = c.section_id
         WHERE t.id = :teacher_id
         ORDER BY c.subject ASC'
    );
    $statement->execute(['teacher_id' => $teacherId]);
    $rows = $statement->fetchAll();

    if (count($rows) === 0) {
        json_response(['message' => 'Teacher was created but could not be loaded.'], 500);
    }

    $firstRow = $rows[0];
    $teacher = [
        'id' => (int) $firstRow['id'],
        'teacherId' => (string) ($firstRow['teacher_id'] ?? ''),
        'fullName' => (string) ($firstRow['full_name'] ?? ''),
        'email' => (string) ($firstRow['email'] ?? ''),
        'status' => (string) ($firstRow['status'] ?? 'Active'),
        'createdAt' => $firstRow['created_at'],
        'assignedClasses' => [],
    ];

    foreach ($rows as $row) {
        if ($row['teacher_class_id'] === null || $row['class_id'] === null) {
            continue;
        }

        $teacher['assignedClasses'][] = [
            'id' => (int) $row['teacher_class_id'],
            'classId' => (int) $row['class_id'],
            'subject' => (string) ($row['subject'] ?? ''),
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
            'dayOfWeek' => (string) ($row['day_of_week'] ?? 'Monday'),
            'startTime' => $row['start_time'] !== null ? substr((string) $row['start_time'], 0, 5) : '',
            'endTime' => $row['end_time'] !== null ? substr((string) $row['end_time'], 0, 5) : '',
        ];
    }

    return $teacher;
}

$payload = json_input();
$fullName = trim((string) ($payload['fullName'] ?? ''));
$email = strtolower(trim((string) ($payload['email'] ?? '')));
$password = (string) ($payload['password'] ?? '');
$status = (string) ($payload['status'] ?? 'Active');
$assignedClasses = $payload['assignedClasses'] ?? [];

if ($fullName === '' || $email === '' || $password === '') {
    json_response(['message' => 'Full name, email, and password are required.'], 422);
}

if (!in_array($status, ['Active', 'On Leave', 'Inactive'], true)) {
    json_response(['message' => 'Invalid teacher status.'], 422);
}

if (!is_array($assignedClasses) || count($assignedClasses) === 0) {
    json_response(['message' => 'At least one assigned class is required.'], 422);
}

$normalizedAssignments = [];

foreach ($assignedClasses as $assignment) {
    if (!is_array($assignment)) {
        json_response(['message' => 'Invalid assigned class payload.'], 422);
    }

    $subject = trim((string) ($assignment['subject'] ?? ''));
    $courseId = (int) ($assignment['courseId'] ?? 0);
    $yearLevelId = (int) ($assignment['yearLevelId'] ?? 0);
    $sectionId = (int) ($assignment['sectionId'] ?? 0);
    $dayOfWeek = normalize_day_of_week((string) ($assignment['dayOfWeek'] ?? ''));
    $startTime = normalize_time((string) ($assignment['startTime'] ?? ''));
    $endTime = normalize_time((string) ($assignment['endTime'] ?? ''));

    if ($subject === '' || $courseId <= 0 || $yearLevelId <= 0 || $sectionId <= 0) {
        json_response(['message' => 'Each assigned class must include subject, course, year level, and section.'], 422);
    }

    if ($startTime >= $endTime) {
        json_response(['message' => 'Assigned class end time must be later than the start time.'], 422);
    }

    $normalizedAssignments[] = [
        'subject' => $subject,
        'courseId' => $courseId,
        'yearLevelId' => $yearLevelId,
        'sectionId' => $sectionId,
        'dayOfWeek' => $dayOfWeek,
        'startTime' => $startTime,
        'endTime' => $endTime,
    ];
}

$pdo = database();

$existingTeacher = $pdo->prepare('SELECT id FROM teachers WHERE email = :email LIMIT 1');
$existingTeacher->execute(['email' => $email]);
if ($existingTeacher->fetch()) {
    json_response(['message' => 'A teacher with that email already exists.'], 409);
}

$existingUser = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$existingUser->execute(['email' => $email]);
if ($existingUser->fetch()) {
    json_response(['message' => 'A user with that email already exists.'], 409);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$teacherId = 0;

try {
    $pdo->beginTransaction();

    $teacherCode = generate_teacher_code($pdo);

    $insertTeacher = $pdo->prepare(
        'INSERT INTO teachers (teacher_id, full_name, email, password, status)
         VALUES (:teacher_id, :full_name, :email, :password, :status)'
    );
    $insertTeacher->execute([
        'teacher_id' => $teacherCode,
        'full_name' => $fullName,
        'email' => $email,
        'password' => $passwordHash,
        'status' => $status,
    ]);

    $teacherId = (int) $pdo->lastInsertId();

    $insertUser = $pdo->prepare(
        "INSERT INTO users (name, email, password, role, status)
         VALUES (:name, :email, :password, 'teacher', :status)"
    );
    $insertUser->execute([
        'name' => $fullName,
        'email' => $email,
        'password' => $passwordHash,
        'status' => $status === 'Inactive' ? 'inactive' : 'active',
    ]);

    $assignTeacher = $pdo->prepare(
        'INSERT INTO teacher_classes (teacher_id, class_id, start_time, end_time, day_of_week)
         VALUES (:teacher_id, :class_id, :start_time, :end_time, :day_of_week)'
    );

    foreach ($normalizedAssignments as $assignment) {
        $classId = find_or_create_class($pdo, $assignment);
        $assignTeacher->execute([
            'teacher_id' => $teacherId,
            'class_id' => $classId,
            'start_time' => $assignment['startTime'],
            'end_time' => $assignment['endTime'],
            'day_of_week' => $assignment['dayOfWeek'],
        ]);
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response(['message' => 'Failed to create teacher.'], 500);
}

json_response([
    'message' => 'Teacher created successfully.',
    'teacher' => fetch_teacher_record($pdo, $teacherId),
], 201);
