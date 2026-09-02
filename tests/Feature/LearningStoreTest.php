<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Learnings\Learning;
use App\Learnings\LearningStore;
use App\Learnings\Severity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class LearningStoreTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/learnings-'.bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    private function store(): LearningStore
    {
        return new LearningStore($this->dir);
    }

    public function test_it_writes_both_the_file_and_the_row(): void
    {
        $learning = $this->store()->file(
            title: 'Something worth keeping',
            filedBy: 'prism.php',
            languages: ['php', 'ts'],
            whatWasLearned: 'A thing.',
            evidence: 'The output.',
            whyItMatters: 'Because two ports disagree.',
        );

        $this->assertSame('0L-0001', $learning->ref);
        $this->assertDatabaseHas('learnings', ['ref' => '0L-0001']);
        $this->assertFileExists($learning->path);

        $body = file_get_contents($learning->path);
        $this->assertStringContainsString('id: 0L-0001', $body);
        $this->assertStringContainsString('## Why it matters to the ecosystem', $body);
        $this->assertStringContainsString('Because two ports disagree.', $body);
    }

    public function test_it_refuses_a_learning_that_cannot_say_why_it_matters(): void
    {
        // The section that makes a 0L worth reading later is the first one an
        // agent in a hurry leaves blank. Enforced, not trusted.
        $this->expectException(RuntimeException::class);

        $this->store()->file(
            title: 'Log line',
            filedBy: 'prism.php',
            languages: ['php'],
            whatWasLearned: 'A thing.',
            evidence: 'The output.',
            whyItMatters: '   ',
        );
    }

    public function test_nothing_is_written_when_the_learning_is_refused(): void
    {
        try {
            $this->store()->file(
                title: 'Log line',
                filedBy: 'prism.php',
                languages: ['php'],
                whatWasLearned: 'A thing.',
                evidence: 'The output.',
                whyItMatters: '',
            );
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, Learning::count());
        $this->assertSame([], glob($this->dir.'/*.md') ?: []);
    }

    public function test_the_next_ref_comes_from_the_files_not_the_table(): void
    {
        // The files outlive any one database. A Lab rebuilt from scratch must
        // not reissue 0L-0001 over a learning that already exists on disk.
        $this->store()->file(
            title: 'First', filedBy: 'prism.php', languages: ['php'],
            whatWasLearned: 'a', evidence: 'b', whyItMatters: 'c',
        );

        Learning::query()->delete();

        $this->assertSame('0L-0002', $this->store()->nextRef());
    }

    public function test_the_next_ref_never_reissues_one_the_table_still_holds(): void
    {
        // The inverse of the test above, and the one that actually bit. `ref`
        // is UNIQUE in the table, so a ref derived from the files alone is
        // rejected when a markdown file was deleted while its row stayed —
        // the insert fails on the constraint and the learning is lost. It
        // happened in this workspace: fixture-named 0Ls were cleaned off disk
        // and the next real learning collided with a row nobody could see.
        $first = $this->store()->file(
            title: 'First', filedBy: 'prism.php', languages: ['php'],
            whatWasLearned: 'a', evidence: 'b', whyItMatters: 'c',
        );

        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }

        $this->assertSame('0L-0001', $first->ref);
        $this->assertSame('0L-0002', $this->store()->nextRef());

        // And the next real filing succeeds rather than dying on the
        // constraint — a gap in the sequence is harmless, a collision is not.
        $second = $this->store()->file(
            title: 'Second', filedBy: 'prism.php', languages: ['php'],
            whatWasLearned: 'a', evidence: 'b', whyItMatters: 'c',
        );

        $this->assertSame('0L-0002', $second->ref);
    }

    public function test_it_quotes_a_title_that_yaml_would_read_as_structure(): void
    {
        // Titles are model-written and will eventually contain a colon.
        $learning = $this->store()->file(
            title: 'Ports disagree: absent versus null',
            filedBy: 'prism.php', languages: ['php', 'py'],
            whatWasLearned: 'a', evidence: 'b', whyItMatters: 'c',
            severity: Severity::Notable,
        );

        $this->assertStringContainsString('title: "Ports disagree: absent versus null"', file_get_contents($learning->path));
    }
}
