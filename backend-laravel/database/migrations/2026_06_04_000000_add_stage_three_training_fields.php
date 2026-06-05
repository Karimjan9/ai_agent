<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('backtest_runs')) {
            Schema::table('backtest_runs', function (Blueprint $table) {
                if (! Schema::hasColumn('backtest_runs', 'symbol')) {
                    $table->string('symbol')->nullable()->after('id');
                }
                if (! Schema::hasColumn('backtest_runs', 'strategy')) {
                    $table->string('strategy')->nullable()->after('timeframe');
                }
                if (! Schema::hasColumn('backtest_runs', 'date_from')) {
                    $table->date('date_from')->nullable()->after('strategy');
                }
                if (! Schema::hasColumn('backtest_runs', 'date_to')) {
                    $table->date('date_to')->nullable()->after('date_from');
                }
                if (! Schema::hasColumn('backtest_runs', 'initial_balance')) {
                    $table->decimal('initial_balance', 15, 2)->default(10000)->after('date_to');
                }
                if (! Schema::hasColumn('backtest_runs', 'final_balance')) {
                    $table->decimal('final_balance', 15, 2)->default(0)->after('initial_balance');
                }
                if (! Schema::hasColumn('backtest_runs', 'total_trades')) {
                    $table->integer('total_trades')->default(0)->after('final_balance');
                }
                if (! Schema::hasColumn('backtest_runs', 'wins')) {
                    $table->integer('wins')->default(0)->after('total_trades');
                }
                if (! Schema::hasColumn('backtest_runs', 'losses')) {
                    $table->integer('losses')->default(0)->after('wins');
                }
                if (! Schema::hasColumn('backtest_runs', 'winrate')) {
                    $table->decimal('winrate', 8, 2)->default(0)->after('losses');
                }
                if (! Schema::hasColumn('backtest_runs', 'net_profit_percent')) {
                    $table->decimal('net_profit_percent', 8, 2)->default(0)->after('winrate');
                }
                if (! Schema::hasColumn('backtest_runs', 'max_drawdown_percent')) {
                    $table->decimal('max_drawdown_percent', 8, 2)->nullable()->default(0)->after('net_profit_percent');
                }
                if (! Schema::hasColumn('backtest_runs', 'profit_factor')) {
                    $table->decimal('profit_factor', 8, 2)->nullable()->default(0)->after('max_drawdown_percent');
                }
                if (! Schema::hasColumn('backtest_runs', 'conclusion')) {
                    $table->longText('conclusion')->nullable()->after('profit_factor');
                }
                if (! Schema::hasColumn('backtest_runs', 'raw_result')) {
                    $table->json('raw_result')->nullable()->after('conclusion');
                }
            });
        }

        if (Schema::hasTable('trades')) {
            Schema::table('trades', function (Blueprint $table) {
                if (! Schema::hasColumn('trades', 'strategy')) {
                    $table->string('strategy')->nullable()->after('timeframe');
                }
                if (! Schema::hasColumn('trades', 'profit_percent')) {
                    $table->decimal('profit_percent', 8, 3)->default(0)->after('result');
                }
                if (! Schema::hasColumn('trades', 'balance_after_trade')) {
                    $table->decimal('balance_after_trade', 15, 2)->nullable()->after('profit_percent');
                }
            });
        }

        if (Schema::hasTable('mistakes')) {
            Schema::table('mistakes', function (Blueprint $table) {
                if (! Schema::hasColumn('mistakes', 'description')) {
                    $table->text('description')->nullable()->after('mistake_type');
                }
                if (! Schema::hasColumn('mistakes', 'suggestion')) {
                    $table->text('suggestion')->nullable()->after('description');
                }
            });
        }

        if (Schema::hasTable('daily_reports')) {
            Schema::table('daily_reports', function (Blueprint $table) {
                if (! Schema::hasColumn('daily_reports', 'symbol')) {
                    $table->string('symbol')->nullable()->after('report_date');
                }
                if (! Schema::hasColumn('daily_reports', 'timeframe')) {
                    $table->string('timeframe')->nullable()->after('symbol');
                }
                if (! Schema::hasColumn('daily_reports', 'strategy')) {
                    $table->string('strategy')->nullable()->after('timeframe');
                }
                if (! Schema::hasColumn('daily_reports', 'total_backtests')) {
                    $table->integer('total_backtests')->default(0)->after('strategy');
                }
                if (! Schema::hasColumn('daily_reports', 'total_trades')) {
                    $table->integer('total_trades')->default(0)->after('total_backtests');
                }
                if (! Schema::hasColumn('daily_reports', 'total_wins')) {
                    $table->integer('total_wins')->default(0)->after('total_trades');
                }
                if (! Schema::hasColumn('daily_reports', 'total_losses')) {
                    $table->integer('total_losses')->default(0)->after('total_wins');
                }
                if (! Schema::hasColumn('daily_reports', 'average_winrate')) {
                    $table->decimal('average_winrate', 8, 2)->default(0)->after('total_losses');
                }
                if (! Schema::hasColumn('daily_reports', 'average_profit')) {
                    $table->decimal('average_profit', 8, 2)->default(0)->after('average_winrate');
                }
                if (! Schema::hasColumn('daily_reports', 'top_mistakes')) {
                    $table->json('top_mistakes')->nullable()->after('average_profit');
                }
                if (! Schema::hasColumn('daily_reports', 'ai_conclusion')) {
                    $table->longText('ai_conclusion')->nullable()->after('top_mistakes');
                }
                if (! Schema::hasColumn('daily_reports', 'next_training_plan')) {
                    $table->longText('next_training_plan')->nullable()->after('ai_conclusion');
                }
            });
        }
    }

    public function down(): void
    {
        //
    }
};
