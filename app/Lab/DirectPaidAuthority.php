<?php

declare(strict_types=1);

namespace App\Lab;

use App\Benchmarks\CanonicalJson;

/** Application services also enforce authority when invoked without a controller. */
final readonly class DirectPaidAuthority
{
    public function __construct(private PaidRunContext $context) {}

    public function research(string $question, ?int $maxResults = null): void
    {
        // Search is a nested tool, not a separately approved public action.
        $actions = ['team.ask', 'chat.run', 'agent.send', 'agent.voice', 'benchmarks.launch'];
        $intent = $this->context->authorizedRun($maxResults === null ? [...$actions, 'provider.research'] : $actions);
        if ($question === '' || strlen($question) > 10000
            || ($maxResults !== null && ($maxResults < 1 || $maxResults > (int) config('team.research.max_results')))) {
            throw new PaidRunNotAuthorised;
        }
        if ($intent->action === 'provider.research') {
            $this->context->requireInput(['provider.research'], ['question' => $question]);

            return;
        }
        if ($intent->action === 'benchmarks.launch') {
            // Builders may research their approved artifact. A launch producer,
            // scorer or commentator cannot borrow that lane's tool authority.
            $this->context->requireBoundWork('benchmark.lane');

            return;
        }
        // Questions generated inside a bounded, approved tool loop are expected
        // to differ from its initial prompt. The parent action and current
        // runtime/worker evidence still have to be valid on every tool call.
        $this->context->requireActions($actions);
    }

    public function testCase(PrismTestCase $case): void
    {
        $actions = ['tests.run', 'cli.tests.run'];
        $intent = $this->context->authorizedRun($actions);
        $input = $intent->payload['input'];
        $known = app(PrismTestRegistry::class)->find($case->id);
        if ($known === null || CanonicalJson::encode($case->toArray()) !== CanonicalJson::encode($known->toArray())
            || ! is_array($input['cases'] ?? null) || ! in_array($case->id, $input['cases'], true)
            || ($intent->action === 'cli.tests.run' && $case->costly && ($input['include_costly'] ?? false) !== true)) {
            throw new PaidRunNotAuthorised;
        }
        $this->context->requireActions($actions);
    }
}
