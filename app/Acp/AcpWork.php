<?php

declare(strict_types=1);

namespace App\Acp;

use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Models\AcpTurn;

/** The actual queued turn, excluding its evolving native output. */
final class AcpWork
{
    public static function payload(AcpTurn $turn): array
    {
        return ['prompt' => $turn->prompt, 'provider' => $turn->provider, 'model' => $turn->model,
            'resume_session_id' => $turn->resume_session_id, 'approved_run_id' => $turn->approved_run_id];
    }

    public static function assertHost(string $intentId, ?string $expectedTurnId = null): ?string
    {
        $turn = $expectedTurnId === null ? AcpTurn::query()->where('approved_run_id', $intentId)->first()
            : AcpTurn::query()->find($expectedTurnId);
        // An approved standalone CLI has no queued business row. A queued turn
        // must have passed its native worker before the host can use that GO.
        if ($turn === null && $expectedTurnId === null) {
            return null;
        }
        if (! $turn instanceof AcpTurn || $turn->approved_run_id !== $intentId || $turn->status !== AcpTurn::RUNNING) {
            throw new PaidRunNotAuthorised;
        }
        app(PaidWorkRegistry::class)->checkAcpHostReference($turn->paid_work_id, $intentId, $turn->id, self::payload($turn));

        return $turn->id;
    }
}
