<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A knowledge card is the current, queryable projection for one lab
     * agent. Lessons are append-only evidence records; neither table is a
     * promotion shortcut or a replacement for immutable replay evidence.
     */
    public function up(): void
    {
        Schema::create('agent_knowledge_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_agent_id')->unique()->constrained('lab_agents')->cascadeOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('skill_stage', 32)->default('novice');
            $table->decimal('skill_score', 7, 2)->default(0);
            $table->json('strong_regimes')->nullable();
            $table->json('strong_state_clusters')->nullable();
            $table->json('failure_profiles')->nullable();
            $table->json('tested_mutations')->nullable();
            $table->json('blocked_mutations')->nullable();
            $table->unsignedInteger('independent_window_count')->default(0);
            $table->unsignedInteger('confirmed_lesson_count')->default(0);
            $table->string('retention_status', 32)->default('baseline_unavailable');
            $table->decimal('retention_score', 7, 2)->nullable();
            $table->string('abstention_status', 32)->default('unassessed');
            $table->decimal('abstention_precision', 7, 2)->nullable();
            $table->string('unknown_state_action', 32)->default('WAIT');
            $table->string('drift_status', 32)->default('unknown');
            $table->timestamp('drift_recheck_at')->nullable();
            $table->json('capability_vector')->nullable();
            $table->json('skill_contract')->nullable();
            $table->json('provenance')->nullable();
            $table->string('last_evidence_run_id', 64)->nullable();
            $table->timestamp('last_observed_at')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'strategy_family', 'skill_stage'], 'agent_knowledge_scope_stage_idx');
            $table->index(['drift_status', 'drift_recheck_at'], 'agent_knowledge_drift_idx');
        });

        Schema::create('agent_learning_lessons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('lesson_id')->unique();
            $table->string('lesson_hash', 128)->unique();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('lesson_type', 32);
            $table->string('status', 32)->default('provisional');
            $table->string('failure_class', 64)->nullable();
            $table->string('parameter_key', 128)->nullable();
            $table->string('state_cluster_id', 128)->nullable();
            $table->string('regime', 32)->nullable();
            $table->string('volatility', 32)->nullable();
            $table->string('transition_state', 32)->nullable();
            $table->string('spread_liquidity_state', 32)->nullable();
            $table->string('veto_reason', 64)->nullable();
            $table->string('outcome', 32)->nullable();
            $table->unsignedInteger('independent_window_count')->default(0);
            $table->unsignedInteger('confirmation_count')->default(0);
            $table->decimal('lower_confidence_bound', 10, 4)->nullable();
            $table->json('source_run_ids')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('observed_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'strategy_family', 'lesson_type', 'status'], 'agent_lesson_scope_type_status_idx');
            $table->index(['state_cluster_id', 'lesson_type', 'status'], 'agent_lesson_cluster_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_learning_lessons');
        Schema::dropIfExists('agent_knowledge_cards');
    }
};
