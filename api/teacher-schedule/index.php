<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/http.php';

handle_cors(['http://localhost:5173']);

const SCHEDULE_DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

function normalize_time(string $value): string
{
    $trimmed = trim($value);

    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $trimmed)) {
        json_response(['message' => 'Schedule times must use HH:MM format.'], 422);
    }

    return $trimmed . ':00';
}

function normalize_day(?string $value): string
{
    $day = trim((string) $value);

    if (!in_array($day, SCHEDULE_DAYS, true)) {
        json_response(['message' => 'Day of week must be Monday through Saturday.'], 422);
    }

    return $day;
}

function teacher_schedule_has_room_column(PDO $pdo): bool
{
    static $hasRoomColumn = null;

    if ($hasRoomColumn !== null) {
        return $hasRoomColumn;
    }

    $statement = $pdo->query('DESCRIBE teacher_classes');

    foreach ($statement->fetchAll() as $row) {
        if (($row['Field'] ?? '') === 'room') {
            $hasRoomColumn = true;
            return true;
        }
    }

    $hasRoomColumn = false;
    return false;
}

function fetch_lookup_options(PDO $pdo, string $table, bool $includeCode = false): array
{
    $columns = $includeCode ? 'id, name, code' : 'id, name';
    $statement = $pdo->query("SELECT {$columns} FROM {$table} ORDER BY name ASC");

    return array_map(
        static function (array $row) use ($includeCode): array {
            $payload = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];

            if ($includeCode) {
                $payload['code'] = (string) ($row['code'] ?? '');
            }

            return $payload;
        },
        $statement->fetchAll()
    );
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

function find_or_create_class(PDO $pdo, array $payload): int
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
        'course_id' => $payload['courseId'],
        'year_level_id' => $payload['yearLevelId'],
        'section_id' => $payload['sectionId'],
        'subject' => $payload['subject'],
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
        'course_id' => $payload['courseId'],
        'year_level_id' => $payload['yearLevelId'],
        'section_id' => $payload['sectionId'],
        'subject' => $payload['subject'],
    ]);

    return (int) $pdo->lastInsertId();
}

function validate_schedule_payload(array $payload): array
{
    $subject = trim((string) ($payload['subject'] ?? ''));
    $courseId = (int) ($payload['courseId'] ?? 0);
    $yearLevelId = (int) ($payload['yearLevelId'] ?? 0);
    $sectionId = (int) ($payload['sectionId'] ?? 0);
    $dayOfWeek = normalize_day($payload['dayOfWeek'] ?? null);
    $startTime = normalize_time((string) ($payload['startTime'] ?? ''));
    $endTime = normalize_time((string) ($payload['endTime'] ?? ''));
    $room = trim((string) ($payload['room'] ?? ''));

    if ($subject === '' || $courseId <= 0 || $yearLevelId <= 0 || $sectionId <= 0) {
        json_response(['message' => 'Subject, course, year level, and section are required.'], 422);
    }

    if ($startTime >= $endTime) {
        json_response(['message' => 'Class end time must be later than the start time.'], 422);
    }

    return [
        'subject' => $subject,
        'courseId' => $courseId,
        'yearLevelId' => $yearLevelId,
        'sectionId' => $sectionId,
        'dayOfWeek' => $dayOfWeek,
        'startTime' => $startTime,
        'endTime' => $endTime,
        'room' => $room,
    ];
}

function ensure_no_overlap(PDO $pdo, int $teacherId, string $dayOfWeek, string $startTime, string $endTime, ?int $ignoreScheduleId = null): void
{
    $query = '
        SELECT id
        FROM teacher_classes
        WHERE teacher_id = :teacher_id
          AND day_of_week = :day_of_week
          AND start_time < :end_time
          AND end_time > :start_time';

    $params = [
        'teacher_id' => $teacherId,
        'day_of_week' => $dayOfWeek,
        'start_time' => $startTime,
        'end_time' => $endTime,
    ];

    if ($ignoreScheduleId !== null) {
        $query .= ' AND id <> :schedule_id';
        $params['schedule_id'] = $ignoreScheduleId;
    }

    $query .= ' LIMIT 1';

    $statement = $pdo->prepare($query);
    $statement->execute($params);

    if ($statement->fetch()) {
        json_response(['message' => 'This class overlaps with another scheduled class on the same day.'], 422);
    }
}

function fetch_schedule_response(PDO $pdo, int $teacherId): array
{
    $hasRoomColumn = teacher_schedule_has_room_column($pdo);
    $roomSelect = $hasRoomColumn ? 'tc.room AS room,' : "'' AS room,";

    $statement = $pdo->prepare(
        "SELECT
            tc.id AS schedule_id,
            tc.class_id,
            tc.day_of_week,
            tc.start_time,
            tc.end_time,
            {$roomSelect}
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
         ORDER BY FIELD(tc.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
                  tc.start_time ASC,
                  tc.id ASC"
    );
    $statement->execute(['teacher_id' => $teacherId]);

    $groupedSchedule = array_fill_keys(SCHEDULE_DAYS, []);

    foreach ($statement->fetchAll() as $row) {
        $day = (string) ($row['day_of_week'] ?? '');

        if (!isset($groupedSchedule[$day])) {
            continue;
        }

        $groupedSchedule[$day][] = [
            'id' => (int) ($row['schedule_id'] ?? 0),
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
            'dayOfWeek' => $day,
            'room' => (string) ($row['room'] ?? ''),
            'startTime' => $row['start_time'] !== null ? substr((string) $row['start_time'], 0, 5) : '',
            'endTime' => $row['end_time'] !== null ? substr((string) $row['end_time'], 0, 5) : '',
        ];
    }

    return [
        'days' => SCHEDULE_DAYS,
        'schedule' => $groupedSchedule,
        'lookups' => [
            'subjects' => fetch_lookup_options($pdo, 'subjects', true),
            'courses' => fetch_lookup_options($pdo, 'courses', true),
            'yearLevels' => fetch_lookup_options($pdo, 'year_levels'),
            'sections' => fetch_lookup_options($pdo, 'sections'),
            'roomSupported' => $hasRoomColumn,
        ],
    ];
}

$user = require_auth();
$pdo = database();
$teacherId = find_teacher_id($pdo, (string) ($user['email'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    json_response(fetch_schedule_response($pdo, $teacherId));
}

$payload = json_input();

if ($method === 'POST') {
    $normalized = validate_schedule_payload($payload);
    ensure_no_overlap($pdo, $teacherId, $normalized['dayOfWeek'], $normalized['startTime'], $normalized['endTime']);

    try {
        $pdo->beginTransaction();

        $classId = find_or_create_class($pdo, $normalized);
        $hasRoomColumn = teacher_schedule_has_room_column($pdo);

        if ($hasRoomColumn) {
            $insert = $pdo->prepare(
                'INSERT INTO teacher_classes (teacher_id, class_id, start_time, end_time, day_of_week, room)
                 VALUES (:teacher_id, :class_id, :start_time, :end_time, :day_of_week, :room)'
            );
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO teacher_classes (teacher_id, class_id, start_time, end_time, day_of_week)
                 VALUES (:teacher_id, :class_id, :start_time, :end_time, :day_of_week)'
            );
        }

        $params = [
            'teacher_id' => $teacherId,
            'class_id' => $classId,
            'start_time' => $normalized['startTime'],
            'end_time' => $normalized['endTime'],
            'day_of_week' => $normalized['dayOfWeek'],
        ];

        if ($hasRoomColumn) {
            $params['room'] = $normalized['room'];
        }

        $insert->execute($params);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        json_response(['message' => 'Failed to add class to schedule.'], 500);
    }

    json_response(fetch_schedule_response($pdo, $teacherId), 201);
}

if ($method === 'PUT') {
    $scheduleId = (int) ($payload['scheduleId'] ?? 0);

    if ($scheduleId <= 0) {
        json_response(['message' => 'Schedule ID is required.'], 422);
    }

    $normalized = validate_schedule_payload($payload);

    $scheduleLookup = $pdo->prepare(
        'SELECT id
         FROM teacher_classes
         WHERE id = :schedule_id
           AND teacher_id = :teacher_id
         LIMIT 1'
    );
    $scheduleLookup->execute([
        'schedule_id' => $scheduleId,
        'teacher_id' => $teacherId,
    ]);

    if (!$scheduleLookup->fetch()) {
        json_response(['message' => 'Scheduled class not found.'], 404);
    }

    ensure_no_overlap($pdo, $teacherId, $normalized['dayOfWeek'], $normalized['startTime'], $normalized['endTime'], $scheduleId);

    try {
        $pdo->beginTransaction();

        $classId = find_or_create_class($pdo, $normalized);
        $hasRoomColumn = teacher_schedule_has_room_column($pdo);

        if ($hasRoomColumn) {
            $update = $pdo->prepare(
                'UPDATE teacher_classes
                 SET class_id = :class_id,
                     start_time = :start_time,
                     end_time = :end_time,
                     day_of_week = :day_of_week,
                     room = :room
                 WHERE id = :schedule_id
                   AND teacher_id = :teacher_id'
            );
        } else {
            $update = $pdo->prepare(
                'UPDATE teacher_classes
                 SET class_id = :class_id,
                     start_time = :start_time,
                     end_time = :end_time,
                     day_of_week = :day_of_week
                 WHERE id = :schedule_id
                   AND teacher_id = :teacher_id'
            );
        }

        $params = [
            'class_id' => $classId,
            'start_time' => $normalized['startTime'],
            'end_time' => $normalized['endTime'],
            'day_of_week' => $normalized['dayOfWeek'],
            'schedule_id' => $scheduleId,
            'teacher_id' => $teacherId,
        ];

        if ($hasRoomColumn) {
            $params['room'] = $normalized['room'];
        }

        $update->execute($params);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        json_response(['message' => 'Failed to update scheduled class.'], 500);
    }

    json_response(fetch_schedule_response($pdo, $teacherId));
}

if ($method === 'DELETE') {
    $scheduleId = (int) ($payload['scheduleId'] ?? 0);

    if ($scheduleId <= 0) {
        json_response(['message' => 'Schedule ID is required.'], 422);
    }

    $delete = $pdo->prepare(
        'DELETE FROM teacher_classes
         WHERE id = :schedule_id
           AND teacher_id = :teacher_id'
    );
    $delete->execute([
        'schedule_id' => $scheduleId,
        'teacher_id' => $teacherId,
    ]);

    if ($delete->rowCount() === 0) {
        json_response(['message' => 'Scheduled class not found.'], 404);
    }

    json_response(fetch_schedule_response($pdo, $teacherId));
}

json_response(['message' => 'Method not allowed.'], 405);
