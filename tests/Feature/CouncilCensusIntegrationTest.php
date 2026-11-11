<?php

declare(strict_types=1);

namespace Tests\Feature;

use ChangeCouncil\Census\Census;
use ChangeCouncil\Census\CensusIngest;
use ChangeCouncil\Census\CensusParser;
use ChangeCouncil\Census\FirstParty;
use ChangeCouncil\Laravel\CouncilServiceProvider;
use ChangeCouncil\Models\AuditEvent;
use ChangeCouncil\Models\Consumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CouncilCensusIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_host_rule_accepts_every_canonical_prism_name_through_the_native_parser(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/council/prism-packages.json')), true, flags: JSON_THROW_ON_ERROR);
        $rows = array_map(fn (array $p): array => ['ecosystem' => $p['ecosystem'], 'name' => $p['name'], 'version' => '1.2.3'], $fixture['packages']);
        $this->assertCount(11, array_filter($rows, fn (array $p): bool => $p['ecosystem'] === 'pypi'));
        $parsed = CensusParser::parse($this->body('synthetic-nonce', $rows), app(FirstParty::class));
        $this->assertTrue($parsed['ok']);
        $this->assertSame($rows, $parsed['census']['packages']);
        $this->assertSame([], $parsed['census']['dropped']);
        $this->assertSame('Prism', app(FirstParty::class)->publisher());
        // These fixture versions and planned names prove acceptance only.
        $this->assertDatabaseCount('council_census_requests', 0);
        Http::assertNothingSent();
    }

    public function test_native_parser_drops_foreign_names_and_newline_suffixed_names_and_versions(): void
    {
        $rows = [
            ['pypi', 'fancy-inertia-server', '1.2.3', 'not_first_party'],
            ['pypi', 'prism-foo', '1.2.3', 'not_first_party'],
            ['pypi', 'prism-conformance-extra', '1.2.3', 'not_first_party'],
            ['composer', 'particle-academy/react-fancy', '1.2.3', 'not_first_party'],
            ['npm', '@particle-academy/react-fancy', '1.2.3', 'not_first_party'],
            ['pypi', "prism-ai-core\n", '1.2.3', 'bad_name'],
            ['composer', "particle-academy/prism\n", '1.2.3', 'bad_name'],
            ['npm', "@particle-academy/prism\n", '1.2.3', 'bad_name'],
            ['pypi', 'prism-ai-core', "1.2.3\n", 'bad_version'],
            ['composer', 'particle-academy/prism', "1.2.3\n", 'bad_version'],
            ['npm', '@particle-academy/prism', "1.2.3\n", 'bad_version'],
        ];
        foreach ($rows as [$ecosystem, $name, $version, $reason]) {
            $parsed = CensusParser::parse($this->body('synthetic-nonce', [compact('ecosystem', 'name', 'version')]), app(FirstParty::class));
            $this->assertTrue($parsed['ok']);
            $this->assertSame([], $parsed['census']['packages']);
            $this->assertSame([['ecosystem' => $ecosystem, 'name' => $name, 'reason' => $reason]], $parsed['census']['dropped']);
        }
    }

    public function test_invalid_host_patterns_fail_in_the_actual_package_provider_boot(): void
    {
        $original = config('council.census.first_party');
        foreach (['*', '(prism-)+.*', 'prism*core', "prism-ai-*\n", ['not-a-string']] as $pattern) {
            config(['council.census.first_party' => ['pypi' => ['patterns' => [$pattern]]]]);
            $this->app->forgetInstance(FirstParty::class);
            try {
                (new CouncilServiceProvider($this->app))->boot();
                $this->fail('The native boot must refuse an invalid first-party pattern.');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('council.census.first_party.pypi.patterns.0', $error->getMessage());
            } finally {
                config(['council.census.first_party' => $original]);
                $this->app->forgetInstance(FirstParty::class);
                $this->app->make(FirstParty::class);
            }
        }
    }

    public function test_an_omitted_ecosystem_accepts_nothing_and_does_not_merge_the_fancy_defaults(): void
    {
        $original = config('council.census.first_party');
        config(['council.census.first_party' => ['pypi' => ['patterns' => ['prism-conformance']]]]);
        $this->app->forgetInstance(FirstParty::class);
        try {
            $rule = app(FirstParty::class);
            $this->assertSame(['composer' => [], 'npm' => [], 'pypi' => ['prism-conformance']], $rule->describe());
            $this->assertSame('not_first_party', CensusParser::reason('composer', 'particle-academy/prism', '1.2.3', $rule));
            $this->assertSame('not_first_party', CensusParser::reason('npm', '@particle-academy/prism', '1.2.3', $rule));
            $this->assertNull(CensusParser::reason('pypi', 'prism-conformance', '1.2.3', $rule));
        } finally {
            config(['council.census.first_party' => $original]);
            $this->app->forgetInstance(FirstParty::class);
        }
    }

    public function test_native_census_queue_ingest_and_replay_keep_sender_binding_and_owner_fields(): void
    {
        $member = Consumer::query()->create([
            'address' => 'member@synthetic', 'workspace' => 'synthetic',
            'packages' => [], 'watches' => [['ecosystem' => 'npm', 'name' => '@particle-academy/prism']],
            'internal' => false, 'whitelisted' => true, 'silence_policy' => 'proceed_and_notify',
            'weight' => 7, 'project_name' => 'Owner project', 'project_url' => 'https://fixture.invalid/',
        ]);
        $before = $member->fresh()->getAttributes();
        $census = app(Census::class);
        [$request] = $census->request();
        $this->assertSame([], $census->request());
        [$queued] = $census->queued();
        $this->assertSame('member@synthetic', $queued['to']);
        foreach (['Prism', 'prism-ai-*', 'prism-conformance', 'particle-academy/prism*', '@particle-academy/prism*'] as $literal) {
            $this->assertStringContainsString($literal, $queued['text']);
        }
        $this->assertStringNotContainsString('fancy-*', $queued['text']);
        $this->assertSame('queued', $request->delivery);
        $census->markSent($request, '{"ok":false,"error":"synthetic refusal"}');
        $this->assertNotSame('sent', $request->fresh()->delivery);
        $census->markSent($request, '{"ok":true,"messageId":"synthetic-send"}');
        $this->assertSame('sent', $request->fresh()->delivery);
        $rows = [['ecosystem' => 'pypi', 'name' => 'prism-ai-core', 'version' => '1.2.3']];
        $text = $this->body($request->nonce, $rows);
        $ingest = app(CensusIngest::class);
        $wrong = $ingest->receive('forged@synthetic', $text, 'synthetic-wrong');
        $this->assertSame('sender_mismatch', $wrong->problem);
        $this->assertSame([], $member->fresh()->packages);
        $this->assertNull($request->fresh()->answered_at);
        $result = $ingest->receive('member@synthetic', $text, 'synthetic-accepted');
        $this->assertSame('applied', $result->status);
        $this->assertSame($rows, $member->fresh()->packages);
        $this->assertSame('census', $member->fresh()->source);
        $this->assertNotNull($request->fresh()->answered_at);
        foreach (['weight', 'silence_policy', 'whitelisted', 'watches', 'project_name', 'project_url'] as $field) {
            $this->assertSame($before[$field], $member->fresh()->getAttributes()[$field], $field);
        }
        $auditCount = AuditEvent::query()->count();
        $this->assertSame('duplicate', $ingest->receive('member@synthetic', $text, 'synthetic-accepted')->status);
        $this->assertSame($auditCount, AuditEvent::query()->count());
        $this->assertSame('already_answered', $ingest->receive('member@synthetic', $text, 'synthetic-new-id')->problem);
        $this->assertDatabaseCount('lab_run_intents', 0);
        Http::assertNothingSent();
    }

    public function test_native_ingest_refuses_unknown_nonce_closed_body_and_oversize_without_changing_members(): void
    {
        $ingest = app(CensusIngest::class);
        $this->assertSame('unknown_nonce', $ingest->receive('synthetic@fixture', $this->body('not-issued', []), 'unknown')->problem);
        $this->assertSame('unknown_key', $ingest->receive('synthetic@fixture', "COUNCIL-CENSUS v1\n".json_encode(['nonce' => 'x', 'apps' => [], 'packages' => [], 'weight' => 999]), 'closed')->problem);
        $large = $ingest->receive('synthetic@fixture', str_repeat('x', 65537), 'large');
        $this->assertSame('too_large', $large->problem);
        $this->assertLessThanOrEqual(65536, strlen($large->reply->raw_text));
        $this->assertDatabaseCount('council_consumers', 0);
        Http::assertNothingSent();
    }

    private function body(string $nonce, array $packages): string
    {
        return "COUNCIL-CENSUS v1\n".json_encode([
            'nonce' => $nonce, 'apps' => [['name' => 'Synthetic only', 'lockfiles' => ['uv.lock']]],
            'packages' => $packages,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
