<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/http.php';

handle_cors();
require_method('POST');
require_auth();

$payload = json_input();
$teacherId = (int) ($payload['teacherId'] ?? 0);

if ($teacherId <= 0) {
    json_response(['message' => 'Teacher ID is required.'], 422);
}

$pdo = database();
$teacherStatement = $pdo->prepare('SELECT id, email, status FROM teachers WHERE id = :id LIMIT 1');
$teacherStatement->execute(['id' => $teacherId]);
$teacher = $teacherStatement->fetch();

if (!$teacher) {
    json_response(['message' => 'Teacher not found.'], 404);
}

try {
    $pdo->beginTransaction();

    $deleteAssignments = $pdo->prepare('DELETE FROM teacher_classes WHERE teacher_id = :teacher_id');
    $deleteAssignments->execute(['teacher_id' => $teacherId]);

    $deleteTeacher = $pdo->prepare('DELETE FROM teachers WHERE id = :id');
    $deleteTeacher->execute(['id' => $teacherId]);

    $deleteUser = $pdo->prepare("DELETE FROM users WHERE email = :email AND role = 'teacher'");
    $deleteUser->execute(['email' => $teacher['email']]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response(['message' => 'Failed to delete teacher.'], 500);
}

json_response(['message' => 'Teacher deleted successfully.']);