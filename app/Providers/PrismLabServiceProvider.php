<?php

declare(strict_types=1);

namespace App\Providers;

use App\Benchmarks\BenchmarkLaneExecutor;
use App\Benchmarks\WorkspaceToolset;
use App\Lab\CapabilityManager;
use App\Lab\LabSession;
use App\Telemetry\PrismTelemetrySubscriber;
use FancyFlow\ExecutorRegistry;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\NodeKind;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Common\Time\SystemClock;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Prism\Harness\Flow\HarnessAgentExecutor;
use Prism\Harness\Tools\ToolRegistry;
use Prism\HumanPlus\Contracts\RelayTransport;
use Prism\HumanPlus\HumanPlusManager;
use Prism\HumanPlus\Security\ResultGuard;
use Prism\HumanPlus\Security\TrustPolicy;
use Prism\HumanPlus\Stores\LaravelAttachmentStore;
use Prism\HumanPlus\Transport\SsePostRelayTransport;
use Prism\OpenTelemetry\PrismOpenTelemetryServiceProvider;

final class PrismLabServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->environment('local')) {
            return;
        }

        $this->app['config']->set('prism.telemetry.enabled', true);
        $this->app['config']->set('prism.telemetry.capture_content', false);
        $this->app['config']->set('prism-opentelemetry.enabled', true);

        $this->app->singleton(TrustPolicy::class, fn (): TrustPolicy => TrustPolicy::allowing(config('capabilities.human_plus.allowed_tools', [])));
        $this->app->singleton(RelayTransport::class, function (): RelayTransport {
            $unverified = (bool) config('capabilities.human_plus.allow_unverified_egress', false);
            $resolve = config('capabilities.human_plus.local_resolve', []);
            $clientOptions = $unverified ? ['verify' => false] : [];
            if ($unverified && is_array($resolve) && $resolve !== []) {
                $clientOptions['curl'] = [CURLOPT_RESOLVE => $resolve];
            }

            return new SsePostRelayTransport(
                new Client($clientOptions), config('capabilities.human_plus.allowed_relay_hosts', []),
                allowedRelayPorts: config('capabilities.human_plus.allowed_relay_ports', [443]),
                egressProxy: config('capabilities.human_plus.egress_proxy'),
                allowUnverifiedEgress: $unverified,
                authMode: (string) config('capabilities.human_plus.auth_mode', 'query'),
                useNativeCurl: true,
            );
        });
        $this->app->singleton(HumanPlusManager::class, fn ($app): HumanPlusManager => new HumanPlusManager(
            $app->make(RelayTransport::class), $app->make(LaravelAttachmentStore::class),
            $app->make(TrustPolicy::class), $app->make(ResultGuard::class),
        ));

        $endpoint = (string) env('PHOENIX_OTLP_ENDPOINT', 'http://localhost:6006/v1/traces');
        $project = (string) env('PHOENIX_PROJECT', 'prism-lab');

        $resource = ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([
            'service.name' => $project,
            'openinference.project.name' => $project,
        ])));

        $transport = (new OtlpHttpTransportFactory)->create($endpoint, 'application/x-protobuf');
        $exporter = new SpanExporter($transport);
        $processor = new BatchSpanProcessor($exporter, SystemClock::create());
        $tracerProvider = new TracerProvider($processor, null, $resource);

        Globals::registerInitializer(
            fn (Configurator $configurator): Configurator => $configurator->withTracerProvider($tracerProvider)
        );

        $this->app->instance(TracerProvider::class, $tracerProvider);
        $this->app->terminating(static fn (): bool => $tracerProvider->shutdown());
        $this->app->register(PrismOpenTelemetryServiceProvider::class);
    }

    public function boot(): void
    {
        if (! $this->app->environment('local')) {
            return;
        }

        Event::subscribe(PrismTelemetrySubscriber::class);
        $this->app->make(ToolRegistry::class)->registerProvider(
            fn ($session): array => $this->app->make(CapabilityManager::class)->tools($session),
        );
        $this->app->make(ToolRegistry::class)->registerProvider(
            fn ($session): array => $this->app->make(WorkspaceToolset::class)->forSession($session),
        );

        $this->app->make(NodeKindRegistry::class)->register(NodeKind::fromArray([
            'name' => '@prism-lab/harness_agent',
            'aliases' => ['lab_harness_agent'],
            'category' => 'ai',
            'label' => 'Prism Harness Agent',
            'description' => 'Runs one step through a durable Prism Harness session.',
            'configSchema' => [
                ['type' => 'text', 'key' => 'harness_scope', 'label' => 'Harness scope', 'required' => true],
                ['type' => 'textarea', 'key' => 'prompt', 'label' => 'Prompt', 'required' => true],
                ['type' => 'text', 'key' => 'mode', 'label' => 'Harness mode'],
                ['type' => 'text', 'key' => 'provider', 'label' => 'Provider'],
                ['type' => 'text', 'key' => 'model', 'label' => 'Model'],
            ],
        ]));
        $this->app->make(ExecutorRegistry::class)->bind(
            '@prism-lab/harness_agent',
            new HarnessAgentExecutor(fn (string $scope) => $this->app->make(LabSession::class)->resolveScope($scope)),
        );
        $this->app->make(NodeKindRegistry::class)->register(NodeKind::fromArray([
            'name' => '@prism-lab/benchmark_lane',
            'category' => 'agentic',
            'label' => 'Prism Benchmark Lane',
            'description' => 'Executes one frozen parity benchmark lane and records bounded proof.',
            'configSchema' => [
                ['type' => 'text', 'key' => 'lane_id', 'label' => 'Benchmark lane', 'required' => true],
            ],
            'sideEffects' => 'unsafe-to-replay',
        ]));
        $this->app->make(ExecutorRegistry::class)->bind(
            '@prism-lab/benchmark_lane',
            $this->app->make(BenchmarkLaneExecutor::class),
        );
    }
}
