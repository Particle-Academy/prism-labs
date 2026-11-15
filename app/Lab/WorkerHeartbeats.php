<?php

declare(strict_types=1);

namespace App\Lab;

use FilesystemIterator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class WorkerHeartbeats
{
    private ?string $worker = null;

    private ?array $context = null;

    private ?string $executingQueue = null;

    private readonly string $fingerprint;

    public function __construct(private readonly Filesystem $files)
    {
        // Captured once per loaded worker; web requests compare their own build.
        $this->fingerprint = hash('sha256', json_encode([
            base_path(), hash_file('sha256', base_path('composer.lock')),
            hash_file('sha256', __FILE__), config('lab-database.names'),
            config('queue.default'), config('lab-preflight.workers.queues'),
            config('queue.connections.'.config('queue.default').'.retry_after'),
        ], JSON_THROW_ON_ERROR));
    }

    public function looping(Looping $event): void
    {
        $this->executingQueue = null;
        $queues = is_string($event->queue) ? explode(',', $event->queue) : [];
        if (! is_string($event->connectionName) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $event->connectionName)
            || $queues === [] || count($queues) > 16
            || count(array_filter($queues, static fn ($queue): bool => is_string($queue) && preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D', $queue) === 1)) !== count($queues)) {
            return;
        }
        $timeout = $event->workerOptions->timeout ?? null;
        // Symfony supplies actual shell options as strings; Artisan::call may
        // supply integers. Accept only canonical bounded decimal syntax.
        if (is_string($timeout) && preg_match('/^[1-9][0-9]{0,3}$/D', $timeout)) {
            $timeout = (int) $timeout;
        }
        $this->context = ['connection' => $event->connectionName, 'queues' => $queues,
            'timeout' => is_int($timeout) ? $timeout : null];
        try {
            $this->worker ??= bin2hex(random_bytes(16));
            $this->write('idle');
        } catch (Throwable) {
            $this->diagnostic();
        }
    }

    public function processing(JobProcessing $event): void
    {
        if ($this->context !== null && $this->context['connection'] === $event->connectionName) {
            $this->executingQueue = null;
            try {
                $queue = $event->job->getQueue();
                $this->executingQueue = is_string($queue) && in_array($queue, $this->context['queues'], true) ? $queue : null;
            } catch (Throwable) {
                // Observing a queue must never replace native job behavior.
                $this->diagnostic();
            }
            $this->write('busy');
        }
    }

    public function processed(JobProcessed $event): void
    {
        if ($this->context !== null && $this->context['connection'] === $event->connectionName) {
            $this->executingQueue = null;
            $this->write('idle');
        }
    }

    public function stopping(WorkerStopping $event): void
    {
        $this->executingQueue = null;
        if ($this->worker === null) {
            return;
        }
        try {
            $file = $this->directory().'/'.$this->worker.'.json';
            if (is_file($file) && ! is_link($file)) {
                unlink($file);
            }
        } catch (Throwable) {
            $this->diagnostic();
        }
        $this->worker = null;
        $this->context = null;
    }

    public function check(): array
    {
        return $this->inspect(config('lab-preflight.workers.queues'), false);
    }

    /** Only the process handling a native JobProcessing event can qualify. */
    public function checkForExecution(): array
    {
        if ($this->executingQueue === null || $this->worker === null) {
            return $this->unavailable();
        }

        return $this->inspect([$this->executingQueue], true);
    }

    private function inspect(mixed $required, bool $currentExecution): array
    {
        $connection = config('queue.default');
        $key = config('app.key');
        if (! is_array($required) || $required === [] || count($required) > 16
            || ! is_string($connection) || ! is_string($key) || $key === '') {
            return $this->unavailable();
        }
        $records = [];
        try {
            $directory = $this->directory();
            if (is_link($directory)) {
                return $this->unavailable();
            }
            if (is_dir($directory)) {
                $count = 0;
                foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
                    if (++$count > 256) {
                        return $this->unavailable();
                    }
                    if ($currentExecution && $file->getFilename() !== ($this->worker.'.json')) {
                        continue;
                    }
                    if (! $file->isFile() || $file->isLink() || $file->getSize() > 4096
                        || ! preg_match('/^[a-f0-9]{32}\.json$/D', $file->getFilename())) {
                        continue;
                    }
                    $contents = file_get_contents($file->getPathname(), false, null, 0, 4097);
                    if (! is_string($contents) || strlen($contents) > 4096) {
                        continue;
                    }
                    $record = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
                    if (! is_array($record) || ! is_string($record['signature'] ?? null)) {
                        continue;
                    }
                    $signature = $record['signature'];
                    unset($record['signature']);
                    if (! hash_equals(hash_hmac('sha256', json_encode($record, JSON_THROW_ON_ERROR), $key), $signature)
                        || ($record['fingerprint'] ?? null) !== $this->fingerprint
                        || ($record['connection'] ?? null) !== $connection
                        || ! is_array($record['queues'] ?? null) || ! is_int($record['seen_at'] ?? null)
                        || $record['seen_at'] > now()->timestamp || ! is_int($record['lease_until'] ?? null)
                        || $record['lease_until'] < now()->timestamp) {
                        continue;
                    }
                    $records[] = $record;
                }
            }
        } catch (Throwable) {
            return $this->unavailable();
        }

        $queues = [];
        foreach ($required as $queue) {
            if (! is_string($queue) || ! preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D', $queue)) {
                return $this->unavailable();
            }
            $state = 'missing';
            foreach ($records as $record) {
                if (! in_array($queue, $record['queues'], true)) {
                    continue;
                }
                $timeout = $record['timeout'] ?? null;
                $retry = config('queue.connections.'.$connection.'.retry_after');
                if (! is_int($timeout) || $timeout <= 0 || $timeout > 3600
                    || ! is_int($retry) || $retry <= $timeout) {
                    $state = 'misconfigured';

                    continue;
                }
                if (! $currentExecution && ($record['state'] ?? null) === 'idle'
                    && now()->timestamp - $record['seen_at'] <= $this->freshness()) {
                    $state = 'ready';
                    break;
                }
                if (($record['state'] ?? null) === 'busy') {
                    if ($currentExecution) {
                        $state = 'executing';
                        break;
                    }
                    $state = 'busy';
                }
            }
            $queues[] = ['queue' => $queue, 'status' => $state];
        }
        $ready = count(array_filter($queues, static fn (array $queue): bool => in_array($queue['status'], ['ready', 'executing'], true))) === count($required);

        return ['ready' => $ready, 'status' => $ready ? 'ready' : 'blocked',
            'message' => $ready ? ($currentExecution ? 'This native worker is executing the claimed job.' : 'Required queue workers have recent available heartbeats.')
                : 'Required queue workers are unavailable, busy or misconfigured.',
            'queues' => $queues];
    }

    private function write(string $state): void
    {
        if ($this->context === null || $this->worker === null) {
            return;
        }
        try {
            $key = config('app.key');
            if (! is_string($key) || $key === '') {
                $this->diagnostic();

                return;
            }
            $directory = $this->directory();
            $file = $directory.'/'.$this->worker.'.json';
            if (is_link($directory) || is_link($file)) {
                $this->diagnostic();

                return;
            }
            $this->files->ensureDirectoryExists($directory, 0700);
            $timeout = $this->context['timeout'];
            $duration = $state === 'busy' && is_int($timeout) ? max(1, min(3600, $timeout)) : $this->freshness();
            $record = [...$this->context, 'state' => $state, 'seen_at' => now()->timestamp,
                'lease_until' => now()->timestamp + $duration, 'fingerprint' => $this->fingerprint];
            $record['signature'] = hash_hmac('sha256', json_encode($record, JSON_THROW_ON_ERROR), $key);
            $this->files->replace($file, json_encode($record, JSON_THROW_ON_ERROR), 0600);
        } catch (Throwable) {
            // Observability cannot replace native job failure/transport semantics.
            $this->diagnostic();
        }
    }

    private function directory(): string
    {
        $value = config('lab-preflight.workers.directory');
        if (! is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw new RuntimeException('Worker evidence storage is invalid.');
        }
        $value = str_replace('\\', '/', $value);
        $root = str_replace('\\', '/', realpath(storage_path('framework')) ?: '');
        if ($root === '' || in_array('..', explode('/', $value), true)) {
            throw new RuntimeException('Worker evidence storage is invalid.');
        }
        $ancestor = $value;
        while (! is_dir($ancestor) && dirname($ancestor) !== $ancestor) {
            $ancestor = dirname($ancestor);
        }
        $resolved = str_replace('\\', '/', realpath($ancestor) ?: '');
        $compareRoot = PHP_OS_FAMILY === 'Windows' ? strtolower($root) : $root;
        $compareValue = PHP_OS_FAMILY === 'Windows' ? strtolower($value) : $value;
        $compareResolved = PHP_OS_FAMILY === 'Windows' ? strtolower($resolved) : $resolved;
        if (! str_starts_with($compareValue, $compareRoot.'/')
            || ($compareResolved !== $compareRoot && ! str_starts_with($compareResolved, $compareRoot.'/'))) {
            throw new RuntimeException('Worker evidence storage is invalid.');
        }

        return $value;
    }

    private function freshness(): int
    {
        return max(5, min(60, (int) config('lab-preflight.workers.fresh_seconds', 30)));
    }

    private function unavailable(): array
    {
        return ['ready' => false, 'status' => 'unknown', 'message' => 'Queue worker evidence is unavailable.', 'queues' => []];
    }

    private function diagnostic(): void
    {
        try {
            Log::warning('Lab worker heartbeat could not be recorded.');
        } catch (Throwable) {
            // A logging failure must not change the worker's native outcome.
        }
    }
}
