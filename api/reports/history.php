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

    return min($value, 30);
}

function create_placeholders(int $count): string
{
    return implode(', ', array_fill(0, $count, '?'));
}

$user = require_auth();

if (strtolower((string) ($user['role'] ?? '')) !== 'admin') {
    json_response(['message' => 'Only admins can access attendance history.'], 403);
}

$pdo = database();
$limit = parse_limit();

$datesStatement = $pdo->prepare(
    'SELECT DISTINCT date
     FROM attendance
     ORDER BY date DESC
     LIMIT :limit'
);
$datesStatement->bindValue(':limit', $limit, PDO::PARAM_INT);
$datesStatement->execute();

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

$placeholders = create_placeholders(count($dates));

$summaryStatement = $pdo->prepare(
    "SELECT
        date,
        SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) AS present_count,
        SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) AS late_count,
        SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) AS absent_count,
        COUNT(*) AS total_count
     FROM attendance
     WHERE date IN ($placeholders)
     GROUP BY date"
);
$summaryStatement->execute($dates);

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

$attentionStatement = $pdo->prepare(
    "SELECT
        a.date,
        s.id AS student_id,
        s.student_id AS student_code,
        s.first_name,
        s.last_name,
        COALESCE(NULLIF(c.code, ''), c.name, 'Unassigned') AS course_label,
        yl.name AS year_level_name,
        sec.name AS section_name,
        a.status
     FROM attendance a
     INNER JOIN students s ON s.id = a.student_id
     LEFT JOIN courses c ON c.id = s.course_id
     LEFT JOIN year_levels yl ON yl.id = s.year_level_id
     LEFT JOIN sections sec ON sec.id = s.section_id
     WHERE a.date IN ($placeholders)
       AND a.status <> 'Present'
     ORDER BY a.date DESC, course_label ASC, yl.name ASC, sec.name ASC, s.last_name ASC, s.first_name ASC"
);
$attentionStatement->execute($dates);

$attentionRowsByDate = [];

foreach ($attentionStatement->fetchAll() as $row) {
    $date = (string) ($row['date'] ?? '');
    $attentionRowsByDate[$date][] = [
        'studentId' => (int) ($row['student_id'] ?? 0),
        'studentCode' => (string) ($row['student_code'] ?? ''),
        'fullName' => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
        'course' => (string) ($row['course_label'] ?? 'Unassigned'),
        'yearSection' => trim((string) ($row['year_level_name'] ?? '') . ' - ' . (string) ($row['section_name'] ?? '')),
        'status' => (string) ($row['status'] ?? ''),
    ];
}

$history = array_map(
    static function (string $date) use ($summariesByDate, $attentionRowsByDate): array {
        return [
            'date' => $date,
            'dateLabel' => date('F j, Y', strtotime($date)),
            'summary' => $summariesByDate[$date] ?? [
                'presentCount' => 0,
                'lateCount' => 0,
                'absentCount' => 0,
                'totalRecords' => 0,
                'attendanceRate' => 0,
            ],
            'attentionRows' => $attentionRowsByDate[$date] ?? [],
        ];
    },
    $dates
);

json_response([
    'availableDates' => $dates,
    'history' => $history,
]);
