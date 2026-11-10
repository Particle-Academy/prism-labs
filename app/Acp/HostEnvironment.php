<?php

declare(strict_types=1);

namespace App\Acp;

use App\Lab\PaidRunNotAuthorised;
use App\Lab\ProviderRegistry;
use Illuminate\Support\Env;
use Prism\Acp\Env\ChildEnv;

/** Normal Laravel host configuration. Never reuse this environment for a CLI. */
final class HostEnvironment
{
    private const CONFIGURATION = [
        'APP_KEY' => 'app.key', 'LAB_OWNER_USER_ID' => 'lab_authority.owner_user_id',
        'LAB_SANDBOX_DB_DATABASE' => 'lab-database.sandbox_database',
        'LAB_SANDBOX_SQLITE_DATABASE' => 'lab-database.sandbox_sqlite',
        'QUEUE_CONNECTION' => 'queue.default', 'DB_QUEUE_CONNECTION' => 'queue.connections.database.connection',
        'DB_QUEUE_TABLE' => 'queue.connections.database.table', 'DB_QUEUE' => 'queue.connections.database.queue',
        'DB_QUEUE_RETRY_AFTER' => 'queue.connections.database.retry_after',
        'LAB_WORKER_HEARTBEATS_DIRECTORY' => 'lab-preflight.workers.directory',
        'PRISM_COORDINATOR_PROVIDER' => 'team.coordinator.provider', 'PRISM_COORDINATOR_MODEL' => 'team.coordinator.model',
        'PRISM_COORDINATOR_MAX_STEPS' => 'team.coordinator.max_steps', 'PRISM_COORDINATOR_TIMEOUT' => 'team.coordinator.timeout',
        'PRISM_VOICE_PROVIDER' => 'team.voice.provider', 'PRISM_VOICE_TRANSCRIBE_MODEL' => 'team.voice.transcribe_model',
        'PRISM_VOICE_SPEAK_MODEL' => 'team.voice.speak_model', 'PRISM_VOICE_NAME' => 'team.voice.voice',
        'PRISM_RESEARCH_MODEL' => 'team.research.model', 'PRISM_RESEARCH_MAX_TOKENS' => 'team.research.max_tokens',
        'PRISM_RESEARCH_MAX_RESULTS' => 'team.research.max_results',
        'PRISM_TASK_PROBE_PROVIDER' => 'team.tasks.provider', 'PRISM_TASK_PROBE_MODEL' => 'team.tasks.model',
        'LAB_ACP_CLAUDE_BINARY' => 'acp.claude.binary', 'LAB_ACP_MODEL' => 'acp.claude.model',
        'LAB_ACP_CODEX_BINARY' => 'acp.codex.binary', 'LAB_ACP_CODEX_MODEL' => 'acp.codex.model',
        'LAB_ACP_TURN_SECONDS' => 'acp.turn_seconds', 'LAB_ACP_SESSION_SECONDS' => 'acp.session_seconds',
    ];

    /** @param array<string, string|null> $parent
     * @return array<string, string>
     */
    public static function build(#[\SensitiveParameter] array $parent): array
    {
        $connection = config('database.default');
        $database = is_string($connection) ? config('database.connections.'.$connection) : null;
        $provider = config('team.coordinator.provider');
        $descriptor = is_string($provider) ? app(ProviderRegistry::class)->find($provider) : null;
        $credential = $descriptor['env'] ?? null;
        if (! app()->environment('local') || ! is_array($database)
            || ! in_array($connection, ['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'], true)
            || ($database['driver'] ?? null) !== $connection
            || ! is_string($database['database'] ?? null) || $database['database'] === ''
            || $database['database'] !== config('lab-database.names.'.$connection)
            || ($connection === 'sqlite' && $database['database'] === ':memory:')
            || ($descriptor['described'] ?? false) !== true || ($descriptor['modality'] ?? null) !== 'text'
            || ! is_string($credential) || ! preg_match('/^[A-Z][A-Z0-9_]*_API_KEY$/D', $credential)
            || ! is_string(config('app.key')) || config('app.key') === ''
            || ! is_scalar(config('lab_authority.owner_user_id')) || (string) config('lab_authority.owner_user_id') === '') {
            throw new PaidRunNotAuthorised;
        }
        $set = ['APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => $connection,
            'LAB_DB_DATABASE' => $database['database'], 'DB_URL' => '', 'LAB_ACP_WORKSPACE' => (string) config('acp.workspace')];
        foreach (self::CONFIGURATION as $name => $key) {
            $value = config($key);
            if ($value !== null && ! is_scalar($value)) {
                throw new PaidRunNotAuthorised;
            }
            // Laravel distinguishes null from an empty connection name.
            $set[$name] = $value === null ? '(null)' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }
        foreach (['host', 'port', 'username', 'password', 'unix_socket', 'charset', 'collation', 'sslmode'] as $field) {
            $value = $database[$field] ?? '';
            if (! is_scalar($value)) {
                throw new PaidRunNotAuthorised;
            }
            $set['DB_'.($field === 'unix_socket' ? 'SOCKET' : strtoupper($field))] = (string) $value;
        }
        foreach (['claude', 'codex'] as $driver) {
            $args = config('acp.'.$driver.'.binary_args');
            // The normal config uses space-delimited interpreter arguments.
            // Refuse an unrepresentable override instead of changing its argv.
            if (! is_array($args) || count(array_filter($args, 'is_string')) !== count($args)
                || array_values(array_filter(explode(' ', implode(' ', $args)))) !== $args) {
                throw new PaidRunNotAuthorised;
            }
            $set['LAB_ACP_'.strtoupper($driver).'_ARGS'] = implode(' ', $args);
        }
        $key = config('prism.providers.'.$provider.'.api_key');
        if (! is_string($key) || $key === '') {
            throw new PaidRunNotAuthorised;
        }
        // This key is needed by the host's normal readiness check. Both native
        // drivers independently apply ChildEnv again, withholding it from CLIs.
        $set[$credential] = $key;
        foreach (['APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE'] as $selector) {
            $cache = Env::get($selector);
            if ($cache === null) {
                continue;
            }
            if (! is_string($cache) || $cache === '' || str_contains($cache, "\0")) {
                throw new PaidRunNotAuthorised;
            }
            // Preserve Laravel's selector. Replacing a relative selector with
            // its resolved drive-letter path changes its meaning on Windows.
            $set[$selector] = $cache;
        }

        return ChildEnv::build($parent, ['CLAUDE_CONFIG_DIR', 'CODEX_HOME', 'CODEX_CONFIG_DIR', 'XDG_CONFIG_HOME'], $set)->env;
    }
}
