<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/app_settings.php';
require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../../utils/http.php';

handle_cors();
$user = require_auth();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function current_valid_academic_years(): array
{
    $timezone = new DateTimeZone(APP_SETTINGS_TIMEZONE);
    $currentYear = (int) (new DateTimeImmutable('now', $timezone))->format('Y');

    return [$currentYear - 1, $currentYear];
}

function normalize_settings_update(array $payload): array
{
    $normalized = [];

    if (array_key_exists('schoolName', $payload)) {
        $schoolName = trim((string) $payload['schoolName']);

        if ($schoolName === '') {
            json_response(['message' => 'School name is required.'], 422);
        }

        $normalized['schoolName'] = $schoolName;
    }

    if (array_key_exists('academicYearStart', $payload)) {
        $academicYearStart = (int) $payload['academicYearStart'];
        $validYears = current_valid_academic_years();

        if (!in_array($academicYearStart, $validYears, true)) {
            json_response(['message' => 'Academic year is outside the allowed range.'], 422);
        }

        $normalized['academicYearStart'] = $academicYearStart;
    }

    if (array_key_exists('aiInsightsEnabled', $payload)) {
        $normalized['aiInsightsEnabled'] = (bool) $payload['aiInsightsEnabled'];
    }

    if (array_key_exists('defaultLandingPage', $payload)) {
        $defaultLandingPage = (string) $payload['defaultLandingPage'];
        $allowedRoutes = ['dashboard', 'students', 'teachers', 'reports', 'settings'];

        if (!in_array($defaultLandingPage, $allowedRoutes, true)) {
            json_response(['message' => 'Default landing page is invalid.'], 422);
        }

        $normalized['defaultLandingPage'] = $defaultLandingPage;
    }

    if (array_key_exists('lateThresholdMinutes', $payload)) {
        $lateThresholdMinutes = (int) $payload['lateThresholdMinutes'];

        if ($lateThresholdMinutes < 1 || $lateThresholdMinutes > 180) {
            json_response(['message' => 'Late threshold must be between 1 and 180 minutes.'], 422);
        }

        $normalized['lateThresholdMinutes'] = $lateThresholdMinutes;
    }

    return $normalized;
}

function settings_response(array $settings): array
{
    return [
        'schoolName' => (string) ($settings['schoolName'] ?? ''),
        'academicYearStart' => (int) ($settings['academicYearStart'] ?? 0),
        'aiInsightsEnabled' => (bool) ($settings['aiInsightsEnabled'] ?? false),
        'defaultLandingPage' => (string) ($settings['defaultLandingPage'] ?? 'dashboard'),
        'lateThresholdMinutes' => (int) ($settings['lateThresholdMinutes'] ?? 15),
        'schoolLogoPath' => isset($settings['schoolLogoPath']) && $settings['schoolLogoPath'] !== ''
            ? (string) $settings['schoolLogoPath']
            : null,
        'validAcademicYearStarts' => current_valid_academic_years(),
    ];
}

function school_logo_upload_directory(): string
{
    return dirname(__DIR__, 2) . '/uploads/logos';
}

function delete_existing_school_logo(?string $schoolLogoPath): void
{
    if (!$schoolLogoPath || !str_starts_with($schoolLogoPath, '/uploads/logos/')) {
        return;
    }

    $absolutePath = dirname(__DIR__, 2) . str_replace('/', DIRECTORY_SEPARATOR, $schoolLogoPath);

    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function handle_school_logo_upload(array $currentSettings): array
{
    if (!isset($_FILES['logo']) || !is_array($_FILES['logo'])) {
        json_response(['message' => 'Logo image is required.'], 422);
    }

    $file = $_FILES['logo'];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        json_response(['message' => 'The logo upload could not be processed.'], 422);
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        json_response(['message' => 'Invalid uploaded logo file.'], 422);
    }

    if ($size <= 0 || $size > 2 * 1024 * 1024) {
        json_response(['message' => 'Logo image must be 2 MB or smaller.'], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmpName);
    $allowedMimeTypes = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    if (!array_key_exists($mimeType, $allowedMimeTypes)) {
        json_response(['message' => 'Logo image must be a PNG, JPG, or WEBP file.'], 422);
    }

    $uploadDirectory = school_logo_upload_directory();

    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
        json_response(['message' => 'Failed to prepare the logo upload directory.'], 500);
    }

    $filename = sprintf('school-logo-%s.%s', bin2hex(random_bytes(8)), $allowedMimeTypes[$mimeType]);
    $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        json_response(['message' => 'Failed to save the uploaded logo image.'], 500);
    }

    delete_existing_school_logo($currentSettings['schoolLogoPath'] ?? null);

    $updatedSettings = array_merge($currentSettings, [
        'schoolLogoPath' => '/uploads/logos/' . $filename,
    ]);

    try {
        save_app_settings($updatedSettings);
    } catch (RuntimeException $exception) {
        @unlink($destination);
        json_response(['message' => 'Failed to save settings.'], 500);
    }

    return $updatedSettings;
}

if ($method === 'GET') {
    json_response(settings_response(load_app_settings()));
}

if ($method === 'POST') {
    if (strtolower((string) ($user['role'] ?? '')) !== 'admin') {
        json_response(['message' => 'Only admins can update settings.'], 403);
    }

    $currentSettings = load_app_settings();
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

    if (str_starts_with($contentType, 'multipart/form-data')) {
        json_response(settings_response(handle_school_logo_upload($currentSettings)));
    }

    $payload = json_input();
    $updatedSettings = array_merge($currentSettings, normalize_settings_update($payload));

    try {
        save_app_settings($updatedSettings);
    } catch (RuntimeException $exception) {
        json_response(['message' => 'Failed to save settings.'], 500);
    }

    json_response(settings_response($updatedSettings));
}

json_response(['message' => 'Method not allowed.'], 405);