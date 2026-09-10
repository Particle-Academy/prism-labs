<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Docs\DocsLibrary;
use App\Docs\DocsToolset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Harness\PrismHarness;
use Tests\TestCase;

/**
 * The Overseer's reading, and the one thing that must not go wrong with it.
 *
 * A MODEL chooses the path these tools open. That makes the shelf boundary a
 * security property rather than a tidiness one, and it is what most of this
 * file is about.
 */
class DocsLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_indexes_the_labs_own_documentation(): void
    {
        $pages = $this->library()->index('plab');

        $this->assertNotEmpty($pages, 'The plab shelf should carry this repo\'s docs/ directory.');

        $paths = array_column($pages, 'path');
        $this->assertContains('agent-guide.md', $paths);
        $this->assertContains('architecture.md', $paths);

        // Titles come back with the index so an agent can choose a page without
        // opening several — each open is a turn.
        $this->assertNotSame('(untitled)', $pages[0]['title']);
    }

    public function test_it_reads_a_page(): void
    {
        $body = $this->library()->read('plab', 'agent-guide.md');

        $this->assertNotNull($body);
        $this->assertStringContainsString('docs_search', (string) $body);
    }

    public function test_it_refuses_to_walk_out_of_the_shelf(): void
    {
        $library = $this->library();

        // The whole reason the path is resolved before it is compared. Written
        // as a string prefix check, every one of these would pass.
        $this->assertNull($library->read('plab', '../.env'));
        $this->assertNull($library->read('plab', '../../.env'));
        $this->assertNull($library->read('plab', '../composer.json'));
        $this->assertNull($library->read('plab', '/etc/passwd'));
    }

    public function test_it_only_serves_markdown(): void
    {
        $this->assertNull($this->library()->read('plab', '../artisan'));
    }

    public function test_an_unknown_shelf_is_not_a_crash(): void
    {
        $this->assertNull($this->library()->read('nope', 'agent-guide.md'));
        $this->assertSame([], $this->library()->index('nope'));
    }

    public function test_a_missing_shelf_reports_empty_rather_than_throwing(): void
    {
        // A checkout without the sibling prism-sandbox is ordinary. Failing a
        // conversation over absent documentation would not be.
        $library = new DocsLibrary(['prism' => base_path('../this-does-not-exist')]);

        $this->assertSame([], $library->index());
        $this->assertSame([], $library->search('anything'));
    }

    public function test_search_returns_the_lines_that_matched(): void
    {
        $hits = $this->library()->search('EvictionSink');

        $this->assertNotEmpty($hits);
        $this->assertNotEmpty($hits[0]['matches']);
    }

    public function test_the_toolset_offers_all_three_tools_to_any_session(): void
    {
        $tools = (new DocsToolset($this->library()))->forSession(
            app(PrismHarness::class)
                ->for(User::factory()->create())
                ->session('docs-test'),
        );

        $names = array_map(fn ($tool): string => $tool->name(), $tools);

        // Unlike the lane toolsets, these are NOT gated on a benchmark lane. An
        // agent that cannot look something up guesses instead.
        $this->assertEqualsCanonicalizing(['docs_index', 'docs_read', 'docs_search'], $names);
    }

    private function library(): DocsLibrary
    {
        return new DocsLibrary(['plab' => base_path('docs')]);
    }
}
