<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('elite_agent_portfolios', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 16);
            $table->string('timeframe', 8);
            $table->string('portfolio_key', 96);
            $table->string('status', 32)->default('waiting');
            $table->string('gate_status', 48)->default('waiting_for_members');
            $table->unsignedInteger('member_count')->default(0);
            $table->json('gate_reasons')->nullable();
            $table->json('route_policy')->nullable();
            $table->json('evidence')->nullable();
            $table->string('membership_hash', 64)->nullable();
            $table->string('execution_hash', 64)->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamps();
            $table->unique(['symbol', 'timeframe', 'portfolio_key'], 'elite_portfolio_market_key_unique');
            $table->index(['symbol', 'timeframe', 'status'], 'elite_portfolio_market_status_idx');
        });

        Schema::create('elite_agent_portfolio_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('elite_agent_portfolio_id');
            $table->unsignedBigInteger('model_market_performance_id');
            $table->foreign('elite_agent_portfolio_id', 'eap_member_portfolio_fk')->references('id')->on('elite_agent_portfolios')->cascadeOnDelete();
            $table->foreign('model_market_performance_id', 'eap_member_performance_fk')->references('id')->on('model_market_performance')->cascadeOnDelete();
            $table->string('role', 64);
            $table->string('target_regime', 32)->nullable();
            $table->string('target_volatility', 32)->nullable();
            $table->decimal('risk_weight', 8, 4)->default(1.0);
            $table->string('parameter_hash', 64);
            $table->json('evidence')->nullable();
            $table->timestamps();
            $table->unique(['elite_agent_portfolio_id', 'model_market_performance_id'], 'elite_portfolio_member_unique');
            $table->index(['elite_agent_portfolio_id', 'target_regime', 'target_volatility'], 'elite_portfolio_member_route_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elite_agent_portfolio_members');
        Schema::dropIfExists('elite_agent_portfolios');
    }
};
