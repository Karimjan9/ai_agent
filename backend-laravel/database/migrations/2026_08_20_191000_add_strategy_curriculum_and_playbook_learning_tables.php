<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playbook_value_posteriors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('playbook_composition_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('state_key', 128);
            $table->unsignedInteger('observations')->default(0);
            $table->decimal('net_value', 14, 6)->default(0);
            $table->decimal('uncertainty', 14, 6)->default(1);
            $table->string('decay_state', 32)->default('provisional');
            $table->json('value_vector');
            $table->timestamp('last_observed_at')->nullable();
            $table->timestamps();
            $table->unique(['playbook_composition_id', 'symbol', 'timeframe', 'state_key'], 'playbook_posterior_scope_uq');
        });

        Schema::create('strategy_curriculum_contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('contract_key', 160)->unique();
            $table->foreignId('lab_agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('strategy_id', 96);
            $table->string('strategy_version', 48)->default('v1');
            $table->string('mastery_lane', 48);
            $table->string('training_stage', 32)->default('apprentice');
            $table->json('allowed_instruments');
            $table->json('forbidden_instruments')->nullable();
            $table->json('target_regimes')->nullable();
            $table->json('target_sessions')->nullable();
            $table->json('control_contract');
            $table->unsignedTinyInteger('innovation_budget')->default(0);
            $table->string('state', 32)->default('active');
            $table->timestamps();
            $table->index(['strategy_id', 'mastery_lane', 'training_stage'], 'strategy_curriculum_lookup_idx');
        });

        Schema::create('strategy_master_passports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_version_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('strategy_id', 96);
            $table->string('mastery_stage', 48)->default('seed');
            $table->string('status', 32)->default('provisional');
            $table->json('target_regimes')->nullable();
            $table->json('metrics');
            $table->json('evidence')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->timestamps();
            $table->index(['strategy_id', 'mastery_stage', 'status'], 'strategy_master_passport_lookup_idx');
        });

        Schema::create('strategy_innovation_trials', function (Blueprint $table): void {
            $table->id();
            $table->string('trial_key', 160)->unique();
            $table->foreignId('lab_agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('playbook_composition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('innovation_trial');
            $table->json('instrument_keys');
            $table->json('control_contract');
            $table->json('behavior_contract');
            $table->json('evidence')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at'], 'strategy_innovation_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_innovation_trials');
        Schema::dropIfExists('strategy_master_passports');
        Schema::dropIfExists('strategy_curriculum_contracts');
        Schema::dropIfExists('playbook_value_posteriors');
    }
};
