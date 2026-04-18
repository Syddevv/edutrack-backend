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

$payload = json_input();
$teacherId = (int) ($payload['teacherId'] ?? 0);
$assignedClasses = $payload['assignedClasses'] ?? [];

if ($teacherId <= 0) {
    json_response(['message' => 'Teacher ID is required.'], 422);
}

if (!is_array($assignedClasses)) {
    json_response(['message' => 'Assigned classes payload must be an array.'], 422);
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
        'startTime' => $startTime,
        'endTime' => $endTime,
    ];
}

$pdo = database();
$teacherExists = $pdo->prepare('SELECT id FROM teachers WHERE id = :id LIMIT 1');
$teacherExists->execute(['id' => $teacherId]);

if (!$teacherExists->fetch()) {
    json_response(['message' => 'Teacher not found.'], 404);
}

try {
    $pdo->beginTransaction();

    $deleteAssignments = $pdo->prepare('DELETE FROM teacher_classes WHERE teacher_id = :teacher_id');
    $deleteAssignments->execute(['teacher_id' => $teacherId]);

    if (count($normalizedAssignments) > 0) {
        $insertAssignment = $pdo->prepare(
            'INSERT INTO teacher_classes (teacher_id, class_id, start_time, end_time)
             VALUES (:teacher_id, :class_id, :start_time, :end_time)'
        );

        foreach ($normalizedAssignments as $assignment) {
            $classId = find_or_create_class($pdo, $assignment);
            $insertAssignment->execute([
                'teacher_id' => $teacherId,
                'class_id' => $classId,
                'start_time' => $assignment['startTime'],
                'end_time' => $assignment['endTime'],
            ]);
        }
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response(['message' => 'Failed to update teacher assignments.'], 500);
}

json_response(['message' => 'Teacher assignments updated successfully.']);