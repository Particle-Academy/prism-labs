<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Lab\LabSession;
use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Prism\Harness\Enums\TaskOutcome;
use Prism\Harness\Enums\TaskState;
use Prism\Harness\Exceptions\InvalidTaskIdentifier;
use Prism\Harness\Exceptions\TaskNotReleasable;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tasks\StoreTaskSource;
use Prism\Harness\Tasks\TaskRecord;
use Prism\Harness\Tools\TaskCompletionTool;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Throwable;

/**
 * `prism-harness` task lists, exercised as a CONSUMER — against the real
 * package, in a real application, with nothing stubbed.
 *
 * WHAT THIS ASKS, AND WHAT IT DELIBERATELY DOES NOT. Seeding two tasks and
 * claiming them proves almost nothing: it is the happy path, and the happy
 * path is the one every port already agrees on. Every property below is the
 * SECURITY question instead — what can still be invoked, by whom, after the
 * thing that was supposed to stop it.
 *
 * Three of them come straight off the package's own documented guarantees:
 *
 *  - an expired lease returns a task to `todo`, never to `failed`;
 *  - `release()` refuses anyone but the current holder, AND the legitimate
 *    reclaimer's own release still succeeds afterwards — that second half is
 *    the one an "already terminal" error swallows;
 *  - the agent cannot close its own task.
 *
 * The rest are the adversarial versions of those, because a probe that asks
 * politely is how `/lab/team` reported a broken property green for weeks: it
 * used a clean tool name. So a worker id here is attacked with a trailing
 * space, a case flip and a Cyrillic homoglyph, and the completion tool is
 * asked under a HOST POLICY THAT ALLOWS EVERYTHING — which is the
 * configuration where a careless implementation opens.
 *
 * THE LAST PROPERTY IS A POSITIVE CONTROL AND IS NOT DECORATION. Every other
 * lane here passes when the completion tool refuses. A tool that refused
 * unconditionally — or one wired up wrong by this probe — would make all of
 * them green while proving nothing at all. P7 authorizes the holder properly
 * and requires the task to actually close, so the refusals above are known to
 * be refusals rather than an inert tool.
 *
 * THE CLOCK IS FROZEN, NEVER SLEPT ON. Lease expiry is the axis half of these
 * properties turn on; a probe that waited out a real lease would take five
 * minutes and flake. `Carbon::setTestNow()` is what {@see StoreTaskSource}
 * reads, so travelling it moves the package's own clock rather than a copy.
 */
final class TaskListProbe
{
    /** Every worker id is spelled here, because two of them differ by one codepoint. */
    private const WORKER_A = 'lab-worker-a';

    private const WORKER_B = 'lab-worker-b';

    private const WORKER_C = 'lab-worker-c';

    private const WORKER_D = 'lab-worker-d';

    private const WORKER_E = 'lab-worker-e';

    public function __construct(
        private readonly LabSession $sessions,
        private readonly PrismHarness $harness,
        private readonly ToolRegistry $tools,
        private readonly Container $container,
    ) {}

    /**
     * @return array{run: string, held: bool, properties: list<array<string, mixed>>, lists: list<string>}
     */
    public function run(): array
    {
        $run = bin2hex(random_bytes(4));
        $lists = [];

        $properties = [
            $this->guard('lapsed-lease-returns-to-todo', $run, $lists, $this->lapsedLeaseReturnsToTodo(...)),
            $this->guard('release-refuses-a-non-holder', $run, $lists, $this->releaseRefusesANonHolder(...)),
            $this->guard('worker-ids-are-compared-verbatim', $run, $lists, $this->workerIdsAreComparedVerbatim(...)),
            $this->guard('completion-tool-is-registered-nowhere', $run, $lists, $this->completionToolIsRegisteredNowhere(...)),
            $this->guard('silence-is-not-consent', $run, $lists, $this->silenceIsNotConsent(...)),
            $this->guard('no-worker-closes-a-neighbours-task', $run, $lists, $this->noWorkerClosesANeighboursTask(...)),
            $this->guard('an-authorized-holder-can-close-its-own', $run, $lists, $this->anAuthorizedHolderCanCloseItsOwn(...)),
        ];

        return [
            'run' => $run,
            'held' => array_reduce($properties, fn (bool $carry, array $p): bool => $carry && $p['holds'] === true, true),
            'properties' => $properties,
            'lists' => $lists,
        ];
    }

    /**
     * One property, and a throw is a FAILED property rather than a failed page.
     *
     * A probe whose sixth lane takes down the first five reports nothing about
     * the five, which is the outcome least useful to the person reading it.
     *
     * @param  list<string>  $lists
     * @param  callable(string): array{claim: string, why: string, steps: list<array<string, mixed>>, list: string}  $property
     * @return array<string, mixed>
     */
    private function guard(string $id, string $run, array &$lists, callable $property): array
    {
        try {
            $result = $property($run);
            $lists[] = $result['list'];

            return [
                'id' => $id,
                'claim' => $result['claim'],
                'why' => $result['why'],
                'holds' => array_reduce($result['steps'], fn (bool $c, array $s): bool => $c && $s['ok'] === true, true),
                'steps' => $result['steps'],
                'error' => null,
            ];
        } catch (Throwable $failure) {
            report($failure);

            return [
                'id' => $id,
                'claim' => 'This property could not be exercised.',
                'why' => 'A probe that cannot run is not a property that holds.',
                'holds' => false,
                'steps' => [],
                'error' => $failure::class.': '.$failure->getMessage(),
            ];
        } finally {
            // Always, including on the throw. A frozen clock leaking out of
            // here would silently date every later row in the request.
            Carbon::setTestNow();
        }
    }

    /**
     * A worker dying is not the task failing.
     *
     * `failed` is terminal and a failed task does not return to the queue, so
     * recording a lapsed lease as a failure burns a retry that never ran — the
     * work is dropped and the list agrees that it was attempted.
     *
     * @return array{claim: string, why: string, steps: list<array<string, mixed>>, list: string}
     */
    private function lapsedLeaseReturnsToTodo(string $run): array
    {
        [$tasks, $key] = $this->list($run, 'lease');
        $id = 'reconcile-ledger';
        $tasks->add('Reconcile yesterday\'s ledger against the provider invoices.', $id);

        $t0 = $this->freeze();
        $claim = $tasks->claim(self::WORKER_A, leaseSeconds: 60);
        $steps = [
            $this->step(
                self::WORKER_A.' claims with a 60 second lease',
                $claim === null ? 'nothing was claimable' : sprintf('%s, held until +60s', $claim->state->value),
                $claim?->state === TaskState::Claimed && $claim->claimedUntil === $t0 + 60,
                $claim,
            ),
            $this->step('pending() while the claim is live', (string) $tasks->pending().' claimable', $tasks->pending() === 0),
        ];

        // The worker dies here. Nothing releases, nothing sweeps.
        Carbon::setTestNow(Carbon::createFromTimestamp($t0 + 61));

        $after = $tasks->find($id);
        $steps[] = $this->step(
            'the lease lapses (clock +61s), and the list is read again',
            $after === null ? 'the task vanished' : $after->state->value,
            $after?->state === TaskState::Todo,
            $after,
        );
        $steps[] = $this->step(
            'the state it did NOT return to',
            $after?->state === TaskState::Failed ? 'failed' : 'not failed',
            $after?->state !== TaskState::Failed,
        );
        $steps[] = $this->step(
            'the holder on the record',
            $after?->claimedBy ?? 'null',
            $after !== null && $after->claimedBy === null && $after->claimedUntil === null,
        );
        $steps[] = $this->step('pending() after the lapse', (string) $tasks->pending().' claimable', $tasks->pending() === 1);

        return [
            'claim' => 'A lapsed lease returns the task to todo, never to failed.',
            'why' => 'failed is terminal and does not requeue. Recording a dead worker as a failed task drops the work while the list reports it was attempted.',
            'steps' => $steps,
            'list' => $key,
        ];
    }

    /**
     * The sequence the package's own docs describe, staged end to end.
     *
     * A's lease lapses mid-task. B legitimately reclaims and starts. A finishes
     * late and releases. THE HALF THAT MATTERS IS THE LAST STEP: B's own
     * release must still succeed. Without the ownership check A's release wins,
     * the task reads `done` while B is still working, and B is then blamed for
     * A's mistake by an "already terminal" error.
     *
     * @return array{claim: string, why: string, steps: list<array<string, mixed>>, list: string}
     */
    private function releaseRefusesANonHolder(string $run): array
    {
        [$tasks, $key] = $this->list($run, 'holder');
        $id = 'draft-release-notes';
        $tasks->add('Draft the release notes for the next tag.', $id);

        $t0 = $this->freeze();
        $a = $tasks->claim(self::WORKER_A, leaseSeconds: 60);

        Carbon::setTestNow(Carbon::createFromTimestamp($t0 + 61));
        $b = $tasks->claim(self::WORKER_B, leaseSeconds: 300);

        $steps = [
            $this->step(
                self::WORKER_A.' claims, its lease lapses, '.self::WORKER_B.' reclaims',
                $b === null ? 'nothing was claimable' : 'held by '.($b->claimedBy ?? 'nobody'),
                $b?->claimedBy === self::WORKER_B && $a?->id === $b->id,
                $b,
            ),
        ];

        // A finishes late and reports success for work B is halfway through.
        $refusal = $this->refusalFrom(fn () => $tasks->release($a ?? new TaskRecord($id, ''), self::WORKER_A, TaskOutcome::Done));
        $steps[] = $this->step(
            self::WORKER_A.' finishes late and releases it as done',
            $refusal ?? 'ACCEPTED — a live claim was overwritten',
            $refusal === 'task_lease_not_held',
        );

        $survived = $tasks->find($id);
        $steps[] = $this->step(
            'the claim '.self::WORKER_B.' is working under',
            $survived === null ? 'gone' : $survived->state->value.', held by '.($survived->claimedBy ?? 'nobody'),
            $survived?->state === TaskState::Claimed && $survived->claimedBy === self::WORKER_B,
            $survived,
        );

        // The half an "already terminal" error swallows.
        $bReleased = $this->refusalFrom(fn () => $tasks->release($b ?? new TaskRecord($id, ''), self::WORKER_B, TaskOutcome::Done));
        $steps[] = $this->step(
            self::WORKER_B.' releases its own work as done',
            $bReleased ?? 'accepted',
            $bReleased === null,
            $tasks->find($id),
        );

        $again = $this->refusalFrom(fn () => $tasks->release($b ?? new TaskRecord($id, ''), self::WORKER_B, TaskOutcome::Done));
        $steps[] = $this->step(
            'and releasing the finished task a second time',
            $again ?? 'ACCEPTED — a terminal state moved again',
            $again === 'task_already_terminal',
        );

        return [
            'claim' => 'release() refuses a non-holder, and the reclaimer\'s own release still succeeds.',
            'why' => 'A late release by a lapsed holder overwrites a live claim: the task reads done while the second worker is still working, its work is discarded, and its own release then fails as already terminal.',
            'steps' => $steps,
            'list' => $key,
        ];
    }

    /**
     * The G-36 shape, asked of worker ids.
     *
     * `human-plus` reserved confirmation for the human and a single trailing
     * space defeated it in all three languages at once, because the name was
     * chosen by the surface. A worker id is chosen by the APPLICATION, so the
     * same question is worth asking: does a padded, case-flipped or
     * homoglyphed id resolve to the holder?
     *
     * It must not. The package compares verbatim and never trims, which fails
     * CLOSED — a mistyped id is refused rather than merged into the real
     * holder's claim.
     *
     * @return array{claim: string, why: string, steps: list<array<string, mixed>>, list: string}
     */
    private function workerIdsAreComparedVerbatim(string $run): array
    {
        [$tasks, $key] = $this->list($run, 'identity');
        $id = 'rotate-credentials';
        $tasks->add('Rotate the staging credentials and record the new expiry.', $id);

        $this->freeze();
        $blank = $this->refusalFrom(fn () => $tasks->claim(''));
        $steps = [
            $this->step(
                'a worker that failed to identify itself claims with an empty id',
                $blank ?? 'ACCEPTED — every anonymous worker would share one claim',
                $blank === 'task_identifier_blank',
            ),
        ];

        $claim = $tasks->claim(self::WORKER_C, leaseSeconds: 300);
        $steps[] = $this->step(
            self::WORKER_C.' claims it',
            $claim === null ? 'nothing was claimable' : 'held by '.($claim->claimedBy ?? 'nobody'),
            $claim?->claimedBy === self::WORKER_C,
            $claim,
        );

        // Each of these is one codepoint away from the holder.
        $impostors = [
            'lab-worker-c ' => 'a trailing space',
            ' lab-worker-c' => 'a leading space',
            'lab-worker-C' => 'a capital C',
            "lab-worker-\u{0441}" => 'a Cyrillic es (U+0441) for the c',
            "lab-worker-c\u{00A0}" => 'a non-breaking space (U+00A0)',
        ];

        foreach ($impostors as $impostor => $label) {
            $refusal = $this->refusalFrom(fn () => $tasks->release($claim ?? new TaskRecord($id, ''), $impostor, TaskOutcome::Done));
            $steps[] = $this->step(
                'released by the same id with '.$label,
                $refusal ?? 'ACCEPTED — the holder\'s claim was overwritten',
                $refusal === 'task_lease_not_held',
            );
        }

        $survived = $tasks->find($id);
        $steps[] = $this->step(
            'the holder after five near-miss releases',
            $survived === null ? 'gone' : $survived->state->value.', held by '.($survived->claimedBy ?? 'nobody'),
            $survived?->state === TaskState::Claimed && $survived->claimedBy === self::WORKER_C,
            $survived,
        );

        $real = $this->refusalFrom(fn () => $tasks->release($claim ?? new TaskRecord($id, ''), self::WORKER_C, TaskOutcome::Done));
        $steps[] = $this->step(
            'and the holder itself, spelled exactly',
            $real ?? 'accepted',
            $real === null,
            $tasks->find($id),
        );

        return [
            'claim' => 'Worker ids are compared byte for byte — padding, case and homoglyphs are all a different worker.',
            'why' => 'A forgiving comparison merges two workers into one holder and fails OPEN. Exact comparison refuses a mistyped id instead, which is the direction a mistake should break in.',
            'steps' => $steps,
            'list' => $key,
        ];
    }

    /**
     * Nothing in this application registers the completion tool.
     *
     * The wildcard is the question worth asking. The Lab's `chat` mode offers
     * `['*']`, so a `complete_task` registered anywhere — a provider, a
     * factory, a stray line in a service provider — would be handed to EVERY
     * chat run without anyone deciding to. Asking for it by name only would
     * miss that entirely.
     *
     * @return array{claim: string, why: string, steps: list<array<string, mixed>>, list: string}
     */
    private function completionToolIsRegisteredNowhere(string $run): array
    {
        [$tasks, $key, $session] = $this->list($run, 'registry');
        $tasks->add('A task nobody will be able to close from inside the model.', 'unclosable');

        $byName = $this->tools->resolve([TaskCompletionTool::NAME], $session);
        $byWildcard = $this->tools->resolve(['*'], $session);

        return [
            'claim' => 'TaskCompletionTool is registered nowhere, so no agent in this application is offered it.',
            'why' => 'If the model can set its own task to done, "run until the goal is met" becomes "run until it decides it is met" — and a stalled run ends by declaring victory.',
            'steps' => [
                $this->step(
                    'the toolset asked for '.TaskCompletionTool::NAME.' by name',
                    $byName === [] ? 'nothing resolved' : implode(', ', array_keys($byName)),
                    ! array_key_exists(TaskCompletionTool::NAME, $byName),
                ),
                $this->step(
                    'the toolset the chat mode actually asks for — every tool, [*]',
                    sprintf('%d tools, %s', count($byWildcard), array_key_exists(TaskCompletionTool::NAME, $byWildcard) ? 'INCLUDING '.TaskCompletionTool::NAME : 'none of them '.TaskCompletionTool::NAME),
                    ! array_key_exists(TaskCompletionTool::NAME, $byWildcard),
                ),
                $this->step(
                    'so completion is recorded by',
                    'the application, from evidence',
                    ! array_key_exists(TaskCompletionTool::NAME, $byWildcard),
                ),
            ],
            'list' => $key,
        ];
    }

    /**
     * A host that trusts this participant with EVERY tool has still not been
     * asked about self-completion.
     *
     * This is the configuration a careless implementation opens in, and it is
     * not exotic: `harness.tool` returning true for a trusted participant is
     * the ordinary way to say "this one may use tools". `allowsCall()` returns
     * true when no per-call policy exists — correct for an ordinary tool, and
     * wrong for the one authority that decides whether a run is finished.
     *
     * @return array{claim: string, why: string, steps: list<array<string, mixed>>, list: string}
     */
    private function silenceIsNotConsent(string $run): array
    {
        [$tasks, $key, $session] = $this->list($run, 'silence');
        $id = 'summarise-incident';
        $tasks->add('Summarise the incident and attach the timeline.', $id);

        $this->freeze();
        $claim = $tasks->claim(self::WORKER_D, leaseSeconds: 300);

        // A broad OFFER-time policy and no per-call policy at all.
        $authorizer = new ToolAuthorizer($this->gateAllowing($session, offer: true, call: null), enabled: true);
        $tool = TaskCompletionTool::for($tasks, $session, $authorizer, self::WORKER_D);
        $offered = $authorizer->allowed($session, [TaskCompletionTool::NAME => $tool]);

        $steps = [
            $this->step(
                'the host allows every tool at offer time (harness.tool → true)',
                count($offered) === 1 ? TaskCompletionTool::NAME.' was offered to the run' : 'nothing was offered',
                count($offered) === 1,
            ),
            $this->step(
                'and has defined no harness.tool.call policy',
                $authorizer->hasCallPolicy() ? 'a call policy exists' : 'no call policy exists',
                $authorizer->hasCallPolicy() === false,
            ),
        ];

        $decision = $this->invoke($offered[0] ?? $tool, $id, 'done');
        $steps[] = $this->step(
            'the holder calls it on its own task',
            $this->verdict($decision),
            ($decision['allowed'] ?? null) === false,
        );

        $after = $tasks->find($id);
        $steps[] = $this->step(
            'the list afterwards',
            $after === null ? 'gone' : $after->state->value.', held by '.($after->claimedBy ?? 'nobody'),
            $after?->state === TaskState::Claimed && $after->claimedBy === self::WORKER_D,
            $after,
        );

        return [
            'claim' => 'A blanket "may use tools" policy does not grant self-completion. Silence is refused, not read as consent.',
            'why' => 'Every other tool is decided by the offer-time policy. This one is not, because a host that was never asked about self-completion must not be recorded as having said yes to it.',
            'steps' => $steps,
            'list' => $key,
        ];
    }

    /**
     * With both policies wide open, a worker still cannot close a task it does
     * not hold.
     *
     * `release()` takes a worker, but the AgentTask contract has no "who holds
     * this" — so the tool is bound to a worker at construction. Without that
     * binding an agent supplies the id and could name a neighbour's task,
     * including one another worker is halfway through.
     *
     * @return array{claim: string, why: string, steps: list<array<string, mixed>>, list: string}
     */
    private function noWorkerClosesANeighboursTask(string $run): array
    {
        [$tasks, $key, $session] = $this->list($run, 'neighbour');
        $id = 'migrate-tenants';
        $tasks->add('Migrate the remaining tenants to the new schema.', $id);

        $this->freeze();
        $claim = $tasks->claim(self::WORKER_D, leaseSeconds: 300);

        // Nothing is withheld here: the host allows the tool AND allows this
        // exact call. The refusal below is the package's own, not the policy's.
        $authorizer = new ToolAuthorizer($this->gateAllowing($session, offer: true, call: true), enabled: true);
        $neighbour = TaskCompletionTool::for($tasks, $session, $authorizer, self::WORKER_E);

        $steps = [
            $this->step(
                self::WORKER_D.' holds the task; the host allows this call outright',
                $claim === null ? 'nothing was claimed' : 'held by '.($claim->claimedBy ?? 'nobody').', harness.tool.call → true',
                $claim?->claimedBy === self::WORKER_D && $authorizer->hasCallPolicy(),
                $claim,
            ),
        ];

        $decision = $this->invoke($neighbour, $id, 'done');
        $steps[] = $this->step(
            self::WORKER_E.' names '.self::WORKER_D.'\'s task id',
            $this->verdict($decision),
            ($decision['allowed'] ?? null) === false,
        );
        $steps[] = $this->step(
            'and the refusal names the holder',
            is_string($decision['reason'] ?? null) && str_contains((string) $decision['reason'], self::WORKER_D) ? 'yes — the boundary is an oracle' : 'no',
            ! (is_string($decision['reason'] ?? null) && str_contains((string) $decision['reason'], self::WORKER_D)),
        );

        $after = $tasks->find($id);
        $steps[] = $this->step(
            'the list afterwards',
            $after === null ? 'gone' : $after->state->value.', held by '.($after->claimedBy ?? 'nobody'),
            $after?->state === TaskState::Claimed && $after->claimedBy === self::WORKER_D,
            $after,
        );

        return [
            'claim' => 'Even fully authorized, a worker cannot close a task another worker holds.',
            'why' => 'The agent supplies the task id. Only the worker the tool is bound to stops it naming a task somebody else is halfway through — and the refusal must not name the holder, or the boundary becomes a directory.',
            'steps' => $steps,
            'list' => $key,
        ];
    }

    /**
     * THE CONTROL. Every lane above passes when the tool refuses.
     *
     * A completion tool that refused unconditionally — or one this probe had
     * simply wired up wrong — would make all of them green while demonstrating
     * nothing. So this lane authorizes the actual holder properly and REQUIRES
     * the task to close. A green board with this lane red means the refusals
     * above are worth nothing.
     *
     * @return array{claim: string, why: string, steps: list<array<string, mixed>>, list: string}
     */
    private function anAuthorizedHolderCanCloseItsOwn(string $run): array
    {
        [$tasks, $key, $session] = $this->list($run, 'control');
        $id = 'file-the-report';
        $tasks->add('File the incident report once the timeline is attached.', $id);

        $this->freeze();
        $claim = $tasks->claim(self::WORKER_D, leaseSeconds: 300);

        $authorizer = new ToolAuthorizer($this->gateAllowing($session, offer: true, call: true), enabled: true);
        $tool = TaskCompletionTool::for($tasks, $session, $authorizer, self::WORKER_D);

        $decision = $this->invoke($tool, $id, 'done');
        $after = $tasks->find($id);

        $failed = $this->invoke(
            TaskCompletionTool::for($tasks, $session, $authorizer, self::WORKER_D),
            $id,
            'DONE',
        );

        return [
            'claim' => 'A host that opts in properly DOES get self-completion — so the refusals above are refusals, not an inert tool.',
            'why' => 'Every other lane here is green when the tool says no. Without a lane that requires a yes, a tool broken shut would score a perfect board.',
            'steps' => [
                $this->step(
                    'both policies allow, and the tool is bound to the holder',
                    $claim === null ? 'nothing was claimed' : 'held by '.($claim->claimedBy ?? 'nobody'),
                    $claim?->claimedBy === self::WORKER_D,
                    $claim,
                ),
                $this->step(
                    self::WORKER_D.' closes its own task',
                    $this->verdict($decision),
                    ($decision['state'] ?? null) === 'done',
                ),
                $this->step(
                    'the list afterwards',
                    $after === null ? 'gone' : $after->state->value.', held by '.($after->claimedBy ?? 'nobody'),
                    $after?->state === TaskState::Done && $after->claimedBy === null,
                    $after,
                ),
                $this->step(
                    'and an outcome of "DONE" in the wrong case, on a fresh call',
                    $this->verdict($failed),
                    ($failed['allowed'] ?? null) === false,
                ),
            ],
            'list' => $key,
        ];
    }

    /**
     * A gate this probe owns, never the application's.
     *
     * Defining `harness.tool` on the app's own gate would make the container's
     * ToolAuthorizer — constructed with `authorize_tools => false` — throw
     * `policyDefinedButDisabled` the next time anything resolved it. The probe
     * would then have broken every agent in the request in order to test one.
     */
    private function gateAllowing(Session $session, bool $offer, ?bool $call): AccessGate
    {
        $gate = new AccessGate($this->container, fn (): object => $session->participant());
        $gate->define(ToolAuthorizer::ABILITY, fn (): bool => $offer);

        if ($call !== null) {
            $gate->define(ToolAuthorizer::CALL_ABILITY, fn (): bool => $call);
        }

        return $gate;
    }

    /**
     * Call the tool the way the runtime would, and read what the model reads.
     *
     * @return array<string, mixed>
     */
    private function invoke(Tool $tool, string $taskId, string $outcome): array
    {
        $raw = $tool->handle(task_id: $taskId, outcome: $outcome);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : ['allowed' => null, 'reason' => is_string($raw) ? $raw : 'the tool returned no text'];
    }

    /**
     * One line naming what the tool answered.
     *
     * @param  array<string, mixed>  $decision
     */
    private function verdict(array $decision): string
    {
        if (($decision['allowed'] ?? null) === false) {
            return 'refused — '.mb_substr((string) ($decision['reason'] ?? 'no reason given'), 0, 160);
        }

        return isset($decision['state'])
            ? 'ALLOWED — the task is now '.(string) $decision['state']
            : 'ALLOWED — '.json_encode($decision, JSON_UNESCAPED_SLASHES);
    }

    /**
     * The error CODE a call was refused with, or null when it was accepted.
     *
     * The code rather than the message, because the message is written for a
     * developer in a stack trace and changes with the wording.
     */
    private function refusalFrom(callable $call): ?string
    {
        try {
            $call();

            return null;
        } catch (TaskNotReleasable|InvalidTaskIdentifier $refusal) {
            return $refusal->code();
        } catch (Throwable) {
            return 'refused';
        }
    }

    /**
     * A fresh session-backed list per property.
     *
     * Per property rather than one shared list, so `pending()` means something:
     * a count over a list holding six other properties' leftovers answers a
     * different question than the one being asked.
     *
     * @return array{0: StoreTaskSource, 1: string, 2: Session}
     */
    private function list(string $run, string $name): array
    {
        $session = $this->sessions->resolveScope("lab:tasks-probe:{$run}:{$name}");
        $tasks = $session->tasks();

        return [$tasks, $tasks->key(), $session];
    }

    /** Freeze at a whole second and return it, so every expiry is exact. */
    private function freeze(): int
    {
        $now = Carbon::now()->startOfSecond();
        Carbon::setTestNow($now);

        return $now->getTimestamp();
    }

    /**
     * @return array{did: string, got: string, ok: bool, record: string|null}
     */
    private function step(string $did, string $got, bool $ok, ?TaskRecord $record = null): array
    {
        return [
            'did' => $did,
            'got' => $got,
            'ok' => $ok,
            // The canonical five-key record, verbatim. The bytes are the thing
            // three languages have to agree on, so the board shows the bytes.
            'record' => $record?->toCanonicalJson(),
        ];
    }

    /**
     * Drop the lists this run created.
     *
     * REACHING PAST THE SOURCE INTO THE STORE, because there is no other way:
     * the task source can add, claim, release, count and find, and it cannot
     * delete a task or clear a list. A probe that seeded a list on every run
     * and could not clear one would grow a single durable payload forever.
     * See the 0L this probe files.
     *
     * @param  list<string>  $keys
     */
    public function forget(array $keys): void
    {
        $store = $this->harness->stores()->durable();

        foreach ($keys as $key) {
            $store->forget($key);
        }
    }
}
