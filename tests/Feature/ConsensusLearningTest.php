<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Consensus\ConsensusCoordinator;
use App\Consensus\ConsensusLearningRecorder;
use App\Http\Controllers\Lab\ConsensusController;
use App\Learnings\Learning;
use App\Learnings\LearningStore;
use App\Models\ConsensusResponse;
use App\Models\ConsensusRun;
use App\Team\AgentRoster;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * What a consensus run LEAVES BEHIND, and what its page SHOWS.
 *
 * Two defects, one surface, and they are the same defect twice.
 *
 * The first: a consensus run filed nothing at all. The standing directive in
 * this workspace is that every test results in a learning even if all lanes
 * fail, and the benchmark side was brought up to it while consensus was not —
 * so a run that called every language agent, heard back from none of them, and
 * was never reviewed left its entire spend recorded in two tables nobody greps
 * and in no file anybody reads.
 *
 * The second: the page rendered the question, the status and nothing else.
 * `consensus_responses` carried each agent's answer, stated confidence and
 * declared dissent from the first build and none of it reached the screen. A
 * surface whose stated purpose is "agreement without erasing dissent" was
 * erasing all of it — the same shape as the Run Room, which recorded proof,
 * checks, receipts and a 0Learning and displayed none of them.
 */
final class ConsensusLearningTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        // A per-test directory, so a learning filed here can never land in the
        // workspace knowledge base beside the real ones. `phpunit.xml` already
        // redirects PRISM_LEARNINGS_PATH; this narrows it further so the
        // assertions below can count files without seeing another test's.
        $this->dir = storage_path('framework/testing/learnings-consensus-'.bin2hex(random_bytes(4)));
        $this->app->singleton(LearningStore::class, fn (): LearningStore => new LearningStore($this->dir));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $responses */
    private function runWith(array $responses, array $attributes = []): ConsensusRun
    {
        $run = ConsensusRun::query()->create([
            'question' => 'Should prism-ts ship a fim surface before a provider behind it exists?',
            'evidence_digest' => str_repeat('a', 64),
            'status' => 'awaiting_review',
            ...$attributes,
        ]);

        foreach ($responses as $response) {
            ConsensusResponse::query()->create(['consensus_run_id' => $run->id, ...$response]);
        }

        return $run->refresh();
    }

    private function responded(string $agent, string $language, ?string $dissent = null, ?string $confidence = null): array
    {
        return [
            'agent' => $agent, 'language' => $language, 'status' => 'responded',
            'answer' => "No. A capability with no provider behind it moves the count and refuses every call. [{$agent}]",
            'evidence' => ['port-gaps-register.md'], 'confidence' => $confidence, 'dissent' => $dissent,
        ];
    }

    private function unavailable(string $agent, string $language): array
    {
        return [
            'agent' => $agent, 'language' => $language, 'status' => 'unavailable',
            'answer' => null, 'evidence' => [], 'confidence' => null, 'dissent' => null,
        ];
    }

    /**
     * Make every addressable lane unreachable, deterministically.
     *
     * Blanking the endpoints is not enough on its own. `AgentRoster` is a
     * container SINGLETON and `TeamServiceProvider::boot()` already resolved it
     * while registering the coordinator's tools, so by the time a test runs the
     * roster has memoised the real loopback URLs and ignores the new config.
     * Without the forget, these tests made live MCP calls to whatever happens
     * to be listening on 127.0.0.1:7411 — which on this machine IS the ts and
     * py agents, so the "nobody answered" tests quietly became "everybody
     * answered" and took nineteen seconds to do it.
     */
    private function silenceEveryAgent(): void
    {
        config(['team.endpoints.ts' => '', 'team.endpoints.py' => '']);
        $this->app->forgetInstance(AgentRoster::class);
    }

    /** @return array<string, mixed> */
    private function props(): array
    {
        $request = Request::create('/lab/consensus', 'GET', server: ['HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => '']);

        return app(ConsensusController::class)->show()->toResponse($request)->getData(true)['props'];
    }

    public function test_a_reviewed_run_files_a_learning_carrying_the_synthesis_verbatim(): void
    {
        // The synthesis is the most valuable text this surface produces and it
        // lived only in a textarea. A conclusion a human wrote after reading
        // three independent opinions belongs in the knowledge base, not in a
        // column of a table that nothing greps.
        $run = $this->runWith([$this->responded('prism.ts', 'ts'), $this->responded('prism.py', 'py')]);

        $reviewed = app(ConsensusCoordinator::class)->review($run, 'Provider first, capability second.');

        $this->assertNotNull($reviewed->learning_ref, 'Every terminal consensus run files a 0L.');
        $learning = Learning::query()->where('ref', $reviewed->learning_ref)->firstOrFail();
        $this->assertSame('prism-lab/consensus', $learning->filed_by);
        $this->assertSame(['py', 'ts'], $learning->languages, 'Roster order, so two runs read the same way side by side.');
        $this->assertStringContainsString('Provider first, capability second.', $learning->what_was_learned);
        $this->assertStringContainsString('prism.ts', $learning->evidence);
        $this->assertStringContainsString('prism.py', $learning->evidence);
        $this->assertFileExists($learning->path);
        $this->assertStringContainsString('Provider first, capability second.', (string) file_get_contents($learning->path));
    }

    public function test_a_collection_that_heard_nothing_files_its_learning_without_waiting_for_a_human(): void
    {
        // The most valuable run is the one that produced nothing, and it is the
        // one nobody comes back to review. Waiting for a human here is how the
        // "every agent was down" run ended up being the only kind that left no
        // trace at all.
        //
        // Silenced lanes rather than a network fake: LanguageAgent short
        // circuits to `unreachable` before any transport, so this exercises the
        // real coordinator without depending on whether an agent happens to be
        // listening on 127.0.0.1 while the suite runs.
        $this->silenceEveryAgent();

        $run = app(ConsensusCoordinator::class)->collect('Is the opentelemetry port comparable yet?');

        $this->assertNotNull($run->learning_ref);
        $learning = Learning::query()->where('ref', $run->learning_ref)->firstOrFail();
        $this->assertSame('urgent', $learning->severity->value, 'A run where nobody answered is the loudest kind, not the quietest.');
        $this->assertStringContainsString('Not one agent answered', $learning->what_was_learned);

        // Still reviewable. The learning is filed because no synthesis can add
        // to it, NOT because the human is locked out of recording what they did.
        $this->assertSame('awaiting_review', $run->status);
    }

    public function test_one_run_files_exactly_one_learning_however_many_paths_touch_it(): void
    {
        // The recorder is reachable from collection, review and abandonment,
        // and a run passes through two of them. Without the `learning_ref`
        // guard the same dead run would file a second 0L the moment a human
        // opened it and typed anything.
        $this->silenceEveryAgent();
        $run = app(ConsensusCoordinator::class)->collect('Is the opentelemetry port comparable yet?');
        $first = $run->learning_ref;

        $reviewed = app(ConsensusCoordinator::class)->review($run, 'Both lanes were down; nothing was learned about the port.');

        $this->assertSame($first, $reviewed->learning_ref);
        $this->assertSame(1, Learning::query()->count());
        $this->assertCount(1, glob($this->dir.'/*.md') ?: []);
    }

    public function test_declared_dissent_is_notable_and_named_rather_than_flattened(): void
    {
        // A synthesis flattens dissent by design. If the disagreement is not
        // recorded separately it survives only as long as somebody remembers
        // reading it — and the whole reason for collecting independent opinions
        // is that the minority one might be the correct one.
        $run = $this->runWith([
            $this->responded('prism.ts', 'ts'),
            $this->responded('prism.py', 'py', dissent: 'The reference ships fim on one provider, so parity means one provider here too.'),
        ]);

        $reviewed = app(ConsensusCoordinator::class)->review($run, 'Going with the majority.');
        $learning = Learning::query()->where('ref', $reviewed->learning_ref)->firstOrFail();

        $this->assertSame('notable', $learning->severity->value);
        $this->assertStringContainsString('prism.py', (string) $learning->what_should_change);
        $this->assertStringContainsString('The reference ships fim on one provider', $learning->evidence);
    }

    public function test_a_unanimous_run_is_info_and_claims_nothing_more_than_nobody_objected(): void
    {
        // Nothing in the Lab compares two natural-language answers for meaning.
        // "They agreed" is not a claim this surface is entitled to make, and a
        // 0L that made it would be a machine opinion wearing a measurement's
        // clothes -- the same defect as inventing a score.
        $run = $this->runWith([$this->responded('prism.ts', 'ts'), $this->responded('prism.py', 'py')]);

        $reviewed = app(ConsensusCoordinator::class)->review($run, 'Agreed on all points.');
        $learning = Learning::query()->where('ref', $reviewed->learning_ref)->firstOrFail();

        $this->assertSame('info', $learning->severity->value);
        $this->assertStringContainsString('nobody objected', $learning->what_was_learned);
    }

    public function test_an_unreachable_agent_is_named_so_a_partial_roster_is_not_read_as_a_full_one(): void
    {
        // A conclusion drawn from two of three agents reads exactly like one
        // drawn from three. An absent language is not a consenting language.
        //
        // Caught by reading a generated 0L rather than by an assertion: the
        // headline for this exact shape said "every agent that answered did so
        // without recording a dissent", which is true and reads as agreement
        // across the roster to anyone skimming. The headline is the part that
        // gets quoted, so it is the part that has to carry the caveat.
        $run = $this->runWith([$this->responded('prism.ts', 'ts'), $this->unavailable('prism.py', 'py')]);

        $reviewed = app(ConsensusCoordinator::class)->review($run, 'Shipping on the one opinion we have.');
        $learning = Learning::query()->where('ref', $reviewed->learning_ref)->firstOrFail();

        $this->assertSame('notable', $learning->severity->value);
        $this->assertStringContainsString('NOT unanimity', $learning->what_was_learned);
        $this->assertStringNotContainsString('nobody objected', $learning->what_was_learned);
        $this->assertStringContainsString('prism.py (py)', (string) $learning->what_should_change);
        $this->assertStringContainsString('1 of 2 agents', $learning->why_it_matters);
    }

    public function test_abandoning_an_unreviewed_run_records_that_nothing_was_concluded(): void
    {
        // The case the directive names outright. Before this there was no way
        // to REACH "abandoned" -- a run a human looked at and walked away from
        // sat in `awaiting_review` forever, indistinguishable from one they
        // were about to open, so its spend was never written down.
        $run = $this->runWith([$this->responded('prism.ts', 'ts'), $this->responded('prism.py', 'py')]);

        $abandoned = app(ConsensusCoordinator::class)->abandon($run, 'We asked the wrong question; the register already answers it.');

        $this->assertSame('abandoned', $abandoned->status);
        $this->assertNotNull($abandoned->abandoned_at);
        $learning = Learning::query()->where('ref', $abandoned->learning_ref)->firstOrFail();
        $this->assertSame('notable', $learning->severity->value);
        $this->assertStringContainsString('no human ever synthesised them', $learning->what_was_learned);
        $this->assertStringContainsString('We asked the wrong question', (string) $learning->what_should_change);
    }

    public function test_a_reviewed_run_cannot_then_be_abandoned(): void
    {
        $run = $this->runWith([$this->responded('prism.ts', 'ts')]);
        $reviewed = app(ConsensusCoordinator::class)->review($run, 'Done.');

        $this->expectException(\LogicException::class);
        app(ConsensusCoordinator::class)->abandon($reviewed);
    }

    public function test_a_learning_that_cannot_be_written_is_reported_and_does_not_take_the_run_down(): void
    {
        // A missing learning is a gap; a lost run status is corruption. The
        // synthesis a human just typed must survive a store that is broken,
        // and the failure must be REPORTED rather than swallowed silently --
        // otherwise "no 0L was filed" and "no 0L could be filed" look the same
        // from the outside.
        Exceptions::fake();
        $run = $this->runWith([$this->responded('prism.ts', 'ts')]);
        Schema::drop('learnings');

        $reviewed = app(ConsensusCoordinator::class)->review($run, 'Ship it.');

        $this->assertSame('reviewed', $reviewed->status);
        $this->assertSame('Ship it.', $reviewed->synthesis);
        $this->assertNull($reviewed->learning_ref);
        Exceptions::assertReported(QueryException::class);
    }

    public function test_the_recorder_is_safe_on_a_run_with_no_responses_at_all(): void
    {
        // Zero addressable agents is not the same failure as agents that were
        // asked and did not answer, and the 0L must survive the difference --
        // `languages: []` and an empty matrix are the shapes most likely to
        // throw inside a formatter.
        $run = $this->runWith([]);

        app(ConsensusLearningRecorder::class)->record($run);

        $learning = Learning::query()->where('ref', $run->refresh()->learning_ref)->firstOrFail();
        $this->assertSame([], $learning->languages);
        $this->assertStringContainsString('no addressable agent', $learning->what_was_learned);
        $this->assertStringContainsString('reaches nobody', $learning->why_it_matters);
    }

    public function test_a_very_long_question_does_not_become_the_filename(): void
    {
        // `question` accepts twelve thousand characters and LearningStore slugs
        // the title straight into the path. Untruncated, the first person to
        // paste a brief in gets a filesystem error instead of a learning.
        $run = $this->runWith([$this->responded('prism.ts', 'ts')], ['question' => str_repeat('parity ', 400)]);

        $reviewed = app(ConsensusCoordinator::class)->review($run, 'Fine.');

        $learning = Learning::query()->where('ref', $reviewed->learning_ref)->firstOrFail();
        $this->assertLessThan(200, strlen(basename($learning->path)));
        $this->assertFileExists($learning->path);
    }

    public function test_the_page_shows_every_agent_response(): void
    {
        // Recorded from the first build, displayed never. The page rendered the
        // question and the status and dropped the answers, the stated
        // confidence and the dissent on the floor.
        $this->runWith([
            $this->responded('prism.ts', 'ts', confidence: '0.8000'),
            $this->responded('prism.py', 'py', dissent: 'One provider is the reference behaviour.'),
        ]);

        $run = $this->props()['runs'][0];

        $this->assertCount(2, $run['responses']);
        $this->assertSame('prism.py', $run['responses'][0]['agent'], 'Responses read in a stable roster order, not insertion order.');
        $this->assertSame('One provider is the reference behaviour.', $run['responses'][0]['dissent']);
        $this->assertStringContainsString('A capability with no provider behind it', (string) $run['responses'][1]['answer']);
        $this->assertSame('0.8000', $run['responses'][1]['confidence']);
    }

    public function test_a_stated_confidence_of_none_stays_null_rather_than_becoming_zero(): void
    {
        // A model that stated no confidence is not a model that is certain it
        // is wrong. Casting the decimal to a float on the way to the page turns
        // the first into the second.
        $this->runWith([$this->responded('prism.ts', 'ts')]);

        $this->assertNull($this->props()['runs'][0]['responses'][0]['confidence']);
    }

    public function test_the_page_shows_the_learning_the_run_filed(): void
    {
        $run = $this->runWith([$this->responded('prism.ts', 'ts')]);
        $reviewed = app(ConsensusCoordinator::class)->review($run, 'Recorded.');

        $learning = $this->props()['runs'][0]['learning'];

        $this->assertNotNull($learning, 'Every terminal run files a 0L, and the run that filed it must show it.');
        $this->assertSame($reviewed->learning_ref, $learning['ref']);
        $this->assertNotEmpty($learning['why_it_matters']);
        $this->assertNotEmpty($learning['severity_label']);
    }

    public function test_the_tally_counts_what_happened_and_invents_no_agreement_figure(): void
    {
        // Do not add an agreement percentage here. Consensus has no rubric, no
        // weighted dimensions and no cited receipts behind a number, so one on
        // this panel would be read as a verdict on the languages rather than as
        // a count of who replied. The synthesis is where a verdict belongs, and
        // it carries the name of whoever wrote it.
        $this->runWith([
            $this->responded('prism.ts', 'ts'),
            $this->responded('prism.py', 'py', dissent: 'Disagree.'),
            $this->unavailable('prism.rust', 'rust'),
        ]);

        $tally = $this->props()['runs'][0]['tally'];

        $this->assertSame(['agents' => 3, 'responded' => 2, 'unavailable' => 1, 'dissenting' => 1], $tally);
    }

    public function test_an_unreviewed_run_shows_no_learning_rather_than_a_dangling_reference(): void
    {
        // `learning_ref` is a string, not a foreign key. A ref whose row was
        // deleted must render as "none filed" and never as a half-built panel.
        $run = $this->runWith([$this->responded('prism.ts', 'ts')]);
        $this->assertNull($this->props()['runs'][0]['learning']);

        $run->forceFill(['learning_ref' => '0L-9999'])->save();
        $this->assertNull($this->props()['runs'][0]['learning']);
    }
}
