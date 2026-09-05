<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mtf_agent_validation_runs')) {
            return;
        }

        Schema::create('mtf_agent_validation_runs', function (Blueprint $table): void {
            $table->id();
            $table->char('run_key', 64)->unique('mtf_agent_validation_run_key_uq');
            $table->string('protocol', 64);
            $table->foreignId('source_run_id')->constrained('mtf_playbook_frozen_control_runs')->cascadeOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('symbol', 16)->default('XAUUSD');
            $table->string('entry_timeframe', 16)->default('M5');
            $table->string('status', 32)->default('started');
            $table->unsignedInteger('attempts')->default(0);
            $table->char('data_hash', 64);
            $table->char('execution_hash', 64);
            $table->char('candidate_parameter_hash', 64);
            $table->char('control_parameter_hash', 64);
            $table->json('dataset_manifest');
            $table->json('validation_contract');
            $table->json('candidate_result')->nullable();
            $table->json('control_result')->nullable();
            $table->json('paired_summary')->nullable();
            $table->json('causal_accounting')->nullable();
            $table->json('reason_codes')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'status', 'source_run_id'], 'mtf_agent_validation_scope_idx');
            $table->index(['model_version_id', 'status'], 'mtf_agent_validation_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mtf_agent_validation_runs');
    }
};
