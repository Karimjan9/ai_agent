<?php

namespace Tests\Feature;

use App\Models\CapabilitySkill;
use App\Services\AntiSkillCemeteryService;
use App\Services\ExperimentGovernorService;
use App\Services\ProgressScoreboardService;
use App\Services\RegimeCapabilityRouter;
use App\Services\SkillCompilerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapabilityOrganismServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_skill_requires_every_causal_confirmation_before_it_can_route_capital(): void
    {
        $compiler = app(SkillCompilerService::class);
        $base = ['symbol' => 'XAUUSD', 'timeframe' => 'M15', 'state_key' => 'trend_up|london|normal', 'strategy_id' => 'fibonacci_structure_pullback', 'tactic_id' => 'dynamic_fibonacci_structure_pullback', 'exact_control' => ['paired_isolated' => true], 'data_hash' => 'data-a', 'execution_hash' => 'execution-a', 'independent_windows' => ['observed_windows' => 3, 'positive_windows' => 2], 'independent_confirmation' => true];
        $provisional = $compiler->compile([...$base, 'non_target_regression' => true]);
        $confirmed = $compiler->compile([...$base, 'non_target_regression' => false]);

        $this->assertSame('provisional', $provisional['status']);
        $this->assertFalse($provisional['routing_eligible']);
        $this->assertSame('active', $confirmed['status']);
        $this->assertTrue($confirmed['routing_eligible']);
        $this->assertDatabaseCount('capability_skills', 1);
    }

    public function test_router_observes_unknown_state_but_trades_only_a_confirmed_capability(): void
    {
        $router = app(RegimeCapabilityRouter::class);
        $state = ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal', 'state_key' => 'trend_up|london|normal'];
        $this->assertSame('OBSERVE', $router->route('XAUUSD', 'M15', $state)['organism_state']);
        CapabilitySkill::create(['skill_key' => 'confirmed-xau', 'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'state_key' => $state['state_key'], 'status' => 'active', 'independent_windows' => 3, 'positive_windows' => 2, 'independently_confirmed' => true, 'contract' => [], 'evidence' => [], 'compiled_at' => now()]);
        $decision = $router->route('XAUUSD', 'M15', $state);
        $this->assertSame('TRADE', $decision['organism_state']);
        $this->assertTrue($decision['capital_authorized']);
    }

    public function test_cemetery_forbids_repeated_or_hard_risk_failures_and_governor_stays_shadow_only(): void
    {
        $cemetery = app(AntiSkillCemeteryService::class);
        $failure = ['symbol' => 'XAUUSD', 'timeframe' => 'M15', 'state_key' => 'range|london|normal', 'strategy_id' => 'range_reversion', 'failure_mode' => 'risk_breach'];
        $cemetery->bury($failure);
        $cemetery->bury($failure);
        $burial = $cemetery->bury($failure);
        $governor = app(ExperimentGovernorService::class)->decide(['primary_cause' => 'execution', 'severity' => .9], ['drawdown_percent' => 10]);

        $this->assertSame('forbidden', $burial['status']);
        $this->assertSame('repair', $governor['contract']['lane']);
        $this->assertSame(1, $governor['contract']['max_changed_axes']);
        $this->assertFalse($governor['contract']['live_execution']);
    }

    public function test_scoreboard_reports_capability_progress_not_pnl(): void
    {
        CapabilitySkill::create(['skill_key' => 'score-xau', 'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'state_key' => 'x', 'status' => 'active', 'independent_windows' => 3, 'positive_windows' => 2, 'independently_confirmed' => true, 'contract' => [], 'evidence' => [], 'compiled_at' => now()]);
        $score = app(ProgressScoreboardService::class)->measure('XAUUSD', 'M15');

        $this->assertSame(24.0, $score['progress_score']);
        $this->assertSame(1, $score['metrics']['confirmed_skills']);
        $this->assertArrayHasKey('learning_starvation', $score['metrics']['events']);
    }
}
