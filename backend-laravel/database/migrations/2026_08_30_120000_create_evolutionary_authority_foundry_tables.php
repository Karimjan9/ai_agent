<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * These ledgers deliberately sit beside the existing learning projections.
     * They do not rewrite historical research rows; authority is granted only
     * by new, hash-bound evidence assembled under the current protocol.
     */
    public function up(): void
    {
        Schema::create('evolutionary_authority_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->string('authority_key', 160)->unique();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('strategy_family', 96)->nullable();
            $table->string('authority_stage', 48); $table->string('status', 48);
            $table->string('data_hash', 128)->nullable(); $table->string('execution_hash', 128)->nullable();
            $table->json('evidence'); $table->dateTime('evaluated_at', 6); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'authority_stage', 'status'], 'authority_scope_stage_idx');
        });

        Schema::create('skill_incubation_trials', function (Blueprint $table): void {
            $table->id(); $table->string('trial_key', 160)->unique();
            $table->foreignId('mentor_model_version_id')->constrained('model_versions')->cascadeOnDelete();
            $table->foreignId('child_model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('strategy_family', 96);
            $table->string('arm', 48); $table->string('fold_stage', 24); $table->string('status', 48);
            $table->string('data_hash', 128)->nullable(); $table->string('execution_hash', 128)->nullable();
            $table->json('evidence'); $table->dateTime('settled_at', 6)->nullable(); $table->timestamps();
            $table->index(['mentor_model_version_id', 'arm', 'status'], 'incubation_mentor_arm_idx');
        });

        Schema::create('descendant_value_trials', function (Blueprint $table): void {
            $table->id(); $table->string('trial_key', 160)->unique();
            $table->foreignId('mentor_model_version_id')->constrained('model_versions')->cascadeOnDelete();
            $table->foreignId('child_model_version_id')->constrained('model_versions')->cascadeOnDelete();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('strategy_family', 96);
            $table->string('window_key', 128); $table->string('status', 48); $table->json('evidence');
            $table->dateTime('settled_at', 6)->nullable(); $table->timestamps();
            $table->unique(['mentor_model_version_id', 'child_model_version_id', 'window_key'], 'descendant_trial_window_unique');
        });

        Schema::create('settlement_watermarks', function (Blueprint $table): void {
            $table->id(); $table->string('watermark_key', 160)->unique();
            $table->foreignId('agent_learning_episode_id')->nullable()->constrained('agent_learning_episodes')->nullOnDelete();
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('disposition', 48); $table->boolean('terminal')->default(false);
            $table->string('data_hash', 128)->nullable(); $table->string('execution_hash', 128)->nullable(); $table->json('evidence');
            $table->dateTime('observed_at', 6); $table->timestamps();
            $table->index(['lab_generation_id', 'terminal', 'disposition'], 'settlement_watermark_generation_idx');
        });

        Schema::create('legacy_control_debts', function (Blueprint $table): void {
            $table->id(); $table->string('debt_key', 160)->unique();
            $table->foreignId('lab_learning_lane_pair_id')->nullable()->constrained('lab_learning_lane_pairs')->nullOnDelete();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('classification', 64); $table->boolean('authority_allowed')->default(false);
            $table->boolean('excluded_from_current_kpi')->default(true); $table->json('evidence'); $table->dateTime('classified_at', 6); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'classification'], 'legacy_control_debt_scope_idx');
        });

        Schema::create('council_counterfactual_cases', function (Blueprint $table): void {
            $table->id(); $table->string('case_key', 160)->unique(); $table->string('cluster_key', 160)->index();
            $table->foreignId('lab_council_disagreement_id')->nullable()->constrained('lab_council_disagreements')->nullOnDelete();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('status', 48); $table->json('evidence');
            $table->dateTime('planned_at', 6); $table->timestamps();
        });

        Schema::create('paper_authority_admissions', function (Blueprint $table): void {
            $table->id(); $table->string('admission_key', 160)->unique();
            $table->foreignId('model_version_id')->constrained('model_versions')->cascadeOnDelete();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('status', 48);
            $table->string('passport_hash', 128)->nullable(); $table->string('execution_hash', 128)->nullable(); $table->json('evidence');
            $table->dateTime('frozen_at', 6)->nullable(); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'status'], 'paper_authority_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_authority_admissions'); Schema::dropIfExists('council_counterfactual_cases');
        Schema::dropIfExists('legacy_control_debts'); Schema::dropIfExists('settlement_watermarks');
        Schema::dropIfExists('descendant_value_trials'); Schema::dropIfExists('skill_incubation_trials');
        Schema::dropIfExists('evolutionary_authority_ledgers');
    }
};
