<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/http.php';

handle_cors();
require_method('GET');
$user = require_auth();

const SCHEDULE_DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const APP_TIMEZONE = 'Asia/Manila';

function percentage(int $numerator, int $denominator): float
{
    if ($denominator <= 0) {
        return 0.0;
    }

    return round(($numerator / $denominator) * 100, 1);
}

function format_time(string $value): string
{
    return date('h:i A', strtotime($value));
}

function class_label(array $class): string
{
    return trim((string) ($class['subject'] ?? ''));
}

function schedule_day_index(?string $dayOfWeek): ?int
{
    if ($dayOfWeek === null) {
        return null;
    }

    $index = array_search($dayOfWeek, SCHEDULE_DAYS, true);

    return $index === false ? null : (int) $index;
}

function schedule_occurrence_start(array $scheduleRow, DateTimeImmutable $now, DateTimeZone $timezone): ?DateTimeImmutable
{
    $dayIndex = schedule_day_index($scheduleRow['dayOfWeek'] ?? null);

    if ($dayIndex === null) {
        return null;
    }

    $currentDayIndex = schedule_day_index($now->format('l'));

    if ($currentDayIndex === null) {
        return null;
    }

    $daysUntilClass = $dayIndex - $currentDayIndex;

    if ($daysUntilClass < 0 || ($daysUntilClass === 0 && $scheduleRow['startTimeRaw'] <= $now->format('H:i:s'))) {
        $daysUntilClass += count(SCHEDULE_DAYS);
    }

    $classDate = $now->setTime(0, 0)->modify(sprintf('+%d days', $daysUntilClass));

    return new DateTimeImmutable(
        $classDate->format('Y-m-d') . ' ' . $scheduleRow['startTimeRaw'],
        $timezone
    );
}

function build_ai_insight(PDO $pdo, ?array $selectedClass, ?array $classMeta, array $summary, ?string $attendanceDate): ?array
{
    if ($selectedClass === null || $classMeta === null) {
        return [
            'title' => 'Attendance Snapshot',
            'body' => 'No class data is ready yet. Once attendance is recorded for one of your classes, this card will highlight trends to review.',
        ];
    }

    $courseId = (int) ($classMeta['course_id'] ?? 0);
    $yearLevelId = (int) ($classMeta['year_level_id'] ?? 0);
    $sectionId = (int) ($classMeta['section_id'] ?? 0);
    $classId = (int) ($selectedClass['classId'] ?? 0);

    if ($courseId <= 0 || $yearLevelId <= 0 || $sectionId <= 0 || $classId <= 0) {
        return [
            'title' => 'Attendance Snapshot',
            'body' => 'Class details are incomplete, so there is not enough data to generate an attendance insight yet.',
        ];
    }

    $referenceDate = $attendanceDate ?? date('Y-m-d');
    $windowStart = date('Y-m-d', strtotime($referenceDate . ' -6 days'));

    $patternStatement = $pdo->prepare(
        "SELECT
            s.id AS student_id,
            s.first_name,
            s.last_name,
            SUM(CASE WHEN a.status = 'Late' THEN 1 ELSE 0 END) AS late_count,
            SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN a.status IN ('Present', 'Late') THEN 1 ELSE 0 END) AS attended_count,
            COUNT(a.id) AS total_count
         FROM students s
         LEFT JOIN attendance a
           ON a.student_id = s.id
          AND a.class_id = :class_id
          AND a.date BETWEEN :window_start AND :window_end
         WHERE s.course_id = :course_id
           AND s.year_level_id = :year_level_id
           AND s.section_id = :section_id
         GROUP BY s.id, s.first_name, s.last_name
         ORDER BY s.last_name ASC, s.first_name ASC"
    );
    $patternStatement->execute([
        'class_id' => $classId,
        'window_start' => $windowStart,
        'window_end' => $referenceDate,
        'course_id' => $courseId,
        'year_level_id' => $yearLevelId,
        'section_id' => $sectionId,
    ]);
    $studentPatterns = $patternStatement->fetchAll();

    $lateLeader = null;
    $absenceLeader = null;
    $lowestRateStudent = null;

    foreach ($studentPatterns as $row) {
        $fullName = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        $lateCount = (int) ($row['late_count'] ?? 0);
        $absentCount = (int) ($row['absent_count'] ?? 0);
        $attendedCount = (int) ($row['attended_count'] ?? 0);
        $totalCount = (int) ($row['total_count'] ?? 0);
        $attendanceRate = percentage($attendedCount, $totalCount);

        $candidate = [
            'fullName' => $fullName !== '' ? $fullName : 'This student',
            'lateCount' => $lateCount,
            'absentCount' => $absentCount,
            'attendanceRate' => $attendanceRate,
            'totalCount' => $totalCount,
        ];

        if ($lateCount > 0 && ($lateLeader === null || $lateCount > $lateLeader['lateCount'])) {
            $lateLeader = $candidate;
        }

        if ($absentCount > 0 && ($absenceLeader === null || $absentCount > $absenceLeader['absentCount'])) {
            $absenceLeader = $candidate;
        }

        if (
            $totalCount >= 2 &&
            ($lowestRateStudent === null || $attendanceRate < $lowestRateStudent['attendanceRate'])
        ) {
            $lowestRateStudent = $candidate;
        }
    }

    if ($lateLeader !== null && $lateLeader['lateCount'] >= 3) {
        return [
            'title' => 'Attendance Alert',
            'body' => sprintf(
                '%s has been late %d times in the last 7 days. A quick check-in may help before this pattern affects class participation.',
                $lateLeader['fullName'],
                $lateLeader['lateCount']
            ),
        ];
    }

    if ($absenceLeader !== null && $absenceLeader['absentCount'] >= 2) {
        return [
            'title' => 'Absence Trend',
            'body' => sprintf(
                '%s has %d absences in the last 7 days for %s. It would be worth following up on what is blocking attendance.',
                $absenceLeader['fullName'],
                $absenceLeader['absentCount'],
                class_label($selectedClass)
            ),
        ];
    }

    if ($lowestRateStudent !== null && $lowestRateStudent['attendanceRate'] < 80) {
        return [
            'title' => 'Support Needed',
            'body' => sprintf(
                '%s is currently at %.1f%% attendance across recent %s records. This student may need closer follow-up.',
                $lowestRateStudent['fullName'],
                $lowestRateStudent['attendanceRate'],
                class_label($selectedClass)
            ),
        ];
    }

    if (($summary['absentCount'] ?? 0) > 0) {
        return [
            'title' => 'Daily Attendance',
            'body' => sprintf(
                '%d student%s marked absent in %s. Reviewing today\'s follow-up list would be the next useful step.',
                (int) ($summary['absentCount'] ?? 0),
                (int) ($summary['absentCount'] ?? 0) === 1 ? ' was' : 's were',
                class_label($selectedClass)
            ),
        ];
    }

    return [
        'title' => 'Positive Trend',
        'body' => sprintf(
            '%s is at %.1f%% attendance with no urgent lateness or absence pattern in the latest records.',
            class_label($selectedClass),
            (float) ($summary['attendanceRate'] ?? 0)
        ),
    ];
}

$pdo = database();
$teacherStatement = $pdo->prepare(
    'SELECT id, full_name, email
     FROM teachers
     WHERE email = :email
     LIMIT 1'
);
$teacherStatement->execute([
    'email' => (string) ($user['email'] ?? ''),
]);
$teacher = $teacherStatement->fetch();

if (!$teacher) {
    json_response(['message' => 'Teacher profile not found.'], 404);
}

$scheduleStatement = $pdo->prepare(
    "SELECT
        tc.id AS schedule_id,
        tc.class_id,
        tc.start_time,
        tc.end_time,
        tc.day_of_week,
        c.subject,
        course.code AS course_code,
        course.name AS course_name,
        yl.name AS year_level_name,
        sec.name AS section_name
     FROM teacher_classes tc
     INNER JOIN classes c ON c.id = tc.class_id
     LEFT JOIN courses course ON course.id = c.course_id
     LEFT JOIN year_levels yl ON yl.id = c.year_level_id
     LEFT JOIN sections sec ON sec.id = c.section_id
     WHERE tc.teacher_id = :teacher_id
     ORDER BY FIELD(tc.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
              tc.start_time ASC,
              tc.end_time ASC,
              tc.id ASC"
);
$scheduleStatement->execute([
    'teacher_id' => (int) $teacher['id'],
]);

$scheduleRows = array_map(
    static function (array $row): array {
        return [
            'scheduleId' => (int) ($row['schedule_id'] ?? 0),
            'classId' => (int) ($row['class_id'] ?? 0),
            'subject' => (string) ($row['subject'] ?? ''),
            'course' => (string) (($row['course_code'] ?? '') !== '' ? $row['course_code'] : ($row['course_name'] ?? '')),
            'yearLevel' => (string) ($row['year_level_name'] ?? ''),
            'section' => (string) ($row['section_name'] ?? ''),
            'dayOfWeek' => $row['day_of_week'] !== null ? (string) $row['day_of_week'] : null,
            'startTimeRaw' => (string) ($row['start_time'] ?? '00:00:00'),
            'endTimeRaw' => (string) ($row['end_time'] ?? '00:00:00'),
        ];
    },
    $scheduleStatement->fetchAll()
);

$timezone = new DateTimeZone(APP_TIMEZONE);
$now = new DateTimeImmutable('now', $timezone);
$currentTime = $now->format('H:i:s');
$currentDay = $now->format('l');

$todaySchedule = array_values(array_filter(
    $scheduleRows,
    static fn (array $row): bool => $row['dayOfWeek'] === $currentDay
));
$todayClass = null;
$nextClass = null;

foreach ($todaySchedule as $scheduleRow) {
    if ($todayClass === null && $scheduleRow['startTimeRaw'] <= $currentTime && $scheduleRow['endTimeRaw'] >= $currentTime) {
        $todayClass = $scheduleRow;
    }

    if ($todayClass === null && $scheduleRow['startTimeRaw'] > $currentTime) {
        $todayClass = $scheduleRow;
        break;
    }
}

$nextClassStart = null;

foreach ($scheduleRows as $scheduleRow) {
    $candidateStart = schedule_occurrence_start($scheduleRow, $now, $timezone);

    if ($candidateStart === null) {
        continue;
    }

    if ($nextClassStart === null || $candidateStart < $nextClassStart) {
        $nextClass = $scheduleRow;
        $nextClassStart = $candidateStart;
    }
}

$scheduleRowsByClassId = [];
$assignedClassIds = [];

foreach ($scheduleRows as $scheduleRow) {
    $classId = (int) ($scheduleRow['classId'] ?? 0);

    if ($classId <= 0) {
        continue;
    }

    $scheduleRowsByClassId[$classId] = $scheduleRow;
    $assignedClassIds[$classId] = $classId;
}

$selectedClass = $todayClass;
$attendanceDate = null;
$previousAttendanceDate = null;
$classMeta = null;
$summary = [
    'totalStudents' => 0,
    'presentCount' => 0,
    'absentCount' => 0,
    'attendanceRate' => 0.0,
    'attendanceRateDelta' => 0.0,
];
$recentActivity = [];

if ($selectedClass !== null) {
    $classMetaStatement = $pdo->prepare(
        'SELECT course_id, year_level_id, section_id
         FROM classes
         WHERE id = :class_id
         LIMIT 1'
    );
    $classMetaStatement->execute([
        'class_id' => $selectedClass['classId'],
    ]);
    $classMeta = $classMetaStatement->fetch() ?: null;

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
        $summary['totalStudents'] = (int) $studentCountStatement->fetchColumn();
    }

    $attendanceDateStatement = $pdo->prepare(
        'SELECT DISTINCT date
         FROM attendance
         WHERE class_id = :class_id
         ORDER BY date DESC
         LIMIT 2'
    );
    $attendanceDateStatement->execute([
        'class_id' => $selectedClass['classId'],
    ]);
    $attendanceDates = $attendanceDateStatement->fetchAll(PDO::FETCH_COLUMN);
    $attendanceDate = $attendanceDates[0] ?? null;
    $previousAttendanceDate = $attendanceDates[1] ?? null;

    if ($attendanceDate !== null) {
        $summaryStatement = $pdo->prepare(
            'SELECT
                SUM(CASE WHEN status = \'Present\' THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN status = \'Absent\' THEN 1 ELSE 0 END) AS absent_count,
                SUM(CASE WHEN status IN (\'Present\', \'Late\') THEN 1 ELSE 0 END) AS attended_count,
                COUNT(*) AS total_count
             FROM attendance
             WHERE class_id = :class_id
               AND date = :attendance_date'
        );
        $summaryStatement->execute([
            'class_id' => $selectedClass['classId'],
            'attendance_date' => $attendanceDate,
        ]);
        $attendanceSummary = $summaryStatement->fetch() ?: [];

        $summary['presentCount'] = (int) ($attendanceSummary['attended_count'] ?? 0);
        $summary['absentCount'] = (int) ($attendanceSummary['absent_count'] ?? 0);
        $summary['attendanceRate'] = percentage(
            (int) ($attendanceSummary['attended_count'] ?? 0),
            (int) ($attendanceSummary['total_count'] ?? 0)
        );

        if ($previousAttendanceDate !== null) {
            $summaryStatement->execute([
                'class_id' => $selectedClass['classId'],
                'attendance_date' => $previousAttendanceDate,
            ]);
            $previousSummary = $summaryStatement->fetch() ?: [];

            $previousRate = percentage(
                (int) ($previousSummary['attended_count'] ?? 0),
                (int) ($previousSummary['total_count'] ?? 0)
            );
            $summary['attendanceRateDelta'] = round($summary['attendanceRate'] - $previousRate, 1);
        }

        $recentActivityStatement = $pdo->prepare(
            'SELECT
                s.id AS student_id,
                s.student_id AS student_code,
                s.first_name,
                s.last_name,
                a.status
             FROM attendance a
             INNER JOIN students s ON s.id = a.student_id
             WHERE a.class_id = :class_id
               AND a.date = :attendance_date
             ORDER BY s.last_name ASC, s.first_name ASC
             LIMIT 8'
        );
        $recentActivityStatement->execute([
            'class_id' => $selectedClass['classId'],
            'attendance_date' => $attendanceDate,
        ]);

        $recentActivity = array_map(
            static function (array $row) use ($selectedClass): array {
                return [
                    'studentId' => (int) ($row['student_id'] ?? 0),
                    'studentCode' => (string) ($row['student_code'] ?? ''),
                    'fullName' => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
                    'status' => (string) ($row['status'] ?? 'No Record'),
                    'className' => class_label($selectedClass),
                ];
            },
            $recentActivityStatement->fetchAll()
        );
    }
}

if ($recentActivity === [] && count($assignedClassIds) > 0) {
    $placeholders = implode(', ', array_fill(0, count($assignedClassIds), '?'));
    $fallbackRecentActivityStatement = $pdo->prepare(
        "SELECT
            s.id AS student_id,
            s.student_id AS student_code,
            s.first_name,
            s.last_name,
            a.status,
            a.class_id,
            a.date
         FROM attendance a
         INNER JOIN students s ON s.id = a.student_id
         INNER JOIN (
            SELECT class_id, MAX(date) AS latest_date
            FROM attendance
            WHERE class_id IN ($placeholders)
            GROUP BY class_id
         ) latest
           ON latest.class_id = a.class_id
          AND latest.latest_date = a.date
         WHERE a.class_id IN ($placeholders)
         ORDER BY a.date DESC, a.class_id ASC, s.last_name ASC, s.first_name ASC
         LIMIT 8"
    );
    $fallbackParams = array_values($assignedClassIds);
    $fallbackRecentActivityStatement->execute([
        ...$fallbackParams,
        ...$fallbackParams,
    ]);

    $recentActivity = array_map(
        static function (array $row) use ($scheduleRowsByClassId): array {
            $classId = (int) ($row['class_id'] ?? 0);
            $classRow = $scheduleRowsByClassId[$classId] ?? ['subject' => 'Unknown Class'];

            return [
                'studentId' => (int) ($row['student_id'] ?? 0),
                'studentCode' => (string) ($row['student_code'] ?? ''),
                'fullName' => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
                'status' => (string) ($row['status'] ?? 'No Record'),
                'className' => class_label($classRow),
            ];
        },
        $fallbackRecentActivityStatement->fetchAll()
    );
}

$aiInsight = build_ai_insight($pdo, $selectedClass, $classMeta, $summary, $attendanceDate);

$todayClassPayload = null;

if ($todayClass !== null) {
    $todayClassPayload = [
        'scheduleId' => $todayClass['scheduleId'],
        'classId' => $todayClass['classId'],
        'subject' => $todayClass['subject'],
        'course' => $todayClass['course'],
        'yearLevel' => $todayClass['yearLevel'],
        'section' => $todayClass['section'],
        'dayOfWeek' => $todayClass['dayOfWeek'],
        'startTime' => format_time($todayClass['startTimeRaw']),
        'endTime' => format_time($todayClass['endTimeRaw']),
        'status' => $todayClass['startTimeRaw'] <= $currentTime && $todayClass['endTimeRaw'] >= $currentTime
            ? 'active'
            : ($todayClass['startTimeRaw'] > $currentTime ? 'upcoming' : 'completed'),
    ];
}

$nextClassPayload = null;

if ($nextClass !== null && $nextClassStart !== null) {
    $minutesRemaining = max(0, (int) floor(($nextClassStart->getTimestamp() - $now->getTimestamp()) / 60));
    $isNextClassToday = $nextClassStart->format('Y-m-d') === $now->format('Y-m-d');

    $nextClassPayload = [
        'scheduleId' => $nextClass['scheduleId'],
        'classId' => $nextClass['classId'],
        'subject' => $nextClass['subject'],
        'dayOfWeek' => $nextClass['dayOfWeek'],
        'time' => format_time($nextClass['startTimeRaw']),
        'minutesRemaining' => $minutesRemaining,
        'isToday' => $isNextClassToday,
    ];
}

json_response([
    'teacherName' => (string) ($teacher['full_name'] ?? ($user['name'] ?? '')),
    'dateLabel' => $now->format('F j, Y'),
    'attendanceDateLabel' => $attendanceDate !== null ? date('F j, Y', strtotime((string) $attendanceDate)) : null,
    'aiInsight' => $aiInsight,
    'todayClass' => $todayClassPayload,
    'nextClass' => $nextClassPayload,
    'summary' => $summary,
    'recentActivity' => $recentActivity,
]);
