<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_instruments', function (Blueprint $table): void {
            $table->id();
            $table->string('instrument_key', 128)->unique();
            $table->string('label');
            $table->string('role', 32);
            $table->string('tactic_id', 96)->nullable();
            $table->string('promotion_state', 32)->default('provisional');
            $table->boolean('is_abstention')->default(false);
            $table->json('definition');
            $table->timestamps();
            $table->index(['role', 'promotion_state'], 'trading_instrument_role_state_idx');
        });

        Schema::create('instrument_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trading_instrument_id')->unique()->constrained('trading_instruments')->cascadeOnDelete();
            $table->json('compatible_regimes')->nullable();
            $table->json('forbidden_regimes')->nullable();
            $table->json('required_inputs')->nullable();
            $table->json('allowed_genes')->nullable();
            $table->json('cost_model')->nullable();
            $table->json('risk_model')->nullable();
            $table->json('control_contract')->nullable();
            $table->json('contract')->nullable();
            $table->timestamps();
        });

        Schema::create('instrument_value_posteriors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trading_instrument_id')->constrained('trading_instruments')->cascadeOnDelete();
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
            $table->unique(['trading_instrument_id', 'symbol', 'timeframe', 'state_key'], 'instrument_posterior_scope_uq');
            $table->index(['symbol', 'timeframe', 'state_key', 'net_value'], 'instrument_posterior_router_idx');
        });

        Schema::create('playbook_compositions', function (Blueprint $table): void {
            $table->id();
            $table->string('playbook_key', 128)->unique();
            $table->string('label');
            $table->string('symbol', 16)->nullable();
            $table->string('timeframe', 16)->nullable();
            $table->string('promotion_state', 32)->default('provisional');
            $table->json('instrument_keys');
            $table->json('preconditions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'promotion_state'], 'playbook_scope_state_idx');
        });

        Schema::create('instrument_evidence', function (Blueprint $table): void {
            $table->id();
            $table->string('evidence_key', 160)->unique();
            $table->foreignId('trading_instrument_id')->constrained('trading_instruments')->cascadeOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('state_key', 128);
            $table->string('outcome_state', 32);
            $table->string('source_type', 64)->nullable();
            $table->string('source_key', 160)->nullable();
            $table->json('metrics');
            $table->json('control_metrics')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->index(['trading_instrument_id', 'symbol', 'timeframe', 'state_key'], 'instrument_evidence_scope_idx');
        });

        Schema::create('router_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('decision_key', 160)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('state_key', 128);
            $table->foreignId('playbook_composition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('decision', 16);
            $table->string('reason_code', 96);
            $table->json('state_fingerprint');
            $table->json('candidates')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'state_key', 'decided_at'], 'router_decision_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_decisions');
        Schema::dropIfExists('instrument_evidence');
        Schema::dropIfExists('playbook_compositions');
        Schema::dropIfExists('instrument_value_posteriors');
        Schema::dropIfExists('instrument_contracts');
        Schema::dropIfExists('trading_instruments');
    }
};
