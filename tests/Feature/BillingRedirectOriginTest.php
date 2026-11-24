<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\AnthropicBilling;
use App\Billing\BilledPeriod;
use App\Billing\OpenAiBilling;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use RedirectLoopback;
use RuntimeException;
use Tests\TestCase;

require_once __DIR__.'/../Support/RedirectLoopback.php';

/**
 * Billing requests carry an admin key. A redirect is followed only when the
 * scheme, host and port stay the same; anything else is refused before a
 * second request is built, so the other server receives nothing.
 *
 * Real loopback servers, with every non-loopback connection refused.
 */
final class BillingRedirectOriginTest extends TestCase
{
    private const OPENAI_KEY = 'sk-admin-FIXTURE-redirect-openai-never-real';

    private const ANTHROPIC_KEY = 'sk-ant-admin01-FIXTURE-redirect-anthropic-never-real';

    private RedirectLoopback $endpoint;

    private RedirectLoopback $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();

        Http::allowStrayRequests(['http://127.0.0.*']);
        Http::globalMiddleware(fn (callable $handler): callable => function (RequestInterface $request, array $options) use ($handler) {
            if (preg_match('/\A127\.\d+\.\d+\.\d+\z/', $request->getUri()->getHost()) !== 1) {
                throw new RuntimeException('Only loopback hosts may be contacted in this test.');
            }

            return $handler($request, $options);
        });

        $this->endpoint = RedirectLoopback::start('127.0.0.1');
        $this->elsewhere = RedirectLoopback::start('127.0.0.2');

        config([
            'lab.billing.timeout_seconds' => 5,
            'lab.billing.openai_url' => $this->endpoint->origin().'/v1',
            'lab.billing.openai_admin_key' => self::OPENAI_KEY,
            'lab.billing.openai_api_key_ids' => ['key_fixture'],
            'lab.billing.anthropic_url' => $this->endpoint->origin().'/v1',
            'lab.billing.anthropic_admin_key' => self::ANTHROPIC_KEY,
            'lab.billing.anthropic_api_key_ids' => ['apikey_fixture'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->endpoint->stop();
        $this->elsewhere->stop();

        parent::tearDown();
    }

    /** @return array<string, array{int}> */
    public static function statuses(): array
    {
        return ['301' => [301], '302' => [302], '303' => [303], '307' => [307], '308' => [308]];
    }

    private function fetch(string $provider): BilledPeriod
    {
        $from = CarbonImmutable::parse('2026-11-01T00:00:00Z');
        $billing = $provider === 'anthropic' ? new AnthropicBilling : new OpenAiBilling;

        return $billing->fetch($from, $from->addDay());
    }

    #[DataProvider('statuses')]
    public function test_anthropic_billing_does_not_follow_a_redirect_to_another_host(int $status): void
    {
        $this->endpoint->answer([['match' => '/organizations/', 'status' => $status, 'location' => $this->elsewhere->origin().'/landed']]);

        $period = $this->fetch('anthropic');

        $this->assertSame([], $this->elsewhere->requests());
        $this->assertStringNotContainsString(self::ANTHROPIC_KEY, $this->elsewhere->received());
        $this->assertSame('redirect_refused', $period->tokensFailure);
        $this->assertSame('redirect_refused', $period->costReason);
    }

    #[DataProvider('statuses')]
    public function test_openai_billing_does_not_follow_a_redirect_to_another_host(int $status): void
    {
        $this->endpoint->answer([['match' => '/organization/', 'status' => $status, 'location' => $this->elsewhere->origin().'/landed']]);

        $period = $this->fetch('openai');

        $this->assertSame([], $this->elsewhere->requests());
        $this->assertSame('redirect_refused', $period->tokensFailure);
    }

    public function test_billing_does_not_follow_a_redirect_to_the_same_host_on_another_port(): void
    {
        $other = RedirectLoopback::start('127.0.0.1');

        try {
            $this->endpoint->answer([['match' => '/organizations/', 'status' => 307, 'location' => $other->origin().'/landed']]);

            $period = $this->fetch('anthropic');

            $this->assertSame([], $other->requests());
            $this->assertSame('redirect_refused', $period->tokensFailure);
        } finally {
            $other->stop();
        }
    }

    public function test_billing_does_not_follow_a_protocol_relative_redirect(): void
    {
        $this->endpoint->answer([['match' => '/organizations/', 'status' => 302, 'location' => '//'.$this->elsewhere->host.':'.$this->elsewhere->port.'/landed']]);

        $period = $this->fetch('anthropic');

        $this->assertSame([], $this->elsewhere->requests());
        $this->assertSame('redirect_refused', $period->tokensFailure);
    }

    public function test_billing_does_not_follow_a_redirect_that_changes_the_scheme(): void
    {
        $this->endpoint->answer([['match' => '/organizations/', 'status' => 308, 'location' => 'https://{host}/landed']]);

        $period = $this->fetch('anthropic');

        $this->assertSame('redirect_refused', $period->tokensFailure);
    }

    public function test_billing_follows_a_redirect_that_keeps_the_scheme_host_and_port(): void
    {
        $this->endpoint->answer(
            [['match' => '/organizations/analytics/usage', 'status' => 307, 'location' => '/v1/landed']],
            body: '{"data":[],"has_more":false,"data_refreshed_at":null}',
        );

        $period = $this->fetch('anthropic');

        $uris = array_column($this->endpoint->requests(), 'uri');

        $this->assertNull($period->tokensFailure);
        $this->assertContains('/v1/landed', $uris);
        $this->assertSame([], $this->elsewhere->requests());
    }
}
