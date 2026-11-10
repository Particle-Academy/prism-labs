<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Trace\NoopTracer;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use RuntimeException;

/** Synthetic transport only; actual commands, authority, DB and worker stay native. */
final class NativeAuthorityProvider extends ServiceProvider
{
    public function register(): void
    {
        $directory = NativeAuthorityFixture::directory((string) config('testing-native-authority.directory'));
        if (! app()->configurationIsCached() || realpath(app()->getCachedConfigPath()) !== realpath($directory.'/config.php')) {
            throw new RuntimeException('Native command must load its owned cache before environment discovery.');
        }
        // Resource discovery is outside this synthetic fixture. In particular,
        // the Windows host detector launches PowerShell to read MachineGuid.
        putenv('OTEL_PHP_DETECTORS=none');
        $_ENV['OTEL_PHP_DETECTORS'] = $_SERVER['OTEL_PHP_DETECTORS'] = 'none';
        $this->app->booting(function (): void {
            $this->closeExporter();
        });
    }

    private function closeExporter(): void
    {
        // Local boot creates a real OTLP exporter. Close it before providers
        // boot or any synthetic generation can emit a span; keep the native
        // application ledger and business authority, with no operational I/O.
        if ($this->app->bound(TracerProvider::class)) {
            $provider = $this->app->make(TracerProvider::class);
            $provider->shutdown();
            if (! $provider->getTracer('g246-native-fixture')->spanBuilder('closed-exporter')->startSpan()->isRecording()) {
                $this->app->instance('native-authority-exporter-closed', true);
            } else {
                throw new RuntimeException('Native fixture exporter is still recording.');
            }
        } else {
            $this->app->instance('native-authority-exporter-closed', true);
        }
        config(['prism-opentelemetry.enabled' => false]);
        $this->app->instance(TracerInterface::class, NoopTracer::getInstance());
    }

    public function boot(): void
    {
        $directory = NativeAuthorityFixture::directory((string) config('testing-native-authority.directory'));
        $tag = getenv('G246_AUTHORITY_OBSERVATION_TAG');
        if (! is_string($tag) || preg_match('/^[a-z0-9-]{1,64}$/D', $tag) !== 1) {
            throw new RuntimeException('Refuse an unbound native observation.');
        }
        if (($_SERVER['argv'][2] ?? null) === 'race' && ($_SERVER['argv'][3] ?? null) === 'cap') {
            if ($tag === 'race-0') {
                register_shutdown_function(static fn () => file_put_contents($directory.'/cap-first-finished', 'done', LOCK_EX));
                $held = false;
                DB::listen(function ($query) use (&$held, $directory): void {
                    if (! $held && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'lab_authority_sessions')) {
                        $held = true;
                        file_put_contents($directory.'/cap-first-read', 'ready', LOCK_EX);
                        NativeAuthorityFixture::waitForCapObservation($directory, 'cap-writer-requested');
                        usleep(200000); // Let the peer enter the actual native write.
                    }
                });
            } elseif ($tag === 'race-1') {
                $announced = false;
                DB::beforeExecuting(function ($query) use (&$announced, $directory): void {
                    if (! $announced && str_starts_with(strtolower($query), 'update') && str_contains($query, 'lab_authority_sessions')) {
                        $announced = true;
                        file_put_contents($directory.'/cap-writer-requested', 'ready', LOCK_EX);
                    }
                });
                DB::listen(function ($query) use ($directory): void {
                    if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'lab_authority_sessions')
                        && ($query->bindings[0] ?? null) === 3000000) {
                        NativeAuthorityFixture::waitForCapObservation($directory, 'cap-first-finished');
                    }
                });
            }
        }
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
        app()->instance('native-authority-sdk', Prism::fake([TextResponseFake::make()->withText('Owned native authority output')]));
        register_shutdown_function(function () use ($directory, $tag): void {
            $result = ['calls' => NativeAuthorityFixture::sdkCalls(), 'guest' => Auth::guard('web')->guest(),
                'external_exporter_closed' => app('native-authority-exporter-closed') === true,
                'cached' => app()->configurationIsCached(), 'owned_sqlite' => app('db')->connection()->getDriverName() === 'sqlite'
                    && app('db')->connection()->getDatabaseName() === $directory.'/lab.sqlite'];
            $file = fopen($directory.'/observation-'.$tag.'.json', 'x');
            if ($file === false) {
                throw new RuntimeException('Native observation already exists.');
            }
            fwrite($file, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);
            fclose($file);
        });
    }
}
