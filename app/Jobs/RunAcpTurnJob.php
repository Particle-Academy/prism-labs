<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Acp\AcpClient;
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

    public function __construct(public readonly string $turnId)
    {
        $this->onQueue('overseer');
    }

    public function handle(): void
    {
        $claimed = AcpTurn::query()->whereKey($this->turnId)->where('status', AcpTurn::QUEUED)->update([
            'status' => AcpTurn::RUNNING, 'started_at' => now(), 'updates' => '[]',
        ]);
        if ($claimed !== 1) {
            return;
        }
        $turn = AcpTurn::query()->findOrFail($this->turnId);

        $updates = [];
        $savedAt = 0.0;
        $save = function (bool $now = false) use ($turn, &$updates, &$savedAt): void {
            if ($now || microtime(true) - $savedAt > 0.3) {
                $turn->forceFill(['updates' => $updates])->save();
                $savedAt = microtime(true);
            }
        };
        $client = null;
        try {
            $client = new AcpClient(
                onUpdate: function (mixed $params) use (&$updates, $save): void {
                    $updates[] = json_decode(Json::encode($params->update ?? null), true);
                    $save();
                },
                // Every tool asks through the hook, including future tools. This Lab surface denies all of them.
                onPermission: fn (mixed $params) => ['outcome' => ['outcome' => 'cancelled']],
            );
            $process = ['tree' => $client->tree()];
            $turn->forceFill(['process' => $process])->save();

            $cwd = (string) config('acp.workspace');
            if (! is_dir($cwd)) {
                mkdir($cwd, 0700, true);
            }
            $init = $client->request('initialize', ['protocolVersion' => 1, 'clientCapabilities' => new \stdClass], 30);
            $session = $turn->resume_session_id === null
                ? $client->request('session/new', ['cwd' => $cwd, 'mcpServers' => []], 60)
                : $client->request('session/load', ['sessionId' => $turn->resume_session_id, 'cwd' => $cwd, 'mcpServers' => []], 60);
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
                'cli_session_id' => self::cliSessionId($updates) ?? $turn->resume_session_id,
                'process' => $process,
                'finished_at' => now(),
            ])->save();
        }
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
