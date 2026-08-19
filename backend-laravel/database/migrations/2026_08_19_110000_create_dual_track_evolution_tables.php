<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dual_track_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->string('outcome_key', 160)->unique();
            $table->foreignId('dual_track_run_id')->nullable()->constrained('dual_track_runs')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('task_type', 32)->default('paper_signal');
            $table->string('cell_key', 160);
            $table->string('lane', 24);
            $table->string('decision', 16)->default('WAIT');
            $table->string('outcome_status', 24)->default('pending');
            $table->string('actual_outcome', 24)->nullable();
            $table->decimal('reward', 14, 6)->nullable();
            $table->decimal('profit_percent', 14, 6)->nullable();
            $table->decimal('risk_percent', 14, 6)->nullable();
            $table->decimal('regret', 14, 6)->nullable();
            $table->decimal('confidence', 8, 6)->nullable();
            $table->boolean('correct')->nullable();
            $table->json('evidence')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'cell_key', 'lane'], 'dual_track_outcome_cell_lane_idx');
            $table->index(['outcome_status', 'actual_outcome'], 'dual_track_outcome_status_idx');
        });

        Schema::create('dual_track_cell_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('policy_key', 160)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('cell_key', 160);
            $table->string('mode', 24)->default('shadow');
            $table->string('recommended_lane', 24)->default('incumbent');
            $table->string('active_lane', 24)->default('incumbent');
            $table->string('status', 24)->default('learning');
            $table->unsignedInteger('sample_count')->default(0);
            $table->unsignedInteger('minimum_samples')->default(30);
            $table->decimal('confidence_margin', 10, 6)->default(0);
            $table->decimal('disagreement_value', 14, 6)->default(0);
            $table->json('lane_statistics')->nullable();
            $table->json('risk_bounds')->nullable();
            $table->json('policy')->nullable();
            $table->string('policy_hash', 160)->nullable();
            $table->timestamp('last_outcome_at')->nullable();
            $table->timestamp('certified_at')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();

            $table->unique(['symbol', 'timeframe', 'cell_key'], 'dual_track_cell_policy_scope_uq');
            $table->index(['status', 'active_lane'], 'dual_track_cell_policy_status_idx');
        });

        Schema::create('dual_track_evaluator_calibrations', function (Blueprint $table): void {
            $table->id();
            $table->string('calibration_key', 200)->unique();
            $table->string('evaluator', 96);
            $table->string('cell_key', 160);
            $table->unsignedInteger('sample_count')->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->unsignedInteger('false_positive_count')->default(0);
            $table->unsignedInteger('false_negative_count')->default(0);
            $table->decimal('brier_score', 10, 6)->nullable();
            $table->decimal('calibration_error', 10, 6)->nullable();
            $table->decimal('reputation_score', 10, 6)->default(0);
            $table->json('bins')->nullable();
            $table->json('evidence')->nullable();
            $table->string('status', 24)->default('uncalibrated');
            $table->timestamp('last_observed_at')->nullable();
            $table->timestamps();

            $table->unique(['evaluator', 'cell_key'], 'dual_track_evaluator_cell_uq');
            $table->index(['evaluator', 'status'], 'dual_track_evaluator_status_idx');
        });

        Schema::create('dual_track_memory_lessons', function (Blueprint $table): void {
            $table->id();
            $table->string('lesson_key', 180)->unique();
            $table->string('layer', 24)->default('raw');
            $table->string('status', 24)->default('observed');
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('cell_key', 160);
            $table->string('lane', 24)->nullable();
            $table->string('failure_signature', 96)->nullable();
            $table->text('statement');
            $table->text('lesson')->nullable();
            $table->unsignedInteger('sample_count')->default(1);
            $table->decimal('confidence', 10, 6)->default(0);
            $table->foreignId('source_run_id')->nullable()->constrained('dual_track_runs')->nullOnDelete();
            $table->foreignId('source_outcome_id')->nullable()->constrained('dual_track_outcomes')->nullOnDelete();
            $table->json('evidence')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'cell_key', 'layer', 'status'], 'dual_track_memory_scope_idx');
        });

        Schema::create('dual_track_evolution_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 180)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('cell_key', 160);
            $table->string('island_key', 96);
            $table->string('lane', 24);
            $table->string('event_type', 48);
            $table->string('capability_key', 96)->nullable();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->json('source_parent_model_version_ids')->nullable();
            $table->decimal('incremental_value', 14, 6)->nullable();
            $table->string('status', 24)->default('research');
            $table->json('evidence')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'island_key', 'status'], 'dual_track_evolution_island_idx');
            $table->index(['cell_key', 'capability_key'], 'dual_track_evolution_capability_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dual_track_evolution_events');
        Schema::dropIfExists('dual_track_memory_lessons');
        Schema::dropIfExists('dual_track_evaluator_calibrations');
        Schema::dropIfExists('dual_track_cell_policies');
        Schema::dropIfExists('dual_track_outcomes');
    }
};
