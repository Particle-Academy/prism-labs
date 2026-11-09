<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Trace\NoopTracer;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;

/** Free HTTP fixtures only; actual authority/DB/queue/process code stays native. */
final class NativeAcpReadinessProvider extends ServiceProvider
{
    public function register(): void
    {
        $directory = $this->fixtureDirectory();
        if (! app()->configurationIsCached() || realpath(app()->getCachedConfigPath()) !== realpath($directory.'/config.php')) {
            throw new \RuntimeException('Native ACP fixture must load its owned configuration cache.');
        }
        // Local application registration constructs an OTLP exporter. Close
        // it before provider boot or any synthetic native work can emit spans.
        if ($this->app->bound(TracerProvider::class)) {
            $provider = $this->app->make(TracerProvider::class);
            $provider->shutdown();
            if ($provider->getTracer('g246-native-acp')->spanBuilder('closed-exporter')->startSpan()->isRecording()) {
                throw new \RuntimeException('Native ACP fixture exporter is still recording.');
            }
        }
        $this->app->instance('native-acp-exporter-closed', true);
        config(['prism-opentelemetry.enabled' => false]);
        $this->app->instance(TracerInterface::class, NoopTracer::getInstance());
    }

    private function fixtureDirectory(): string
    {
        $directory = config('testing-native-acp.evidence_directory');
        $root = str_replace('\\', '/', dirname(base_path(), 3));
        if (is_string($directory) && str_starts_with($directory, str_replace('\\', '/', storage_path('framework/testing')).'/acp-native-')) {
            return NativeAcpTurnFixture::directory($directory);
        }
        foreach (['/owner6762/resume-owner6853/', '/owner7253-resume/'] as $continuation) {
            $prefix = $root.'/.ai/evidence/g246-foundations/approval-foundation'.$continuation;
            if (is_string($directory) && str_starts_with($directory, $prefix)
                && preg_match('~^acp-normal-host-v[0-9]+/(claude|codex)$~D', substr($directory, strlen($prefix))) === 1
                && str_replace('\\', '/', (string) realpath($directory)) === $directory && ! is_link($directory)) {
                return $directory;
            }
        }

        throw new \RuntimeException('Refuse non-owned native ACP fixture.');
    }

    public function boot(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/repos/Particle-Academy/prism/git/ref/heads/main' => fn () => Http::response([
                'ref' => 'refs/heads/main', 'object' => ['type' => 'commit', 'sha' => str_repeat('a', 40)],
            ]),
            'https://api.github.com/repos/Particle-Academy/prism/actions/runs*' => fn () => Http::response([
                'total_count' => count(config('lab-preflight.core_actions.workflows')),
                'workflow_runs' => array_map(fn ($path, $i) => ['id' => 100 + $i, 'run_number' => 1,
                    'path' => $path, 'head_sha' => str_repeat('a', 40), 'head_branch' => 'main',
                    'event' => 'push', 'status' => 'completed', 'conclusion' => 'success'],
                    config('lab-preflight.core_actions.workflows'), array_keys(config('lab-preflight.core_actions.workflows'))),
            ]),
        ]);
        if (in_array('queue:work', $_SERVER['argv'] ?? [], true) && in_array('--queue=overseer', $_SERVER['argv'] ?? [], true)) {
            $directory = $this->fixtureDirectory();
            register_shutdown_function(function () use ($directory): void {
                $observation = ['guest' => Auth::guard('web')->guest(),
                    'exporterClosed' => app('native-acp-exporter-closed') === true,
                    'normalCacheLoaded' => app()->configurationIsCached(),
                    'ownedSqlite' => app('db')->connection()->getDriverName() === 'sqlite'
                        && app('db')->connection()->getDatabaseName() === $directory.'/lab.sqlite'];
                $file = fopen($directory.'/worker-env-'.getmypid().'.json', 'x');
                if ($file === false) {
                    throw new \RuntimeException('Native worker observation already exists.');
                }
                fwrite($file, json_encode($observation, JSON_THROW_ON_ERROR).PHP_EOL);
                fclose($file);
            });
        }
        if (in_array('lab:acp-agent', $_SERVER['argv'] ?? [], true)) {
            $directory = $this->fixtureDirectory();
            $observation = [
                'externalExporterClosed' => app('native-acp-exporter-closed') === true,
                'normalCacheLoaded' => app()->configurationIsCached(),
                'sameCachePath' => realpath(base_path((string) getenv('APP_CONFIG_CACHE'))) === realpath(app()->getCachedConfigPath()),
                'appKeyPresentAndMatches' => getenv('APP_KEY') === config('app.key') && config('app.key') !== '',
                'databasePresentAndMatches' => getenv('LAB_DB_DATABASE') === $directory.'/lab.sqlite',
                'syntheticDbPasswordPresent' => getenv('DB_PASSWORD') === 'synthetic-host-db-password',
                'syntheticProviderKeyPresent' => getenv('ANTHROPIC_API_KEY') === 'synthetic-host-provider-key',
            ];
            if (str_contains($directory, '/acp-native-')) {
                $observation['servicesCacheOwned'] = str_replace('\\', '/', app()->getCachedServicesPath()) === $directory.'/services.php';
                $observation['packagesCacheOwned'] = str_replace('\\', '/', app()->getCachedPackagesPath()) === $directory.'/packages.php';
            }
            $file = fopen($directory.'/host-env-'.getmypid().'.json', 'x');
            fwrite($file, json_encode($observation, JSON_THROW_ON_ERROR).PHP_EOL);
            fclose($file);
        }
    }
}
