<?php

declare(strict_types=1);

namespace Tests\Feature\DocsLab;

use App\DocsLab\DocsLabRunner;
use App\Models\DocsLabRun;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\DocsLabDatabase;
use Tests\TestCase;

/** G-316: /lab/docs shows scenarios, runs, per-check verdicts and artefacts; parity with lab:docs-run. */
final class DocsLabPageTest extends TestCase
{
    use DocsLabDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocsLab();
        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
        $this->app->detectEnvironment(fn () => 'local');
        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
        // A real CSRF token rather than disabling the check.
        $this->withSession(['_token' => 'owned-docs-csrf'])->withHeader('X-CSRF-TOKEN', 'owned-docs-csrf');
    }

    public function test_the_page_lists_scenarios_runs_and_checks_from_the_same_records_as_the_cli(): void
    {
        $this->artisan('lab:docs-run', ['--scenario' => 'S1'])->expectsOutputToContain('PASS S1')->assertSuccessful();
        $run = DocsLabRun::query()->sole();

        $this->get('/lab/docs')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Lab/DocsLab')
            ->has('scenarios', 6)
            ->where('scenarios.0.id', 'S1')
            ->where('agent.package', 'particle-academy/prism-agent-docs')
            ->has('runs', 1)
            ->where('runs.0.id', $run->id)
            ->where('runs.0.lane', 'scripted')
            ->where('runs.0.status', 'passed')
            ->where('runs.0.certifiable', false)
            ->where('runs.0.results.0.scenario', 'S1')
            ->has('runs.0.results.0.checks', 10)
            ->where('runs.0.results.0.artefacts', ['source.docx', 'draft.docx', 'draft.json', 'result.json'])
            ->where('chainProblems', []));
        Http::assertNothingSent();
    }

    public function test_the_owner_runs_the_scripted_lane_from_the_page(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/lab/docs/scripted', ['scenario' => 'S3'])->assertRedirect();

        $run = DocsLabRun::query()->sole();
        $this->assertSame('scripted', $run->lane);
        $this->assertSame(['S3'], $run->results->pluck('scenario')->all());
        $this->assertTrue($run->passed);
    }

    public function test_running_and_downloading_need_the_owner(): void
    {
        $run = app(DocsLabRunner::class)->scripted('S1');
        $this->post('/lab/docs/scripted', ['scenario' => 'all'])->assertForbidden();
        $this->get("/lab/docs/runs/{$run->id}/S1/draft.docx")->assertForbidden();
        $this->assertSame(1, DocsLabRun::query()->count());
    }

    public function test_an_artefact_downloads_only_when_it_is_the_recorded_file(): void
    {
        $run = app(DocsLabRunner::class)->scripted('S1');
        $this->actingAs($this->owner());

        $response = $this->get("/lab/docs/runs/{$run->id}/S1/draft.docx")->assertOk();
        $this->assertSame($run->results->sole()->artefacts['draft.docx']['sha256'], hash_file('sha256', $response->baseResponse->getFile()->getPathname()));

        $this->get("/lab/docs/runs/{$run->id}/S1/secrets.docx")->assertNotFound();
        $this->get("/lab/docs/runs/{$run->id}/S2/draft.docx")->assertNotFound();
        // A file changed on disk after the run is no longer the evidence.
        file_put_contents(DocsLabRunner::directory($run, 'S1').'/draft.docx', 'tampered');
        $this->get("/lab/docs/runs/{$run->id}/S1/draft.docx")->assertNotFound();
    }

    public function test_the_cli_refuses_an_unknown_lane_or_scenario(): void
    {
        $this->artisan('lab:docs-run', ['--lane' => 'cheap'])->assertFailed();
        $this->artisan('lab:docs-run', ['--scenario' => 'S9'])->assertFailed();
        $this->assertSame(0, DocsLabRun::query()->count());
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $owner->id]);

        return $owner;
    }
}
