<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_skill_zoo_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('skill_key', 128)->unique();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('strategy_family', 64);
            $table->string('module_key', 64); $table->string('niche_key', 128); $table->string('gene_key', 96)->nullable();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->foreignId('lab_mutation_response_map_id')->nullable()->constrained('lab_mutation_response_maps')->nullOnDelete();
            $table->decimal('quality_score', 12, 6)->default(0); $table->decimal('confidence', 8, 4)->default(0);
            $table->string('status', 32)->default('provisional'); $table->json('evidence')->nullable(); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'strategy_family', 'module_key', 'niche_key'], 'lab_skill_zoo_scope_idx');
        });

        Schema::create('lab_mutation_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('action_key', 64)->unique();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('strategy_family', 64);
            $table->string('gene_key', 96); $table->string('direction', 24); $table->decimal('magnitude', 14, 6)->nullable();
            $table->json('context')->nullable(); $table->json('parent_state')->nullable(); $table->json('reward')->nullable();
            $table->string('status', 32)->default('proposed'); $table->string('evidence_run_id', 96)->nullable(); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'strategy_family', 'gene_key', 'status'], 'lab_mutation_action_scope_idx');
        });

        Schema::create('lab_adversarial_scenarios', function (Blueprint $table): void {
            $table->id();
            $table->string('scenario_key', 64)->unique();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('strategy_family', 64)->nullable();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->string('scenario_type', 64); $table->json('historical_bounds'); $table->decimal('novelty_score', 8, 4)->default(0);
            $table->decimal('realism_score', 8, 4)->default(0); $table->decimal('failure_discovery_score', 12, 6)->default(0);
            $table->string('status', 32)->default('planned'); $table->json('result')->nullable(); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'status', 'scenario_type'], 'lab_adversarial_scenario_scope_idx');
        });

        Schema::create('lab_evolution_directors', function (Blueprint $table): void {
            $table->id();
            $table->string('director_key', 128)->unique();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('director_type', 48);
            $table->json('policy'); $table->decimal('reward_score', 12, 6)->default(0); $table->decimal('budget_share', 8, 4)->default(0);
            $table->string('status', 32)->default('active'); $table->json('evidence')->nullable(); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'status'], 'lab_evolution_director_scope_idx');
        });

        Schema::create('lab_evolution_extinction_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('plan_key', 64)->unique();
            $table->foreignId('ai_laboratory_id')->constrained('ai_laboratories')->cascadeOnDelete();
            $table->string('island_key', 128); $table->string('status', 32)->default('proposed');
            $table->decimal('retirement_fraction', 8, 4)->default(0); $table->json('trigger_metrics'); $table->json('preservation_contract');
            $table->json('replacement_contract')->nullable(); $table->timestamp('approved_at')->nullable(); $table->timestamp('executed_at')->nullable(); $table->timestamps();
            $table->index(['ai_laboratory_id', 'status'], 'lab_evolution_extinction_lab_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_evolution_extinction_plans');
        Schema::dropIfExists('lab_evolution_directors');
        Schema::dropIfExists('lab_adversarial_scenarios');
        Schema::dropIfExists('lab_mutation_actions');
        Schema::dropIfExists('lab_skill_zoo_entries');
    }
};
