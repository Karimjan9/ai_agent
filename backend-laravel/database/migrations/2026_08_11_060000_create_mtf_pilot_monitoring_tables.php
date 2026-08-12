<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mtf_pilot_monitor_runs')) {
            Schema::create('mtf_pilot_monitor_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('pilot_id', 80);
                $table->string('symbol', 16);
                $table->string('status', 16);
                $table->decimal('health_score', 6, 2)->default(0);
                $table->unsignedInteger('lookback_hours')->default(24);
                $table->dateTime('latest_h1_open_at')->nullable();
                $table->dateTime('latest_h1_closed_at')->nullable();
                $table->dateTime('latest_m15_open_at')->nullable();
                $table->dateTime('latest_m15_closed_at')->nullable();
                $table->json('report');
                $table->timestamp('checked_at')->useCurrent();
                $table->timestamps();

                $table->index(['symbol', 'checked_at'], 'mtf_monitor_symbol_checked');
                $table->index(['pilot_id', 'status', 'checked_at'], 'mtf_monitor_status_checked');
            });
        }

        if (! Schema::hasTable('mtf_ablation_runs')) {
            Schema::create('mtf_ablation_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('model_market_performance_id')->nullable();
                $table->string('pilot_id', 80);
                $table->string('symbol', 16);
                $table->string('regime_timeframe', 16);
                $table->string('entry_timeframe', 16);
                $table->char('run_key', 64)->unique('mtf_ablation_run_key_unique');
                $table->char('data_hash', 64);
                $table->char('execution_hash', 64);
                $table->string('status', 16)->default('completed');
                $table->json('variants');
                $table->boolean('promotion_evidence')->default(false);
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->foreign('model_market_performance_id', 'mtf_ablation_candidate_fk')
                    ->references('id')->on('model_market_performance')->nullOnDelete();
                $table->index(['symbol', 'entry_timeframe', 'completed_at'], 'mtf_ablation_scope_completed');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mtf_ablation_runs');
        Schema::dropIfExists('mtf_pilot_monitor_runs');
    }
};
