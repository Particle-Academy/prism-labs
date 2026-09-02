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
        $stored = DB::table('lab_settings')->where('key', self::KEY)->value('value');

        if (! is_string($stored)) {
            return ModelCatalogue::DEFAULT_ENABLED;
        }

        $decoded = json_decode($stored, true);

        if (! is_array($decoded)) {
            return ModelCatalogue::DEFAULT_ENABLED;
        }

        // Intersected with the catalogue on every read, so a model retired from
        // the catalogue stops being allowed without anyone having to remember
        // to clear the stored row.
        return array_values(array_intersect(
            array_values(array_filter($decoded, 'is_string')),
            $this->catalogue->keys(),
        ));
    }

    /**
     * @param  list<string>  $keys
     * @return list<string> what was actually stored, after unknown keys are dropped
     */
    public function allow(array $keys): array
    {
        $valid = array_values(array_intersect($keys, $this->catalogue->keys()));

        DB::table('lab_settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => json_encode($valid, JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()],
        );

        return $valid;
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
        return $this->catalogue->has($provider, $model)
            ? sprintf('`%s` on %s is in the catalogue but not enabled for testing. Enable it at /lab/models, or choose a model that is.', $model, $provider)
            : sprintf('`%s` on %s is not a model this Lab knows. Add it to ModelCatalogue only after verifying the id against the provider.', $model, $provider);
    }
}
