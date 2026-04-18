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
        t.id,
        t.teacher_id,
        t.full_name,
        t.email,
        t.status,
        t.created_at,
        tc.id AS teacher_class_id,
        tc.class_id,
        tc.start_time,
        tc.end_time,
        tc.day_of_week,
        c.subject,
        course.id AS course_id,
        course.name AS course_name,
        course.code AS course_code,
        yl.id AS year_level_id,
        yl.name AS year_level_name,
        s.id AS section_id,
        s.name AS section_name
     FROM teachers t
     LEFT JOIN teacher_classes tc ON tc.teacher_id = t.id
     LEFT JOIN classes c ON c.id = tc.class_id
     LEFT JOIN courses course ON course.id = c.course_id
     LEFT JOIN year_levels yl ON yl.id = c.year_level_id
     LEFT JOIN sections s ON s.id = c.section_id
     ORDER BY t.created_at DESC, t.id DESC, c.subject ASC'
);

$teachersById = [];
$summary = [
    'total' => 0,
    'active' => 0,
    'onLeave' => 0,
    'inactive' => 0,
];

foreach ($statement->fetchAll() as $row) {
    $teacherId = (int) $row['id'];

    if (!isset($teachersById[$teacherId])) {
        $status = (string) ($row['status'] ?? 'Active');

        $teachersById[$teacherId] = [
            'id' => $teacherId,
            'teacherId' => (string) ($row['teacher_id'] ?? ''),
            'fullName' => (string) ($row['full_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'status' => $status,
            'createdAt' => $row['created_at'],
            'assignedClasses' => [],
        ];

        $summary['total']++;

        if ($status === 'Active') {
            $summary['active']++;
        } elseif ($status === 'On Leave') {
            $summary['onLeave']++;
        } else {
            $summary['inactive']++;
        }
    }

    if ($row['teacher_class_id'] === null || $row['class_id'] === null) {
        continue;
    }

    $teachersById[$teacherId]['assignedClasses'][] = [
        'id' => (int) $row['teacher_class_id'],
        'classId' => (int) $row['class_id'],
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
        'dayOfWeek' => (string) ($row['day_of_week'] ?? 'Monday'),
        'startTime' => $row['start_time'] !== null ? substr((string) $row['start_time'], 0, 5) : '',
        'endTime' => $row['end_time'] !== null ? substr((string) $row['end_time'], 0, 5) : '',
    ];
}

json_response([
    'teachers' => array_values($teachersById),
    'summary' => $summary,
    'lookups' => [
        'subjects' => fetch_lookup_options($pdo, 'subjects', true),
        'courses' => fetch_lookup_options($pdo, 'courses', true),
        'yearLevels' => fetch_lookup_options($pdo, 'year_levels'),
        'sections' => fetch_lookup_options($pdo, 'sections'),
        'statuses' => ['Active', 'On Leave', 'Inactive'],
    ],
]);
