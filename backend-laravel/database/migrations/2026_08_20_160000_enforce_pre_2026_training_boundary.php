<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cutoff = '2026-01-01 00:00:00';

        // The scope is deliberately limited to the isolated training table;
        // canonical candles and frozen paper snapshots are never touched.
        DB::table('market_training_candles')
            ->whereIn('timeframe', ['H1', 'M15'])
            ->where('time', '>=', $cutoff)
            ->delete();

        $archives = DB::table('market_training_archives')
            ->whereIn('timeframe', ['H1', 'M15'])
            ->get(['id', 'target_to', 'backfill_cursor_at']);

        foreach ($archives as $archive) {
            $targetTo = $archive->target_to !== null && (string) $archive->target_to < $cutoff
                ? $archive->target_to
                : $cutoff;
            $cursor = $archive->backfill_cursor_at !== null && (string) $archive->backfill_cursor_at < $cutoff
                ? $archive->backfill_cursor_at
                : $cutoff;
            // Coverage is refreshed by the runtime service on the next tick;
            // this migration only closes the target and cursor boundary.
            DB::table('market_training_archives')->where('id', $archive->id)->update([
                'target_to' => $targetTo,
                'backfill_cursor_at' => $cursor,
                'status' => 'complete',
                'last_error' => null,
            ]);
        }
    }

    public function down(): void
    {
        // Deleted paper-year training rows are intentionally not reconstructed.
    }
};
