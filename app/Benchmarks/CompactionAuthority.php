<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;

/** Freeze effective probe arguments, including both explicitly approved CLI arms. */
final readonly class CompactionAuthority
{
    private const ACTIONS = [
        'reservation' => ['benchmarks.compaction-probe', 'cli.compaction-probe'],
        'recall' => ['benchmarks.compaction-recall', 'cli.compaction-recall'],
        'summary-loss' => ['benchmarks.summary-loss', 'cli.compaction-summary-loss'],
    ];

    public function __construct(private PaidRunContext $context) {}

    public static function snapshot(string $action, array $input): ?array
    {
        $kind = null;
        foreach (self::ACTIONS as $candidate => $actions) {
            if (in_array($action, $actions, true)) {
                $kind = $candidate;
                break;
            }
        }
        if ($kind === null) {
            return null;
        }
        $cli = str_starts_with($action, 'cli.');
        $arguments = match ($kind) {
            'reservation' => [
                'model' => $cli ? (string) ($input['model'] ?? 'claude-sonnet-5') : 'claude-sonnet-5',
                'max_steps' => $cli ? (int) ($input['steps'] ?? 24) : 24,
                'trigger' => (int) ($input['trigger'] ?? 5000), 'keep' => (int) ($input['keep'] ?? 3),
                'reserve' => $cli || (bool) ($input['reserved'] ?? true), 'rounds' => 14,
            ],
            'recall' => [
                'model' => $cli ? (string) ($input['model'] ?? 'claude-sonnet-5') : 'claude-sonnet-5',
                'keep' => $cli ? (int) ($input['keep'] ?? 6) : 6, 'padding' => $cli ? (int) ($input['padding'] ?? 8) : 8,
                'summarise_with' => $cli ? ($input['summarise_with'] ?? null) : null,
                'summary_words' => $cli ? (int) ($input['summary_words'] ?? 200) : 200,
            ],
            'summary-loss' => [
                'model' => $cli ? (string) ($input['model'] ?? 'claude-sonnet-5') : 'claude-sonnet-5',
                'summarise_with' => $cli ? (string) ($input['summarise_with'] ?? 'claude-haiku-4-5-20251001') : 'claude-haiku-4-5-20251001',
                'keep' => $cli ? (int) ($input['keep'] ?? 6) : 6, 'padding' => $cli ? (int) ($input['padding'] ?? 8) : 8,
                'summary_words' => $cli ? (int) ($input['summary_words'] ?? 60) : 60,
            ],
        };
        $arms = [$arguments];
        if ($kind === 'reservation' && $cli && ($input['control'] ?? false) === true) {
            $arms[] = [...$arguments, 'reserve' => false];
        }

        return ['kind' => $kind, 'arguments' => $arms];
    }

    public function require(string $kind, array $arguments): void
    {
        $intent = $this->context->authorizedRun($this->actions($kind));
        $snapshot = $intent->payload['compaction'] ?? null;
        if (! is_array($snapshot) || ($snapshot['kind'] ?? null) !== $kind || ! is_array($snapshot['arguments'] ?? null)
            || ! in_array(CanonicalJson::encode($arguments), array_map(CanonicalJson::encode(...), $snapshot['arguments']), true)) {
            throw new PaidRunNotAuthorised;
        }
        $this->check($kind);
    }

    public function check(string $kind): void
    {
        $this->context->requireActions($this->actions($kind));
    }

    private function actions(string $kind): array
    {
        return self::ACTIONS[$kind] ?? throw new PaidRunNotAuthorised;
    }
}
