<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/http.php';

handle_cors();
require_method('POST');
require_auth();

$payload = json_input();
$studentId = (int) ($payload['studentId'] ?? 0);

if ($studentId <= 0) {
    json_response(['message' => 'Student ID is required.'], 422);
}

$pdo = database();
$existingStudent = $pdo->prepare('SELECT id FROM students WHERE id = :id LIMIT 1');
$existingStudent->execute(['id' => $studentId]);

if (!$existingStudent->fetch()) {
    json_response(['message' => 'Student not found.'], 404);
}

try {
    $pdo->beginTransaction();

    $deleteAttendance = $pdo->prepare('DELETE FROM attendance WHERE student_id = :student_id');
    $deleteAttendance->execute(['student_id' => $studentId]);

    $deleteStudent = $pdo->prepare('DELETE FROM students WHERE id = :id');
    $deleteStudent->execute(['id' => $studentId]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response(['message' => 'Failed to delete student.'], 500);
}

json_response(['message' => 'Student deleted successfully.']);