<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Benchmarks\CompactionReservationProbe;
use App\Jobs\RunCompactionReservationProbe;
use App\Lab\PaidRunContext;
use Composer\InstalledVersions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** An independent writer probes the real admission transaction, never production data. */
final class NativeAdmissionContention implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(private readonly string $directory, private readonly array $work) {}

    public function handle(): void
    {
        $report = self::measure($this->directory, $this->work);
        $file = fopen($this->directory.'/contention-result.json', 'x');
        if ($file === false) {
            throw new RuntimeException('Native contention result already exists.');
        }
        fwrite($file, json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL);
        fclose($file);
    }

    public static function measure(string $directory, array $work): array
    {
        NativeAuthorityFixture::directory($directory);
        if (config('database.connections.sqlite.transaction_mode') !== 'DEFERRED') {
            throw new RuntimeException('Measure the default deferred admission transaction.');
        }
        // WAL lets the independent writer commit while the old reader holds its
        // snapshot. This is an adversarial probe, not fixture-only IMMEDIATE.
        $mode = DB::selectOne('PRAGMA journal_mode=WAL');
        if (strtolower((string) $mode->journal_mode) !== 'wal') {
            throw new RuntimeException('The private contention probe requires WAL.');
        }
        DB::statement('CREATE TABLE native_claim_contention_probe (version INTEGER NOT NULL)');
        DB::statement('INSERT INTO native_claim_contention_probe (version) VALUES (0)');
        $peer = new PDO('sqlite:'.$directory.'/lab.sqlite');
        $peer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $peer->exec('PRAGMA busy_timeout=0');
        $writer = false;
        $firstReadProtected = null;
        $commits = 0;
        $refusals = 0;
        $queries = [];
        DB::beforeExecuting(function (string $sql, array $bindings, $connection) use (&$queries): void {
            if (self::insideClaim()) {
                if (count($queries) >= 64) {
                    throw new RuntimeException('Bound the native contention trace.');
                }
                $queries[] = ['sql' => $sql, 'transaction_depth' => $connection->transactionLevel()];
            }
        });
        DB::listen(function ($query) use ($peer, &$writer, &$firstReadProtected, &$commits, &$refusals): void {
            if (! self::insideClaim()) {
                return;
            }
            $sql = strtolower(ltrim($query->sql));
            if (str_starts_with($sql, 'update')) {
                $writer = true;
            }
            if (! str_starts_with($sql, 'select') || ($writer && $firstReadProtected !== null)) {
                return;
            }
            $firstReadProtected ??= $writer;
            try {
                $peer->exec('UPDATE native_claim_contention_probe SET version = version + 1');
                $commits++;
            } catch (PDOException $error) {
                if (! in_array((int) ($error->errorInfo[1] ?? 0), [5, 6, 517], true)) {
                    throw $error;
                }
                $refusals++;
            }
        });
        $before = NativeAuthorityFixture::sdkCalls();
        $failure = null;
        try {
            (new RunCompactionReservationProbe(approvedRunId: $work['intent'], paidWorkId: $work['work']))
                ->handle(app(CompactionReservationProbe::class));
        } catch (Throwable $error) {
            $failure = $error;
        }
        $chain = [];
        $failedSql = null;
        for ($error = $failure; $error !== null && count($chain) < 8; $error = $error->getPrevious()) {
            $chain[] = $error::class;
            if ($error instanceof QueryException) {
                $failedSql ??= $error->getSql();
            }
        }

        return ['outcome' => $failure === null ? 'claimed' : 'error', 'exception_class' => $failure === null ? null : $failure::class,
            'exception_chain' => $chain, 'failing_sql' => $failedSql, 'queries' => $queries,
            'writer_before_first_read' => $firstReadProtected, 'intrusion_commits' => $commits, 'intrusion_refusals' => $refusals,
            'transaction_mode' => 'DEFERRED', 'journal_mode' => 'WAL', 'framework' => InstalledVersions::getPrettyVersion('laravel/framework'),
            'calls' => NativeAuthorityFixture::sdkCalls() - $before];
    }

    private static function insideClaim(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 48) as $frame) {
            if (($frame['class'] ?? null) === PaidRunContext::class && ($frame['function'] ?? null) === 'requireActions') {
                return true;
            }
        }

        return false;
    }
}
