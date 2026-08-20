<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canonical audit trail for the learning loop.  Existing lesson and lab
     * tables remain source projections; these tables prove their use from a
     * decision through its settled outcome.
     */
    public function up(): void
    {
        Schema::create('agent_learning_episodes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('episode_id')->unique();
            $table->string('decision_key', 128)->unique();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64)->nullable();
            $table->string('stage', 32)->default('decision');
            $table->string('status', 32)->default('open');
            $table->string('decision', 32)->nullable();
            $table->decimal('confidence', 10, 6)->nullable();
            $table->string('risk_veto', 96)->nullable();
            $table->string('context_hash', 128);
            $table->string('data_hash', 128)->nullable();
            $table->string('code_hash', 128)->nullable();
            $table->string('parameter_hash', 128)->nullable();
            $table->string('execution_hash', 128)->nullable();
            $table->json('decision_context');
            $table->json('observations')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'strategy_family', 'status'], 'learning_episode_scope_status_idx');
        });

        Schema::create('agent_learning_settlements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('settlement_id')->unique();
            $table->foreignId('episode_id')->unique()->constrained('agent_learning_episodes')->cascadeOnDelete();
            $table->string('source_key', 160)->unique();
            $table->string('source_type', 96)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('outcome_status', 32);
            $table->string('failure_class', 64)->nullable();
            $table->string('evidence_state', 32)->default('uncertain');
            $table->decimal('selection_reward', 14, 6)->nullable();
            $table->boolean('hard_failure')->default(false);
            $table->json('outcome');
            $table->json('reward_components')->nullable();
            $table->json('reflection')->nullable();
            $table->timestamp('settled_at');
            $table->timestamps();
            $table->index(['outcome_status', 'failure_class', 'evidence_state'], 'learning_settlement_status_failure_idx');
        });

        Schema::create('agent_learning_retrievals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('retrieval_id')->unique();
            $table->string('packet_id', 64);
            $table->foreignId('episode_id')->nullable()->constrained('agent_learning_episodes')->nullOnDelete();
            $table->foreignId('agent_learning_lesson_id')->nullable()->constrained('agent_learning_lessons')->nullOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64)->nullable();
            $table->string('retrieval_state', 32)->default('retrieved');
            $table->string('match_level', 32)->nullable();
            $table->decimal('rank_score', 14, 6)->nullable();
            $table->string('reason_code', 96)->nullable();
            $table->json('context');
            $table->json('metadata')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('outcome_linked_at')->nullable();
            $table->timestamps();
            $table->index(['packet_id', 'retrieval_state'], 'learning_retrieval_packet_state_idx');
            $table->index(['symbol', 'timeframe', 'strategy_family', 'retrieval_state'], 'learning_retrieval_scope_state_idx');
        });

        Schema::create('agent_learning_policies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('policy_id')->unique();
            $table->string('policy_key', 128);
            $table->unsignedInteger('version');
            $table->string('symbol', 16)->nullable();
            $table->string('timeframe', 16)->nullable();
            $table->string('strategy_family', 64)->nullable();
            $table->string('state', 24)->default('draft');
            $table->string('parent_policy_id', 36)->nullable();
            $table->json('definition');
            $table->json('evidence')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['policy_key', 'version'], 'learning_policy_key_version_uq');
            $table->index(['symbol', 'timeframe', 'strategy_family', 'state'], 'learning_policy_scope_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_learning_policies');
        Schema::dropIfExists('agent_learning_retrievals');
        Schema::dropIfExists('agent_learning_settlements');
        Schema::dropIfExists('agent_learning_episodes');
    }
};
