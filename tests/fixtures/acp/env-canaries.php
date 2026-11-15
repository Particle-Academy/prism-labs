<?php

declare(strict_types=1);

// Called only by owned CLI fixtures. Report names/booleans, never env values.
return static function (): string {
    $env = getenv();
    $names = array_values(array_filter(array_keys($env), static fn ($name): bool => preg_match(
        '/^(?:APP_(?:KEY|ENV|DEBUG|CONFIG_CACHE)|DB_.*|LAB_.*|(?:PRISM_COORDINATOR|PRISM_VOICE|PRISM_RESEARCH|PRISM_TASK_PROBE)_.*|.*_API_KEY)$/i', $name) === 1));
    sort($names);

    return json_encode(['hostNames' => $names, 'pathPresent' => isset($env['PATH']) || isset($env['Path']),
        'syntheticValuesAbsent' => ! str_contains(json_encode($env), 'synthetic-host-')], JSON_THROW_ON_ERROR);
};
