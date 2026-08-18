<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lab_temporal_ablation_runs')) return;

        Schema::create('lab_temporal_ablation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_laboratory_id')->nullable()->constrained('ai_laboratories')->nullOnDelete();
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('protocol', 64);
            $table->char('run_key', 64)->unique('lab_temporal_ablation_run_key_uq');
            $table->char('hypothesis_hash', 64);
            $table->char('data_identity_hash', 64)->nullable();
            $table->char('execution_hash', 64)->nullable();
            $table->string('status', 24)->default('blocked');
            $table->string('decision', 64)->nullable();
            $table->unsignedInteger('window_count')->default(0);
            $table->unsignedInteger('variant_count')->default(4);
            $table->json('window_manifest')->nullable();
            $table->json('results')->nullable();
            $table->json('reason_codes')->nullable();
            $table->boolean('mutation_credit_allowed')->default(false);
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'status'], 'lab_temporal_ablation_scope_idx');
            $table->index(['hypothesis_hash', 'data_identity_hash'], 'lab_temporal_ablation_identity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_temporal_ablation_runs');
    }
};
