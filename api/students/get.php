<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/http.php';

handle_cors();
require_method('GET');
require_auth();

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

$pdo = database();
$statement = $pdo->query(
    'SELECT
        s.id,
        s.student_id,
        s.first_name,
        s.last_name,
        s.email,
        s.created_at,
        c.id AS course_id,
        c.name AS course_name,
        c.code AS course_code,
        yl.id AS year_level_id,
        yl.name AS year_level_name,
        sec.id AS section_id,
        sec.name AS section_name,
        (
            SELECT a.status
            FROM attendance a
            WHERE a.student_id = s.id
            ORDER BY a.date DESC, a.id DESC
            LIMIT 1
        ) AS attendance_status
     FROM students s
     LEFT JOIN courses c ON c.id = s.course_id
     LEFT JOIN year_levels yl ON yl.id = s.year_level_id
     LEFT JOIN sections sec ON sec.id = s.section_id
     ORDER BY s.created_at DESC, s.id DESC'
);

$students = array_map(
    static function (array $row): array {
        $firstName = (string) ($row['first_name'] ?? '');
        $lastName = (string) ($row['last_name'] ?? '');

        return [
            'id' => (int) $row['id'],
            'studentId' => (string) ($row['student_id'] ?? ''),
            'firstName' => $firstName,
            'lastName' => $lastName,
            'fullName' => trim($firstName . ' ' . $lastName),
            'email' => (string) ($row['email'] ?? ''),
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
            'attendanceStatus' => (string) ($row['attendance_status'] ?? 'No Record'),
            'createdAt' => $row['created_at'],
        ];
    },
    $statement->fetchAll()
);

json_response([
    'students' => $students,
    'lookups' => [
        'courses' => fetch_lookup_options($pdo, 'courses', true),
        'yearLevels' => fetch_lookup_options($pdo, 'year_levels'),
        'sections' => fetch_lookup_options($pdo, 'sections'),
    ],
]);