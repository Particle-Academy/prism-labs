<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lab\ApprovedCliRun;
use App\Lab\ProviderFollowupCheck;
use App\Lab\ProviderFollowupPlan;
use App\Lab\RunAuthority;
use App\Lab\RunScopeFactory;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * A provider request-shape check: a few tiny PAID requests on the frozen plan
 * in ProviderFollowupPlan. It needs an owner GO recorded by
 * `lab:approve-from-ftq --action=cli.provider.followup-check`; it can neither
 * create nor widen one, and it has no option that changes what is sent.
 *
 * The exit code is 0 when the plan ran to its end, including when a provider
 * rejected a request: that rejection is the result. It is 1 when the run was
 * refused, stopped at the cap, or a tool was executed.
 */
final class ProviderFollowupCheckCommand extends Command
{
    protected $signature = 'lab:provider-followup-check
        {--question= : the Genie question id the GO was recorded from}
        {--approved-run= : the owner-approved GO reference}';

    protected $description = 'Run the owner-approved provider request-shape check at its frozen plan and cap';

    public function handle(ApprovedCliRun $runs, ProviderFollowupCheck $check): int
    {
        $input = ['question' => (string) $this->option('question')];
        try {
            return $runs->run($this, ProviderFollowupPlan::ACTION, $input, function () use ($check, $input): int {
                $intent = app(RunAuthority::class)->approvedReference((string) $this->option('approved-run'));
                $scope = app(RunScopeFactory::class)->fromAction(ProviderFollowupPlan::ACTION, 'lab:agent', $input);
                $report = $check->run($intent, $scope);
                $this->line('FOLLOWUP_RESULT '.json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

                return $report['stopped_at_cap'] || $report['tool_runs'] !== 0 ? self::FAILURE : self::SUCCESS;
            });
        } catch (ValidationException) {
            $this->error('paid_call_not_authorised: the frozen plan no longer holds.');

            return self::FAILURE;
        }
    }
}
