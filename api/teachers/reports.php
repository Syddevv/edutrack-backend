<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/http.php';

handle_cors();
require_method('GET');
$user = require_auth();

const APP_TIMEZONE = 'Asia/Manila';

function percentage(int $numerator, int $denominator): float
{
    if ($denominator <= 0) {
        return 0.0;
    }

    return round(($numerator / $denominator) * 100, 1);
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
            tc.day_of_week,
            tc.start_time,
            tc.end_time,
            c.subject,
            course.id AS course_id,
            COALESCE(NULLIF(course.code, ''), course.name) AS course_label,
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
         ORDER BY course_label ASC, yl.id ASC, sec.name ASC, c.subject ASC, tc.id ASC"
    );
    $statement->execute(['teacher_id' => $teacherId]);

    return array_map(
        static function (array $row): array {
            return [
                'scheduleId' => (int) ($row['schedule_id'] ?? 0),
                'classId' => (int) ($row['class_id'] ?? 0),
                'course' => (string) ($row['course_label'] ?? ''),
                'year' => (string) ($row['year_level_name'] ?? ''),
                'section' => (string) ($row['section_name'] ?? ''),
                'subject' => (string) ($row['subject'] ?? ''),
                'dayOfWeek' => $row['day_of_week'] !== null ? (string) $row['day_of_week'] : null,
                'startTime' => $row['start_time'] !== null ? substr((string) $row['start_time'], 0, 5) : '',
                'endTime' => $row['end_time'] !== null ? substr((string) $row['end_time'], 0, 5) : '',
            ];
        },
        $statement->fetchAll()
    );
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

function select_assignment(array $assignments, ?int $classId): ?array
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

$pdo = database();
$teacherId = find_teacher_id($pdo, (string) ($user['email'] ?? ''));
$assignments = fetch_teacher_assignments($pdo, $teacherId);
$selectedAssignment = select_assignment($assignments, parse_requested_class_id());

if ($selectedAssignment === null) {
    json_response([
        'assignments' => [],
        'selectedClassId' => null,
        'summary' => [
            'attendanceRate' => 0,
            'attendanceRateDelta' => 0,
            'totalStudents' => 0,
        ],
        'atRiskStudents' => [],
    ]);
}

$classId = (int) $selectedAssignment['classId'];
$timezone = new DateTimeZone(APP_TIMEZONE);
$today = new DateTimeImmutable('today', $timezone);
$currentWeekStart = $today->modify('monday this week');
$previousWeekStart = $currentWeekStart->modify('-7 days');
$previousWeekEnd = $currentWeekStart->modify('-1 day');

$classMetaStatement = $pdo->prepare(
    'SELECT course_id, year_level_id, section_id
     FROM classes
     WHERE id = :class_id
     LIMIT 1'
);
$classMetaStatement->execute(['class_id' => $classId]);
$classMeta = $classMetaStatement->fetch() ?: null;

$totalStudents = 0;

if ($classMeta) {
    $studentCountStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM students
         WHERE course_id = :course_id
           AND year_level_id = :year_level_id
           AND section_id = :section_id'
    );
    $studentCountStatement->execute([
        'course_id' => (int) ($classMeta['course_id'] ?? 0),
        'year_level_id' => (int) ($classMeta['year_level_id'] ?? 0),
        'section_id' => (int) ($classMeta['section_id'] ?? 0),
    ]);
    $totalStudents = (int) $studentCountStatement->fetchColumn();
}

$attendanceSummaryStatement = $pdo->prepare(
    'SELECT
        COUNT(*) AS total_records,
        SUM(CASE WHEN status IN (\'Present\', \'Late\') THEN 1 ELSE 0 END) AS attended_records
     FROM attendance
     WHERE class_id = :class_id
       AND date BETWEEN :start_date AND :end_date'
);
$attendanceSummaryStatement->execute([
    'class_id' => $classId,
    'start_date' => $currentWeekStart->format('Y-m-d'),
    'end_date' => $today->format('Y-m-d'),
]);
$currentWeekAttendance = $attendanceSummaryStatement->fetch() ?: [];

$attendanceSummaryStatement->execute([
    'class_id' => $classId,
    'start_date' => $previousWeekStart->format('Y-m-d'),
    'end_date' => $previousWeekEnd->format('Y-m-d'),
]);
$previousWeekAttendance = $attendanceSummaryStatement->fetch() ?: [];

$currentAttendanceRate = percentage(
    (int) ($currentWeekAttendance['attended_records'] ?? 0),
    (int) ($currentWeekAttendance['total_records'] ?? 0)
);
$previousAttendanceRate = percentage(
    (int) ($previousWeekAttendance['attended_records'] ?? 0),
    (int) ($previousWeekAttendance['total_records'] ?? 0)
);

$classSessionsStatement = $pdo->prepare(
    'SELECT COUNT(DISTINCT date)
     FROM attendance
     WHERE class_id = :class_id'
);
$classSessionsStatement->execute([
    'class_id' => $classId,
]);
$totalMarkedSessions = (int) $classSessionsStatement->fetchColumn();

$atRiskStudentsStatement = $pdo->prepare(
    'SELECT
        s.id AS student_id,
        s.student_id AS student_code,
        s.first_name,
        s.last_name,
        SUM(CASE WHEN a.status = \'Absent\' THEN 1 ELSE 0 END) AS absence_count,
        SUM(CASE WHEN a.status IN (\'Present\', \'Late\') THEN 1 ELSE 0 END) AS attended_count
     FROM students s
     LEFT JOIN attendance a
        ON a.student_id = s.id
       AND a.class_id = :class_id
     WHERE s.course_id = :course_id
       AND s.year_level_id = :year_level_id
       AND s.section_id = :section_id
     GROUP BY s.id, s.student_id, s.first_name, s.last_name
     ORDER BY absence_count DESC, attended_count ASC, s.last_name ASC, s.first_name ASC
     LIMIT 10'
);
$atRiskStudentsStatement->execute([
    'class_id' => $classId,
    'course_id' => (int) ($classMeta['course_id'] ?? 0),
    'year_level_id' => (int) ($classMeta['year_level_id'] ?? 0),
    'section_id' => (int) ($classMeta['section_id'] ?? 0),
]);

$atRiskStudents = array_map(
    static function (array $row) use ($totalMarkedSessions): array {
        $attendanceRate = (int) round(percentage(
            (int) ($row['attended_count'] ?? 0),
            $totalMarkedSessions
        ));

        return [
            'studentId' => (int) ($row['student_id'] ?? 0),
            'studentCode' => (string) ($row['student_code'] ?? ''),
            'fullName' => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
            'absences' => (int) ($row['absence_count'] ?? 0),
            'attendanceRate' => $attendanceRate,
        ];
    },
    $atRiskStudentsStatement->fetchAll()
);

$atRiskStudents = array_values(array_filter(
    $atRiskStudents,
    static fn (array $student): bool => $student['attendanceRate'] < 80
));

json_response([
    'assignments' => $assignments,
    'selectedClassId' => $classId,
    'summary' => [
        'attendanceRate' => $currentAttendanceRate,
        'attendanceRateDelta' => round($currentAttendanceRate - $previousAttendanceRate, 1),
        'totalStudents' => $totalStudents,
    ],
    'atRiskStudents' => $atRiskStudents,
]);
