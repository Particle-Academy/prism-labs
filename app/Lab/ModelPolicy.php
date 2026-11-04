<?php

declare(strict_types=1);

namespace App\Lab;

use Illuminate\Support\Facades\DB;

/**
 * Which models a benchmark lane is ALLOWED to use.
 *
 * The Lab spends real money per lane, and a spec is frozen the moment it is
 * approved — so a model chosen carelessly at design time is a model every run
 * of that spec pays for, and the only way to change it afterwards is a new
 * revision. This is the operator's standing answer, applied before a spec can
 * be launched rather than after the bill arrives.
 *
 * It is an ALLOW-list. An id absent from it is refused, including one that is
 * perfectly valid at the provider: the question here is not "does this model
 * exist" but "did a human agree to spend on it".
 */
final class ModelPolicy
{
    private const KEY = 'benchmark.allowed_models';

    public function __construct(private readonly ModelCatalogue $catalogue) {}

    /**
     * @return list<string> provider-qualified keys, e.g. `anthropic:claude-sonnet-5`
     */
    public function allowed(): array
    {
        return array_values(array_intersect($this->selection(), $this->catalogue->keys()));
    }

    /** The operator's decision survives an unavailable or withdrawn listing. @return list<string> */
    public function selection(): array
    {
        $stored = DB::table('lab_settings')->where('key', self::KEY)->value('value');

        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        $selected = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : ModelCatalogue::DEFAULT_ENABLED;

        return array_values(array_unique($selected));
    }

    /** @return list<array{key: string, provider: string, id: string, reason: string}> */
    public function unavailable(): array
    {
        $providers = array_column($this->catalogue->providers(), null, 'key');
        $unavailable = [];
        foreach ($this->selection() as $key) {
            [$provider, $id] = array_pad(explode(':', $key, 2), 2, '');
            $listing = $providers[$provider] ?? null;
            if ($listing === null || $listing['status'] !== 'ready' || ! in_array($key, array_column($listing['models'], 'key'), true)) {
                $unavailable[] = ['key' => $key, 'provider' => $provider, 'id' => $id,
                    'reason' => ($listing['status'] ?? null) === 'ready' ? 'withdrawn' : 'unavailable'];
            }
        }

        return $unavailable;
    }

    /**
     * @param  list<string>  $keys
     * @return list<string> effective permissions after preserving unavailable saved choices
     */
    public function allow(array $keys): array
    {
        $previous = $this->selection();
        $providers = $this->catalogue->providers();
        $ready = array_column(array_filter($providers, fn (array $row): bool => $row['status'] === 'ready'), 'key');
        $known = array_merge($this->catalogue->keys(), $previous);
        // An outage is not an instruction to erase a provider's saved choices.
        // Withdrawn choices on a successful listing remain until explicitly removed.
        $kept = array_filter($previous, fn (string $key): bool => ! in_array(explode(':', $key, 2)[0], $ready, true));
        $changed = array_filter($keys, fn (string $key): bool => in_array(explode(':', $key, 2)[0], $ready, true) && in_array($key, $known, true));
        $valid = array_values(array_unique(array_merge($kept, $changed)));

        DB::table('lab_settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => json_encode($valid, JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()],
        );

        return $this->allowed();
    }

    public function permits(string $provider, string $model): bool
    {
        return in_array($provider.':'.$model, $this->allowed(), true);
    }

    /**
     * Why a model was refused, phrased for whoever is looking at the refusal.
     *
     * The two reasons need different sentences: a model nobody has heard of is
     * probably a typo or a retired id, and one the operator simply has not
     * ticked is a decision they can change in one click. Collapsing them into
     * "not allowed" sends someone hunting for a bug in the first case and for
     * a missing model in the second.
     */
    public function refusal(string $provider, string $model): string
    {
        $listing = collect($this->catalogue->providers())->firstWhere('key', $provider);
        if ($listing === null || $listing['status'] !== 'ready') {
            return sprintf('The model listing is unavailable for %s. Your saved selection is kept, but launches are refused until its listing is available. Check the provider configuration or Refresh /lab/models.', $listing['label'] ?? $provider);
        }

        return $this->catalogue->has($provider, $model)
            ? sprintf('`%s` on %s is in the catalogue but not enabled for testing. Enable it at /lab/models, or choose a model that is.', $model, $provider)
            : sprintf('`%s` on %s is not a model this Lab knows from the current provider listing. Refresh /lab/models and choose an available model.', $model, $provider);
    }
}
