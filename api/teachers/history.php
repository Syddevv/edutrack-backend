<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/http.php';

handle_cors();
require_method('GET');

function percentage(int $numerator, int $denominator): float
{
    if ($denominator <= 0) {
        return 0.0;
    }

    return round(($numerator / $denominator) * 100, 1);
}

function parse_limit(): int
{
    $value = isset($_GET['limit']) ? (int) $_GET['limit'] : 7;

    if ($value <= 0) {
        return 7;
    }

    return min($value, 21);
}

function create_placeholders(int $count): string
{
    return implode(', ', array_fill(0, $count, '?'));
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

function class_name_from_row(array $row): string
{
    return implode(' - ', array_filter([
        (string) ($row['course_label'] ?? ''),
        (string) ($row['year_level_name'] ?? ''),
        (string) ($row['section_name'] ?? ''),
        (string) ($row['subject'] ?? ''),
    ]));
}

$user = require_auth();
$pdo = database();
$teacherId = find_teacher_id($pdo, (string) ($user['email'] ?? ''));
$limit = parse_limit();

$classIdsStatement = $pdo->prepare(
    'SELECT DISTINCT class_id
     FROM teacher_classes
     WHERE teacher_id = :teacher_id'
);
$classIdsStatement->execute(['teacher_id' => $teacherId]);

$classIds = array_map(
    static fn (mixed $value): int => (int) $value,
    $classIdsStatement->fetchAll(PDO::FETCH_COLUMN)
);

if ($classIds === []) {
    json_response([
        'availableDates' => [],
        'history' => [],
    ]);
}

$classPlaceholders = create_placeholders(count($classIds));

$datesStatement = $pdo->prepare(
    "SELECT DISTINCT date
     FROM attendance
     WHERE class_id IN ($classPlaceholders)
     ORDER BY date DESC
     LIMIT $limit"
);
$datesStatement->execute($classIds);

$dates = array_map(
    static fn (mixed $date): string => (string) $date,
    $datesStatement->fetchAll(PDO::FETCH_COLUMN)
);

if ($dates === []) {
    json_response([
        'availableDates' => [],
        'history' => [],
    ]);
}

$datePlaceholders = create_placeholders(count($dates));

$summaryStatement = $pdo->prepare(
    "SELECT
        date,
        SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) AS present_count,
        SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) AS late_count,
        SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) AS absent_count,
        COUNT(*) AS total_count
     FROM attendance
     WHERE class_id IN ($classPlaceholders)
       AND date IN ($datePlaceholders)
     GROUP BY date"
);
$summaryStatement->execute([...$classIds, ...$dates]);

$summariesByDate = [];

foreach ($summaryStatement->fetchAll() as $row) {
    $date = (string) ($row['date'] ?? '');
    $presentCount = (int) ($row['present_count'] ?? 0);
    $lateCount = (int) ($row['late_count'] ?? 0);
    $absentCount = (int) ($row['absent_count'] ?? 0);
    $totalCount = (int) ($row['total_count'] ?? 0);

    $summariesByDate[$date] = [
        'presentCount' => $presentCount,
        'lateCount' => $lateCount,
        'absentCount' => $absentCount,
        'totalRecords' => $totalCount,
        'attendanceRate' => percentage($presentCount + $lateCount, $totalCount),
    ];
}

$recordsStatement = $pdo->prepare(
    "SELECT
        a.date,
        a.class_id,
        a.status,
        s.id AS student_id,
        s.student_id AS student_code,
        s.first_name,
        s.last_name,
        cls.subject,
        COALESCE(NULLIF(course.code, ''), course.name) AS course_label,
        yl.name AS year_level_name,
        sec.name AS section_name
     FROM attendance a
     INNER JOIN students s ON s.id = a.student_id
     INNER JOIN classes cls ON cls.id = a.class_id
     LEFT JOIN courses course ON course.id = cls.course_id
     LEFT JOIN year_levels yl ON yl.id = cls.year_level_id
     LEFT JOIN sections sec ON sec.id = cls.section_id
     WHERE a.class_id IN ($classPlaceholders)
       AND a.date IN ($datePlaceholders)
     ORDER BY a.date DESC, course_label ASC, yl.name ASC, sec.name ASC, cls.subject ASC, s.last_name ASC, s.first_name ASC"
);
$recordsStatement->execute([...$classIds, ...$dates]);

$historyMap = [];

foreach ($dates as $date) {
    $historyMap[$date] = [
        'date' => $date,
        'dateLabel' => date('F j, Y', strtotime($date)),
        'summary' => $summariesByDate[$date] ?? [
            'presentCount' => 0,
            'lateCount' => 0,
            'absentCount' => 0,
            'totalRecords' => 0,
            'attendanceRate' => 0,
        ],
        'classes' => [],
    ];
}

foreach ($recordsStatement->fetchAll() as $row) {
    $date = (string) ($row['date'] ?? '');
    $classId = (int) ($row['class_id'] ?? 0);

    if (!isset($historyMap[$date])) {
        continue;
    }

    if (!isset($historyMap[$date]['classes'][$classId])) {
        $historyMap[$date]['classes'][$classId] = [
            'classId' => $classId,
            'className' => class_name_from_row($row),
            'statusCounts' => [
                'Present' => 0,
                'Late' => 0,
                'Absent' => 0,
            ],
            'students' => [],
        ];
    }

    $status = (string) ($row['status'] ?? '');

    if (isset($historyMap[$date]['classes'][$classId]['statusCounts'][$status])) {
        $historyMap[$date]['classes'][$classId]['statusCounts'][$status]++;
    }

    $historyMap[$date]['classes'][$classId]['students'][] = [
        'studentId' => (int) ($row['student_id'] ?? 0),
        'studentCode' => (string) ($row['student_code'] ?? ''),
        'fullName' => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
        'status' => $status,
    ];
}

$history = array_map(
    static function (string $date) use ($historyMap): array {
        $entry = $historyMap[$date];
        $entry['classes'] = array_values($entry['classes']);
        return $entry;
    },
    $dates
);

json_response([
    'availableDates' => $dates,
    'history' => $history,
]);
