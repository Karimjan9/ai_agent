<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dual_track_evidence_work_items', function (Blueprint $table): void {
            $table->id();
            $table->string('work_key', 180)->unique();
            $table->foreignId('dual_track_run_id')->nullable()->constrained('dual_track_runs')->nullOnDelete();
            $table->foreignId('dual_track_outcome_id')->nullable()->constrained('dual_track_outcomes')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('cell_key', 160);
            $table->string('work_type', 40);
            $table->string('status', 24)->default('queued');
            $table->unsignedTinyInteger('priority')->default(1);
            $table->unsignedInteger('attempts')->default(0);
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('leased_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'priority', 'available_at'], 'twin_work_claim_idx');
            $table->index(['cell_key', 'work_type', 'status'], 'twin_work_cell_type_idx');
        });

        Schema::create('dual_track_statistic_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 180)->unique();
            $table->foreignId('dual_track_outcome_id')->unique()->constrained('dual_track_outcomes')->cascadeOnDelete();
            $table->string('cell_key', 160);
            $table->string('lane', 24);
            $table->timestamps();
        });

        Schema::create('dual_track_cell_statistics', function (Blueprint $table): void {
            $table->id();
            $table->string('stat_key', 180)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('cell_key', 160);
            $table->string('lane', 24);
            $table->unsignedInteger('settled_count')->default(0);
            $table->unsignedInteger('known_count')->default(0);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('action_count')->default(0);
            $table->unsignedInteger('risk_violation_count')->default(0);
            $table->decimal('reward_sum', 18, 6)->default(0);
            $table->decimal('reward_sq_sum', 22, 6)->default(0);
            $table->decimal('regret_sum', 18, 6)->default(0);
            $table->timestamp('last_observed_at')->nullable();
            $table->timestamps();
            $table->unique(['symbol', 'timeframe', 'cell_key', 'lane'], 'twin_stats_scope_uq');
            $table->index(['symbol', 'timeframe', 'cell_key'], 'twin_stats_scope_idx');
        });

        Schema::create('dual_track_drift_states', function (Blueprint $table): void {
            $table->id();
            $table->string('state_key', 180)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('cell_key', 160);
            $table->string('lane', 24);
            $table->string('state', 24)->default('healthy');
            $table->decimal('baseline_mean', 18, 8)->default(0);
            $table->decimal('cusum_positive', 18, 8)->default(0);
            $table->decimal('cusum_negative', 18, 8)->default(0);
            $table->decimal('last_value', 18, 8)->nullable();
            $table->unsignedInteger('sample_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->timestamp('last_change_at')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();
            $table->unique(['symbol', 'timeframe', 'cell_key', 'lane'], 'twin_drift_scope_uq');
            $table->index(['cell_key', 'lane', 'state'], 'twin_drift_state_idx');
        });

        Schema::create('dual_track_memory_replay_queue', function (Blueprint $table): void {
            $table->id();
            $table->string('replay_key', 180)->unique();
            $table->foreignId('dual_track_outcome_id')->nullable()->constrained('dual_track_outcomes')->nullOnDelete();
            $table->unsignedBigInteger('dual_track_memory_lesson_id')->nullable();
            $table->foreign('dual_track_memory_lesson_id', 'twin_memory_lesson_fk')->references('id')->on('dual_track_memory_lessons')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('cell_key', 160);
            $table->string('lane', 24);
            $table->decimal('priority_score', 12, 6)->default(0);
            $table->string('priority_reason', 160)->nullable();
            $table->string('status', 24)->default('queued');
            $table->unsignedInteger('replay_count')->default(0);
            $table->json('evidence')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('last_replayed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'priority_score', 'available_at'], 'twin_memory_priority_idx');
        });

        Schema::create('dual_track_gene_proofs', function (Blueprint $table): void {
            $table->id();
            $table->string('proof_key', 180)->unique();
            $table->foreignId('model_market_performance_id')->nullable()->constrained('model_market_performance')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('cell_key', 160)->nullable();
            $table->unsignedInteger('sample_count')->default(0);
            $table->decimal('bootstrap_lower_bound', 14, 6)->nullable();
            $table->decimal('deflated_sharpe_probability', 14, 6)->nullable();
            $table->decimal('pbo_probability', 14, 6)->nullable();
            $table->string('status', 24)->default('insufficient');
            $table->json('evidence')->nullable();
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'status'], 'twin_gene_proof_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dual_track_gene_proofs');
        Schema::dropIfExists('dual_track_memory_replay_queue');
        Schema::dropIfExists('dual_track_drift_states');
        Schema::dropIfExists('dual_track_cell_statistics');
        Schema::dropIfExists('dual_track_statistic_events');
        Schema::dropIfExists('dual_track_evidence_work_items');
    }
};
