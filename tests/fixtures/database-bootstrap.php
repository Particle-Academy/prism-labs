<?php

declare(strict_types=1);

use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $application = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $cached = $application->configurationIsCached();
    $providers = $application->getLoadedProviders();
    // Intentionally stop before RegisterProviders: a rejected configuration
    // must never give a provider an opportunity to connect.
    $application->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);
    echo json_encode([
        'loaded' => true,
        'cached' => $cached,
        'noProvidersRegistered' => $providers === $application->getLoadedProviders(),
        'noDatabaseManager' => ! $application->bound('db'),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    $known = ['The Lab database is not isolated.', 'Lab tests require in-memory SQLite.'];
    echo json_encode(['loaded' => false, 'cached' => $cached ?? false, 'error' => in_array($exception->getMessage(), $known, true)
        ? $exception->getMessage() : 'Unexpected bootstrap failure.'], JSON_THROW_ON_ERROR);
}
