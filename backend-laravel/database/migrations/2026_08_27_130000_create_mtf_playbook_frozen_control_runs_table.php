<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mtf_playbook_frozen_control_runs')) return;

        Schema::create('mtf_playbook_frozen_control_runs', function (Blueprint $table): void {
            $table->id();
            $table->char('run_key', 64)->unique('mtf_playbook_frozen_control_run_key_uq');
            $table->string('protocol', 64);
            $table->string('research_model_id', 96);
            $table->string('symbol', 16);
            $table->string('entry_timeframe', 16)->default('M5');
            $table->string('related_symbol', 16)->nullable();
            $table->char('data_hash', 64);
            $table->char('execution_hash', 64);
            $table->char('control_parameter_hash', 64);
            $table->char('candidate_parameter_hash', 64);
            $table->string('status', 32)->default('started');
            $table->json('required_streams');
            $table->json('dataset_manifest');
            $table->json('control_result')->nullable();
            $table->json('candidate_result')->nullable();
            $table->json('comparison')->nullable();
            $table->json('reason_codes')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'research_model_id', 'status'], 'mtf_playbook_frozen_control_scope_idx');
            $table->index(['data_hash', 'execution_hash'], 'mtf_playbook_frozen_control_identity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mtf_playbook_frozen_control_runs');
    }
};
