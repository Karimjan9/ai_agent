<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('causal_stage_mastery_assessments', function (Blueprint $table): void {
            $table->id();
            $table->string('assessment_key', 160)->unique();
            $table->unsignedBigInteger('edge_genesis_trial_id')->nullable();
            $table->unsignedBigInteger('control_edge_genesis_trial_id')->nullable();
            $table->unsignedBigInteger('model_version_id')->nullable();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('gene_key', 96);
            $table->string('transition_key', 96);
            $table->string('status', 64);
            $table->json('assessment');
            $table->timestamp('assessed_at')->nullable();
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'status'], 'causal_stage_mastery_scope_status_idx');
            $table->index(['edge_genesis_trial_id', 'gene_key'], 'causal_stage_mastery_trial_gene_idx');
            $table->foreign('edge_genesis_trial_id', 'causal_stage_mastery_trial_fk')->references('id')->on('edge_genesis_trials')->nullOnDelete();
            $table->foreign('control_edge_genesis_trial_id', 'causal_stage_mastery_control_trial_fk')->references('id')->on('edge_genesis_trials')->nullOnDelete();
            $table->foreign('model_version_id', 'causal_stage_mastery_model_fk')->references('id')->on('model_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('causal_stage_mastery_assessments');
    }
};
