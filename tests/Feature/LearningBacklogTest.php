<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Learnings\Learning;
use App\Learnings\LearningBacklog;
use App\Learnings\Severity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The backlog of learnings nobody has acted on.
 *
 * Filing a 0L used to be the end of the loop: the Lab wrote it to disk and to
 * the database and nothing ever asked whether anybody had done something about
 * it. A backlog nobody can see the shape of is indistinguishable from an
 * archive, and the difference between those two is the entire point of filing
 * them.
 */
class LearningBacklogTest extends TestCase
{
    use RefreshDatabase;

    private function file(string $ref, Severity $severity): Learning
    {
        return Learning::query()->create([
            'ref' => $ref, 'title' => 'T '.$ref, 'filed_by' => 'prism-lab/benchmark',
            'severity' => $severity, 'languages' => ['php'],
            'what_was_learned' => 'w', 'evidence' => 'e', 'why_it_matters' => 'y',
            'path' => '/tmp/'.$ref.'.md',
        ]);
    }

    public function test_the_backlog_is_worst_first_not_newest_first(): void
    {
        // An urgent learning from last week outranks an informational one from
        // this morning. A backlog sorted newest-first buries exactly the items
        // it exists to raise.
        $this->file('0L-0001', Severity::Info);
        $this->file('0L-0002', Severity::Urgent);
        $this->file('0L-0003', Severity::Notable);

        $this->assertSame(
            ['0L-0002', '0L-0003', '0L-0001'],
            app(LearningBacklog::class)->open()->pluck('ref')->all(),
        );
    }

    public function test_an_acted_learning_leaves_the_backlog(): void
    {
        $this->file('0L-0001', Severity::Urgent);
        $this->file('0L-0002', Severity::Urgent);

        app(LearningBacklog::class)->close('0L-0001', 'fixed the cursor');

        $this->assertSame(['0L-0002'], app(LearningBacklog::class)->open()->pluck('ref')->all());
    }

    public function test_closing_without_a_note_is_refused(): void
    {
        // "Closed" with no reason is the same as deleted: it destroys the one
        // thing a later reader needs, which is whether this was fixed, deferred
        // deliberately, or judged wrong.
        $this->file('0L-0001', Severity::Urgent);

        $this->assertNull(app(LearningBacklog::class)->close('0L-0001', '   '));
        $this->assertCount(1, app(LearningBacklog::class)->open());
    }

    public function test_sending_is_not_closing(): void
    {
        // THE distinction. Handing a learning to an agent is not the same as
        // the learning being dealt with, and collapsing the two would silently
        // lose every finding that was passed on and then dropped.
        $one = $this->file('0L-0001', Severity::Urgent);

        app(LearningBacklog::class)->markSent(collect([$one]));

        $this->assertNotNull($one->refresh()->sent_at);
        $this->assertNull($one->acted_at);
        $this->assertCount(1, app(LearningBacklog::class)->open(), 'A sent learning is still open until someone says what they did.');
    }

    public function test_it_can_be_narrowed_to_the_urgent_ones(): void
    {
        $this->file('0L-0001', Severity::Urgent);
        $this->file('0L-0002', Severity::Notable);
        $this->file('0L-0003', Severity::Info);

        $this->assertSame(['0L-0001'], app(LearningBacklog::class)->open(Severity::Urgent)->pluck('ref')->all());
        $this->assertSame(['0L-0001', '0L-0002'], app(LearningBacklog::class)->open(Severity::Notable)->pluck('ref')->all());
    }

    public function test_the_open_command_reports_an_empty_backlog_as_success(): void
    {
        $this->artisan('learnings:open')->expectsOutputToContain('Nothing open')->assertSuccessful();
    }

    public function test_the_close_command_requires_a_note(): void
    {
        $this->file('0L-0001', Severity::Urgent);

        $this->artisan('learnings:close', ['ref' => '0L-0001'])->assertFailed();
        $this->artisan('learnings:close', ['ref' => '0L-0001', '--note' => 'deferred, needs a decision'])->assertSuccessful();

        $this->assertSame('deferred, needs a decision', Learning::query()->where('ref', '0L-0001')->value('acted_note'));
    }
}
