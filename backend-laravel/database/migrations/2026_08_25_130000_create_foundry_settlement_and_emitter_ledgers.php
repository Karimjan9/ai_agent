<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('composition_component_posteriors', function (Blueprint $table): void {
            $table->id(); $table->string('posterior_key', 180)->unique(); $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('state_key', 160); $table->string('component_type', 48); $table->string('component_id', 128); $table->unsignedInteger('observations')->default(0); $table->decimal('after_cost_value', 14, 6)->default(0); $table->decimal('uncertainty', 10, 6)->default(1); $table->json('evidence'); $table->dateTime('last_settled_at', 6); $table->timestamps(); $table->index(['symbol','timeframe','state_key','component_type'], 'composition_posterior_scope_idx');
        });
        Schema::create('foundry_emitter_rewards', function (Blueprint $table): void {
            $table->id(); $table->string('reward_key', 180)->unique(); $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('emitter', 64); $table->string('trial_family_id', 128)->nullable(); $table->decimal('reward', 14, 6); $table->decimal('penalty', 14, 6)->default(0); $table->string('status', 32); $table->json('evidence'); $table->dateTime('settled_at', 6); $table->timestamps(); $table->index(['symbol','timeframe','emitter','status'], 'foundry_emitter_reward_scope_idx');
        });
        Schema::create('foundry_multiple_testing_ledgers', function (Blueprint $table): void {
            $table->id(); $table->string('ledger_key', 180)->unique(); $table->string('symbol',16); $table->string('timeframe',16); $table->string('trial_family_id',128); $table->string('experiment_id',128); $table->string('status',32); $table->decimal('deflated_sharpe_probability',10,6)->nullable(); $table->decimal('pbo_probability',10,6)->nullable(); $table->json('evidence'); $table->dateTime('recorded_at',6); $table->timestamps(); $table->unique(['symbol','timeframe','experiment_id'], 'foundry_multiple_testing_experiment_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('foundry_multiple_testing_ledgers'); Schema::dropIfExists('foundry_emitter_rewards'); Schema::dropIfExists('composition_component_posteriors'); }
};
