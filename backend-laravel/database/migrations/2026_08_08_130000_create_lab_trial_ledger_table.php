<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_trial_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64)->nullable();
            $table->string('stage', 32);
            $table->string('run_id', 128)->nullable()->unique();
            $table->string('parameter_hash', 128);
            $table->string('data_manifest_hash', 128)->nullable();
            $table->string('execution_hash', 128)->nullable();
            $table->unsignedInteger('trial_index')->default(1);
            $table->unsignedInteger('trial_count')->default(1);
            $table->decimal('score', 12, 4)->nullable();
            $table->decimal('observed_sharpe', 14, 8)->nullable();
            $table->decimal('selection_penalty_points', 10, 4)->default(0);
            $table->decimal('selection_adjusted_score', 12, 4)->nullable();
            $table->string('status', 24)->default('recorded');
            $table->json('metrics')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'stage', 'evaluated_at'], 'lab_trial_scope_lookup');
            $table->index(['lab_generation_id', 'stage'], 'lab_trial_generation_stage');
            $table->unique(['symbol', 'timeframe', 'stage', 'parameter_hash', 'data_manifest_hash', 'execution_hash'], 'lab_trial_recovery_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_trial_ledger');
    }
};
