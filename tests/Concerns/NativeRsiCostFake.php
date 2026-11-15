<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;
use RuntimeException;

/** Synthetic provider entry AFTER the actual production reservation committed. */
final class NativeRsiCostFake extends PrismFake
{
    public function __construct(private string $directory, array $responses)
    {
        parent::__construct($responses);
    }

    public function text(Request $request): Response
    {
        NativeAuthorityFixture::directory($this->directory);
        $pending = DB::table('lab_call_reservations')->whereNull('settled_at')->get();
        if (DB::transactionLevel() !== 0 || $pending->count() !== 1 || (int) $pending->first()->maximum_microusd !== 600000) {
            throw new RuntimeException('Production reservation was not committed before provider entry.');
        }
        $response = parent::text($request);
        $file = fopen($this->directory.'/rsi-provider-entered.json', 'x');
        if ($file === false) {
            throw new RuntimeException('A second provider bypassed the shared account.');
        }
        fwrite($file, json_encode(['pid' => getmypid(), 'calls' => count($this->recorded), 'call_id' => $pending->first()->call_id], JSON_THROW_ON_ERROR));
        fclose($file);
        $deadline = microtime(true) + 20;
        while (! is_file($this->directory.'/rsi-release')) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Owned provider barrier timed out.');
            }
            usleep(10000);
        }

        return $response;
    }
}
