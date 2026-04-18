<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/http.php';

handle_cors();
require_method('POST');
require_auth();

function normalize_lookup_key(string $value): string
{
    return strtoupper(trim($value));
}

function normalize_year_key(string $value): string
{
    $trimmed = trim($value);
    $digits = preg_replace('/\D+/', '', $trimmed);

    if ($digits !== '') {
        return $digits;
    }

    return strtoupper($trimmed);
}

function next_student_sequence(PDO $pdo): int
{
    $year = date('Y');
    $pattern = $year . '-%';

    $statement = $pdo->prepare(
        'SELECT student_id
         FROM students
         WHERE student_id LIKE :pattern
         ORDER BY id DESC
         LIMIT 1'
    );
    $statement->execute(['pattern' => $pattern]);

    $lastCode = (string) ($statement->fetchColumn() ?: $year . '-0000');

    return (int) substr($lastCode, -4) + 1;
}

function format_student_code(int $sequence): string
{
    return date('Y') . '-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
}

function fetch_email_index(PDO $pdo): array
{
    $emails = [];
    $statement = $pdo->query('SELECT email FROM students');

    foreach ($statement->fetchAll() as $row) {
        $email = strtolower(trim((string) ($row['email'] ?? '')));

        if ($email !== '') {
            $emails[$email] = true;
        }
    }

    return $emails;
}

function fetch_course_lookup(PDO $pdo): array
{
    $lookup = [];
    $statement = $pdo->query('SELECT id, name, code FROM courses');

    foreach ($statement->fetchAll() as $row) {
        $id = (int) $row['id'];
        $lookup[normalize_lookup_key((string) ($row['name'] ?? ''))] = $id;

        $code = trim((string) ($row['code'] ?? ''));
        if ($code !== '') {
            $lookup[normalize_lookup_key($code)] = $id;
        }
    }

    return $lookup;
}

function fetch_year_lookup(PDO $pdo): array
{
    $lookup = [];
    $statement = $pdo->query('SELECT id, name FROM year_levels');

    foreach ($statement->fetchAll() as $row) {
        $id = (int) $row['id'];
        $name = (string) ($row['name'] ?? '');
        $lookup[normalize_year_key($name)] = $id;
        $lookup[normalize_lookup_key($name)] = $id;
    }

    return $lookup;
}

function fetch_section_lookup(PDO $pdo): array
{
    $lookup = [];
    $statement = $pdo->query('SELECT id, name FROM sections');

    foreach ($statement->fetchAll() as $row) {
        $lookup[normalize_lookup_key((string) ($row['name'] ?? ''))] = (int) $row['id'];
    }

    return $lookup;
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    json_response(['message' => 'CSV file is required.'], 422);
}

$file = $_FILES['file'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['message' => 'Failed to upload CSV file.'], 422);
}

$tmpPath = (string) ($file['tmp_name'] ?? '');
$originalName = strtolower((string) ($file['name'] ?? ''));

if ($tmpPath === '' || !str_ends_with($originalName, '.csv')) {
    json_response(['message' => 'Please upload a valid CSV file.'], 422);
}

$handle = fopen($tmpPath, 'rb');

if ($handle === false) {
    json_response(['message' => 'Unable to read uploaded CSV file.'], 500);
}

$headerRow = fgetcsv($handle);

if (!is_array($headerRow)) {
    fclose($handle);
    json_response(['message' => 'CSV file is empty.'], 422);
}

$normalizedHeaders = array_map(
    static function ($header): string {
        $value = trim((string) $header);
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;

        return strtolower($value);
    },
    $headerRow
);

$requiredHeaders = ['first_name', 'last_name', 'email', 'course', 'year', 'section'];
$headerIndexes = [];

foreach ($requiredHeaders as $header) {
    $index = array_search($header, $normalizedHeaders, true);

    if ($index === false) {
        fclose($handle);
        json_response(['message' => "Missing required CSV column: {$header}."], 422);
    }

    $headerIndexes[$header] = (int) $index;
}

$pdo = database();
$courseLookup = fetch_course_lookup($pdo);
$yearLookup = fetch_year_lookup($pdo);
$sectionLookup = fetch_section_lookup($pdo);
$knownEmails = fetch_email_index($pdo);
$nextSequence = next_student_sequence($pdo);

$insert = $pdo->prepare(
    'INSERT INTO students (student_id, first_name, last_name, email, course_id, year_level_id, section_id)
     VALUES (:student_id, :first_name, :last_name, :email, :course_id, :year_level_id, :section_id)'
);

$importedCount = 0;
$skippedCount = 0;
$errors = [];
$lineNumber = 1;

while (($row = fgetcsv($handle)) !== false) {
    $lineNumber++;

    $joined = implode('', array_map(static fn ($value) => trim((string) $value), $row));
    if ($joined === '') {
        continue;
    }

    $firstName = trim((string) ($row[$headerIndexes['first_name']] ?? ''));
    $lastName = trim((string) ($row[$headerIndexes['last_name']] ?? ''));
    $email = strtolower(trim((string) ($row[$headerIndexes['email']] ?? '')));
    $courseValue = trim((string) ($row[$headerIndexes['course']] ?? ''));
    $yearValue = trim((string) ($row[$headerIndexes['year']] ?? ''));
    $sectionValue = trim((string) ($row[$headerIndexes['section']] ?? ''));

    if ($firstName === '' || $lastName === '' || $email === '' || $courseValue === '' || $yearValue === '' || $sectionValue === '') {
        $skippedCount++;
        $errors[] = "Row {$lineNumber}: missing one or more required values.";
        continue;
    }

    if (isset($knownEmails[$email])) {
        $skippedCount++;
        $errors[] = "Row {$lineNumber}: email {$email} already exists.";
        continue;
    }

    $courseId = $courseLookup[normalize_lookup_key($courseValue)] ?? 0;
    $yearLevelId = $yearLookup[normalize_year_key($yearValue)] ?? ($yearLookup[normalize_lookup_key($yearValue)] ?? 0);
    $sectionId = $sectionLookup[normalize_lookup_key($sectionValue)] ?? 0;

    if ($courseId <= 0) {
        $skippedCount++;
        $errors[] = "Row {$lineNumber}: course {$courseValue} was not found.";
        continue;
    }

    if ($yearLevelId <= 0) {
        $skippedCount++;
        $errors[] = "Row {$lineNumber}: year {$yearValue} was not found.";
        continue;
    }

    if ($sectionId <= 0) {
        $skippedCount++;
        $errors[] = "Row {$lineNumber}: section {$sectionValue} was not found.";
        continue;
    }

    $insert->execute([
        'student_id' => format_student_code($nextSequence),
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'course_id' => $courseId,
        'year_level_id' => $yearLevelId,
        'section_id' => $sectionId,
    ]);

    $nextSequence++;
    $knownEmails[$email] = true;
    $importedCount++;
}

fclose($handle);

json_response([
    'message' => 'CSV import completed.',
    'importedCount' => $importedCount,
    'skippedCount' => $skippedCount,
    'errors' => $errors,
]);