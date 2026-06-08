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

        Schema::table('strategy_scores', function (Blueprint $table) {
            if (! Schema::hasColumn('strategy_scores', 'average_win_percent')) {
                $table->decimal('average_win_percent', 8, 3)->default(0)->after('profit_factor');
            }
            if (! Schema::hasColumn('strategy_scores', 'average_loss_percent')) {
                $table->decimal('average_loss_percent', 8, 3)->default(0)->after('average_win_percent');
            }
            if (! Schema::hasColumn('strategy_scores', 'risk_reward_ratio')) {
                $table->decimal('risk_reward_ratio', 8, 2)->default(0)->after('average_loss_percent');
            }
            if (! Schema::hasColumn('strategy_scores', 'max_consecutive_losses')) {
                $table->integer('max_consecutive_losses')->default(0)->after('risk_reward_ratio');
            }
            if (! Schema::hasColumn('strategy_scores', 'stability_score')) {
                $table->integer('stability_score')->default(0)->after('max_consecutive_losses');
            }
            if (! Schema::hasColumn('strategy_scores', 'equity_curve')) {
                $table->json('equity_curve')->nullable()->after('stability_score');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('strategy_scores')) {
            return;
        }

        Schema::table('strategy_scores', function (Blueprint $table) {
            foreach ([
                'average_win_percent',
                'average_loss_percent',
                'risk_reward_ratio',
                'max_consecutive_losses',
                'stability_score',
                'equity_curve',
            ] as $column) {
                if (Schema::hasColumn('strategy_scores', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
