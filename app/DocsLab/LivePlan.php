<?php

declare(strict_types=1);

namespace App\DocsLab;

use App\Lab\ModelPolicy;
use App\Lab\ModelPrices;
use Illuminate\Validation\ValidationException;

/**
 * Exactly what a live run will do, frozen into the owner's approval.
 *
 * The owner approves THIS: which scenarios (by digest), which builder and
 * judge model, the output ceiling, the prices the cap is enforced with, and
 * the exact prism-agent-docs bytes under test. RunScopeFactory puts it in the
 * intent payload, and the runner re-derives it at execution and refuses when
 * anything moved (a scenario edited, a price changed, the agent rebuilt).
 */
final class LivePlan
{
    public const PROVIDER = 'anthropic';

    public const DEFAULT_MODEL = 'claude-sonnet-5-5';

    /** Each call's output ceiling; the worst case every reservation is priced at. */
    public const MAX_OUTPUT_TOKENS = 4096;

    /** A conservative bound on the tool prompt the provider adds, per call. */
    public const TOOL_OVERHEAD_TOKENS = 600;

    public const INPUT_KEYS = ['scenario', 'model', 'judge', 'judge_model'];

    /**
     * @param  array<string, mixed>  $input  the CLI/UI selection: scenario, model, judge, judge_model
     * @return array<string, mixed>
     */
    public static function snapshot(array $input): array
    {
        $unknown = array_diff(array_keys($input), self::INPUT_KEYS);
        $scenario = $input['scenario'] ?? null;
        $model = $input['model'] ?? null;
        $judge = $input['judge'] ?? null;
        $judgeModel = $input['judge_model'] ?? $model;
        if ($unknown !== [] || ! is_string($scenario) || ! is_string($model) || ! is_bool($judge) || ! is_string($judgeModel)) {
            throw ValidationException::withMessages(['payload' => 'A live docs run takes scenario, model, judge and judge_model only.']);
        }
        try {
            $scenarios = app(Scenarios::class)->identities($scenario);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['scenario' => $e->getMessage()]);
        }
        $models = array_values(array_unique($judge ? [$model, $judgeModel] : [$model]));
        $pricing = [];
        foreach ($models as $id) {
            $pricing[$id] = self::price($id);
        }

        return [
            'lane' => 'live',
            'selection' => $scenario,
            'scenarios' => $scenarios,
            'provider' => self::PROVIDER,
            'model' => $model,
            'judge' => $judge,
            'judge_model' => $judge ? $judgeModel : null,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
            'pricing' => [self::PROVIDER => $pricing],
            'agent' => AgentVersion::current(),
        ];
    }

    /**
     * The harness price entry for a model, from the Lab's dated list prices.
     * Only a model the owner enabled, with one standard tier, is accepted.
     *
     * @return array<string, float|int>
     */
    public static function price(string $model): array
    {
        if (preg_match('/\A[a-z0-9][a-z0-9.-]{0,99}\z/', $model) !== 1 || ! app(ModelPolicy::class)->permits(self::PROVIDER, $model)) {
            throw ValidationException::withMessages(['model' => "`{$model}` is not enabled for testing on anthropic. Enable it at /lab/models first."]);
        }
        $quote = app(ModelPrices::class)->forModel(self::PROVIDER, $model);
        $rates = $quote['rates'] ?? [];
        if ($quote === null || ($quote['stale'] ?? true) || count($rates) !== 1 || ! is_numeric($rates[0]['input'] ?? null) || ! is_numeric($rates[0]['output'] ?? null)) {
            throw ValidationException::withMessages(['model' => "`{$model}` has no current single-tier list price in the Lab, so a cap cannot be enforced for it."]);
        }
        $rate = $rates[0];

        return array_filter([
            'inputPerMTok' => (float) $rate['input'],
            'outputPerMTok' => (float) $rate['output'],
            'cacheReadPerMTok' => isset($rate['cache_read']) ? (float) $rate['cache_read'] : null,
            'cacheWritePerMTok' => isset($rate['cache_write_5m']) ? (float) $rate['cache_write_5m'] : null,
            'toolOverheadTokens' => self::TOOL_OVERHEAD_TOKENS,
        ], fn (mixed $value): bool => $value !== null);
    }
}
