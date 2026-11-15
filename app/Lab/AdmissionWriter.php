<?php

declare(strict_types=1);

namespace App\Lab;

use Illuminate\Support\Facades\DB;
use LogicException;

/** Reserve SQLite's writer before a transaction can acquire a stale read snapshot. */
final class AdmissionWriter
{
    public static function forIntent(?string $id): void
    {
        if (self::required()) {
            // Keep the lookup inside the UPDATE. A separate SELECT, even
            // before a nested transaction, would recreate the upgrade race.
            DB::table('lab_authority_sessions')->whereIn('id',
                DB::table('lab_run_intents')->select('authority_session_id')->where('id', $id))
                ->update(['allocated_microusd' => DB::raw('allocated_microusd')]);
        }
    }

    public static function forWork(?string $id): void
    {
        if (self::required()) {
            DB::table('lab_authority_sessions')->whereIn('id',
                DB::table('lab_run_intents')->join('lab_paid_works', 'lab_paid_works.intent_id', '=', 'lab_run_intents.id')
                    ->where('lab_paid_works.id', $id)->select('lab_run_intents.authority_session_id'))
                ->update(['allocated_microusd' => DB::raw('allocated_microusd')]);
        }
    }

    public static function forSpecification(string $id): void
    {
        if (self::required()) {
            DB::table('benchmark_specs')->where('id', $id)->update(['revision' => DB::raw('revision')]);
        }
    }

    private static function required(): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('An admission writer reservation requires an active transaction.');
        }

        // Other stores retain the existing session -> intent row locks.
        // These no-op writes grant no authority and change no ledger values.
        return DB::connection()->getDriverName() === 'sqlite';
    }
}
