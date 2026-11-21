<?php

declare(strict_types=1);

namespace App\DocsLab;

use App\Benchmarks\CanonicalJson;
use App\Models\DocsLabCertificate;
use App\Models\DocsLabResult;
use App\Models\DocsLabRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Hash-chained certificates: "this prism-agent-docs passed every lab scenario
 * on the live lane". Format in docs/docs-agent-lab.md.
 *
 * Charlotte's certificates are Ed25519-signed with a key in the lab's .env.
 * This lab keeps the idea (a record that says exactly what was proven, which
 * nobody can quietly edit afterwards) and drops the key: each certificate's
 * `hash` is the sha256 of its canonical JSON, and each one names the previous
 * certificate's hash, so changing, removing or reordering any past certificate
 * breaks every link after it, which `verify()` reports. No secret is read or
 * stored. What a chain does NOT give is proof of WHO issued it; a signature
 * would, and is the upgrade if a certificate ever leaves this machine as
 * evidence on its own.
 *
 * A certificate is refused unless the run is a finished live run that passed,
 * covers every current scenario at its current digest, had the judge run and
 * pass on each, and was made against exactly the agent bytes installed now.
 */
final class Certificates
{
    public const FORMAT = 'prism-labs/docs-agent-certificate@1';

    public function __construct(private readonly Scenarios $scenarios) {}

    public function issue(DocsLabRun $run): DocsLabCertificate
    {
        $refusal = $this->refusal($run);
        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }

        return DB::transaction(function () use ($run): DocsLabCertificate {
            $last = DocsLabCertificate::query()->orderByDesc('sequence')->lockForUpdate()->first();
            $body = [
                'format' => self::FORMAT,
                'sequence' => ($last?->sequence ?? 0) + 1,
                'previous' => $last?->hash,
                'issuer' => 'prism-labs',
                'subject' => [
                    'package' => $run->agent['package'], 'version' => $run->agent['version'],
                    'reference' => $run->agent['reference'] ?? null, 'source_digest' => $run->agent['source_digest'],
                ],
                'evidence' => [
                    'run' => $run->id, 'lane' => $run->lane, 'intent' => $run->intent_id,
                    'provider' => $run->provider, 'model' => $run->model, 'judge_model' => $run->judge_model,
                    'cost_microusd' => $run->cost_microusd,
                    'started_at' => $run->started_at->toIso8601String(), 'finished_at' => $run->finished_at?->toIso8601String(),
                    'scenarios' => $run->results->map(fn (DocsLabResult $r): array => [
                        'id' => $r->scenario, 'version' => $r->scenario_version, 'digest' => $r->scenario_digest,
                        'structural' => $r->structural_passed ? 'pass' : 'fail', 'judge' => $r->judge_verdict,
                        'artefacts' => collect($r->artefacts)->map(fn (array $a): string => $a['sha256'])->all(),
                    ])->values()->all(),
                ],
                'issued_at' => now()->toIso8601String(),
            ];
            $hash = self::hash($body);
            $certificate = DocsLabCertificate::query()->create([
                'sequence' => $body['sequence'], 'package' => $body['subject']['package'], 'version' => $body['subject']['version'],
                'source_digest' => $body['subject']['source_digest'], 'body' => $body, 'hash' => $hash,
                'previous_hash' => $body['previous'], 'issued_at' => now(),
            ]);
            $dir = rtrim((string) config('docs-lab.artefacts'), '/\\').'/certificates';
            File::ensureDirectoryExists($dir);
            file_put_contents($dir.'/'.sprintf('%04d', $body['sequence']).'-prism-agent-docs-'.preg_replace('/[^0-9A-Za-z.-]/', '_', (string) $body['subject']['version']).'.json',
                json_encode([...$body, 'hash' => $hash], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

            return $certificate;
        }, 3);
    }

    /** Why this run cannot be certified, or null when it can. */
    public function refusal(DocsLabRun $run): ?string
    {
        if ($run->lane !== 'live') {
            return 'Only a live-lane run is certified; the scripted lane proves the lab, not the agent with a real model.';
        }
        if ($run->status !== 'passed' || $run->passed !== true || $run->finished_at === null) {
            return 'The run did not pass.';
        }
        $current = collect($this->scenarios->identities('all'))->keyBy('id');
        $results = $run->results()->get()->keyBy('scenario');
        if ($results->keys()->sort()->values()->all() !== $current->keys()->sort()->values()->all()) {
            return 'The run does not cover every lab scenario.';
        }
        foreach ($results as $id => $result) {
            if ($result->scenario_digest !== $current[$id]['digest'] || $result->scenario_version !== $current[$id]['version']) {
                return "Scenario {$id} has changed since this run; run the live lane again.";
            }
            if (! $result->passed || ! $result->structural_passed || $result->judge_verdict !== 'pass') {
                return "Scenario {$id} was not passed by both the structural checks and the judge.";
            }
        }
        if (($run->agent['source_digest'] ?? null) !== AgentVersion::current()['source_digest']) {
            return 'The installed prism-agent-docs is not the code this run exercised.';
        }
        if (DocsLabCertificate::query()->where('body->evidence->run', $run->id)->exists()) {
            return 'This run already has a certificate.';
        }

        return null;
    }

    /**
     * Walk the whole chain. Empty when every certificate's hash and link hold.
     *
     * @return list<string>
     */
    public function verify(): array
    {
        $problems = [];
        $previous = null;
        $expected = 1;
        foreach (DocsLabCertificate::query()->orderBy('sequence')->get() as $certificate) {
            $body = $certificate->body;
            if ($certificate->sequence !== $expected || ($body['sequence'] ?? null) !== $expected) {
                $problems[] = "Certificate {$certificate->sequence}: out of sequence (expected {$expected}).";
            }
            if (! hash_equals($certificate->hash, self::hash($body))) {
                $problems[] = "Certificate {$certificate->sequence}: its contents do not match its hash.";
            }
            if (($body['previous'] ?? null) !== $previous || $certificate->previous_hash !== $previous) {
                $problems[] = "Certificate {$certificate->sequence}: does not link to the certificate before it.";
            }
            $previous = $certificate->hash;
            $expected++;
        }

        return $problems;
    }

    /** @param array<string, mixed> $body every field except `hash` */
    public static function hash(array $body): string
    {
        unset($body['hash']);

        return hash('sha256', CanonicalJson::encode($body));
    }
}
