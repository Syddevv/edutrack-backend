<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/http.php';

handle_cors();

const APP_TIMEZONE = 'Asia/Manila';
const ATTENDANCE_STATUSES = ['Present', 'Late', 'Absent'];

function normalize_date(?string $value): string
{
    $timezone = new DateTimeZone(APP_TIMEZONE);

    if ($value === null || trim($value) === '') {
        return (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', trim($value), $timezone);

    if (!$date || $date->format('Y-m-d') !== trim($value)) {
        json_response(['message' => 'Date must use YYYY-MM-DD format.'], 422);
    }

    return $date->format('Y-m-d');
}

function normalize_status(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_string($value) || !in_array($value, ATTENDANCE_STATUSES, true)) {
        json_response(['message' => 'Attendance status must be Present, Late, or Absent.'], 422);
    }

    return $value;
}

function find_teacher_id(PDO $pdo, string $email): int
{
    $statement = $pdo->prepare(
        'SELECT id
         FROM teachers
         WHERE email = :email
         LIMIT 1'
    );
    $statement->execute(['email' => $email]);
    $teacherId = $statement->fetchColumn();

    if ($teacherId === false) {
        json_response(['message' => 'Teacher profile not found.'], 404);
    }

    return (int) $teacherId;
}

function fetch_teacher_assignments(PDO $pdo, int $teacherId): array
{
    $statement = $pdo->prepare(
        "SELECT
            tc.id AS schedule_id,
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
            sec.id AS section_id,
            sec.name AS section_name
         FROM teacher_classes tc
         INNER JOIN classes c ON c.id = tc.class_id
         LEFT JOIN courses course ON course.id = c.course_id
         LEFT JOIN year_levels yl ON yl.id = c.year_level_id
         LEFT JOIN sections sec ON sec.id = c.section_id
         WHERE tc.teacher_id = :teacher_id
         ORDER BY
            course.code ASC,
            yl.id ASC,
            sec.name ASC,
            c.subject ASC,
            tc.id ASC"
    );
    $statement->execute(['teacher_id' => $teacherId]);

    return array_map(
        static function (array $row): array {
            return [
                'scheduleId' => (int) ($row['schedule_id'] ?? 0),
                'classId' => (int) ($row['class_id'] ?? 0),
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
                'dayOfWeek' => $row['day_of_week'] !== null ? (string) $row['day_of_week'] : null,
                'startTime' => $row['start_time'] !== null ? substr((string) $row['start_time'], 0, 5) : '',
                'endTime' => $row['end_time'] !== null ? substr((string) $row['end_time'], 0, 5) : '',
            ];
        },
        $statement->fetchAll()
    );
}

function find_selected_assignment(array $assignments, ?int $classId): ?array
{
    if ($classId !== null) {
        foreach ($assignments as $assignment) {
            if ((int) ($assignment['classId'] ?? 0) === $classId) {
                return $assignment;
            }
        }
    }

    return $assignments[0] ?? null;
}

function fetch_students_for_assignment(PDO $pdo, array $assignment, string $attendanceDate): array
{
    $statement = $pdo->prepare(
        'SELECT
            s.id,
            s.student_id,
            s.first_name,
            s.last_name,
            a.status
         FROM students s
         LEFT JOIN attendance a
            ON a.student_id = s.id
           AND a.class_id = :class_id
           AND a.date = :attendance_date
         WHERE s.course_id = :course_id
           AND s.year_level_id = :year_level_id
           AND s.section_id = :section_id
         ORDER BY s.last_name ASC, s.first_name ASC, s.id ASC'
    );
    $statement->execute([
        'class_id' => (int) $assignment['classId'],
        'attendance_date' => $attendanceDate,
        'course_id' => (int) ($assignment['course']['id'] ?? 0),
        'year_level_id' => (int) ($assignment['yearLevel']['id'] ?? 0),
        'section_id' => (int) ($assignment['section']['id'] ?? 0),
    ]);

    return array_map(
        static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'studentId' => (string) ($row['student_id'] ?? ''),
                'fullName' => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
                'status' => $row['status'] !== null ? (string) $row['status'] : null,
            ];
        },
        $statement->fetchAll()
    );
}

function build_attendance_response(PDO $pdo, int $teacherId, string $attendanceDate, ?int $requestedClassId): array
{
    $assignments = fetch_teacher_assignments($pdo, $teacherId);
    $selectedAssignment = find_selected_assignment($assignments, $requestedClassId);
    $students = $selectedAssignment !== null
        ? fetch_students_for_assignment($pdo, $selectedAssignment, $attendanceDate)
        : [];

    return [
        'date' => $attendanceDate,
        'assignments' => $assignments,
        'selectedClassId' => $selectedAssignment['classId'] ?? null,
        'students' => $students,
    ];
}

function parse_requested_class_id(): ?int
{
    $value = $_GET['classId'] ?? null;

    if ($value === null || $value === '') {
        return null;
    }

    $classId = (int) $value;

    if ($classId <= 0) {
        json_response(['message' => 'Class ID is invalid.'], 422);
    }

    return $classId;
}

$user = require_auth();
$pdo = database();
$teacherId = find_teacher_id($pdo, (string) ($user['email'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $attendanceDate = normalize_date(isset($_GET['date']) ? (string) $_GET['date'] : null);
    $requestedClassId = parse_requested_class_id();

    json_response(build_attendance_response($pdo, $teacherId, $attendanceDate, $requestedClassId));
}

if ($method === 'POST') {
    $payload = json_input();
    $attendanceDate = normalize_date((string) ($payload['date'] ?? ''));
    $classId = (int) ($payload['classId'] ?? 0);

    if ($classId <= 0) {
        json_response(['message' => 'Class ID is required.'], 422);
    }

    $assignments = fetch_teacher_assignments($pdo, $teacherId);
    $selectedAssignment = find_selected_assignment($assignments, $classId);

    if ($selectedAssignment === null || (int) ($selectedAssignment['classId'] ?? 0) !== $classId) {
        json_response(['message' => 'You are not assigned to this class.'], 403);
    }

    $students = fetch_students_for_assignment($pdo, $selectedAssignment, $attendanceDate);
    $allowedStudentIds = [];

    foreach ($students as $student) {
        $allowedStudentIds[(int) $student['id']] = true;
    }

    $records = $payload['records'] ?? null;

    if (!is_array($records)) {
        json_response(['message' => 'Attendance records are required.'], 422);
    }

    $normalizedRecords = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            json_response(['message' => 'Attendance records are invalid.'], 422);
        }

        $studentId = (int) ($record['studentId'] ?? 0);

        if ($studentId <= 0 || !isset($allowedStudentIds[$studentId])) {
            json_response(['message' => 'Attendance contains a student outside your assigned class.'], 403);
        }

        $normalizedRecords[$studentId] = normalize_status($record['status'] ?? null);
    }

    try {
        $pdo->beginTransaction();

        $deleteStatement = $pdo->prepare(
            'DELETE FROM attendance
             WHERE class_id = :class_id
               AND date = :attendance_date'
        );
        $deleteStatement->execute([
            'class_id' => $classId,
            'attendance_date' => $attendanceDate,
        ]);

        $insertStatement = $pdo->prepare(
            'INSERT INTO attendance (student_id, class_id, date, time_in, status)
             VALUES (:student_id, :class_id, :attendance_date, NULL, :status)'
        );

        foreach ($students as $student) {
            $studentId = (int) $student['id'];
            $status = $normalizedRecords[$studentId] ?? null;

            if ($status === null) {
                continue;
            }

            $insertStatement->execute([
                'student_id' => $studentId,
                'class_id' => $classId,
                'attendance_date' => $attendanceDate,
                'status' => $status,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        json_response(['message' => 'Failed to save attendance.'], 500);
    }

    json_response(build_attendance_response($pdo, $teacherId, $attendanceDate, $classId));
}

json_response(['message' => 'Method not allowed.'], 405);
