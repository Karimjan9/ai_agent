<?php

namespace Tests\Feature;

use App\Models\EvolutionProposal;
use App\Models\FitnessEvaluation;
use App\Models\GenomeCrossover;
use App\Models\GenomeDiscovery;
use App\Models\GenomeLineage;
use App\Models\GenomeMutation;
use App\Models\ModelVersion;
use App\Models\StrategyGenome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvolutionGenomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_all_creates_genomes_fitness_crossovers_and_discoveries(): void
    {
        foreach ([
            ['ema_rsi_v1', 'v1', 1, ['ema_fast' => 40, 'ema_slow' => 200, 'rsi_buy_max' => 70]],
            ['ema_rsi_v2', 'v2', 2, ['ema_fast' => 50, 'ema_slow' => 200, 'rsi_buy_max' => 72]],
            ['ema_rsi_v3', 'v3', 3, ['ema_fast' => 55, 'ema_slow' => 200, 'rsi_buy_max' => 75]],
            ['breakout_v1', 'v1', 1, ['lookback' => 20, 'confirmation_candles' => 1]],
        ] as [$strategy, $version, $generation, $parameters]) {
            ModelVersion::create([
                'name' => $strategy,
                'strategy' => $strategy,
                'version' => $version,
                'generation' => $generation,
                'status' => 'testing',
                'parameters' => $parameters,
                'metadata' => [],
            ]);
        }

        Http::fake([
            'http://127.0.0.1:9000/api/backtest/run-all' => Http::response([
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'leaderboard' => [
                    $this->leaderboardItem('ema_rsi_v3', ['ema_fast' => 55, 'ema_slow' => 200, 'rsi_buy_max' => 75], 88, 96),
                    $this->leaderboardItem('ema_rsi_v2', ['ema_fast' => 50, 'ema_slow' => 200, 'rsi_buy_max' => 72], 82, 90),
                    $this->leaderboardItem('breakout_v1', ['lookback' => 20, 'confirmation_candles' => 1], 77, 84),
                    $this->leaderboardItem('ema_rsi_v1', ['ema_fast' => 40, 'ema_slow' => 200, 'rsi_buy_max' => 70], 72, 80),
                ],
            ], 200),
        ]);

        $this->post('/strategy-lab/run-all', [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'initial_balance' => 10000,
            'risk_per_trade' => 1,
        ])->assertRedirect(route('training-sessions.index'));

        $this->assertDatabaseHas('strategy_genomes', [
            'strategy' => 'ema_rsi_v3',
            'family' => 'ema_rsi',
            'generation' => 3,
            'status' => 'alive',
        ]);

        $this->assertSame(4, StrategyGenome::count());
        $this->assertSame(4, FitnessEvaluation::count());
        $this->assertGreaterThanOrEqual(1, GenomeCrossover::count());
        $this->assertGreaterThanOrEqual(1, GenomeDiscovery::count());

        $discovery = GenomeDiscovery::query()->where('gene_key', 'ema_fast')->first();
        $this->assertNotNull($discovery);
        $this->assertStringContainsString('ema_fast values between', $discovery->discovery);
    }

    public function test_apply_proposal_records_child_genome_mutation_and_lineage(): void
    {
        $parentVersion = ModelVersion::create([
            'name' => 'ema_rsi_v1',
            'strategy' => 'ema_rsi_v1',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => ['ema_fast' => 50, 'rsi_buy_max' => 70],
            'metadata' => [],
        ]);

        StrategyGenome::create([
            'model_version_id' => $parentVersion->id,
            'strategy' => 'ema_rsi_v1',
            'family' => 'ema_rsi',
            'version' => 'v1',
            'generation' => 1,
            'genome_hash' => hash('sha256', 'parent'),
            'genes' => ['ema_fast' => 50, 'rsi_buy_max' => 70],
            'fitness_score' => 60,
            'status' => 'alive',
            'born_at' => now(),
        ]);

        $proposal = EvolutionProposal::create([
            'model_version_id' => $parentVersion->id,
            'strategy' => 'ema_rsi_v1',
            'current_version' => 'v1',
            'proposed_version' => 'v2',
            'current_score' => 40,
            'main_problem' => 'late_entry',
            'reason' => 'EMA fast needs mutation.',
            'proposal' => 'Change EMA fast and RSI upper bound.',
            'old_parameters' => ['ema_fast' => 50, 'rsi_buy_max' => 70],
            'new_parameters' => ['ema_fast' => 40, 'rsi_buy_max' => 75],
            'status' => 'approved',
        ]);

        $this->post(route('evolution-proposals.apply', $proposal))
            ->assertRedirect(route('model-versions.index'));

        $this->assertDatabaseHas('strategy_genomes', [
            'strategy' => 'ema_rsi_v2',
            'family' => 'ema_rsi',
            'generation' => 2,
        ]);

        $this->assertSame(1, GenomeMutation::count());
        $this->assertSame(1, GenomeLineage::count());

        $mutation = GenomeMutation::first();
        $this->assertSame(50, $mutation->mutation_diff['ema_fast']['old']);
        $this->assertSame(40, $mutation->mutation_diff['ema_fast']['new']);
        $this->assertSame(70, $mutation->mutation_diff['rsi_buy_max']['old']);
        $this->assertSame(75, $mutation->mutation_diff['rsi_buy_max']['new']);
    }

    public function test_evolution_lab_dashboard_renders(): void
    {
        StrategyGenome::create([
            'strategy' => 'ema_rsi_v1',
            'family' => 'ema_rsi',
            'version' => 'v1',
            'generation' => 1,
            'genome_hash' => hash('sha256', 'dashboard'),
            'genes' => ['ema_fast' => 50],
            'fitness_score' => 75,
            'status' => 'alive',
            'born_at' => now(),
        ]);

        $response = $this->get('/evolution-lab');

        $response->assertOk()
            ->assertSee('Evolution Lab')
            ->assertSee('Genome Tree')
            ->assertSee('Mutations')
            ->assertSee('Cross Breeding')
            ->assertSee('Evolution Efficiency')
            ->assertSee('EMA_RSI_V1');
    }

    private function leaderboardItem(string $strategy, array $parameters, int $score, int $robustness): array
    {
        return [
            'strategy' => $strategy,
            'parameters' => $parameters,
            'score' => $score,
            'train_score' => $score + 2,
            'validation_score' => $score,
            'forward_score' => $score - 1,
            'robustness_score' => $robustness,
            'is_overfit' => false,
            'result' => [
                'total_trades' => 120,
                'wins' => 72,
                'losses' => 48,
                'winrate' => 60.0,
                'net_profit_percent' => 18.0,
                'max_drawdown_percent' => 7.0,
                'profit_factor' => 1.6,
                'average_win_percent' => 0.9,
                'average_loss_percent' => 0.5,
                'risk_reward_ratio' => 1.8,
                'max_consecutive_losses' => 3,
                'stability_score' => 84,
                'equity_curve' => [10000, 10400, 11800],
                'regime_performance' => [
                    'trend_up' => [
                        'trades' => 8,
                        'wins' => 6,
                        'losses' => 2,
                        'winrate' => 75,
                        'profit_percent' => 5.0,
                    ],
                ],
                'volatility_performance' => [],
                'monte_carlo' => [
                    'risk_of_ruin_percent' => 2.0,
                    'worst_drawdown_percent' => 11.0,
                    'worst_profit_percent' => -4.0,
                    'avg_profit_percent' => 15.0,
                    'best_profit_percent' => 32.0,
                    'avg_drawdown_percent' => 6.0,
                    'worst_equity_curve' => [10000, 9700, 11000],
                    'best_equity_curve' => [10000, 11200, 13200],
                ],
                'strategy_dna' => [
                    'aggression_score' => 55,
                    'trend_dependency' => 70,
                    'range_dependency' => 30,
                    'volatility_sensitivity' => 35,
                    'adaptability_score' => 82,
                    'recovery_score' => 80,
                    'survival_score' => 88,
                    'dna_summary' => 'Stable strategy genome.',
                ],
                'trades' => [
                    [
                        'direction' => 'long',
                        'entry_time' => '2026-01-01 01:00:00',
                        'exit_time' => '2026-01-01 05:00:00',
                        'entry_price' => 2000,
                        'exit_price' => 2020,
                        'result' => 'WIN',
                        'profit_percent' => 1.0,
                        'balance' => 10100,
                        'market_regime' => 'trend_up',
                        'volatility_regime' => 'normal_volatility',
                    ],
                ],
            ],
        ];
    }
}
