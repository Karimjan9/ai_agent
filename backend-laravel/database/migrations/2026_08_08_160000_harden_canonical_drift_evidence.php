<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drift is promotion-adjacent evidence.  Old rows did not identify the
     * provider or the exact candle series, so they must not be reused as a
     * confirmation of a new canonical data stream.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('market_drift_snapshots', 'provider')) {
            Schema::table('market_drift_snapshots', function (Blueprint $table): void {
                $table->string('provider', 32)->nullable();
                $table->string('data_hash', 64)->nullable();
                $table->unsignedInteger('candle_count')->nullable();
                $table->timestamp('first_candle_at')->nullable();
                $table->timestamp('last_candle_at')->nullable();
                $table->timestamp('cutoff_at')->nullable();
                $table->string('evidence_status', 32)->default('legacy_unverified');
            });
        }

        Schema::table('market_drift_snapshots', function (Blueprint $table): void {
            $table->index(['symbol', 'timeframe', 'evidence_status', 'detected_at'], 'drift_confirmation_lookup');
            $table->index(['provider', 'data_hash'], 'drift_source_hash_lookup');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('market_drift_snapshots')) {
            Schema::table('market_drift_snapshots', function (Blueprint $table): void {
                $table->dropIndex('drift_confirmation_lookup');
                $table->dropIndex('drift_source_hash_lookup');
                $table->dropColumn([
                    'provider', 'data_hash', 'candle_count', 'first_candle_at',
                    'last_candle_at', 'cutoff_at', 'evidence_status',
                ]);
            });
        }
    }
};
