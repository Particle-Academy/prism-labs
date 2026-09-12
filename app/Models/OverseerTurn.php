<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A question put to the Overseer, and the answer when it arrives.
 *
 * @property string $id
 * @property string $status
 * @property string $prompt
 * @property string|null $answer
 * @property string|null $error
 * @property string|null $run_id
 */
final class OverseerTurn extends Model
{
    use HasUuids;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const ANSWERED = 'answered';

    public const FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /**
     * Is this turn still going?
     *
     * The panel polls while true. Expressed here rather than compared inline in
     * two places, because "which states are terminal" is the kind of thing that
     * gets a new state added to it and one caller updated.
     */
    public function isPending(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    /** @return array<string, mixed> */
    public function toStatusPayload(): array
    {
        return [
            'turn_id' => $this->id,
            'status' => $this->status,
            'pending' => $this->isPending(),
            'message' => $this->status === self::ANSWERED
                ? ['id' => $this->run_id ?? $this->id, 'role' => 'assistant', 'content' => (string) $this->answer]
                : null,
            'error' => $this->error,
        ];
    }
}
