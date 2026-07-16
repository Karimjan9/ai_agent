<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        // Older MySQL installations changed `market_state_snapshots.time` on
        // every UPDATE. Only a small set of rows is affected, so repair it in
        // PHP by primary key instead of running a large self-join over the
        // complete historical table.
        $corrupted = DB::table('market_state_snapshots as snapshot')
            ->join('candles as candle', 'candle.id', '=', 'snapshot.candle_id')
            ->whereColumn('snapshot.time', '<>', 'candle.time')
            ->select([
                'snapshot.id',
                'snapshot.symbol',
                'snapshot.timeframe',
                'candle.time as candle_time',
            ])
            ->orderBy('snapshot.id')
            ->get();

        if ($corrupted->isEmpty()) {
            return;
        }

        // First move every bad record into a unique reserved historical range.
        // This avoids collisions with another bad record's target time.
        foreach ($corrupted as $snapshot) {
            DB::table('market_state_snapshots')
                ->where('id', $snapshot->id)
                ->update(['time' => now('UTC')->setDate(1000, 1, 1)->addSeconds($snapshot->id)]);
        }

        foreach ($corrupted->groupBy(fn (object $snapshot): string => implode('|', [
            $snapshot->symbol,
            $snapshot->timeframe,
            $snapshot->candle_time,
        ])) as $snapshots) {
            $first = $snapshots->first();
            $canonical = DB::table('market_state_snapshots')
                ->where('symbol', $first->symbol)
                ->where('timeframe', $first->timeframe)
                ->where('time', $first->candle_time)
                ->value('id');

            if ($canonical) {
                DB::table('market_state_snapshots')
                    ->whereIn('id', $snapshots->pluck('id'))
                    ->delete();

                continue;
            }

            $survivor = $first->id;
            DB::table('market_state_snapshots')
                ->whereIn('id', $snapshots->pluck('id')->filter(fn (int $id): bool => $id !== $survivor))
                ->delete();
            DB::table('market_state_snapshots')
                ->where('id', $survivor)
                ->update(['time' => $first->candle_time]);
        }
    }

    public function down(): void
    {
        // Snapshot times are restored from immutable candle times; no rollback
        // is appropriate for corrupted historical values.
    }
};
