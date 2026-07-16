<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theory_generation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('theories_generated')->default(0);
            $table->unsignedInteger('battles_created')->default(0);
            $table->unsignedInteger('predictions_created')->default(0);
            $table->unsignedInteger('unified_models_created')->default(0);
            $table->text('summary')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();
        });

        Schema::create('quant_theories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('theory_generation_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('theory_key', 180)->unique();
            $table->string('title');
            $table->text('thesis');
            $table->string('theory_type')->default('middle_range');
            $table->string('status')->default('emerging');
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->decimal('explanatory_power_score', 6, 2)->default(50);
            $table->decimal('predictive_power_score', 6, 2)->default(50);
            $table->unsignedInteger('evidence_count')->default(0);
            $table->unsignedInteger('law_count')->default(0);
            $table->unsignedInteger('causal_edge_count')->default(0);
            $table->unsignedInteger('root_cause_count')->default(0);
            $table->json('scope')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'confidence_score'], 'quant_theories_status_confidence_idx');
        });

        Schema::create('theory_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quant_theory_id')->constrained()->cascadeOnDelete();
            $table->string('component_type');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('contribution_score', 6, 2)->default(50);
            $table->string('polarity')->default('supporting');
            $table->text('summary');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id'], 'theory_components_source_idx');
        });

        Schema::create('theory_battles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('theory_a_id')->constrained('quant_theories')->cascadeOnDelete();
            $table->foreignId('theory_b_id')->constrained('quant_theories')->cascadeOnDelete();
            $table->string('battle_key', 220)->unique();
            $table->string('status')->default('running');
            $table->foreignId('winner_theory_id')->nullable()->constrained('quant_theories')->nullOnDelete();
            $table->decimal('confidence_gap', 6, 2)->default(0);
            $table->text('summary')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();
        });

        Schema::create('theory_predictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quant_theory_id')->constrained()->cascadeOnDelete();
            $table->string('prediction_key', 220)->unique();
            $table->string('target_metric');
            $table->decimal('baseline_value', 8, 3)->default(0);
            $table->decimal('intervention_value', 8, 3)->default(0);
            $table->decimal('predicted_delta', 8, 3)->default(0);
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->string('horizon')->default('next_research_cycle');
            $table->string('status')->default('untested');
            $table->text('rationale');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('theory_evolution_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quant_theory_id')->constrained()->cascadeOnDelete();
            $table->string('event_type')->default('generated');
            $table->string('previous_status')->nullable();
            $table->string('new_status')->nullable();
            $table->decimal('previous_confidence', 6, 2)->nullable();
            $table->decimal('new_confidence', 6, 2)->nullable();
            $table->text('reason');
            $table->json('evidence')->nullable();
            $table->timestamps();
        });

        Schema::create('unified_quant_models', function (Blueprint $table): void {
            $table->id();
            $table->string('model_key', 180)->unique();
            $table->string('title');
            $table->text('thesis');
            $table->string('status')->default('emerging');
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->unsignedInteger('theory_count')->default(0);
            $table->unsignedInteger('law_count')->default(0);
            $table->unsignedInteger('root_cause_count')->default(0);
            $table->json('components')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unified_quant_models');
        Schema::dropIfExists('theory_evolution_events');
        Schema::dropIfExists('theory_predictions');
        Schema::dropIfExists('theory_battles');
        Schema::dropIfExists('theory_components');
        Schema::dropIfExists('quant_theories');
        Schema::dropIfExists('theory_generation_runs');
    }
};
