<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/http.php';

handle_cors();
require_method('GET');
require_auth();

function percentage(int $numerator, int $denominator): float
{
    if ($denominator <= 0) {
        return 0.0;
    }

    return round(($numerator / $denominator) * 100, 1);
}

function format_signed_percentage(float $value): float
{
    return round($value, 1);
}

$pdo = database();
$today = new DateTimeImmutable('today');
$currentWeekStart = $today->modify('monday this week');
$previousWeekStart = $currentWeekStart->modify('-7 days');
$previousWeekEnd = $currentWeekStart->modify('-1 day');

$totalStudents = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();

$todaySummaryStatement = $pdo->prepare(
    'SELECT status, COUNT(*) AS total
     FROM attendance
     WHERE date = :today
     GROUP BY status'
);
$todaySummaryStatement->execute([
    'today' => $today->format('Y-m-d'),
]);

$dashboardSummary = [
    'totalStudents' => $totalStudents,
    'presentToday' => 0,
    'absentToday' => 0,
    'lateToday' => 0,
];

foreach ($todaySummaryStatement->fetchAll() as $row) {
    $status = (string) ($row['status'] ?? '');
    $total = (int) ($row['total'] ?? 0);

    if ($status === 'Present') {
        $dashboardSummary['presentToday'] = $total;
    } elseif ($status === 'Absent') {
        $dashboardSummary['absentToday'] = $total;
    } elseif ($status === 'Late') {
        $dashboardSummary['lateToday'] = $total;
    }
}

$rowsStatement = $pdo->query(
    'SELECT
        s.id AS student_id,
        s.student_id AS student_code,
        s.first_name,
        s.last_name,
        c.code AS course_code,
        c.name AS course_name,
        yl.name AS year_level_name,
        sec.name AS section_name,
        latest_attendance.date AS attendance_date,
        latest_attendance.status AS attendance_status
     FROM students s
     LEFT JOIN courses c ON c.id = s.course_id
     LEFT JOIN year_levels yl ON yl.id = s.year_level_id
     LEFT JOIN sections sec ON sec.id = s.section_id
     LEFT JOIN attendance latest_attendance ON latest_attendance.id = (
         SELECT a2.id
         FROM attendance a2
         WHERE a2.student_id = s.id
         ORDER BY a2.date DESC, a2.id DESC
         LIMIT 1
     )
     ORDER BY s.first_name ASC, s.last_name ASC, s.id ASC'
);

$rows = array_map(
    static function (array $row): array {
        $firstName = (string) ($row['first_name'] ?? '');
        $lastName = (string) ($row['last_name'] ?? '');
        $date = $row['attendance_date'] ? date('M j, Y', strtotime((string) $row['attendance_date'])) : null;

        return [
            'studentId' => (int) ($row['student_id'] ?? 0),
            'studentCode' => (string) ($row['student_code'] ?? ''),
            'fullName' => trim($firstName . ' ' . $lastName),
            'course' => (string) (($row['course_code'] ?? '') !== '' ? $row['course_code'] : ($row['course_name'] ?? '')),
            'yearSection' => trim((string) ($row['year_level_name'] ?? '') . ' - ' . (string) ($row['section_name'] ?? '')),
            'date' => $date,
            'status' => (string) ($row['attendance_status'] ?? 'No Record'),
        ];
    },
    $rowsStatement->fetchAll()
);

$attendanceSummaryStatement = $pdo->prepare(
    'SELECT
        COUNT(*) AS total_records,
        SUM(CASE WHEN status IN (\'Present\', \'Late\') THEN 1 ELSE 0 END) AS attended_records,
        SUM(CASE WHEN status = \'Late\' THEN 1 ELSE 0 END) AS late_records
     FROM attendance
     WHERE date BETWEEN :start_date AND :end_date'
);

$attendanceSummaryStatement->execute([
    'start_date' => $currentWeekStart->format('Y-m-d'),
    'end_date' => $today->format('Y-m-d'),
]);
$currentWeekAttendance = $attendanceSummaryStatement->fetch() ?: [];

$attendanceSummaryStatement->execute([
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

$absenceBreakdownStatement = $pdo->query(
    'SELECT
        s.id AS student_id,
        s.student_id AS student_code,
        s.first_name,
        s.last_name,
        COALESCE(NULLIF(c.code, \'\'), c.name, \'Unassigned\') AS course_label,
        SUM(CASE WHEN a.status = \'Absent\' THEN 1 ELSE 0 END) AS absence_count,
        SUM(CASE WHEN a.status IN (\'Present\', \'Late\') THEN 1 ELSE 0 END) AS attended_count,
        COUNT(a.id) AS total_count
     FROM students s
     LEFT JOIN courses c ON c.id = s.course_id
     LEFT JOIN attendance a ON a.student_id = s.id
     GROUP BY s.id, s.student_id, s.first_name, s.last_name, course_label
     HAVING COUNT(a.id) > 0
     ORDER BY absence_count DESC, attended_count ASC, s.last_name ASC, s.first_name ASC
     LIMIT 5'
);

$absenceBreakdown = array_map(
    static function (array $row): array {
        $firstName = (string) ($row['first_name'] ?? '');
        $lastName = (string) ($row['last_name'] ?? '');
        $attendedCount = (int) ($row['attended_count'] ?? 0);
        $totalCount = (int) ($row['total_count'] ?? 0);

        return [
            'studentId' => (int) ($row['student_id'] ?? 0),
            'studentCode' => (string) ($row['student_code'] ?? ''),
            'fullName' => trim($firstName . ' ' . $lastName),
            'course' => (string) ($row['course_label'] ?? 'Unassigned'),
            'absences' => (int) ($row['absence_count'] ?? 0),
            'attendanceRate' => (int) round(percentage($attendedCount, $totalCount)),
        ];
    },
    $absenceBreakdownStatement->fetchAll()
);

$courseStatsStatement = $pdo->prepare(
    'SELECT
        c.id AS course_id,
        COALESCE(NULLIF(c.code, \'\'), c.name) AS course_label,
        COUNT(DISTINCT s.id) AS student_count,
        SUM(CASE WHEN a.status IN (\'Present\', \'Late\') THEN 1 ELSE 0 END) AS attended_records,
        COUNT(a.id) AS total_records,
        SUM(CASE WHEN a.date BETWEEN :current_start AND :current_end AND a.status IN (\'Present\', \'Late\') THEN 1 ELSE 0 END) AS current_attended,
        SUM(CASE WHEN a.date BETWEEN :current_start AND :current_end THEN 1 ELSE 0 END) AS current_total,
        SUM(CASE WHEN a.date BETWEEN :previous_start AND :previous_end AND a.status IN (\'Present\', \'Late\') THEN 1 ELSE 0 END) AS previous_attended,
        SUM(CASE WHEN a.date BETWEEN :previous_start AND :previous_end THEN 1 ELSE 0 END) AS previous_total
     FROM courses c
     LEFT JOIN students s ON s.course_id = c.id
     LEFT JOIN attendance a ON a.student_id = s.id
     GROUP BY c.id, course_label
     HAVING COUNT(DISTINCT s.id) > 0
     ORDER BY course_label ASC'
);
$courseStatsStatement->execute([
    'current_start' => $currentWeekStart->format('Y-m-d'),
    'current_end' => $today->format('Y-m-d'),
    'previous_start' => $previousWeekStart->format('Y-m-d'),
    'previous_end' => $previousWeekEnd->format('Y-m-d'),
]);

$courseStats = array_map(
    static function (array $row): array {
        $overallRate = percentage(
            (int) ($row['attended_records'] ?? 0),
            (int) ($row['total_records'] ?? 0)
        );
        $currentRate = percentage(
            (int) ($row['current_attended'] ?? 0),
            (int) ($row['current_total'] ?? 0)
        );
        $previousRate = percentage(
            (int) ($row['previous_attended'] ?? 0),
            (int) ($row['previous_total'] ?? 0)
        );
        $trendDelta = format_signed_percentage($currentRate - $previousRate);

        return [
            'course' => (string) ($row['course_label'] ?? 'Unknown'),
            'studentCount' => (int) ($row['student_count'] ?? 0),
            'attendanceRate' => (int) round($overallRate),
            'trendDirection' => $trendDelta < 0 ? 'down' : 'up',
            'trendDelta' => abs($trendDelta),
        ];
    },
    $courseStatsStatement->fetchAll()
);

json_response([
    'summary' => $dashboardSummary,
    'rows' => $rows,
    'dateLabel' => $today->format('F j, Y'),
    'generatedAt' => $today->format('F j, Y'),
    'reportsSummary' => [
        'attendanceRate' => $currentAttendanceRate,
        'attendanceRateDelta' => format_signed_percentage($currentAttendanceRate - $previousAttendanceRate),
        'lateArrivalsThisWeek' => (int) ($currentWeekAttendance['late_records'] ?? 0),
    ],
    'absenceBreakdown' => $absenceBreakdown,
    'courseStats' => $courseStats,
]);
