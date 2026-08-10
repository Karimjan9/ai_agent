<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operational lifecycle projection for one lab agent.
     *
     * This is intentionally separate from the knowledge card: knowledge is
     * a capability projection, while this card answers the operational
     * questions "what failed, what changed, and what may happen next?".
     * Neither card can grant promotion; immutable replay gates remain the
     * authority for forward, paper and champion decisions.
     */
    public function up(): void
    {
        Schema::create('agent_progress_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_agent_id')->unique()->constrained('lab_agents')->cascadeOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('stage', 32)->default('weak');
            $table->string('status', 32)->default('active');
            $table->string('primary_failure', 64)->nullable();
            $table->string('changed_gene', 128)->nullable();
            $table->unsignedInteger('repair_attempt')->default(0);
            $table->foreignId('parent_model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->json('parent_diff')->nullable();
            $table->json('gates_passed')->nullable();
            $table->json('failure_codes')->nullable();
            $table->string('next_action', 128)->nullable();
            $table->json('stage_history')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->string('last_evidence_run_id', 64)->nullable();
            $table->string('last_result_hash', 128)->nullable();
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'strategy_family', 'stage'], 'agent_progress_scope_stage_idx');
            $table->index(['status', 'primary_failure'], 'agent_progress_status_failure_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_progress_cards');
    }
};
