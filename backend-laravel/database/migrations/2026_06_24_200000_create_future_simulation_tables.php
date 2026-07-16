<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('future_simulation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_state_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('market_genome_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('market_species_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol');
            $table->string('timeframe');
            $table->unsignedInteger('scenario_count')->default(1000);
            $table->unsignedInteger('max_horizon_candles')->default(50);
            $table->unsignedInteger('random_seed')->default(0);
            $table->string('status')->default('completed');
            $table->decimal('current_confidence', 6, 2)->default(50);
            $table->decimal('future_confidence', 6, 2)->default(50);
            $table->string('planning_bias')->default('neutral');
            $table->json('current_market_vector')->nullable();
            $table->json('knowledge_prior_summary')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'created_at']);
        });

        Schema::create('future_scenarios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('future_simulation_run_id')->constrained()->cascadeOnDelete();
            $table->string('scenario_key');
            $table->string('scenario_label');
            $table->unsignedInteger('simulated_count')->default(0);
            $table->decimal('probability', 7, 4)->default(0);
            $table->decimal('expected_return', 8, 2)->default(0);
            $table->decimal('risk_score', 6, 2)->default(50);
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->json('state_path')->nullable();
            $table->json('drivers')->nullable();
            $table->timestamps();

            $table->unique(['future_simulation_run_id', 'scenario_key'], 'future_scenario_unique');
        });

        Schema::create('future_probability_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('future_simulation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('future_probability_nodes')->cascadeOnDelete();
            $table->string('node_key');
            $table->string('label');
            $table->decimal('probability', 7, 4)->default(0);
            $table->unsignedInteger('horizon_candles')->default(0);
            $table->string('node_type')->default('scenario');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['future_simulation_run_id', 'parent_id'], 'future_prob_run_parent_idx');
        });

        Schema::create('future_timeline_forecasts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('future_simulation_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('horizon_candles');
            $table->decimal('bull_probability', 7, 4)->default(0);
            $table->decimal('range_probability', 7, 4)->default(0);
            $table->decimal('panic_probability', 7, 4)->default(0);
            $table->decimal('reversal_probability', 7, 4)->default(0);
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->json('drivers')->nullable();
            $table->timestamps();

            $table->unique(['future_simulation_run_id', 'horizon_candles'], 'future_timeline_unique');
        });

        Schema::create('strategy_survival_forecasts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('future_simulation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_score_id')->nullable()->constrained()->nullOnDelete();
            $table->string('strategy');
            $table->decimal('current_confidence', 6, 2)->default(50);
            $table->decimal('future_confidence', 6, 2)->default(50);
            $table->decimal('survival_probability', 7, 4)->default(0);
            $table->decimal('future_robustness', 6, 2)->default(50);
            $table->string('recommended_action')->default('maintain');
            $table->json('scenario_breakdown')->nullable();
            $table->json('planning_adjustments')->nullable();
            $table->timestamps();

            $table->index(['strategy', 'survival_probability'], 'strategy_survival_idx');
        });

        Schema::create('future_stress_tests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('future_simulation_run_id')->constrained()->cascadeOnDelete();
            $table->string('stress_key');
            $table->string('stress_label');
            $table->decimal('impact_score', 6, 2)->default(50);
            $table->decimal('survival_rate', 7, 4)->default(0);
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->string('risk_level')->default('medium');
            $table->text('planning_note')->nullable();
            $table->json('parameters')->nullable();
            $table->timestamps();
        });

        Schema::create('future_discoveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('future_simulation_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->unique();
            $table->text('discovery');
            $table->string('discovery_type')->default('future_pattern');
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->unsignedInteger('evidence_count')->default(0);
            $table->string('status')->default('provisional');
            $table->json('scope')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['discovery_type', 'confidence_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('future_discoveries');
        Schema::dropIfExists('future_stress_tests');
        Schema::dropIfExists('strategy_survival_forecasts');
        Schema::dropIfExists('future_timeline_forecasts');
        Schema::dropIfExists('future_probability_nodes');
        Schema::dropIfExists('future_scenarios');
        Schema::dropIfExists('future_simulation_runs');
    }
};
