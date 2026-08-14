<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A failed first attempt may have created some of these tables before
        // MySQL rejected a long foreign-key identifier. They belong only to
        // this not-yet-recorded migration, so retrying this exact migration
        // may safely recreate the empty partial objects.
        Schema::dropIfExists('lab_council_ablation_runs');
        Schema::dropIfExists('lab_evolution_credit_events');
        Schema::dropIfExists('lab_parent_counterfactuals');
        Schema::dropIfExists('lab_parent_context_scores');

        Schema::create('lab_parent_context_scores', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->foreignId('parent_model_version_id')->constrained('model_versions', 'id', 'lab_parent_context_parent_fk')->cascadeOnDelete();
            $table->string('skill_key', 128)->default('');
            $table->string('context_key', 64);
            $table->string('regime', 48)->nullable();
            $table->string('session_utc_hour', 16)->nullable();
            $table->string('volume_state', 48)->nullable();
            $table->string('cost_stress', 48)->nullable();
            $table->decimal('trust_score', 8, 4)->default(0.5000);
            $table->decimal('incremental_value', 12, 6)->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->unsignedInteger('uncertainty_count')->default(0);
            $table->timestamp('last_evidence_at')->nullable();
            $table->string('status', 32)->default('probation');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(
                ['symbol', 'timeframe', 'strategy_family', 'parent_model_version_id', 'skill_key', 'context_key'],
                'lab_parent_context_score_identity',
            );
            $table->index(['symbol', 'timeframe', 'strategy_family', 'trust_score'], 'lab_parent_trust_scope_idx');
            $table->index(['parent_model_version_id', 'status'], 'lab_parent_trust_parent_idx');
        });

        Schema::create('lab_parent_counterfactuals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidate_agent_id')->constrained('lab_agents', 'id', 'lab_parent_cf_agent_fk')->cascadeOnDelete();
            $table->foreignId('candidate_model_version_id')->constrained('model_versions', 'id', 'lab_parent_cf_candidate_fk')->cascadeOnDelete();
            $table->foreignId('parent_model_version_id')->nullable()->constrained('model_versions', 'id', 'lab_parent_cf_parent_fk')->nullOnDelete();
            $table->foreignId('autonomous_model_version_id')->nullable()->constrained('model_versions', 'id', 'lab_parent_cf_auto_fk')->nullOnDelete();
            $table->foreignId('ablation_model_version_id')->nullable()->constrained('model_versions', 'id', 'lab_parent_cf_ablation_fk')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('context_key', 64);
            $table->string('snapshot_hash', 64)->nullable();
            $table->string('execution_hash', 64)->nullable();
            $table->string('status', 40)->default('awaiting_branches');
            $table->decimal('autonomous_score', 12, 6)->nullable();
            $table->decimal('mentored_score', 12, 6)->nullable();
            $table->decimal('ablated_score', 12, 6)->nullable();
            $table->decimal('parent_incremental_value', 12, 6)->nullable();
            $table->json('evidence_run_ids')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();
            $table->unique(['candidate_agent_id', 'context_key'], 'lab_parent_counterfactual_identity');
            $table->index(['symbol', 'timeframe', 'strategy_family', 'status'], 'lab_parent_counterfactual_scope_idx');
            $table->index(['parent_model_version_id', 'status'], 'lab_parent_counterfactual_parent_idx');
        });

        Schema::create('lab_evolution_credit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_agent_id')->constrained('lab_agents', 'id', 'lab_evolution_credit_agent_fk')->cascadeOnDelete();
            $table->foreignId('model_version_id')->constrained('model_versions', 'id', 'lab_evolution_credit_model_fk')->cascadeOnDelete();
            $table->foreignId('parent_model_version_id')->nullable()->constrained('model_versions', 'id', 'lab_evolution_credit_parent_fk')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('event_type', 24);
            $table->string('context_key', 64);
            $table->decimal('amount', 12, 6)->default(0);
            $table->string('status', 32)->default('observed');
            $table->string('evidence_fingerprint', 64)->unique('lab_evolution_credit_fingerprint_uq');
            $table->json('payload')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'event_type', 'status'], 'lab_evolution_credit_scope_idx');
            $table->index(['lab_agent_id', 'event_type'], 'lab_evolution_credit_agent_idx');
        });

        Schema::create('lab_council_ablation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('council_key', 128);
            $table->string('member_role', 64);
            $table->foreignId('excluded_member_model_version_id')->nullable()->constrained('model_versions', 'id', 'lab_council_ablation_excluded_fk')->nullOnDelete();
            $table->foreignId('full_council_model_version_id')->nullable()->constrained('model_versions', 'id', 'lab_council_ablation_full_fk')->nullOnDelete();
            $table->string('context_key', 64);
            $table->string('snapshot_hash', 64)->nullable();
            $table->string('execution_hash', 64)->nullable();
            $table->string('status', 32)->default('planned');
            $table->decimal('incremental_delta', 12, 6)->nullable();
            $table->string('evidence_run_id', 128)->nullable();
            $table->json('metrics')->nullable();
            $table->json('payload')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['council_key', 'member_role', 'excluded_member_model_version_id', 'context_key'],
                'lab_council_ablation_identity',
            );
            $table->index(['symbol', 'timeframe', 'council_key', 'status'], 'lab_council_ablation_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_council_ablation_runs');
        Schema::dropIfExists('lab_evolution_credit_events');
        Schema::dropIfExists('lab_parent_counterfactuals');
        Schema::dropIfExists('lab_parent_context_scores');
    }
};
