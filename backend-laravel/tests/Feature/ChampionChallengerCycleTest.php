<?php

namespace Tests\Feature;

use App\Models\EvolutionProposal;
use App\Models\ModelVersion;
use App\Services\EvolutionProposalApplicationService;
use App\Services\MarketChampionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ChampionChallengerCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_challenger_replaces_only_the_same_market_champion_after_all_gates(): void
    {
        $champion = ModelVersion::create($this->model('breakout_v1', 'v1'));
        $challenger = ModelVersion::create($this->model('breakout_v2', 'v2'));
        $service = app(MarketChampionService::class);

        $first = $service->evaluate('breakout_v1', 'XAUUSD', 'H1', 75, $this->resultMetrics(70, [70, 71, 69]));
        $this->assertSame('forward_validated', $first->status);
        $service->recordPaperResult($first, ['sample_count' => 50, 'profit_factor' => 1.3, 'max_drawdown' => 8, 'net_profit_percent' => 4]);
        $service->finalizeHoldout($first, ['score'=>72,'result'=>['profit_factor'=>1.4,'max_drawdown_percent'=>9,'total_trades'=>40,'monte_carlo'=>['risk_of_ruin_percent'=>4]]]);

        $second = $service->evaluate('breakout_v2', 'XAUUSD', 'H1', 84, $this->resultMetrics(80, [80, 79, 81]));
        $this->assertSame('forward_validated', $second->status);
        $this->assertDatabaseHas('model_market_performance', ['model_version_id' => $champion->id, 'status' => 'champion']);
        $service->recordPaperResult($second, ['sample_count' => 55, 'profit_factor' => 1.3, 'max_drawdown' => 9, 'net_profit_percent' => 5]);
        $service->finalizeHoldout($second, ['score'=>82,'result'=>['profit_factor'=>1.5,'max_drawdown_percent'=>8,'total_trades'=>50,'monte_carlo'=>['risk_of_ruin_percent'=>3]]]);

        $this->assertDatabaseHas('model_market_performance', [
            'model_version_id' => $challenger->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'status' => 'champion',
        ]);
        $this->assertDatabaseHas('model_market_performance', [
            'model_version_id' => $champion->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'status' => 'archived',
        ]);
    }

    public function test_apply_is_idempotent_and_unknown_parameter_is_rejected(): void
    {
        $parent = ModelVersion::create($this->model('breakout_v1', 'v1'));
        $proposal = EvolutionProposal::create([
            'model_version_id' => $parent->id, 'parent_model_version_id' => $parent->id,
            'strategy' => 'breakout_v1', 'current_version' => 'v1', 'proposed_version' => 'v2',
            'new_parameters' => ['lookback' => 30], 'status' => 'approved', 'open_status' => 'open',
        ]);
        $service = app(EvolutionProposalApplicationService::class);
        $first = $service->apply($proposal);
        $second = $service->apply($proposal->fresh());
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('model_versions', 2);

        $bad = EvolutionProposal::create([
            'model_version_id' => $first->id, 'parent_model_version_id' => $first->id,
            'strategy' => 'breakout_v2', 'current_version' => 'v2', 'proposed_version' => 'v3',
            'new_parameters' => ['python_does_not_use_this' => true], 'status' => 'approved', 'open_status' => 'open',
        ]);
        $this->expectException(InvalidArgumentException::class);
        $service->apply($bad);
    }

    public function test_candidate_needs_three_rolling_forward_wins_before_paper_trading(): void
    {
        $candidate = ModelVersion::create($this->model('breakout_v1', 'v1'));

        $performance = app(MarketChampionService::class)->evaluate(
            $candidate->strategy,
            'GBPUSD',
            'H1',
            90,
            $this->resultMetrics(90, [90, 91]),
        );

        $this->assertSame('challenger', $performance->status);
        $this->assertSame(2, $performance->rolling_windows_count);
        $this->assertDatabaseMissing('paper_trading_evaluations', [
            'model_market_performance_id' => $performance->id,
        ]);
    }

    public function test_low_profit_factor_candidate_never_enters_paper_trading(): void
    {
        $candidate = ModelVersion::create($this->model('breakout_v1', 'v1'));
        $metrics = $this->resultMetrics(90, [90, 91, 92]);
        $metrics['profit_factor'] = 1.29;

        $performance = app(MarketChampionService::class)->evaluate($candidate->strategy, 'EURUSD', 'H1', 90, $metrics);

        $this->assertSame('challenger', $performance->status);
        $this->assertDatabaseMissing('paper_trading_evaluations', [
            'model_market_performance_id' => $performance->id,
        ]);
    }

    public function test_statistically_overfit_batch_candidate_never_enters_paper_trading(): void
    {
        $candidate = ModelVersion::create($this->model('breakout_v1', 'v1'));
        $metrics = $this->resultMetrics(90, [90, 91, 92, 93]);
        $metrics['selection_validation'] = ['status' => 'assessed', 'probability_of_backtest_overfitting' => 0.67];
        $metrics['statistical_evidence'] = ['deflated_sharpe' => ['status' => 'assessed', 'deflated_sharpe_probability' => 0.99]];

        $performance = app(MarketChampionService::class)->evaluate($candidate->strategy, 'EURUSD', 'H1', 90, $metrics);

        $this->assertSame('challenger', $performance->status);
        $this->assertDatabaseMissing('paper_trading_evaluations', ['model_market_performance_id' => $performance->id]);
    }

    private function model(string $strategy, string $version): array
    {
        return [
            'name' => $strategy, 'strategy' => $strategy, 'version' => $version,
            'generation' => (int) substr($version, 1), 'status' => 'testing',
            'parameters' => ['lookback' => 20], 'metadata' => [],
        ];
    }

    private function resultMetrics(float $forward, array $windows): array
    {
        return [
            'forward_score' => $forward, 'forward_window_scores' => $windows,
            'total_trades' => 90, 'profit_factor' => 1.5, 'max_drawdown_percent' => 10,
            'is_overfit' => false, 'monte_carlo' => ['risk_of_ruin_percent' => 5],
        ];
    }
}
