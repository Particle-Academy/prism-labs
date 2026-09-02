<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkPreflight;
use App\Models\BenchmarkSpec;
use App\Team\CapabilityProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a reviewer is told when a benchmark cannot run.
 *
 * The notice is the whole product of this class: nobody sees the probe, they
 * see the sentence. It previously repeated itself once per lane and asserted
 * one cause for four different situations.
 */
class BenchmarkPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_failure_per_language_however_many_lanes_use_it(): void
    {
        // The reported bug: three TypeScript lanes produced the same sentence
        // three times, reading as three problems.
        $spec = $this->spec([
            ['language' => 'typescript'],
            ['language' => 'typescript'],
            ['language' => 'typescript'],
        ]);

        $failures = app(BenchmarkPreflight::class)->failures($spec);

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('lanes 1, 2, 3', $failures[0]);
        // The caller adds the "Benchmark preflight failed" prefix; saying it
        // here too printed it twice on screen.
        $this->assertStringNotContainsString('Benchmark preflight failed', $failures[0]);
    }

    public function test_the_language_is_spelled_the_way_its_project_spells_it(): void
    {
        // ucfirst('typescript') is "Typescript", which is not its name.
        $failures = app(BenchmarkPreflight::class)->failures($this->spec([['language' => 'typescript']]));

        $this->assertStringContainsString('TypeScript', $failures[0]);
        $this->assertStringNotContainsString('Typescript ', $failures[0]);
    }

    public function test_php_lanes_never_trip_the_preflight(): void
    {
        // PHP runs in-process; there is no endpoint to ask.
        $this->assertSame([], app(BenchmarkPreflight::class)->failures($this->spec([['language' => 'php']])));
    }

    public function test_an_unreachable_agent_is_not_reported_as_the_wrong_kind_of_agent(): void
    {
        // The correctness point. "The parity agent is not a Harness" is a claim
        // about what the port IS; an agent that did not answer might be a
        // perfectly good Harness that is not running. Saying the first when the
        // second is true sends someone to rewrite a port instead of starting a
        // process.
        $this->assertStringContainsString(
            'may simply not be running',
            CapabilityProbe::Unreachable->explain('TypeScript', 'benchmark'),
        );
        $this->assertStringNotContainsString(
            'not a Harness',
            CapabilityProbe::Unreachable->explain('TypeScript', 'benchmark'),
        );

        // And only that one is worth retrying unchanged.
        $this->assertTrue(CapabilityProbe::Unreachable->isTransient());
        $this->assertFalse(CapabilityProbe::NotOffered->isTransient());
        $this->assertFalse(CapabilityProbe::Unregistered->isTransient());
    }

    public function test_a_rostered_language_with_no_endpoint_says_so_rather_than_blaming_the_port(): void
    {
        // Rust is in the roster as a planned lane with no endpoint. The old
        // notice called that "not a Harness", which describes the port; the
        // truth is that nobody has stood one up yet.
        $failures = app(BenchmarkPreflight::class)->failures($this->spec([['language' => 'rust']]));

        $this->assertStringContainsString('Rust', $failures[0]);
        $this->assertStringContainsString('no endpoint configured', $failures[0]);
        $this->assertStringNotContainsString('not a Harness', $failures[0]);
    }

    public function test_a_language_absent_from_the_roster_says_so(): void
    {
        $failures = app(BenchmarkPreflight::class)->failures($this->spec([['language' => 'cobol']]));

        $this->assertStringContainsString('no agent in the roster', $failures[0]);
    }

    public function test_a_provider_the_package_does_not_have_is_caught_before_the_lane_runs(): void
    {
        // The real spec said `google`. Prism has no such provider — it has
        // `gemini` — so the lane would have reached the API and failed there,
        // looking like a result rather than a typo.
        $failures = app(BenchmarkPreflight::class)->failures($this->spec([
            ['language' => 'php', 'provider' => 'google'],
        ]));

        $this->assertStringContainsString('is not a Prism provider', $failures[0]);
        $this->assertStringContainsString('Prism calls it `gemini`', $failures[0]);
    }

    public function test_a_known_provider_with_no_credential_is_caught_before_the_lane_runs(): void
    {
        config(['prism.providers.gemini.api_key' => '']);

        $failures = app(BenchmarkPreflight::class)->failures($this->spec([
            ['language' => 'php', 'provider' => 'gemini'],
        ]));

        $this->assertStringContainsString('no credential is configured', $failures[0]);
        $this->assertStringContainsString('GEMINI_API_KEY', $failures[0]);
    }

    public function test_the_credential_check_covers_php_lanes_too(): void
    {
        // PHP skips the capability probe because the Lab drives those lanes in
        // process. It must NOT skip the credential check: an in-process lane
        // with no API key fails exactly as hard as a remote one, and this is
        // the only check standing between it and a wasted run.
        config(['prism.providers.anthropic.api_key' => '']);

        $failures = app(BenchmarkPreflight::class)->failures($this->spec([
            ['language' => 'php', 'provider' => 'anthropic'],
        ]));

        $this->assertNotSame([], $failures);
        $this->assertStringContainsString('anthropic', $failures[0]);
    }

    public function test_a_configured_provider_passes(): void
    {
        config(['prism.providers.anthropic.api_key' => 'sk-test']);

        $this->assertSame([], app(BenchmarkPreflight::class)->failures($this->spec([
            ['language' => 'php', 'provider' => 'anthropic'],
        ])));
    }

    /** @param list<array<string, mixed>> $lanes */
    private function spec(array $lanes): BenchmarkSpec
    {
        $spec = new BenchmarkSpec;
        $spec->lane_matrix = $lanes;

        return $spec;
    }
}
