<?php

declare(strict_types=1);

namespace App\Lab;

use Illuminate\Support\Facades\Cache;
use Prism\Prism\Exceptions\ModelListingAuthenticationException;
use Prism\Prism\Exceptions\UnsupportedModelListingException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Models\ListModelsRequest;
use Prism\Prism\Models\Model;
use Throwable;

/** Provider-discovered models, separate from the operator's permission to spend. */
final class ModelCatalogue
{
    /** @var list<string> */
    public const DEFAULT_ENABLED = ['anthropic:claude-sonnet-5', 'openai:gpt-4.1-mini'];

    public function __construct(private readonly ProviderRegistry $providers, private readonly ModelPrices $prices) {}

    /** @return list<array<string, mixed>> */
    public function providers(): array
    {
        $result = [];

        foreach ($this->providers->all() as $provider) {
            $key = $provider['key'];
            $cacheKey = 'lab.model-listing.v1.'.$key;

            if (! $provider['configured']) {
                Cache::forget($cacheKey);
                $listing = ['status' => 'unconfigured', 'models' => [], 'checked_at' => null,
                    'message' => $provider['requiresKey'] ? 'Provider not reachable: no API key is configured.' : 'Provider not reachable: no endpoint is configured.'];
            } else {
                $listing = Cache::get($cacheKey);
                if ($listing === null) {
                    $listing = $this->discover($key);
                    Cache::put($cacheKey, $listing, $listing['status'] === 'ready' ? 600 : 60);
                }
            }

            $models = array_map(fn (array $model): array => [
                'key' => $key.':'.$model['id'], 'provider' => $key, 'provider_label' => $provider['label'],
                'id' => $model['id'], 'label' => $model['name'] ?? $model['id'], 'created_at' => $model['created_at'],
                // The listing API returns no capability classification. Never guess from IDs.
                'kind' => 'other', 'configured' => true, 'price' => $this->prices->forModel($key, $model['id']),
                'lifecycle' => $this->prices->lifecycleForModel($key, $model['id']),
            ], $listing['models']);
            usort($models, static function (array $a, array $b): int {
                if ($a['price'] === null || $b['price'] === null) {
                    return ($a['price'] === null) <=> ($b['price'] === null) ?: strcmp($a['id'], $b['id']);
                }

                return $b['price']['input'] <=> $a['price']['input'] ?: strcmp($a['id'], $b['id']);
            });

            $result[] = ['key' => $key, 'label' => $provider['label'], 'configured' => $provider['configured'],
                'status' => $listing['status'], 'message' => $listing['message'], 'checked_at' => $listing['checked_at'],
                'scope' => $key === 'replicate' ? 'Replicate lists a public catalogue, not your account or its private models.' : null,
                'models' => $models];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function refresh(): array
    {
        foreach ($this->providers->all() as $provider) {
            Cache::forget('lab.model-listing.v1.'.$provider['key']);
        }

        return $this->providers();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_merge([], ...array_column($this->providers(), 'models'));
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_column($this->all(), 'key');
    }

    /**
     * Incidental narration/judging may use only explicitly permitted, priced models.
     * The first quoted rate is the standard/small-context rate, not a cost estimate.
     *
     * @param  list<string>  $allowed
     * @return array{provider: string, model: string}|null
     */
    public function cheapestConfigured(array $allowed): ?array
    {
        $usable = array_values(array_filter($this->all(), fn (array $row): bool => $row['price'] !== null && in_array($row['key'], $allowed, true)));
        usort($usable, fn (array $a, array $b): int => $a['price']['input'] <=> $b['price']['input'] ?: strcmp($a['key'], $b['key']));

        return $usable === [] ? null : ['provider' => $usable[0]['provider'], 'model' => $usable[0]['id']];
    }

    public function has(string $provider, string $model): bool
    {
        return in_array($provider.':'.$model, $this->keys(), true);
    }

    /** @return array{status: string, models: list<array<string, mixed>>, checked_at: string, message: ?string} */
    private function discover(string $provider): array
    {
        $checked = now()->toIso8601String();

        try {
            $request = (new ListModelsRequest)->withClientOptions(['timeout' => 8, 'connect_timeout' => 3])->withClientRetry(0);
            $models = Prism::provider($provider)->listModels($request);

            return ['status' => 'ready', 'checked_at' => $checked, 'message' => null,
                'models' => array_map(fn (Model $model): array => ['id' => $model->id, 'name' => $model->displayName, 'created_at' => $model->createdAt], $models)];
        } catch (ModelListingAuthenticationException) {
            return ['status' => 'authentication_rejected', 'models' => [], 'checked_at' => $checked,
                'message' => 'The provider rejected this API key or its access. Update its credentials or permissions in the Lab configuration, then refresh.'];
        } catch (UnsupportedModelListingException) {
            return ['status' => 'unsupported', 'models' => [], 'checked_at' => $checked,
                'message' => 'Model listing for this provider is not implemented in the installed Prism package.'];
        } catch (Throwable) {
            // Underlying failures may contain credentials or response bodies. No raw logs or fallback.
            return ['status' => 'unreachable', 'models' => [], 'checked_at' => $checked,
                'message' => "We couldn't reach this provider. Check its configuration and try Refresh."];
        }
    }
}
