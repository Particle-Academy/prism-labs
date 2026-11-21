<?php

declare(strict_types=1);

namespace App\DocsLab;

use App\Benchmarks\CanonicalJson;
use InvalidArgumentException;
use JsonException;

/**
 * The docs-agent lab's scenarios, as versioned data in resources/docs-lab.
 *
 * A scenario's DIGEST covers its own file, the document files its store slots
 * name and the picture definitions. Change any of them and the digest moves, so
 * a certificate issued against the old expectations no longer matches what the
 * lab now asks (Certificates refuses to issue on a run whose digests are not
 * the current ones).
 */
final class Scenarios
{
    /** The order the lab runs and lists them in. */
    public const ORDER = ['S1', 'S2', 'S3', 'S4', 'R1', 'R2'];

    /** Check names Checks knows. A scenario naming any other is refused at load. */
    public const CHECKS = [
        'outcome', 'never_problem', 'draft_written', 'pictures', 'pictures_only_in', 'body_empty', 'band_kept',
        'margin', 'margin_kept', 'no_first_page_band', 'message', 'shown', 'store_draft_is_result',
        'base_version_is_saved', 'limit_reported', 'draft_changed_if_written', 'max_model_calls', 'body_changed', 'text_present',
    ];

    /** @var array<string, Scenario>|null */
    private ?array $loaded = null;

    public function __construct(private readonly Fixtures $fixtures) {}

    /** @return array<string, Scenario> */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }
        $out = [];
        foreach (self::ORDER as $id) {
            $out[$id] = $this->load($id);
        }

        return $this->loaded = $out;
    }

    public function find(string $id): ?Scenario
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * The scenarios a `--scenario` / UI selection names.
     *
     * @return list<Scenario>
     */
    public function select(string $selection): array
    {
        if ($selection === 'all') {
            return array_values($this->all());
        }
        $scenario = $this->find($selection);
        if ($scenario === null) {
            throw new InvalidArgumentException('Choose all or one of '.implode(', ', self::ORDER).'.');
        }

        return [$scenario];
    }

    /** @return list<array{id: string, version: int, digest: string}> */
    public function identities(string $selection = 'all'): array
    {
        return array_map(fn (Scenario $s): array => $s->identity(), $this->select($selection));
    }

    private function load(string $id): Scenario
    {
        $path = $this->fixtures->root().'/scenarios/'.$id.'.json';
        if (! is_file($path)) {
            throw new InvalidArgumentException("Missing lab scenario [{$id}].");
        }
        $raw = Fixtures::lf((string) file_get_contents($path));
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException("Lab scenario [{$id}] is not valid JSON.");
        }
        if (! is_array($data) || ($data['id'] ?? null) !== $id || ! is_int($data['version'] ?? null) || $data['version'] < 1
            || ! is_string($data['brief'] ?? null) || $data['brief'] === '' || ! is_array($data['store'] ?? null)
            || ! array_key_exists('saved', $data['store']) || ! array_key_exists('draft', $data['store'])
            || ! is_array($data['expect'] ?? null) || $data['expect'] === [] || ! is_string($data['judge'] ?? null)
            || ! is_array($data['scripted'] ?? null) || $data['scripted'] === []) {
            throw new InvalidArgumentException("Lab scenario [{$id}] is malformed.");
        }
        foreach ($data['expect'] as $check) {
            if (! is_array($check) || ! in_array($check['check'] ?? null, self::CHECKS, true)) {
                throw new InvalidArgumentException("Lab scenario [{$id}] names an unknown check.");
            }
        }
        $documents = [];
        foreach (['saved', 'draft'] as $slot) {
            $name = $data['store'][$slot];
            if ($name !== null) {
                $documents[$name] = $this->fixtures->documentSource((string) $name);
            }
        }
        ksort($documents);
        $digest = hash('sha256', CanonicalJson::encode([
            'scenario' => $raw, 'documents' => $documents, 'pictures' => $this->fixtures->picturesSource(),
        ]));

        return new Scenario(
            id: $id,
            version: $data['version'],
            title: (string) ($data['title'] ?? $id),
            origin: (string) ($data['origin'] ?? ''),
            brief: $data['brief'],
            store: ['saved' => $data['store']['saved'], 'draft' => $data['store']['draft']],
            expect: array_values($data['expect']),
            judge: $data['judge'],
            scripted: array_values($data['scripted']),
            digest: $digest,
        );
    }
}
