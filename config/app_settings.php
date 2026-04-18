<?php

declare(strict_types=1);

const APP_SETTINGS_TIMEZONE = 'Asia/Manila';

function default_app_settings(): array
{
    $timezone = new DateTimeZone(APP_SETTINGS_TIMEZONE);
    $currentYear = (int) (new DateTimeImmutable('now', $timezone))->format('Y');

    return [
        'schoolName' => 'Bulacan Polytechnic College',
        'academicYearStart' => $currentYear - 1,
        'aiInsightsEnabled' => false,
        'defaultLandingPage' => 'dashboard',
        'lateThresholdMinutes' => 15,
    ];
}

function app_settings_path(): string
{
    return __DIR__ . '/app-settings.json';
}

function load_app_settings(): array
{
    $defaults = default_app_settings();
    $path = app_settings_path();

    if (!file_exists($path)) {
        return $defaults;
    }

    $contents = file_get_contents($path);

    if ($contents === false || trim($contents) === '') {
        return $defaults;
    }

    $decoded = json_decode($contents, true);

    if (!is_array($decoded)) {
        return $defaults;
    }

    return array_merge($defaults, $decoded);
}

function save_app_settings(array $settings): array
{
    $path = app_settings_path();
    $encoded = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($encoded === false || file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Failed to persist app settings.');
    }

    return $settings;
}
