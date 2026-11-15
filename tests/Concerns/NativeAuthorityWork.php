<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Benchmarks\CompactionReservationProbe;
use App\Jobs\RunCompactionReservationProbe;
use App\Lab\PaidRunNotAuthorised;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

/** Duplicate native deliveries; no synthetic worker event or caller identity. */
final class NativeAuthorityWork implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(private readonly string $directory, private readonly string $intentId,
        private readonly string $workId, private readonly int $slot) {}

    public function handle(CompactionReservationProbe $probe): void
    {
        NativeAuthorityFixture::directory($this->directory);
        if (! in_array($this->slot, [0, 1], true)) {
            throw new RuntimeException('Unknown native work slot.');
        }
        NativeAuthorityFixture::barrier($this->directory, 'work');
        $before = NativeAuthorityFixture::sdkCalls();
        $unexpected = null;
        $refusal = null;
        try {
            (new RunCompactionReservationProbe(approvedRunId: $this->intentId, paidWorkId: $this->workId))->handle($probe);
            $outcome = 'claimed';
        } catch (PaidRunNotAuthorised) {
            $outcome = 'refused';
            $refusal = PaidRunNotAuthorised::class;
        } catch (Throwable $failure) {
            $outcome = 'error';
            $unexpected = $failure;
        }
        $result = ['outcome' => $outcome, 'pid' => getmypid(), 'calls' => NativeAuthorityFixture::sdkCalls() - $before,
            'guest' => Auth::guard('web')->guest(), 'exception_class' => $unexpected === null ? null : $unexpected::class,
            'refusal_class' => $refusal,
            'external_exporter_closed' => app('native-authority-exporter-closed') === true];
        $file = fopen($this->directory.'/work-result-'.$this->slot.'.json', 'x');
        if ($file === false) {
            throw new RuntimeException('Native work result already exists.');
        }
        fwrite($file, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);
        fclose($file);
        if ($unexpected !== null) {
            throw $unexpected;
        }
    }
}
