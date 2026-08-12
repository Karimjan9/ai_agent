<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('paper_signal_passports')) {
            Schema::create('paper_signal_passports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('paper_signal_id')->unique()->constrained('paper_signals')->cascadeOnDelete();
            $table->foreignId('model_market_performance_id')->constrained('model_market_performance')->cascadeOnDelete();
            $table->string('pilot_id', 80);
            $table->string('lane', 24)->default('official');
            $table->string('symbol', 16);
            $table->string('primary_timeframe', 16);
            $table->string('regime_timeframe', 16);
            $table->string('entry_timeframe', 16);
            $table->char('h1_context_hash', 64)->nullable();
            $table->dateTime('h1_closed_at')->nullable();
            $table->dateTime('m15_decision_at');
            $table->string('m15_strategy')->nullable();
            $table->char('data_hash', 64)->nullable();
            $table->char('code_hash', 64)->nullable();
            $table->char('parameter_hash', 64)->nullable();
            $table->char('execution_hash', 64)->nullable();
            $table->char('mtf_contract_hash', 64)->nullable();
            $table->string('risk_decision', 16);
            $table->string('entry_reason', 160)->nullable();
            $table->string('exit_reason', 32)->nullable();
            $table->string('mtf_decision', 16);
            $table->string('h1_regime', 32)->nullable();
            $table->string('h1_permission', 24)->nullable();
            $table->decimal('risk_multiplier', 8, 6)->default(1);
            $table->json('gate_decisions')->nullable();
            $table->json('counterfactuals')->nullable();
            $table->json('payload');
            $table->char('passport_hash', 64)->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['pilot_id', 'symbol', 'primary_timeframe', 'm15_decision_at'], 'mtf_passport_scope_time');
            $table->index('h1_context_hash');
            });
        }

        if (! Schema::hasTable('paper_mtf_shadow_observations')) {
            Schema::create('paper_mtf_shadow_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('model_market_performance_id')->nullable();
            $table->unsignedBigInteger('paper_signal_id')->nullable();
            $table->string('pilot_id', 80);
            $table->string('lane', 24)->default('shadow');
            $table->string('scenario_key', 48);
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->dateTime('candle_time');
            $table->string('decision', 8);
            $table->decimal('price', 18, 6)->nullable();
            $table->decimal('stop_loss', 18, 6)->nullable();
            $table->decimal('take_profit', 18, 6)->nullable();
            $table->decimal('confidence', 8, 4)->default(0);
            $table->char('h1_context_hash', 64)->nullable();
            $table->dateTime('h1_closed_at')->nullable();
            $table->char('idempotency_key', 64);
            $table->char('payload_hash', 64);
            $table->json('payload');
            $table->string('outcome', 16)->nullable();
            $table->decimal('profit_percent', 10, 4)->nullable();
            $table->string('exit_reason', 32)->nullable();
            $table->json('outcome_payload')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamp('observed_at')->useCurrent();
            $table->timestamps();
            $table->foreign('model_market_performance_id', 'mtf_shadow_perf_fk')
                ->references('id')->on('model_market_performance')->nullOnDelete();
            $table->foreign('paper_signal_id', 'mtf_shadow_signal_fk')
                ->references('id')->on('paper_signals')->nullOnDelete();
            $table->unique('idempotency_key', 'mtf_shadow_idempotency_unique');
            $table->index(['pilot_id', 'symbol', 'timeframe', 'candle_time'], 'mtf_shadow_scope_time');
            $table->index(['scenario_key', 'decision'], 'mtf_shadow_scenario_decision');
            });
        }

        // The first deployment attempt may have created the second table
        // before MySQL rejected Laravel's auto-generated long FK name. Keep
        // this migration resumable so a failed additive deploy never needs a
        // broad table reset.
        $this->ensureShadowConstraints();
    }

    private function ensureShadowConstraints(): void
    {
        if (! Schema::hasTable('paper_mtf_shadow_observations')
            || Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! $this->constraintExists('paper_mtf_shadow_observations', 'mtf_shadow_perf_fk')) {
            Schema::table('paper_mtf_shadow_observations', function (Blueprint $table): void {
                $table->foreign('model_market_performance_id', 'mtf_shadow_perf_fk')
                    ->references('id')->on('model_market_performance')->nullOnDelete();
            });
        }
        if (! $this->constraintExists('paper_mtf_shadow_observations', 'mtf_shadow_signal_fk')) {
            Schema::table('paper_mtf_shadow_observations', function (Blueprint $table): void {
                $table->foreign('paper_signal_id', 'mtf_shadow_signal_fk')
                    ->references('id')->on('paper_signals')->nullOnDelete();
            });
        }
        if (! $this->indexExists('paper_mtf_shadow_observations', 'mtf_shadow_idempotency_unique')) {
            Schema::table('paper_mtf_shadow_observations', function (Blueprint $table): void {
                $table->unique('idempotency_key', 'mtf_shadow_idempotency_unique');
            });
        }
        if (! $this->indexExists('paper_mtf_shadow_observations', 'mtf_shadow_scope_time')) {
            Schema::table('paper_mtf_shadow_observations', function (Blueprint $table): void {
                $table->index(['pilot_id', 'symbol', 'timeframe', 'candle_time'], 'mtf_shadow_scope_time');
            });
        }
        if (! $this->indexExists('paper_mtf_shadow_observations', 'mtf_shadow_scenario_decision')) {
            Schema::table('paper_mtf_shadow_observations', function (Blueprint $table): void {
                $table->index(['scenario_key', 'decision'], 'mtf_shadow_scenario_decision');
            });
        }
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', Schema::getConnection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->exists();
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', Schema::getConnection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_mtf_shadow_observations');
        Schema::dropIfExists('paper_signal_passports');
    }
};
