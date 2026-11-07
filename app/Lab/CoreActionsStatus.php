<?php

declare(strict_types=1);

namespace App\Lab;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class CoreActionsStatus
{
    private const string HOST = 'https://api.github.com/repos/Particle-Academy/prism';

    public function cached(): array
    {
        try {
            $cached = Cache::store('file')->get($this->cacheKey());
            if (is_array($cached) && is_int($cached['checked_at'] ?? null)
                && $cached['checked_at'] <= now()->timestamp
                && now()->timestamp - $cached['checked_at'] < $this->lifetime()
                && is_bool($cached['ready'] ?? null) && in_array($cached['status'] ?? null, ['ready', 'blocked', 'unknown'], true)
                && $cached['ready'] === ($cached['status'] === 'ready')
                && ($cached['status'] === 'unknown' ? ($cached['head'] ?? null) === null
                    : is_string($cached['head'] ?? null) && preg_match('/^[a-f0-9]{40}$/D', $cached['head']) === 1)) {
                return ['ready' => $cached['ready'], 'status' => $cached['status'],
                    'message' => $this->message($cached['status']), 'head' => $cached['head'] ?? null, 'checked_at' => $cached['checked_at']];
            }
        } catch (Throwable) {
            // The unavailable state is explicit; cache failures are not green.
        }

        return $this->unknown();
    }

    public function check(bool $fresh = false): array
    {
        $cached = $this->cached();
        if (! $fresh && $cached['checked_at'] !== null) {
            return $cached;
        }

        try {
            $ref = $this->get('/git/ref/heads/main');
            $head = $ref['object']['sha'] ?? null;
            if (($ref['ref'] ?? null) !== 'refs/heads/main' || ($ref['object']['type'] ?? null) !== 'commit'
                || ! is_string($head) || ! preg_match('/^[a-f0-9]{40}$/D', $head)) {
                throw new RuntimeException('Invalid core reference.');
            }
            $response = $this->get('/actions/runs', ['head_sha' => $head, 'event' => 'push', 'branch' => 'main', 'per_page' => 100]);
            $runs = $response['workflow_runs'] ?? null;
            $workflows = config('lab-preflight.core_actions.workflows');
            if (! is_array($runs) || ! is_int($response['total_count'] ?? null)
                || $response['total_count'] !== count($runs) || count($runs) > 100
                || ! is_array($workflows) || $workflows === [] || count($workflows) > 20) {
                throw new RuntimeException('Incomplete core checks.');
            }
            $results = [];
            foreach ($workflows as $path) {
                if (! is_string($path) || ! preg_match('~^\.github/workflows/[a-z0-9-]+\.yml$~D', $path)) {
                    throw new RuntimeException('Invalid workflow configuration.');
                }
                $matches = array_filter($runs, static fn ($run): bool => is_array($run)
                    && ($run['head_sha'] ?? null) === $head && ($run['head_branch'] ?? null) === 'main'
                    && ($run['event'] ?? null) === 'push' && ($run['path'] ?? null) === $path);
                $numbers = [];
                foreach ($matches as $candidate) {
                    if (! is_int($candidate['run_number'] ?? null) || $candidate['run_number'] < 1
                        || ! is_int($candidate['id'] ?? null) || $candidate['id'] < 1
                        || in_array($candidate['run_number'], $numbers, true)) {
                        throw new RuntimeException('Ambiguous core checks.');
                    }
                    $numbers[] = $candidate['run_number'];
                }
                // Pick the newest exact-subject run, never a previous green one.
                usort($matches, static fn (array $a, array $b): int => ($b['run_number'] ?? 0) <=> ($a['run_number'] ?? 0));
                $run = $matches[0] ?? null;
                $results[] = $run !== null && ($run['status'] ?? null) === 'completed'
                    && ($run['conclusion'] ?? null) === 'success'
                    && is_int($run['id'] ?? null) && $run['id'] > 0
                    && is_int($run['run_number'] ?? null) && $run['run_number'] > 0;
            }
            $ready = ! in_array(false, $results, true);
            $result = ['ready' => $ready, 'status' => $ready ? 'ready' : 'blocked',
                'message' => $this->message($ready ? 'ready' : 'blocked'),
                'head' => $head, 'checked_at' => now()->timestamp];
        } catch (Throwable) {
            $result = [...$this->unknown(), 'checked_at' => now()->timestamp];
        }

        try {
            Cache::store('file')->put($this->cacheKey(), $result, $this->lifetime());
        } catch (Throwable) {
            // Return only this bounded observation, with its actual timestamp.
        }

        return $result;
    }

    private function lifetime(): int
    {
        return max(60, min(600, (int) config('lab-preflight.core_actions.cache_seconds', 300)));
    }

    private function cacheKey(): string
    {
        return 'lab-preflight:core-actions:v1:'.hash('sha256', json_encode(config('lab-preflight.core_actions.workflows'), JSON_THROW_ON_ERROR));
    }

    private function message(string $status): string
    {
        return match ($status) {
            'ready' => 'Core checks passed for the observed main.',
            'blocked' => 'Core checks for the observed main are incomplete or unsuccessful.',
            default => 'Core check status is unavailable.',
        };
    }

    private function unknown(): array
    {
        return ['ready' => false, 'status' => 'unknown', 'message' => 'Core check status is unavailable.', 'head' => null, 'checked_at' => null];
    }

    private function get(string $path, array $query = []): array
    {
        $request = Http::acceptJson()->withHeaders(['X-GitHub-Api-Version' => '2026-03-10', 'Cache-Control' => 'no-cache'])
            ->connectTimeout(2)->timeout(4)->withoutRedirecting()
            ->withOptions(['stream' => true, 'read_timeout' => 2]);
        $token = config('lab-preflight.core_actions.token');
        if (is_string($token) && trim($token) !== '') {
            $request = $request->withToken($token);
        }
        $response = $request->get(self::HOST.$path, $query);
        $stream = $response->toPsrResponse()->getBody();
        try {
            if ($response->status() !== 200 || (int) $response->header('Content-Length') > 1_048_576) {
                throw new RuntimeException('Core check request unavailable.');
            }
            $body = '';
            $deadline = microtime(true) + 4;
            while (! $stream->eof()) {
                if (strlen($body) > 1_048_576 || microtime(true) > $deadline) {
                    throw new RuntimeException('Core check response limit.');
                }
                $chunk = $stream->read(min(8192, 1_048_577 - strlen($body)));
                if ($chunk === '' && ! $stream->eof()) {
                    throw new RuntimeException('Incomplete core check response.');
                }
                $body .= $chunk;
            }
            if (strlen($body) > 1_048_576) {
                throw new RuntimeException('Core check response limit.');
            }
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new RuntimeException('Invalid core check response.');
            }

            return $data;
        } finally {
            $stream->close();
        }
    }
}
