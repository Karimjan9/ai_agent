<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_tactic_posteriors', function (Blueprint $table): void {
            $table->id();
            $table->string('tactic_key', 160);
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('state_key', 128);
            $table->unsignedInteger('observations')->default(0);
            $table->decimal('net_expectancy', 14, 6)->default(0);
            $table->decimal('uncertainty', 14, 6)->default(1);
            $table->string('mastery_stage', 32)->default('tactic_seed');
            $table->json('value_vector');
            $table->timestamp('last_observed_at')->nullable();
            $table->timestamps();
            $table->unique(['tactic_key', 'symbol', 'timeframe', 'state_key'], 'execution_tactic_posterior_scope_uq');
        });

        Schema::create('risk_sentinel_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('decision_key', 160)->unique();
            $table->foreignId('paper_signal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('model_market_performance_id')->nullable()->constrained('model_market_performance')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('decision', 16);
            $table->string('reason_code', 96);
            $table->decimal('equity', 16, 4)->nullable();
            $table->decimal('risk_budget_percent', 10, 6)->nullable();
            $table->decimal('position_size_multiple', 14, 6)->nullable();
            $table->json('plan');
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'decision', 'decided_at'], 'risk_sentinel_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_sentinel_decisions');
        Schema::dropIfExists('execution_tactic_posteriors');
    }
};
