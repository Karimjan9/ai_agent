<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('strategy_scores')) {
            return;
        }

        Schema::table('strategy_scores', function (Blueprint $table): void {
            if (! Schema::hasColumn('strategy_scores', 'mc_worst_profit_percent')) {
                $table->decimal('mc_worst_profit_percent', 10, 2)->nullable()->after('is_overfit');
            }

            if (! Schema::hasColumn('strategy_scores', 'mc_avg_profit_percent')) {
                $table->decimal('mc_avg_profit_percent', 10, 2)->nullable()->after('mc_worst_profit_percent');
            }

            if (! Schema::hasColumn('strategy_scores', 'mc_best_profit_percent')) {
                $table->decimal('mc_best_profit_percent', 10, 2)->nullable()->after('mc_avg_profit_percent');
            }

            if (! Schema::hasColumn('strategy_scores', 'mc_worst_drawdown_percent')) {
                $table->decimal('mc_worst_drawdown_percent', 10, 2)->nullable()->after('mc_best_profit_percent');
            }

            if (! Schema::hasColumn('strategy_scores', 'mc_avg_drawdown_percent')) {
                $table->decimal('mc_avg_drawdown_percent', 10, 2)->nullable()->after('mc_worst_drawdown_percent');
            }

            if (! Schema::hasColumn('strategy_scores', 'mc_risk_of_ruin_percent')) {
                $table->decimal('mc_risk_of_ruin_percent', 10, 2)->nullable()->after('mc_avg_drawdown_percent');
            }

            if (! Schema::hasColumn('strategy_scores', 'mc_worst_equity_curve')) {
                $table->json('mc_worst_equity_curve')->nullable()->after('mc_risk_of_ruin_percent');
            }

            if (! Schema::hasColumn('strategy_scores', 'mc_best_equity_curve')) {
                $table->json('mc_best_equity_curve')->nullable()->after('mc_worst_equity_curve');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('strategy_scores')) {
            return;
        }

        Schema::table('strategy_scores', function (Blueprint $table): void {
            foreach ([
                'mc_best_equity_curve',
                'mc_worst_equity_curve',
                'mc_risk_of_ruin_percent',
                'mc_avg_drawdown_percent',
                'mc_worst_drawdown_percent',
                'mc_best_profit_percent',
                'mc_avg_profit_percent',
                'mc_worst_profit_percent',
            ] as $column) {
                if (Schema::hasColumn('strategy_scores', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
