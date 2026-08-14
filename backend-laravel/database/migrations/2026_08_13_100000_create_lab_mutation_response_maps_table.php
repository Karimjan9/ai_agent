<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_mutation_response_maps', function (Blueprint $table): void {
            $table->id();
            $table->string('response_key', 64)->unique();
            $table->string('stage', 32);
            $table->string('status', 32);
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('target', 64)->nullable();
            $table->string('parameter_key', 128)->nullable();
            $table->string('direction', 32)->nullable();
            $table->string('sibling_kind', 48)->nullable();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->foreignId('repair_anchor_id')->nullable()->constrained('lab_failure_repair_anchors')->nullOnDelete();
            $table->string('evidence_run_id', 128)->nullable();
            $table->string('temporal_window_key', 128)->nullable();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->json('baseline_metrics')->nullable();
            $table->json('observed_metrics')->nullable();
            $table->json('target_delta')->nullable();
            $table->json('non_target_regression')->nullable();
            $table->json('regime_result')->nullable();
            $table->json('forward_confirmation')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['symbol', 'timeframe', 'strategy_family', 'target', 'parameter_key', 'status'],
                'mutation_response_scope_lookup',
            );
            $table->index(['repair_anchor_id', 'stage'], 'mutation_response_anchor_stage_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_mutation_response_maps');
    }
};
