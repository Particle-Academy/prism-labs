<?php

declare(strict_types=1);

namespace App\Team;

/**
 * Two lanes' ecosystem probes, merged into one verdict per family.
 *
 * A family is green only when EVERY reachable lane says it is. A port that
 * passes in TypeScript and fails in Python is exactly the parity failure two
 * languages exist to catch, and a merge that took the best of the two would
 * hide it.
 *
 * Extracted from the controller because this is the part with an invariant. A
 * controller can be exercised only with both agents running; this can be
 * exercised with the shapes that matter, including the ones a live run is
 * unlikely to produce on demand.
 */
final readonly class EcosystemVerdicts
{
    /**
     * @param  list<array{language: string, report: array<string, mixed>|null}>  $lanes
     * @return array{families: array<string, bool>, families_green: bool, name_drift: list<array{step: string, missing_from: list<string>}>}
     */
    public static function merge(array $lanes): array
    {
        $families = [];
        $seen = [];
        $reachable = [];

        foreach ($lanes as $lane) {
            if (! is_array($lane['report'] ?? null)) {
                continue;
            }

            $reachable[] = $lane['language'];

            foreach ($lane['report']['families'] ?? [] as $family) {
                $name = $family['family'];
                $passed = ! in_array(false, array_column($family['checks'], 'ok'), true);

                $families[$name] = ($families[$name] ?? true) && $passed;

                foreach (array_column($family['checks'], 'step') as $step) {
                    $seen[$step][$lane['language']] = true;
                }
            }
        }

        // A check name one lane reported and another did not. The two probes
        // mirror each other check for check, so this is drift — either a real
        // one, or the probes having been edited apart. Named rather than left
        // for a reader to decode from a half-ticked row: a silent near-miss is
        // what this panel exists to prevent, and it has already happened once
        // (a typographic apostrophe in one probe, a straight one in the other).
        $drift = [];

        foreach ($seen as $step => $languages) {
            $absent = array_values(array_diff($reachable, array_keys($languages)));

            if ($absent !== []) {
                $drift[] = ['step' => (string) $step, 'missing_from' => $absent];
            }
        }

        return [
            'families' => $families,
            // An empty result is NOT green. No lane answering means nothing was
            // proved, and reporting that as agreement is the worst reading a
            // board can offer.
            'families_green' => $families !== [] && ! in_array(false, $families, true),
            'name_drift' => $drift,
        ];
    }
}
