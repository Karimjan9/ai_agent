<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dual_track_exchange_packets', function (Blueprint $table): void {
            $table->id();
            $table->string('packet_key', 180)->unique();
            $table->foreignId('dual_track_run_id')->nullable()->constrained('dual_track_runs')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('cell_key', 160);
            $table->string('source_lane', 24);
            $table->string('target_lane', 24);
            $table->string('packet_type', 48);
            $table->string('protocol_version', 96);
            $table->json('payload')->nullable();
            $table->string('integrity_hash', 160);
            $table->string('status', 24)->default('observed');
            $table->string('outcome_status', 24)->nullable();
            $table->json('evidence')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();
            $table->index(['cell_key', 'source_lane', 'target_lane'], 'dual_track_exchange_cell_lane_idx');
        });

        Schema::create('dual_track_lane_credits', function (Blueprint $table): void {
            $table->id();
            $table->string('credit_key', 180)->unique();
            $table->foreignId('dual_track_run_id')->nullable()->constrained('dual_track_runs')->nullOnDelete();
            $table->foreignId('dual_track_outcome_id')->nullable()->constrained('dual_track_outcomes')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('cell_key', 160);
            $table->string('lane', 24);
            $table->string('agent_key', 160)->nullable();
            $table->string('credit_type', 48);
            $table->decimal('reward', 14, 6)->default(0);
            $table->decimal('counterfactual_delta', 14, 6)->nullable();
            $table->json('components')->nullable();
            $table->json('evidence')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();
            $table->index(['cell_key', 'lane', 'credit_type'], 'dual_track_credit_cell_lane_idx');
        });

        Schema::create('dual_track_diversity_metrics', function (Blueprint $table): void {
            $table->id();
            $table->string('metric_key', 180)->unique();
            $table->foreignId('dual_track_run_id')->nullable()->constrained('dual_track_runs')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('cell_key', 160);
            $table->decimal('behavioral_distance', 10, 6)->nullable();
            $table->decimal('confidence_distance', 10, 6)->nullable();
            $table->decimal('decision_agreement_rate', 10, 6)->nullable();
            $table->decimal('useful_dissent_rate', 10, 6)->nullable();
            $table->decimal('memory_overlap_rate', 10, 6)->nullable();
            $table->decimal('council_redundancy_rate', 10, 6)->nullable();
            $table->unsignedInteger('sample_count')->default(1);
            $table->string('status', 32)->default('observed');
            $table->json('evidence')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();
            $table->index(['cell_key', 'status'], 'dual_track_diversity_cell_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dual_track_diversity_metrics');
        Schema::dropIfExists('dual_track_lane_credits');
        Schema::dropIfExists('dual_track_exchange_packets');
    }
};
