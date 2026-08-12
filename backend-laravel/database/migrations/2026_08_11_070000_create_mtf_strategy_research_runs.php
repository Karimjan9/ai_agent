<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mtf_strategy_research_runs')) {
            return;
        }

        Schema::create('mtf_strategy_research_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('model_market_performance_id')->nullable();
            $table->string('pilot_id', 80);
            $table->string('symbol', 16);
            $table->string('regime_timeframe', 16);
            $table->string('entry_timeframe', 16);
            $table->string('hypothesis_key', 96);
            $table->string('strategy_identity', 96);
            $table->string('strategy_family', 48)->nullable();
            $table->char('run_key', 64)->unique('mtf_research_run_key_unique');
            $table->char('data_hash', 64);
            $table->char('parameter_hash', 64);
            $table->char('execution_hash', 64);
            $table->string('status', 24)->default('completed');
            $table->string('failure_class', 48)->nullable();
            $table->json('research_contract');
            $table->json('parameters');
            $table->json('result')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('model_market_performance_id', 'mtf_research_candidate_fk')
                ->references('id')->on('model_market_performance')->nullOnDelete();
            $table->index(['symbol', 'hypothesis_key', 'completed_at'], 'mtf_research_hypothesis_completed');
            $table->index(['symbol', 'strategy_family', 'completed_at'], 'mtf_research_family_completed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mtf_strategy_research_runs');
    }
};
