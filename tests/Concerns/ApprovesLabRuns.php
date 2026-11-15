<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Lab\PaidRunContext;
use App\Lab\RunAuthority;
use App\Lab\RunScope;
use App\Lab\RunScopeFactory;
use App\Lab\WorkerHeartbeats;
use App\Models\LabRunIntent;
use App\Models\User;
use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/** Real synthetic owner/GO and native worker evidence, never a guard bypass. */
trait ApprovesLabRuns
{
    private ?string $authorityFixtureDirectory = null;

    private ?User $authorityFixtureOwner = null;

    protected function prepareLabAuthority(): void
    {
        if ($this->authorityFixtureDirectory !== null) {
            return;
        }
        $this->assertSame(':memory:', app('db')->connection()->getDatabaseName());
        $this->authorityFixtureDirectory = storage_path('framework/testing/approved-run-'.bin2hex(random_bytes(8)));
        $this->assertDirectoryDoesNotExist($this->authorityFixtureDirectory);
        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'lab-preflight.workers.directory' => $this->authorityFixtureDirectory.'/workers',
            'cache.stores.file.path' => $this->authorityFixtureDirectory.'/cache',
            'cache.stores.file.lock_path' => $this->authorityFixtureDirectory.'/cache',
            'queue.default' => 'database', 'queue.connections.database.retry_after' => 1900,
            'prism.providers.anthropic.api_key' => 'synthetic-approval-fixture-only',
            'team.learnings_path' => $this->authorityFixtureDirectory.'/learnings',
        ]);
        Cache::forgetDriver('file');
        $this->app->forgetInstance(WorkerHeartbeats::class);
        $this->app->detectEnvironment(fn () => 'local');
        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
        Http::preventStrayRequests();
        $this->fakeAuthorityReadiness();
        Event::dispatch(new Looping('database', 'default,scoring,overseer,commentary', new WorkerOptions(timeout: 1800)));
        $directory = $this->authorityFixtureDirectory;
        $this->beforeApplicationDestroyed(function () use ($directory): void {
            $parent = realpath(storage_path('framework/testing'));
            $resolved = realpath($directory);
            if ($resolved !== false) {
                $this->assertNotFalse($parent);
                $this->assertStringStartsWith(str_replace('\\', '/', $parent).'/', str_replace('\\', '/', $resolved));
                $this->assertFalse(is_link($directory));
                app(Filesystem::class)->deleteDirectory($directory);
            }
        });
    }

    /** Reinstall only the free, closed GitHub fixture after replacing an HTTP fake. */
    protected function fakeAuthorityReadiness(): void
    {
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
    }

    /** Middleware, rather than the fixture, consumes the exact native GO. */
    protected function assertOnlyAuthorityReadinessRequests(): void
    {
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('api.github.com', parse_url($request->url(), PHP_URL_HOST));
            $this->assertContains(parse_url($request->url(), PHP_URL_PATH), [
                '/repos/Particle-Academy/prism/git/ref/heads/main',
                '/repos/Particle-Academy/prism/actions/runs',
            ]);
        }
    }

    /** Middleware, rather than the fixture, consumes the exact native GO. */
    protected function postApprovedLabRun(string $uri, string $action, array $input): TestResponse
    {
        [$intent] = $this->approvedLabRun($action, $input);
        $before = Auth::guard('web')->user();
        $this->actingAs($this->authorityFixtureOwner, 'web');
        try {
            return $this->postJson($uri, [...$input, 'approved_run_id' => $intent->id]);
        } finally {
            if ($before !== null) {
                Auth::guard('web')->setUser($before);
            } else {
                Auth::guard('web')->logout();
            }
        }
    }

    /** @return array{LabRunIntent, RunScope} */
    protected function approvedLabRun(string $action, array $input, string $resource = 'lab:agent'): array
    {
        $this->prepareLabAuthority();
        $this->authorityFixtureOwner ??= User::factory()->create();
        config(['lab_authority.owner_user_id' => $this->authorityFixtureOwner->id]);
        $before = Auth::guard('web')->user();
        $this->actingAs($this->authorityFixtureOwner, 'web');
        try {
            $scope = app(RunScopeFactory::class)->fromAction($action, $resource, $input);
            $authority = app(RunAuthority::class);
            $intent = $authority->draft($scope, '1');
            $authority->approve($intent->id, $intent->digest);

            return [$authority->go($intent->id, $intent->digest, '1', 'GO'), $scope];
        } finally {
            if ($before !== null) {
                Auth::guard('web')->setUser($before);
            } else {
                Auth::guard('web')->logout();
            }
        }
    }

    protected function withApprovedLabRun(string $action, array $input, Closure $operation, string $resource = 'lab:agent'): mixed
    {
        [$intent, $scope] = $this->approvedLabRun($action, $input, $resource);
        app(RunAuthority::class)->consume($intent->id, $scope);

        return app(PaidRunContext::class)->within($intent->id, $scope, $operation);
    }

    protected function beginApprovedJob(string $queue): void
    {
        Event::dispatch(new Looping('database', $queue, new WorkerOptions(timeout: 1800)));
        $driver = $this->app['queue']->connection('database');
        $driver->pushRaw('{"job":"owned-approval-event-only","data":{}}', $queue);
        $job = $driver->pop($queue);
        $this->assertInstanceOf(DatabaseJob::class, $job);
        Event::dispatch(new JobProcessing('database', $job));
    }
}
