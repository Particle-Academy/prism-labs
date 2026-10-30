<?php

declare(strict_types=1);

namespace App\Lab;

use Prism\Prism\Exceptions\PrismPerplexityRequestException;
use Prism\Prism\Exceptions\PrismRunException;
use Prism\Prism\Facades\Prism;
use Throwable;

/** Operator-submitted live checks. No content, credentials or raw errors leave this boundary. */
final class PerplexityResearchProbe
{
    /** @return array<string, mixed> */
    public function run(PrismTestCase $case): array
    {
        $started = hrtime(true);
        $result = ['id' => $case->id, 'provider' => $case->provider, 'model' => $case->model, 'feature' => $case->feature, 'passed' => false, 'verdict' => 'UNMEASURED', 'metrics' => [], 'error' => null, 'diagnostics' => null];
        if (! app()->environment('local')) {
            $result['verdict'] = 'REFUSED';

            return $result + ['latency_ms' => 0, 'note' => 'REFUSED: local operator runs only'];
        }
        // Normal app configuration handles credentials. Only availability is
        // observed; no key is copied, returned, passed to a helper or logged.
        if (blank(config('prism.providers.perplexity.api_key'))) {
            $result['verdict'] = 'SKIPPED';

            return $result + ['latency_ms' => 0, 'note' => 'SKIPPED: no key'];
        }
        $kind = substr($case->id, strlen('perplexity.research.'));
        $accepted = in_array($kind, ['shape-deep', 'shape-pro', 'filters-deep', 'filters-pro'], true);
        $metrics = ['status' => null, 'reason' => null, 'request_id_present' => false, 'response_id_present' => false, 'citations_count' => 0, 'partial_output_present' => false, 'provider_message_present' => false, 'provider_code_present' => false, 'usage_present' => false];
        $telemetry = config('prism.telemetry.enabled');
        config(['prism.telemetry.enabled' => false]);
        try {
            $options = ['max_steps' => $kind === 'completed' ? 6 : ($kind === 'long' ? 4 : 1), 'tools' => [['type' => 'web_search']]];
            if (str_starts_with($kind, 'filters-')) {
                $options += ['search_recency_filter' => 'year', 'search_after_date_filter' => now()->subMonths(6)->format('m/d/Y')];
            }
            $response = Prism::text()->using('perplexity', $case->model)
                ->withProviderOptions($options)->withMaxTokens($kind === 'completed' ? 2048 : ($kind === 'incomplete' ? 1 : 64))
                ->withPrompt($kind === 'long'
                    ? 'Research the official PHP enum documentation and the PHP 8.4 release notes. Search both sources and compare two changes with citations.'
                    : 'Search the official PHP enum documentation. Give one short cited sentence defining an enum.')
                ->asText();
            $metrics = array_replace($metrics, [
                'status' => $this->safeStatus($response->additionalContent['response_status'] ?? 'completed'),
                'request_id_present' => filled($response->additionalContent['provider_request_id'] ?? null),
                'response_id_present' => $response->meta->id !== '',
                'citations_count' => count($response->additionalContent['search_results'] ?? []) + count($response->additionalContent['annotations'] ?? []),
                'partial_output_present' => $response->text !== '',
                'usage_present' => isset($response->additionalContent['usage']),
                'provider_reported_cost' => $response->usage->cost,
            ]);
            $result['passed'] = $accepted || ($kind === 'long') || ($kind === 'completed' && $response->text !== '' && $metrics['citations_count'] > 0 && $metrics['usage_present'] && $metrics['request_id_present']);
            $result['verdict'] = $result['passed'] ? 'PASS' : 'UNMEASURED';
        } catch (PrismRunException $e) {
            $metrics = array_replace($metrics, [
                'status' => $this->safeStatus($e->status()), 'reason' => $this->safeReason($e->incompleteReason()),
                'request_id_present' => filled($e->providerRequestId()), 'response_id_present' => filled($e->runId()),
                'citations_count' => count($e->citations()) + $this->sourceCount($e->output()),
                'partial_output_present' => $e->partialText() !== '', 'usage_present' => $e->usage() !== null,
                'provider_message_present' => filled($e->providerError()['message'] ?? null),
                'provider_code_present' => $e->providerCode() !== null, 'provider_reported_cost' => $e->usage()?->cost,
            ]);
            $result['passed'] = $accepted || ($kind === 'incomplete' && $e->status() === 'incomplete' && $e->incompleteReason() !== null && $metrics['request_id_present']) || ($kind === 'failed' && $e->status() === 'failed' && $metrics['provider_message_present'] && $metrics['request_id_present']) || ($kind === 'long' && $e->status() === 'incomplete');
            $result['verdict'] = $result['passed'] ? 'PASS' : 'UNMEASURED';
        } catch (PrismPerplexityRequestException $e) {
            $metrics['status'] = 'http_'.$e->httpStatus;
            $metrics['request_id_present'] = filled($e->providerRequestId());
            $metrics['provider_code_present'] = $e->providerCode() !== null;
            $metrics['provider_message_present'] = ! str_contains($e->getMessage(), 'provider returned no error message');
            // A request rejection is not an observed HTTP-200 failed run.
            $result['verdict'] = 'FAIL';
            // Real Lab observation3200: this provider uses401 for exhausted
            // credits. Preserve it as an explicit skip, never a successful run.
            if ($e->httpStatus === 401 && str_contains($e->getMessage(), 'You exceeded your current quota')) {
                $metrics['reason'] = 'quota_exhausted';
                $result['verdict'] = 'SKIPPED';
                $result['note'] = 'SKIPPED: provider account has no credits';
            }
        } catch (Throwable $e) {
            $metrics['status'] = 'transport_or_local_error';
            $result['verdict'] = 'FAIL';
        } finally {
            config(['prism.telemetry.enabled' => $telemetry]);
        }
        $result['latency_ms'] = round((hrtime(true) - $started) / 1_000_000, 1);
        $metrics['exceeded_60_seconds'] = $result['latency_ms'] > 60000;
        $metrics['client_timeout_seconds'] = $case->model === 'sonar-deep-research' ? config('prism.providers.perplexity.deep_research_timeout', 600) : config('prism.request_timeout');
        $result['metrics'] = $metrics;
        if ($kind === 'failed' && $metrics['status'] !== 'failed' && $result['verdict'] !== 'SKIPPED') {
            $result['note'] = 'UNMEASURED: no genuine failed run observed; no deterministic trigger or fake substitution';
            $result['verdict'] = 'UNMEASURED';
            $result['passed'] = false;
        }

        return $result;
    }

    private function safeReason(?string $reason): ?string
    {
        return $reason === null || in_array($reason, ['max_output_tokens', 'max_steps'], true) ? $reason : 'other_provider_reason';
    }

    private function safeStatus(mixed $status): ?string
    {
        return $status === null || in_array($status, ['completed', 'failed', 'incomplete', 'cancelled', 'queued', 'in_progress'], true) ? $status : 'other_provider_status';
    }

    /** @param array<array-key, mixed> $items */
    private function sourceCount(array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            if (data_get($item, 'type') === 'search_results' && is_array(data_get($item, 'results'))) {
                $count += count(data_get($item, 'results'));
            }
        }

        return $count;
    }
}
