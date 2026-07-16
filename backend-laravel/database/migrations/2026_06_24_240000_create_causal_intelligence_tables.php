<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('causal_discovery_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('edges_created')->default(0);
            $table->unsignedInteger('effects_estimated')->default(0);
            $table->unsignedInteger('interventions_created')->default(0);
            $table->unsignedInteger('experiments_created')->default(0);
            $table->text('summary')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();
        });

        Schema::create('causal_nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('node_key', 160)->unique();
            $table->string('label');
            $table->string('node_type')->default('variable');
            $table->text('description')->nullable();
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('causal_edges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('causal_discovery_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_node_id')->constrained('causal_nodes')->cascadeOnDelete();
            $table->foreignId('target_node_id')->constrained('causal_nodes')->cascadeOnDelete();
            $table->foreignId('quant_law_id')->nullable()->constrained()->nullOnDelete();
            $table->string('edge_key', 180)->unique();
            $table->string('direction')->default('negative');
            $table->string('identification_status')->default('associational');
            $table->decimal('causality_score', 6, 2)->default(0);
            $table->decimal('correlation_score', 6, 2)->default(0);
            $table->decimal('effect_size', 8, 3)->default(0);
            $table->unsignedInteger('evidence_count')->default(0);
            $table->text('rationale')->nullable();
            $table->json('assumptions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['identification_status', 'causality_score'], 'causal_edges_status_score_idx');
        });

        Schema::create('causal_effect_estimates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('causal_edge_id')->constrained()->cascadeOnDelete();
            $table->string('estimand')->default('average_treatment_effect');
            $table->decimal('effect_estimate', 8, 3)->default(0);
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->decimal('lower_bound', 8, 3)->nullable();
            $table->decimal('upper_bound', 8, 3)->nullable();
            $table->string('method')->default('heuristic_adjusted');
            $table->json('adjustment_set')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('causal_counterfactuals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('causal_edge_id')->constrained()->cascadeOnDelete();
            $table->string('question');
            $table->decimal('baseline_value', 8, 3)->default(0);
            $table->decimal('intervention_value', 8, 3)->default(0);
            $table->decimal('estimated_delta', 8, 3)->default(0);
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->text('result_summary');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('causal_interventions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('causal_edge_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('intervention_type')->default('parameter_adjustment');
            $table->text('recommendation');
            $table->decimal('expected_impact_score', 6, 2)->default(50);
            $table->decimal('cost_score', 6, 2)->default(50);
            $table->decimal('risk_score', 6, 2)->default(50);
            $table->string('status')->default('proposed');
            $table->json('parameters')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('causal_experiments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('causal_edge_id')->nullable()->constrained()->nullOnDelete();
            $table->string('experiment_key', 180)->unique();
            $table->string('title');
            $table->text('hypothesis');
            $table->string('status')->default('planned');
            $table->string('control_group');
            $table->string('experimental_group');
            $table->decimal('expected_information_gain', 6, 2)->default(50);
            $table->json('success_criteria')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('causal_root_causes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('causal_edge_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cause_key', 160)->unique();
            $table->string('title');
            $table->text('summary');
            $table->decimal('impact_score', 6, 2)->default(50);
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->unsignedInteger('rank')->default(0);
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['rank', 'impact_score'], 'causal_root_rank_impact_idx');
        });

        Schema::create('discovery_quality_scores', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('title');
            $table->decimal('correlation_score', 6, 2)->default(0);
            $table->decimal('causality_score', 6, 2)->default(0);
            $table->decimal('quality_score', 6, 2)->default(0);
            $table->string('verdict')->default('correlational');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id'], 'discovery_quality_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_quality_scores');
        Schema::dropIfExists('causal_root_causes');
        Schema::dropIfExists('causal_experiments');
        Schema::dropIfExists('causal_interventions');
        Schema::dropIfExists('causal_counterfactuals');
        Schema::dropIfExists('causal_effect_estimates');
        Schema::dropIfExists('causal_edges');
        Schema::dropIfExists('causal_nodes');
        Schema::dropIfExists('causal_discovery_runs');
    }
};
