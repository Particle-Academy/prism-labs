<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Team\Coordinator;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The coordinator's tool set.
 *
 * This suite existed with 34 green tests while `Coordinator` carried a PHP
 * PARSE ERROR, because nothing in it ever loaded the class. CI's formatting job
 * caught it, which is luck rather than design — a parse error should fail the
 * tests, not the linter.
 *
 * So the point of this file is first that the class LOADS, and second that the
 * tools the team is told it has are actually wired. Both are cheap; the absence
 * of both is what let a broken file ship green.
 */
class CoordinatorToolsTest extends TestCase
{
    public function test_the_coordinator_loads_and_builds_its_tools(): void
    {
        $coordinator = app(Coordinator::class);

        $tools = new ReflectionMethod($coordinator, 'tools');
        $names = array_map(fn (object $tool): string => $tool->name(), $tools->invoke($coordinator));

        // The remit is wide on purpose — conformance AND research AND integrity.
        // Narrowing it has been the recurring mistake here, so the breadth is
        // pinned rather than left to whoever edits tools() next.
        $this->assertContains('fact_check', $names);
        $this->assertContains('search_web', $names);
        $this->assertContains('research', $names);
        $this->assertContains('roster', $names);
    }

    public function test_every_tool_the_coordinator_offers_is_uniquely_named(): void
    {
        // A duplicate name is not an error anywhere in the chain: the later tool
        // silently wins, exactly as a repeated key in a JS object literal does,
        // and the model is offered something other than what was intended.
        $coordinator = app(Coordinator::class);
        $names = array_map(
            fn (object $tool): string => $tool->name(),
            (new ReflectionMethod($coordinator, 'tools'))->invoke($coordinator),
        );

        $this->assertSame(array_unique($names), $names, 'Two tools share a name; the later one silently wins.');
    }
}
