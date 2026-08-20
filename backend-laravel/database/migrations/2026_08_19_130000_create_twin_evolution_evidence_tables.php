<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dual_track_inference_observations', function (Blueprint $table): void {
            $table->id();
            $table->string('observation_key', 180)->unique();
            $table->foreignId('dual_track_run_id')->nullable()->constrained('dual_track_runs')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('cell_key', 160);
            $table->string('lane', 24);
            $table->string('process_id', 160);
            $table->string('snapshot_hash', 160);
            $table->string('context_hash', 160);
            $table->string('prompt_hash', 160)->nullable();
            $table->string('output_hash', 160);
            $table->unsignedInteger('reasoning_budget')->nullable();
            $table->json('output')->nullable();
            $table->json('context')->nullable();
            $table->json('evidence')->nullable();
            $table->string('status', 32)->default('observed');
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();
            $table->unique(['dual_track_run_id', 'lane'], 'twin_inference_run_lane_uq');
            $table->index(['cell_key', 'lane', 'created_at'], 'twin_inference_cell_lane_idx');
        });

        Schema::create('dual_track_member_credits', function (Blueprint $table): void {
            $table->id();
            $table->string('credit_key', 180)->unique();
            $table->foreignId('dual_track_run_id')->nullable()->constrained('dual_track_runs')->nullOnDelete();
            $table->foreignId('dual_track_outcome_id')->nullable()->constrained('dual_track_outcomes')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('cell_key', 160);
            $table->string('member_key', 160);
            $table->string('role', 96)->nullable();
            $table->decimal('full_reward', 14, 6)->nullable();
            $table->decimal('ablated_reward', 14, 6)->nullable();
            $table->decimal('marginal_credit', 14, 6)->nullable();
            $table->string('credit_type', 48)->default('leave_one_out');
            $table->string('status', 32)->default('awaiting_outcome');
            $table->json('evidence')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();
            $table->unique(['dual_track_run_id', 'member_key'], 'twin_member_run_member_uq');
            $table->index(['cell_key', 'role', 'status'], 'twin_member_credit_cell_role_idx');
        });

        Schema::create('dual_track_genome_archives', function (Blueprint $table): void {
            $table->id();
            $table->string('archive_key', 180)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('lane', 24);
            $table->string('cell_key', 160);
            $table->string('behavior_cell', 160);
            $table->string('genome_hash', 160);
            $table->foreignId('model_version_id')->nullable()->constrained()->nullOnDelete();
            $table->json('genes')->nullable();
            $table->json('phenotype')->nullable();
            $table->decimal('fitness_score', 14, 6)->default(0);
            $table->decimal('novelty_score', 14, 6)->default(0);
            $table->unsignedInteger('evidence_count')->default(0);
            $table->string('status', 32)->default('candidate');
            $table->string('death_reason', 160)->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('resurrected_at')->nullable();
            $table->timestamps();
            $table->unique(['lane', 'cell_key', 'behavior_cell'], 'twin_archive_cell_behavior_uq');
            $table->index(['symbol', 'timeframe', 'lane', 'status'], 'twin_archive_scope_idx');
        });

        Schema::create('dual_track_gene_cemeteries', function (Blueprint $table): void {
            $table->id();
            $table->string('cemetery_key', 180)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('lane', 24);
            $table->string('cell_key', 160);
            $table->string('genome_hash', 160);
            $table->string('parent_genome_hash', 160)->nullable();
            $table->string('failure_regime', 96)->nullable();
            $table->string('reason_code', 96);
            $table->json('death_evidence')->nullable();
            $table->string('status', 32)->default('buried');
            $table->timestamp('resurrection_eligible_at')->nullable();
            $table->timestamp('resurrected_at')->nullable();
            $table->timestamps();
            $table->index(['cell_key', 'failure_regime', 'status'], 'twin_cemetery_revival_idx');
        });

        Schema::create('dual_track_organism_health_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('health_key', 180)->unique();
            $table->foreignId('dual_track_run_id')->nullable()->constrained('dual_track_runs')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('cell_key', 160);
            $table->string('lane', 24);
            $table->json('metrics')->nullable();
            $table->decimal('health_score', 10, 6)->default(0);
            $table->string('status', 32)->default('observed');
            $table->json('evidence')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();
            $table->index(['cell_key', 'lane', 'status'], 'twin_health_cell_lane_idx');
        });

        Schema::create('dual_track_reflection_lessons', function (Blueprint $table): void {
            $table->id();
            $table->string('reflection_key', 180)->unique();
            $table->foreignId('dual_track_outcome_id')->nullable()->constrained('dual_track_outcomes')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('cell_key', 160);
            $table->string('lane', 24);
            $table->string('failure_class', 96)->nullable();
            $table->text('reflection');
            $table->unsignedInteger('independent_confirmations')->default(0);
            $table->string('status', 32)->default('provisional');
            $table->json('evidence')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();
            $table->index(['cell_key', 'lane', 'status'], 'twin_reflection_cell_lane_idx');
        });

        Schema::create('dual_track_red_team_trials', function (Blueprint $table): void {
            $table->id();
            $table->string('trial_key', 180)->unique();
            $table->foreignId('dual_track_run_id')->nullable()->constrained('dual_track_runs')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('cell_key', 160);
            $table->string('target_lane', 24);
            $table->string('adversary_type', 64);
            $table->string('status', 32)->default('planned');
            $table->decimal('damage_score', 14, 6)->nullable();
            $table->json('challenge')->nullable();
            $table->json('result')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();
            $table->index(['cell_key', 'target_lane', 'status'], 'twin_red_team_cell_lane_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dual_track_red_team_trials');
        Schema::dropIfExists('dual_track_reflection_lessons');
        Schema::dropIfExists('dual_track_organism_health_snapshots');
        Schema::dropIfExists('dual_track_gene_cemeteries');
        Schema::dropIfExists('dual_track_genome_archives');
        Schema::dropIfExists('dual_track_member_credits');
        Schema::dropIfExists('dual_track_inference_observations');
    }
};
