<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DocsLab\LivePlan;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\RunAuthority;
use App\Lab\RunScopeFactory;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Record the owner's answer to a Genie ForceTheQuestion as an owner GO, so a
 * paid CLI run the owner approved in Genie does not also need /lab/docs.
 *
 *   php artisan lab:approve-from-ftq --question=q_<uuid> --answer="You: approved" \
 *       --action=cli.docs.run --cap-usd=2 --scenario=all --judge
 *
 * It freezes exactly the plan /lab/docs would (DocsRunCommand::liveInput and
 * RunScopeFactory), then takes the same draft, approve and GO steps through
 * RunAuthority, so digest re-derivation, the cap reservation, the 15-minute
 * expiry and single use all apply unchanged. The question id and answer are
 * stored on the intent, the actor is recorded as "owner via Genie FTQ", and a
 * question id can buy one GO, ever. Local console only.
 *
 * The Lab cannot verify an FTQ answer. This trusts the local operator, as far
 * as anyone with a shell on this machine is already trusted.
 */
final class ApproveFromFtqCommand extends Command
{
    /** CLI actions an FTQ answer may approve. Each needs its plan builder below. */
    public const ACTIONS = ['cli.docs.run'];

    protected $signature = 'lab:approve-from-ftq
        {--question= : the Genie ForceTheQuestion id the owner answered (q_<uuid>)}
        {--answer= : the owner\'s answer text, verbatim, stored for audit}
        {--action= : the paid CLI action the question approved (cli.docs.run)}
        {--cap-usd= : the USD cap the question stated; required, at most the session limit}
        {--scenario=all : cli.docs.run: all, or one of S1 S2 S3 S4 R1 R2}
        {--model='.LivePlan::DEFAULT_MODEL.' : cli.docs.run: the builder model (anthropic)}
        {--judge : cli.docs.run: the question approved the model judge too}
        {--judge-model= : cli.docs.run: the judge model; defaults to the builder model}';

    protected $description = 'Record an owner GO from a Genie ForceTheQuestion answer (local CLI only)';

    public function handle(RunAuthority $authority, RunScopeFactory $scopes): int
    {
        if (! app()->environment('local') || ! app()->runningInConsole()) {
            return $this->refuse('this command runs only from the local console.');
        }
        $question = $this->option('question');
        $answer = $this->option('answer');
        $action = $this->option('action');
        $cap = $this->option('cap-usd');
        if (! is_string($question) || preg_match(RunAuthority::QUESTION_ID, $question) !== 1) {
            return $this->refuse('--question must be the FTQ id, q_<uuid>.');
        }
        if (! is_string($answer) || trim($answer) === '') {
            return $this->refuse('--answer must carry the owner\'s answer text.');
        }
        if (! is_string($action) || ! in_array($action, self::ACTIONS, true)) {
            return $this->refuse('--action must be one of: '.implode(', ', self::ACTIONS).'.');
        }
        if (! is_string($cap) || $cap === '') {
            return $this->refuse('--cap-usd is required and must equal the cap the question stated.');
        }

        $input = DocsRunCommand::liveInput((string) $this->option('scenario'), (string) $this->option('model'),
            (bool) $this->option('judge'), $this->option('judge-model'));
        try {
            $intent = $authority->goFromOwnerAnswer($scopes->fromAction($action, 'lab:agent', $input), $cap, $question, $answer);
        } catch (ValidationException $e) {
            return $this->refuse(implode(' ', $e->validator->errors()->all()));
        } catch (PaidRunNotAuthorised) {
            return $this->refuse('the GO was not recorded (question already used, owner not configured, or the cap exceeds what the session has left).');
        }

        $this->info(sprintf('GO %s recorded for %s, cap $%.6f, %s, question %s.', $intent->id, $intent->action,
            $intent->cap_microusd / 1_000_000, RunAuthority::VIA_FTQ, $question));
        $this->line('Single use; expires '.$intent->expires_at->toIso8601String().'. Run:');
        $this->line(sprintf('  php artisan lab:docs-run --lane=live --scenario=%s --model=%s%s%s --approved-run=%s',
            $input['scenario'], $input['model'], $input['judge'] ? ' --judge' : '',
            $input['judge_model'] !== $input['model'] ? ' --judge-model='.$input['judge_model'] : '', $intent->id));

        return self::SUCCESS;
    }

    private function refuse(string $why): int
    {
        $this->error('owner_go_not_recorded: '.$why);

        return self::FAILURE;
    }
}
