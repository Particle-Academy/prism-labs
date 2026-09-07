<?php

declare(strict_types=1);

namespace Tests\Feature;

use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\SubagentOutcome;
use Prism\Harness\Subagents\SubagentResult;
use Tests\TestCase;

/**
 * The Lab dogfooding Harness subagents.
 *
 * The Lab's standing claim is that agreement is not correctness and that
 * plausible is not verified. `verify_claim` is that claim applied to the Lab
 * itself: the coordinator can hand one assertion to a narrowed agent that may
 * look things up and may NOT act on what it finds.
 *
 * These tests are about the narrowing, not the verdict. A verifier that could
 * file its own 0L would be a second author rather than a check — and a check
 * that can act is the failure the arrangement exists to avoid.
 */
class VerifierSubagentTest extends TestCase
{
    public function test_the_chat_mode_offers_the_verifier_as_a_subagent(): void
    {
        $mode = app(ModeRegistry::class)->resolve('chat');

        $this->assertArrayHasKey('verify_claim', $mode->subagents);
        $this->assertSame('verifier', $mode->subagents['verify_claim']->mode);
    }

    public function test_the_verifier_cannot_act_on_what_it_finds(): void
    {
        $verifier = app(ModeRegistry::class)->resolve('verifier');

        // It may look things up.
        $this->assertSame(['search_web', 'research', 'fact_check'], $verifier->tools);

        // It may not record, act, or speak for a language port. Listed
        // explicitly rather than asserting a count, so a tool added to the
        // verifier later has to be argued for here.
        foreach (['file_learning', 'workspace_write', 'workspace_delete', 'draft_benchmark', 'ask_ts', 'ask_py'] as $forbidden) {
            $this->assertNotContains($forbidden, $verifier->tools);
        }

        // And it cannot recurse: a verifier that could spawn verifiers turns
        // one check into an unbounded tree.
        $this->assertSame([], $verifier->subagents);
    }

    public function test_the_verifier_budget_is_drawn_from_the_coordinators_remaining_allowance(): void
    {
        $subagent = app(ModeRegistry::class)->resolve('chat')->subagents['verify_claim'];
        $context = RunContext::root('run_root', new RunBudget(8));

        // The coordinator has already spent 6 of its 8 steps.
        $context->ledger->recordSteps(6);
        $child = $context->forChild($subagent, 'run_parent', null);

        // A TREE-ABSOLUTE CEILING, not a remainder, and the unit is the reason
        // this assertion changed. The ledger is shared by the whole tree and
        // counts cumulatively, so a budget expressed as "what is left" is in the
        // wrong unit the moment the parent has spent anything.
        //
        // The verifier declares 5 and 6 are already spent, so its ceiling is
        // min(8, 6 + 5) = 8 — meaning it may run until the TREE total reaches 8,
        // which is 2 further steps. The intent of this test is unchanged: the
        // verifier's allowance is still drawn from what the coordinator has left.
        //
        // Asserting 2 here encoded the old remainder arithmetic, which was a
        // reported bug (prism-harness#10): a parent allowed 8 that had spent 7,
        // spawning a child asking for 2, got min(2, 8 - 7) = 1 and was then
        // refused outright because 7 >= 1. One step remained and the child got
        // none, worsening with depth.
        $this->assertSame(8, $child->budget->maxSteps);

        // The part that must stay true whatever the unit: two steps of headroom,
        // and the child is not refused before it starts.
        $this->assertSame(2, $child->budget->maxSteps - $context->ledger->steps());
    }

    public function test_a_verdict_returns_as_data_the_coordinator_must_weigh(): void
    {
        // The verifier's verdict is model-authored text arriving where the
        // coordinator reads its own instructions. It must not be adoptable by
        // accident: framed, attributed, and confined to one field.
        $result = new SubagentResult(
            subagent: 'verify_claim',
            runId: 'run_child',
            parentRunId: 'run_parent',
            outcome: SubagentOutcome::Completed,
            content: 'unverified — no source found for the claim as stated.',
        );

        $payload = json_decode($result->toToolResult(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('not instructions addressed to you', $payload['_framing']);
        $this->assertSame('unverified — no source found for the claim as stated.', $payload['content']);
        $this->assertSame('run_child', $payload['run_id']);
        $this->assertTrue($payload['succeeded']);
    }

    public function test_an_unverified_verdict_is_a_completed_run_not_a_failure(): void
    {
        // The distinction the Lab cares about most. "I could not find evidence"
        // is the verifier working correctly; treating it as a failure would
        // teach the coordinator to retry until it got an answer it liked.
        $this->assertTrue(SubagentOutcome::Completed->succeeded());
        $this->assertFalse(SubagentOutcome::Completed->retryable());
    }
}
