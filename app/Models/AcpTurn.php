<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A turn put to the ACP agent at /lab/acp, and everything the agent sent back.
 *
 * @property string $id
 * @property string $status
 * @property string $prompt
 * @property string|null $model
 * @property string|null $resume_session_id
 * @property string|null $cli_session_id
 * @property string|null $stop_reason
 * @property string|null $error
 * @property list<array<string, mixed>>|null $updates
 * @property array<string, mixed>|null $handshake
 * @property array<string, mixed>|null $process
 */
final class AcpTurn extends Model
{
    use HasUuids;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const FINISHED = 'finished';

    public const FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'updates' => 'array',
            'handshake' => 'array',
            'process' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    /** @return array<string, mixed> what the page shows of this turn */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'pending' => $this->isPending(),
            'prompt' => $this->prompt,
            'model' => $this->model,
            'resume_session_id' => $this->resume_session_id,
            'cli_session_id' => $this->cli_session_id,
            'stop_reason' => $this->stop_reason,
            'error' => $this->error,
            'updates' => $this->updates ?? [],
            'handshake' => $this->handshake,
            'process' => $this->process,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
