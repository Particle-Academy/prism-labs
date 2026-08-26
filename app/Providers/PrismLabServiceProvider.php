<?php

declare(strict_types=1);

namespace App\Providers;

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
use Prism\OpenTelemetry\PrismOpenTelemetryServiceProvider;

final class PrismLabServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->environment('local')) {
            return;
        }

        $this->app['config']->set('prism.telemetry.enabled', true);
        $this->app['config']->set('prism.telemetry.capture_content', true);
        $this->app['config']->set('prism-opentelemetry.enabled', true);

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
}
