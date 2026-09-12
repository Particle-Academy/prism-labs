<?php

declare(strict_types=1);

namespace App\Providers;

use App\Benchmarks\BenchmarkLaneExecutor;
use App\Benchmarks\WorkspaceToolset;
use App\Context\TableContextRecall;
use App\Context\TableEvictionSink;
use App\Docs\DocsLibrary;
use App\Docs\DocsToolset;
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
use Prism\Harness\Contracts\ContextRecall;
use Prism\Harness\Contracts\EvictionSink;
use Prism\Harness\Flow\HarnessAgentExecutor;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Harness\Voice\VoiceExchange;
use Prism\HumanPlus\Contracts\RelayTransport;
use Prism\HumanPlus\HumanPlusManager;
use Prism\HumanPlus\Security\ResultGuard;
use Prism\HumanPlus\Security\TrustPolicy;
use Prism\HumanPlus\Stores\LaravelAttachmentStore;
use Prism\HumanPlus\Transport\SsePostRelayTransport;
use Prism\OpenTelemetry\PrismOpenTelemetryServiceProvider;
use Prism\Prism\Enums\Provider;

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

        $this->bindDocs();
        $this->bindContextRecovery();

        // Built from config rather than autowired, so the Lab's voice models
        // are a setting rather than two constructor defaults in a package.
        $this->app->singleton(VoiceExchange::class, function ($app): VoiceExchange {
            $voice = (array) $app['config']->get('team.voice', []);
            $provider = Provider::from((string) ($voice['provider'] ?? 'openai'));

            return new VoiceExchange(
                transcribeModel: (string) ($voice['transcribe_model'] ?? 'whisper-1'),
                speakModel: (string) ($voice['speak_model'] ?? 'tts-1'),
                voice: (string) ($voice['voice'] ?? 'alloy'),
                transcribeProvider: $provider,
                speakProvider: $provider,
            );
        });
    }

    /**
     * Where an evicted turn goes, and how the agent gets it back.
     *
     * The window is bounded in `config/prism-harness.php`, and a bounded window
     * with nowhere to put what leaves it is the configuration the harness's own
     * config calls the worst available: cheap window, agent blind to its own
     * work. So the two halves are bound together here — turning one on without
     * the other is the mistake worth making impossible in one file.
     *
     * These classes already existed and were only ever bound INSIDE probes,
     * which is the whole shape of the miss: the Lab proved evict → store →
     * recall → answer works and then ran its own Overseer without it.
     *
     * Deliberately unglamorous — a table and a `LIKE`, not embeddings. That is
     * what makes the probe built on it mean something: if a compacted agent can
     * answer from an evicted turn on nothing but a substring match, the
     * mechanism is sound. Starting with semantic search would have confounded
     * "does the plumbing work" with "does retrieval work".
     */
    private function bindContextRecovery(): void
    {
        $this->app->singleton(EvictionSink::class, fn (): EvictionSink => new TableEvictionSink);
        $this->app->singleton(ContextRecall::class, fn (): ContextRecall => new TableContextRecall);
    }

    private function bindDocs(): void
    {
        $this->app->singleton(DocsLibrary::class, fn ($app): DocsLibrary => new DocsLibrary(
            array_filter(array_map(
                fn (mixed $path): string => (string) $path,
                (array) $app['config']->get('docs.shelves', []),
            )),
        ));
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

        // Documentation, offered to EVERY session rather than only to a lane.
        //
        // The Overseer designs experiments against the Prism ecosystem, and
        // without this it did so from whatever it happened to remember — which
        // produces a benchmark for the API the model imagined rather than the
        // one that shipped. That is the exact failure this Lab keeps finding in
        // other people's agents.
        $this->app->make(ToolRegistry::class)->registerProvider(
            fn ($session): array => $this->app->make(DocsToolset::class)->forSession($session),
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
