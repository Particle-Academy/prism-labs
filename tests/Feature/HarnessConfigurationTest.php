<?php

declare(strict_types=1);

namespace Tests\Feature;

use Prism\Harness\Modes\ModeRegistry;
use Tests\TestCase;

/**
 * The Lab's own harness configuration, checked the way the harness offers.
 *
 * `harness:doctor` resolves EVERY mode rather than the default one, which is
 * the point: a mode nobody has entered yet keeps its misconfiguration until
 * someone switches to it, and in this app that someone is an agent mid-run.
 */
class HarnessConfigurationTest extends TestCase
{
    public function test_the_lab_harness_configuration_is_consistent(): void
    {
        $this->artisan('harness:doctor')->assertSuccessful();
    }

    public function test_the_only_irreversible_lab_tool_needs_a_human(): void
    {
        $benchmark = app(ModeRegistry::class)->resolve('benchmark');

        // Deleting a workspace is the one action a rerun cannot undo.
        $this->assertTrue($benchmark->needsApproval('workspace_delete'));

        // And the gate is narrow. Gating everything is how a gate stops being
        // read — the render is expensive, not irreversible.
        $this->assertFalse($benchmark->needsApproval('remotion_render'));
        $this->assertFalse($benchmark->needsApproval('workspace_write'));
    }

    public function test_every_configured_mode_resolves(): void
    {
        $names = app(ModeRegistry::class)->names();

        $this->assertContains('chat', $names);
        $this->assertContains('verifier', $names);

        // resolve() validates as it goes; all() is the assertion.
        $this->assertSame($names, array_keys(app(ModeRegistry::class)->all()));
    }
}
