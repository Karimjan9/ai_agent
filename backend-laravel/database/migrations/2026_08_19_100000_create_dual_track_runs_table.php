<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dual_track_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('run_key', 128)->unique();
            $table->string('protocol', 64);
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('task_type', 32)->default('signal');
            $table->string('cell_key', 160);
            $table->string('market_regime', 48)->nullable();
            $table->string('volatility_regime', 48)->nullable();
            $table->string('mode', 24)->default('shadow');
            $table->string('status', 24)->default('observed');
            $table->string('selected_lane', 24)->default('incumbent');
            $table->string('selected_decision', 16)->default('WAIT');
            $table->string('champion_decision', 16)->nullable();
            $table->string('council_decision', 16)->nullable();
            $table->string('disagreement_code', 64)->nullable();
            $table->string('snapshot_hash', 160)->nullable();
            $table->string('input_hash', 160);
            $table->string('output_hash', 160);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('scores')->nullable();
            $table->json('champion_output')->nullable();
            $table->json('council_output')->nullable();
            $table->json('evidence')->nullable();
            $table->json('routing')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'cell_key'], 'dual_track_cell_lookup');
            $table->index(['status', 'selected_lane'], 'dual_track_status_lane_lookup');
            $table->index(['market_regime', 'volatility_regime'], 'dual_track_regime_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dual_track_runs');
    }
};
