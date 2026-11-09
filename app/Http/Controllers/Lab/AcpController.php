<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Acp\AcpWork;
use App\Http\Controllers\Controller;
use App\Jobs\RunAcpTurnJob;
use App\Lab\InstalledVersions;
use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Models\AcpTurn;
use Composer\InstalledVersions as ComposerVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /lab/acp: a person types a prompt, a queued job runs it through the PHP ACP agent (particle-academy/prism-acp)
 * acting as an ACP client, and the page polls what the agent sent. "Continue" resumes the last turn's conversation
 * through session/load with the CLI's own session id, so resuming is exercised too.
 */
final class AcpController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Lab/Acp', [
            'version' => InstalledVersions::prism(),
            'turns' => AcpTurn::query()->latest()->limit(20)->get()->map(fn (AcpTurn $turn) => $turn->toPayload())->all(),
            'model' => (string) config('acp.claude.model'),
            'models' => ['claude' => (string) config('acp.claude.model'), 'codex' => (string) config('acp.codex.model')],
            'acpVersion' => ComposerVersions::getPrettyVersion('particle-academy/prism-acp'),
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $input = $request->validate([
            'prompt' => ['required', 'string', 'max:20000'],
            'provider' => ['sometimes', 'required', 'string', Rule::in(['claude', 'codex'])],
            'model' => ['nullable', 'string', 'max:200', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:\[\]-]*\z/'],
            'continue' => ['nullable', 'string', 'exists:acp_turns,id'],
        ]);
        $provider = $input['provider'] ?? 'claude';
        $resume = null;
        if (isset($input['continue'])) {
            $previous = AcpTurn::query()->findOrFail($input['continue']);
            if (($previous->provider ?? 'claude') !== $provider) {
                return response()->json(['error' => 'Continue with the same provider as the original conversation.'], 422);
            }
            if ($previous->isPending()) {
                return response()->json(['error' => 'Wait for that turn to finish before continuing.'], 422);
            }
            $resume = $previous->cli_session_id;
            if (! is_string($resume) || $resume === '') {
                return response()->json(['error' => 'that turn reported no session to continue'], 422);
            }
        }

        $intent = app(PaidRunContext::class)->authorizedRun(['acp.send']);
        $approved = $intent->payload['acp'] ?? [];
        $model = $input['model'] ?? config('acp.'.$provider.'.model');
        if ($intent->acp_host_claimed_at !== null || $input['prompt'] !== ($intent->payload['input']['prompt'] ?? null)
            || $provider !== ($approved['provider'] ?? null) || $model !== ($approved['model'] ?? null)
            || $resume !== ($approved['resume_session_id'] ?? null)) {
            throw new PaidRunNotAuthorised;
        }
        $turn = DB::transaction(function () use ($intent, $input, $provider, $model, $resume): AcpTurn {
            if (AcpTurn::query()->where('approved_run_id', $intent->id)->exists()) {
                throw new PaidRunNotAuthorised;
            }
            $turn = AcpTurn::query()->create([
                'status' => AcpTurn::QUEUED, 'prompt' => $input['prompt'], 'provider' => $provider,
                'model' => $model, 'resume_session_id' => $resume, 'approved_run_id' => $intent->id,
            ]);
            $work = app(PaidWorkRegistry::class)->bind('acp.turn', $turn->id, AcpWork::payload($turn));
            $turn->forceFill(['paid_work_id' => $work->id])->save();
            RunAcpTurnJob::dispatch($turn->id, $work->id)->afterCommit();

            return $turn;
        });

        return response()->json($turn->toPayload(), 202);
    }

    /** Polled by the page while the turn runs: one primary-key read. */
    public function turn(AcpTurn $turn): JsonResponse
    {
        return response()->json($turn->toPayload());
    }
}
