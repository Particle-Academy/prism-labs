<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Acp\AcpClient;
use App\Acp\AcpWork;
use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Lab\SessionNotReady;
use App\Models\AcpTurn;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Prism\Acp\JsonRpc\RpcError;
use Prism\Acp\Ndjson\Json;
use Throwable;

/**
 * Run one /lab/acp turn: start the PHP ACP agent, speak ACP to it as a client would -- initialize, session/new (or
 * session/load to continue a conversation), session/set_model, session/prompt -- record every session/update as it
 * arrives, and stop the agent, keeping what the stop found of its process tree.
 *
 * Off the web worker for the reason RunOverseerTurnJob gives, and on its queue: a person is watching.
 */
final class RunAcpTurnJob implements ShouldQueue
{
    use Queueable;

    /** One attempt: a turn is billable, and a retry would spend it again. */
    public int $tries = 1;

    public int $timeout = 1800;

    // Old serialized jobs stay readable, then refuse before process launch.
    public ?string $paidWorkId = null;

    public function __construct(public readonly string $turnId, ?string $paidWorkId = null)
    {
        $this->paidWorkId = $paidWorkId;
        $this->onQueue('overseer');
    }

    public function handle(): void
    {
        $turn = AcpTurn::query()->find($this->turnId);
        if (! $turn instanceof AcpTurn || $turn->status !== AcpTurn::QUEUED) {
            return;
        }
        try {
            if ($this->paidWorkId === null || $turn->paid_work_id !== $this->paidWorkId) {
                throw new PaidRunNotAuthorised;
            }
            app(PaidWorkRegistry::class)->run($this->paidWorkId, 'acp.turn', $turn->id, AcpWork::payload($turn),
                fn () => $this->executeApproved($turn));
        } catch (PaidRunNotAuthorised|SessionNotReady) {
            AcpTurn::query()->whereKey($turn->id)->where('status', AcpTurn::QUEUED)->update([
                'status' => AcpTurn::FAILED,
                'error' => 'The approved run authority or current session readiness is unavailable. No ACP host was started.',
                'finished_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function executeApproved(AcpTurn $turn): void
    {
        app(PaidRunContext::class)->requireWork('acp.turn', $turn->id, AcpWork::payload($turn));
        $claimed = AcpTurn::query()->whereKey($this->turnId)->where('status', AcpTurn::QUEUED)->update([
            'status' => AcpTurn::RUNNING, 'started_at' => now(), 'updates' => '[]',
        ]);
        if ($claimed !== 1) {
            return;
        }
        $turn = AcpTurn::query()->findOrFail($this->turnId);

        $updates = [];
        $history = [];
        $replaying = $turn->provider === 'codex' && $turn->resume_session_id !== null;
        $savedAt = 0.0;
        $save = function (bool $now = false) use ($turn, &$updates, &$history, &$savedAt): void {
            if ($now || microtime(true) - $savedAt > 0.3) {
                $turn->forceFill(['updates' => $updates, 'history_updates' => $history])->save();
                $savedAt = microtime(true);
            }
        };
        $client = null;
        try {
            $client = new AcpClient(
                onUpdate: function (mixed $params) use (&$updates, &$history, &$replaying, $save): void {
                    $update = json_decode(Json::encode($params->update ?? null), true);
                    if ($replaying) {
                        $history[] = $update;
                    } else {
                        $updates[] = $update;
                    }
                    $save();
                },
                // Claude hooks ask for every tool. Codex also has native policy and MCP isolation in its host.
                onPermission: fn (mixed $params) => ['outcome' => ['outcome' => 'cancelled']],
                provider: $turn->provider ?? 'claude',
                turnId: $turn->id,
            );
            $process = ['tree' => $client->tree()];
            $turn->forceFill(['process' => $process])->save();

            $cwd = (string) config('acp.workspace');
            if (! is_dir($cwd)) {
                mkdir($cwd, 0700, true);
            }
            $init = $client->request('initialize', ['protocolVersion' => 1, 'clientCapabilities' => new \stdClass], 30);
            $sessionSeconds = (float) config('acp.session_seconds');
            $session = $turn->resume_session_id === null
                ? $client->request('session/new', ['cwd' => $cwd, 'mcpServers' => []], $sessionSeconds)
                : $client->request('session/load', ['sessionId' => $turn->resume_session_id, 'cwd' => $cwd, 'mcpServers' => []], $sessionSeconds);
            $replaying = false;
            $sessionId = $turn->resume_session_id ?? (string) ($session->sessionId ?? '');
            $turn->forceFill(['handshake' => [
                'initialize' => json_decode(Json::encode($init), true),
                $turn->resume_session_id === null ? 'session/new' : 'session/load' => json_decode(Json::encode($session), true),
            ]])->save();

            if ($turn->model !== null && $turn->model !== '') {
                $client->request('session/set_model', ['sessionId' => $sessionId, 'modelId' => $turn->model], 30);
            }

            $prompt = $client->send('session/prompt', ['sessionId' => $sessionId, 'prompt' => [['type' => 'text', 'text' => $turn->prompt]]]);
            if (! $client->runUntil(fn () => $prompt->isSettled(), (float) config('acp.turn_seconds'))) {
                // Too long: ask for the turn to be cancelled, as a client's Stop button does, and give it a moment.
                $client->notify('session/cancel', ['sessionId' => $sessionId]);
                $client->runUntil(fn () => $prompt->isSettled(), 20);
            }
            $result = $prompt->isSettled() ? $prompt->await() : throw new \RuntimeException('the turn did not end, even after a cancel');
            $turn->forceFill(['status' => AcpTurn::FINISHED, 'stop_reason' => (string) ($result->stopReason ?? '')]);
        } catch (RpcError $error) {
            $data = $error->data instanceof \stdClass ? ' '.Json::encode($error->data) : '';
            $turn->forceFill(['status' => AcpTurn::FAILED, 'error' => "{$error->getMessage()} ({$error->rpcCode}){$data}"]);
        } catch (Throwable $error) {
            $turn->forceFill(['status' => AcpTurn::FAILED, 'error' => $error->getMessage()]);
        } finally {
            $process = $turn->process ?? [];
            if ($client !== null) {
                try {
                    $process['stop'] = $client->stop(5000)->toArray();
                } catch (Throwable $error) {
                    $process['stop'] = ['error' => $error->getMessage()];
                }
                $stderr = trim($client->stderr());
                if ($stderr !== '') {
                    $process['stderr'] = substr($stderr, -4000);
                }
            }
            $turn->forceFill([
                'updates' => $updates,
                'history_updates' => $history,
                'cli_session_id' => self::cliSessionId($updates) ?? $turn->resume_session_id,
                'process' => $process,
                'finished_at' => now(),
            ])->save();
        }
    }

    /** A killed worker cannot leave its own bound turn polling forever. */
    public function failed(?Throwable $failure): void
    {
        if ($this->paidWorkId === null) {
            return;
        }
        AcpTurn::query()->whereKey($this->turnId)->where('paid_work_id', $this->paidWorkId)
            ->whereIn('status', [AcpTurn::QUEUED, AcpTurn::RUNNING])->update([
                'status' => AcpTurn::FAILED, 'error' => 'The ACP worker stopped before this turn finished. Prepare a new approved turn to continue.',
                'finished_at' => now(), 'updated_at' => now(),
            ]);
    }

    /** The CLI's own session id, which the agent publishes on its first update: what the next turn resumes. */
    private static function cliSessionId(array $updates): ?string
    {
        foreach ($updates as $update) {
            $id = $update['_meta']['particle.academy/cli_session_id'] ?? null;
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return null;
    }
}
