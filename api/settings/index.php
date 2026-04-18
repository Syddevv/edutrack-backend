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
        'validAcademicYearStarts' => current_valid_academic_years(),
    ];
}

if ($method === 'GET') {
    json_response(settings_response(load_app_settings()));
}

if ($method === 'POST') {
    if (strtolower((string) ($user['role'] ?? '')) !== 'admin') {
        json_response(['message' => 'Only admins can update settings.'], 403);
    }

    $payload = json_input();
    $currentSettings = load_app_settings();
    $updatedSettings = array_merge($currentSettings, normalize_settings_update($payload));

    try {
        save_app_settings($updatedSettings);
    } catch (RuntimeException $exception) {
        json_response(['message' => 'Failed to save settings.'], 500);
    }

    json_response(settings_response($updatedSettings));
}

json_response(['message' => 'Method not allowed.'], 405);
