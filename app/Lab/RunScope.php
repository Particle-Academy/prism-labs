<?php

declare(strict_types=1);

namespace App\Lab;

use App\Benchmarks\CanonicalJson;
use InvalidArgumentException;
use JsonException;

final readonly class RunScope
{
    public const ACTIONS = [
        'provider.research', 'provider.cache', 'consensus.collect', 'team.ask', 'tasks.agent',
        'benchmarks.policy-turn', 'chat.run', 'agent.send', 'agent.voice', 'acp.send', 'tests.run',
        'benchmarks.launch', 'benchmarks.compaction-probe', 'benchmarks.compaction-recall',
        'benchmarks.summary-loss', 'team.nudge', 'evidence.send',
        'cli.compaction-probe', 'cli.compaction-recall', 'cli.compaction-summary-loss', 'cli.tests.run',
    ];

    /** @param array<string, mixed> $payload */
    public function __construct(public string $action, public string $resource, public array $payload)
    {
        if (! in_array($action, self::ACTIONS, true) || ! preg_match('/^[a-zA-Z0-9:._-]{1,200}$/D', $resource)) {
            throw new InvalidArgumentException('Invalid paid run scope.');
        }
        try {
            $this->assertJsonValue($payload, 0);
            if (strlen(CanonicalJson::encode($payload)) > 3 * 1024 * 1024) {
                throw new InvalidArgumentException('The paid run payload is too large.');
            }
        } catch (JsonException) {
            throw new InvalidArgumentException('The paid run payload must be finite JSON data.');
        }
    }

    private function assertJsonValue(mixed $value, int $depth): void
    {
        if ($depth > 32 || is_object($value) || is_resource($value)
            || (is_float($value) && ! is_finite($value))) {
            throw new InvalidArgumentException('The paid run payload must be bounded JSON data.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertJsonValue($item, $depth + 1);
            }
        }
    }
}
