<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategy_scores', function (Blueprint $table): void {
            if (! Schema::hasColumn('strategy_scores', 'regime_performance')) {
                $table->json('regime_performance')->nullable()->after('equity_curve');
            }

            if (! Schema::hasColumn('strategy_scores', 'volatility_performance')) {
                $table->json('volatility_performance')->nullable()->after('regime_performance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('strategy_scores', function (Blueprint $table): void {
            if (Schema::hasColumn('strategy_scores', 'volatility_performance')) {
                $table->dropColumn('volatility_performance');
            }

            if (Schema::hasColumn('strategy_scores', 'regime_performance')) {
                $table->dropColumn('regime_performance');
            }
        });
    }
};
