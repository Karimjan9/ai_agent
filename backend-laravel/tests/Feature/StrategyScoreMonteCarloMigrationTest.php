<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StrategyScoreMonteCarloMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_strategy_scores_table_has_monte_carlo_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('strategy_scores', [
            'mc_worst_profit_percent',
            'mc_avg_profit_percent',
            'mc_best_profit_percent',
            'mc_worst_drawdown_percent',
            'mc_avg_drawdown_percent',
            'mc_risk_of_ruin_percent',
            'mc_worst_equity_curve',
            'mc_best_equity_curve',
        ]));
    }
}
