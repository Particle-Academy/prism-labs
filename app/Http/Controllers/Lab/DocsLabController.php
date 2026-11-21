<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\DocsLab\AgentVersion;
use App\DocsLab\Certificates;
use App\DocsLab\DocsLabRunner;
use App\DocsLab\LivePlan;
use App\DocsLab\Scenario;
use App\DocsLab\Scenarios;
use App\Http\Controllers\Controller;
use App\Models\DocsLabCertificate;
use App\Models\DocsLabResult;
use App\Models\DocsLabRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /lab/docs: the docs-agent lab (G-316). Scenarios, runs, per-check verdicts,
 * artefact downloads and certificates, the same records `lab:docs-run` and
 * `lab:docs-certify` write.
 *
 * The scripted lane runs from here (free). The live lane is prepared here, as
 * an owner approval for the exact plan with a USD cap, and run with the CLI
 * command the page shows, carrying the GO reference; a live run lasts minutes,
 * longer than a request should.
 */
final class DocsLabController extends Controller
{
    public const ARTEFACTS = ['source.docx', 'draft.docx', 'draft.json', 'result.json'];

    public function show(Scenarios $scenarios, Certificates $certificates): Response
    {
        $agent = null;
        $agentError = null;
        try {
            $agent = AgentVersion::current();
        } catch (\RuntimeException $e) {
            $agentError = $e->getMessage();
        }
        $runs = DocsLabRun::query()->with('results')->orderByDesc('started_at')->limit(20)->get();

        return Inertia::render('Lab/DocsLab', [
            'agent' => $agent,
            'agentError' => $agentError,
            'defaults' => ['model' => LivePlan::DEFAULT_MODEL, 'maxOutputTokens' => LivePlan::MAX_OUTPUT_TOKENS],
            'scenarios' => array_values(array_map(fn (Scenario $s): array => [
                'id' => $s->id, 'version' => $s->version, 'title' => $s->title, 'origin' => $s->origin, 'brief' => $s->brief,
                'store' => $s->store, 'digest' => $s->digest, 'judge' => $s->judge,
                'checks' => array_map(fn (array $c): string => $c['check'].(count($c) > 1 ? ' '.json_encode(array_diff_key($c, ['check' => 1]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''), $s->expect),
            ], $scenarios->all())),
            'runs' => $runs->map(fn (DocsLabRun $run): array => [
                'id' => $run->id, 'lane' => $run->lane, 'selection' => $run->selection, 'status' => $run->status,
                'passed' => $run->passed, 'model' => $run->model, 'judgeModel' => $run->judge_model,
                'agentVersion' => $run->agent['version'] ?? null, 'sourceDigest' => $run->agent['source_digest'] ?? null,
                'costMicrousd' => $run->cost_microusd, 'error' => $run->error,
                'startedAt' => $run->started_at->toIso8601String(), 'finishedAt' => $run->finished_at?->toIso8601String(),
                'certifiable' => $certificates->refusal($run) === null,
                'results' => $run->results->map(fn (DocsLabResult $r): array => [
                    'scenario' => $r->scenario, 'passed' => $r->passed, 'structuralPassed' => $r->structural_passed,
                    'outcome' => $r->outcome, 'message' => $r->message, 'judgeVerdict' => $r->judge_verdict,
                    'judgeReason' => $r->judge_reason, 'error' => $r->error, 'modelCalls' => $r->model_calls,
                    'checks' => $r->checks, 'artefacts' => array_keys($r->artefacts),
                ])->values()->all(),
            ])->values()->all(),
            'certificates' => DocsLabCertificate::query()->orderByDesc('sequence')->limit(20)->get()->map(fn (DocsLabCertificate $c): array => [
                'sequence' => $c->sequence, 'version' => $c->version, 'hash' => $c->hash, 'previous' => $c->previous_hash,
                'run' => $c->body['evidence']['run'] ?? null, 'issuedAt' => $c->issued_at->toIso8601String(),
            ])->values()->all(),
            'chainProblems' => $certificates->verify(),
        ]);
    }

    /** The free lane, from the page. Same runner and records as `lab:docs-run`. */
    public function scripted(Request $request, DocsLabRunner $runner): RedirectResponse
    {
        $input = $request->validate(['scenario' => ['required', 'string', Rule::in(['all', ...Scenarios::ORDER])]]);
        $run = $runner->scripted($input['scenario']);

        return back()->with('status', sprintf('Scripted run %s: %s.', substr($run->id, -8), $run->status));
    }

    public function artefact(DocsLabRun $run, string $scenario, string $artefact): BinaryFileResponse
    {
        abort_unless(in_array($scenario, Scenarios::ORDER, true) && in_array($artefact, self::ARTEFACTS, true), 404);
        $result = $run->results()->where('scenario', $scenario)->first();
        abort_unless($result instanceof DocsLabResult && isset($result->artefacts[$artefact]), 404);
        $path = DocsLabRunner::directory($run, $scenario).'/'.$artefact;
        abort_unless(is_file($path) && ! is_link($path) && hash_equals($result->artefacts[$artefact]['sha256'], (string) hash_file('sha256', $path)), 404);

        return response()->download($path, $run->lane.'-'.substr($run->id, -8).'-'.$scenario.'-'.$artefact, [
            'Content-Type' => str_ends_with($artefact, '.docx') ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/json',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
