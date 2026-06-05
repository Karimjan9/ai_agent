<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('symbols', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('display_name', 64);
            $table->string('asset_class', 32)->default('metal');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('candles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('symbol_id')->constrained('symbols')->cascadeOnDelete();
            $table->string('timeframe', 16);
            $table->dateTime('time');
            $table->decimal('open', 16, 5);
            $table->decimal('high', 16, 5);
            $table->decimal('low', 16, 5);
            $table->decimal('close', 16, 5);
            $table->decimal('volume', 20, 4)->nullable();
            $table->timestamps();
            $table->unique(['symbol_id', 'timeframe', 'time']);
        });

        Schema::create('strategies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('title', 128);
            $table->json('config');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('model_versions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 96)->unique();
            $table->string('status', 32)->default('draft');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('training_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('status', 32)->default('queued');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->json('metrics')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('backtest_runs', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('timeframe');
            $table->string('strategy');
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->decimal('initial_balance', 15, 2)->default(10000);
            $table->decimal('final_balance', 15, 2)->default(0);
            $table->integer('total_trades')->default(0);
            $table->integer('wins')->default(0);
            $table->integer('losses')->default(0);
            $table->decimal('winrate', 8, 2)->default(0);
            $table->decimal('net_profit_percent', 8, 2)->default(0);
            $table->decimal('max_drawdown_percent', 8, 2)->nullable()->default(0);
            $table->decimal('profit_factor', 8, 2)->nullable()->default(0);
            $table->longText('conclusion')->nullable();
            $table->json('raw_result')->nullable();
            $table->timestamps();
        });

        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained()->cascadeOnDelete();
            $table->string('symbol');
            $table->string('timeframe');
            $table->string('strategy');
            $table->string('direction');
            $table->dateTime('entry_time');
            $table->dateTime('exit_time')->nullable();
            $table->decimal('entry_price', 15, 5);
            $table->decimal('exit_price', 15, 5)->nullable();
            $table->decimal('stop_loss', 15, 5)->nullable();
            $table->decimal('take_profit', 15, 5)->nullable();
            $table->string('result');
            $table->decimal('profit_percent', 8, 3)->default(0);
            $table->decimal('balance_after_trade', 15, 2)->nullable();
            $table->string('mistake_type')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('mistakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trade_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mistake_type');
            $table->text('description')->nullable();
            $table->text('suggestion')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });

        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->date('report_date')->unique();
            $table->string('symbol')->nullable();
            $table->string('timeframe')->nullable();
            $table->string('strategy')->nullable();
            $table->integer('total_backtests')->default(0);
            $table->integer('total_trades')->default(0);
            $table->integer('total_wins')->default(0);
            $table->integer('total_losses')->default(0);
            $table->decimal('average_winrate', 8, 2)->default(0);
            $table->decimal('average_profit', 8, 2)->default(0);
            $table->json('top_mistakes')->nullable();
            $table->longText('ai_conclusion')->nullable();
            $table->longText('next_training_plan')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_reports');
        Schema::dropIfExists('mistakes');
        Schema::dropIfExists('trades');
        Schema::dropIfExists('backtest_runs');
        Schema::dropIfExists('training_sessions');
        Schema::dropIfExists('model_versions');
        Schema::dropIfExists('strategies');
        Schema::dropIfExists('candles');
        Schema::dropIfExists('symbols');
    }
};
