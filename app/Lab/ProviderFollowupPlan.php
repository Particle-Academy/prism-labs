<?php

declare(strict_types=1);

namespace App\Lab;

use Illuminate\Validation\ValidationException;

/**
 * Exactly what a provider request-shape check does, frozen into the owner's GO.
 *
 * Providers, models, modes, the output ceiling and the prices are all here and
 * none is an option: the only input is the Genie question that approved the
 * run. RunScopeFactory puts this snapshot in the intent, so a price or endpoint
 * that moves after approval no longer matches the GO.
 *
 * A provider is listed only if the Lab holds a dated price for it, because a
 * request the ledger cannot price cannot be reserved.
 */
final class ProviderFollowupPlan
{
    public const ACTION = 'cli.provider.followup-check';

    public const WORK_KIND = 'provider.followup';

    /** The most this action can ever be approved for, whatever the session has left. */
    public const CAP_MICROUSD = 2_000_000;

    public const MAX_OUTPUT_TOKENS = 16;

    /** A bound on the fixed conversation plus the tool definition, per request. */
    public const INPUT_TOKEN_CEILING = 1000;

    /** At most one request of each mode per provider. */
    public const MODES = ['text', 'stream'];

    /** @var array<string, array{model: string, host: string}> */
    public const TARGETS = [
        'anthropic' => ['model' => 'claude-haiku-5-5', 'host' => 'api.anthropic.com'],
        'openai' => ['model' => 'gpt-6-luna', 'host' => 'api.openai.com'],
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function snapshot(array $input): array
    {
        $question = $input['question'] ?? null;
        if (array_keys($input) !== ['question'] || ! is_string($question) || preg_match(RunAuthority::QUESTION_ID, $question) !== 1) {
            throw ValidationException::withMessages(['payload' => 'This check takes the approving question id and nothing else.']);
        }

        $targets = [];
        $total = 0;
        foreach (self::TARGETS as $provider => $target) {
            $url = config('prism.providers.'.$provider.'.url');
            if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== $target['host']) {
                throw ValidationException::withMessages(['endpoint' => $provider.' is not configured with its official endpoint.']);
            }
            $price = app(ModelPrices::class)->forModel($provider, $target['model']);
            if ($price === null || $price['stale'] === true) {
                throw ValidationException::withMessages(['price' => $provider.' has no current list price for '.$target['model'].'.']);
            }
            $inputRate = (int) round((float) $price['rates'][0]['input'] * 1_000_000);
            $outputRate = (int) round((float) $price['rates'][0]['output'] * 1_000_000);
            $worst = (int) ceil((self::INPUT_TOKEN_CEILING * $inputRate + self::MAX_OUTPUT_TOKENS * $outputRate) / 1_000_000);
            $total += $worst * count(self::MODES);
            $targets[$provider] = ['model' => $target['model'], 'host' => $target['host'],
                'input_microusd_per_mtok' => $inputRate, 'output_microusd_per_mtok' => $outputRate, 'worst_case_microusd' => $worst];
        }
        if ($total > self::CAP_MICROUSD) {
            throw ValidationException::withMessages(['price' => 'The worst case of this plan is above its cap.']);
        }

        return ['targets' => $targets, 'modes' => self::MODES, 'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
            'input_token_ceiling' => self::INPUT_TOKEN_CEILING, 'worst_case_microusd' => $total, 'prices_checked_on' => ModelPrices::CHECKED_ON];
    }
}
