<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_causal_control_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('plan_key', 128)->unique();
            $table->foreignId('pair_id')->nullable()->constrained('lab_learning_lane_pairs')->nullOnDelete();
            $table->foreignId('candidate_response_map_id')->nullable()->constrained('lab_mutation_response_maps')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 96);
            $table->string('target', 96)->nullable();
            $table->string('specialist_role', 128)->nullable();
            $table->string('dataset_hash', 160);
            $table->string('execution_hash', 160);
            $table->string('temporal_window_key', 160);
            $table->string('status', 32)->default('planned');
            $table->foreignId('control_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->foreignId('control_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->string('control_evidence_run_id', 128)->nullable();
            $table->json('contract')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'status'], 'lab_control_plan_scope_status_idx');
            $table->index(['dataset_hash', 'execution_hash', 'temporal_window_key'], 'lab_control_plan_contract_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_causal_control_plans');
    }
};
