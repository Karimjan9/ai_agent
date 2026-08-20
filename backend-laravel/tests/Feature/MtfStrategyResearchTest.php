<?php

namespace Tests\Feature;

use App\Models\MtfStrategyResearchRun;
use App\Models\MtfAblationRun;
use App\Services\MtfStrategyResearchReportService;
use App\Services\MtfStrategyResearchService;
use App\Services\MtfCouncilGateService;
use App\Services\MtfControlReplacementGateService;
use App\Services\MtfResearchSnapshotService;
use Illuminate\Support\Facades\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class MtfStrategyResearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_is_bounded_and_declares_distinct_failure_targets(): void
    {
        $service = app(MtfStrategyResearchService::class);
        $catalog = $service->catalog();
        $general = collect($catalog)->reject(fn (array $item): bool => str_starts_with((string) $item['key'], 'council_'));
        $council = collect($catalog)->filter(fn (array $item): bool => str_starts_with((string) $item['key'], 'council_'));

        $this->assertCount(19, $catalog);
        $this->assertCount(19, collect($catalog)->pluck('key')->unique());
        $this->assertCount(15, $general);
        $this->assertCount(4, $council);
        $this->assertEqualsCanonicalizing(
            ['regime_ownership', 'evidence_abstention', 'entry_quality', 'risk_and_exit_topology', 'directional_specialist', 'range_specialist', 'volatility_risk_management', 'directional_risk_defense', 'temporal_session_filter', 'transition_abstention', 'directional_risk_defense', 'volume_entry_quality', 'volume_transition_routing', 'volume_risk_management', 'volume_directional_specialist'],
            $general->pluck('mutation_class')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['council_directional_specialist', 'council_directional_specialist', 'council_range_specialist', 'council_transition_risk'],
            $council->pluck('mutation_class')->all(),
        );
        $this->assertTrue(collect($catalog)->every(fn (array $item): bool => ($item['parameter_overrides'] ?? []) !== []));
        $newResearch = collect($catalog)->filter(fn (array $item): bool => in_array($item['key'], [
            'volatility_managed_risk_v1',
            'trend_up_momentum_crash_firewall_v1',
            'trend_up_risk_budget_v1',
            'gold_session_liquidity_router_v1',
            'transition_persistence_firewall_v1',
        ], true));
        $this->assertCount(5, $newResearch);
        $this->assertTrue($newResearch->every(fn (array $item): bool => ($item['evidence_basis']['source_url'] ?? '') !== ''));
        $this->assertContains('volatility_managed_risk_v1', collect($catalog)->pluck('key')->all());
        $this->assertTrue($council->every(fn (array $item): bool => ($item['council_role'] ?? '') !== '' && ($item['mutation']['parameter'] ?? '') !== ''));
        $volume = collect($catalog)->filter(fn (array $item): bool => ($item['family'] ?? '') === 'volume_context');
        $this->assertCount(4, $volume);
        $this->assertEqualsCanonicalizing(
            ['breakout_volume_confirmation', 'transition_volume_router', 'low_volume_risk_firewall', 'transition_volume_router'],
            $volume->pluck('volume_lane')->all(),
        );
        $this->assertTrue($volume->every(fn (array $item): bool => ($item['evidence_basis']['source_url'] ?? '') !== ''));
        $this->assertCount(1, collect($service->select('council_transition_risk_router_v1', 4)));
    }

    public function test_challenger_frontier_rotates_unseen_families_without_replacing_the_frozen_control(): void
    {
        $service = app(MtfStrategyResearchService::class);

        $frontier = $service->selectFrontier(
            [
                ['hypothesis_key' => 'regime_ensemble_router_v1', 'strategy_family' => 'regime_ensemble', 'status' => 'completed'],
                ['hypothesis_key' => 'hybrid_breakout_dominant_v1', 'strategy_family' => 'hybrid', 'status' => 'completed'],
                ['hypothesis_key' => 'volume_breakout_confirmation_v1', 'strategy_family' => 'volume_context', 'status' => 'completed'],
                // A technical error is retryable evidence, not a completed
                // hypothesis and must remain eligible for recovery.
                ['hypothesis_key' => 'differential_trend_up_v2', 'strategy_family' => 'differential_router', 'status' => 'technical_error'],
            ],
            [
                'differential_router' => ['status' => 'pause_research_family'],
            ],
            4,
        );

        $keys = collect($frontier)->pluck('key')->all();
        $families = collect($frontier)->pluck('family')->all();

        $this->assertNotContains('regime_ensemble_router_v1', $keys);
        $this->assertNotContains('hybrid_breakout_dominant_v1', $keys);
        $this->assertNotContains('differential_trend_up_v2', $keys);
        $this->assertNotContains('council_trend_up_specialist_v1', $keys);
        $this->assertContains('hybrid', $families);
        $this->assertContains('volume_context', $families);
        $this->assertNotContains('differential_router', $families);
    }

    public function test_volume_research_requires_fresh_entry_and_regime_context(): void
    {
        $service = app(MtfStrategyResearchService::class);

        $stale = $service->volumeResearchFreshness([
            'status' => 'passed',
            'entry_quality' => ['lag_seconds' => 5400],
            'regime_quality' => ['lag_seconds' => 7200],
        ]);
        $this->assertFalse($stale['ready']);
        $this->assertContains('m15_volume_freshness_exceeded', $stale['reasons']);

        $fresh = $service->volumeResearchFreshness([
            'status' => 'passed',
            'entry_quality' => ['lag_seconds' => 0],
            'regime_quality' => ['lag_seconds' => 3600],
        ]);
        $this->assertTrue($fresh['ready']);
    }

    public function test_targeted_validation_uses_the_declared_gate_and_council_has_a_separate_proxy_gate(): void
    {
        $service = app(MtfStrategyResearchService::class);

        $this->assertFalse($service->targetGateImproved(
            'stress_drawdown',
            ['profit_factor' => 1.40, 'max_drawdown_percent' => 5.00],
            ['profit_factor' => 1.30, 'max_drawdown_percent' => 5.00],
        ));
        $this->assertTrue($service->targetGateImproved(
            'stress_drawdown',
            ['profit_factor' => 1.20, 'max_drawdown_percent' => 4.00],
            ['profit_factor' => 1.30, 'max_drawdown_percent' => 5.00],
        ));

        $gate = app(MtfCouncilGateService::class)->evaluate(
            ['total_trades' => 40, 'profit_factor' => 1.50, 'max_drawdown_percent' => 4.0],
            ['total_trades' => 32, 'profit_factor' => 1.30, 'max_drawdown_percent' => 5.0],
            [['role' => 'trend_up'], ['role' => 'range']],
        );
        $this->assertTrue($gate['passed']);
        $this->assertSame('cost_exit_stress_then_independent_forward_review', $gate['next_stage']);
        $this->assertFalse($gate['replacement_authorized']);
    }

    public function test_control_replacement_is_blocked_without_official_paper_evidence(): void
    {
        MtfAblationRun::create([
            'pilot_id' => 'xauusd_h1_m15_v1',
            'symbol' => 'XAUUSD',
            'regime_timeframe' => 'H1',
            'entry_timeframe' => 'M15',
            'run_key' => hash('sha256', 'replacement-control'),
            'data_hash' => str_repeat('d', 64),
            'execution_hash' => str_repeat('e', 64),
            'status' => 'completed',
            'variants' => [
                'h1_veto_m15_risk' => [
                    'total_trades' => 31,
                    'profit_factor' => 1.24,
                    'net_profit_percent' => 3.52,
                    'max_drawdown_percent' => 5.57,
                ],
            ],
            'promotion_evidence' => false,
            'completed_at' => now(),
        ]);

        $result = app(MtfControlReplacementGateService::class)->inspect('XAUUSD');

        $this->assertSame('blocked', $result['status']);
        $this->assertSame([], $result['candidates']);
        $this->assertContains('no_official_forward_paper_candidate', $result['blocking_reasons']);
        $this->assertFalse($result['replacement_authorized']);
    }

    public function test_report_classifies_entry_starvation_and_applies_family_budget_without_mutating_gates(): void
    {
        $dataHash = str_repeat('c', 64);
        MtfAblationRun::create([
            'pilot_id' => 'xauusd_h1_m15_v1',
            'symbol' => 'XAUUSD',
            'regime_timeframe' => 'H1',
            'entry_timeframe' => 'M15',
            'run_key' => hash('sha256', 'ablation-control'),
            'data_hash' => $dataHash,
            'execution_hash' => str_repeat('b', 64),
            'status' => 'completed',
            'variants' => [
                'm15_only' => ['total_trades' => 90, 'profit_factor' => 1.20, 'net_profit_percent' => 4.0, 'max_drawdown_percent' => 4.0, 'winrate' => 48],
                'h1_veto_m15_risk' => ['total_trades' => 32, 'profit_factor' => 1.10, 'net_profit_percent' => 2.0, 'max_drawdown_percent' => 4.5, 'winrate' => 42],
            ],
            'promotion_evidence' => false,
            'completed_at' => now(),
        ]);
        for ($index = 1; $index <= 3; $index++) {
            MtfStrategyResearchRun::create([
                'pilot_id' => 'xauusd_h1_m15_v1',
                'symbol' => 'XAUUSD',
                'regime_timeframe' => 'H1',
                'entry_timeframe' => 'M15',
                'hypothesis_key' => 'differential_trend_up_v2_'.$index,
                'strategy_identity' => 'differential_router_v1',
                'strategy_family' => 'differential_router',
                'run_key' => hash('sha256', 'research-'.$index),
                'data_hash' => $dataHash,
                'parameter_hash' => str_repeat('a', 64),
                'execution_hash' => str_repeat('b', 64),
                'status' => 'completed',
                'research_contract' => ['mutation_class' => 'directional_specialist', 'target_gate' => 'trend_up_stability'],
                'parameters' => ['differential_target_regime' => 'trend_up'],
                'result' => [
                    'variants' => [
                        'h1_only' => ['total_trades' => 40, 'profit_factor' => 1.0, 'net_profit_percent' => 1.0, 'max_drawdown_percent' => 5.0, 'winrate' => 40],
                        'm15_only' => ['total_trades' => 90, 'profit_factor' => 1.20, 'net_profit_percent' => 4.0, 'max_drawdown_percent' => 4.0, 'winrate' => 48],
                        'h1_regime_m15' => ['total_trades' => 35, 'profit_factor' => 1.0, 'net_profit_percent' => 1.0, 'max_drawdown_percent' => 5.0, 'winrate' => 40],
                        'h1_veto_m15_risk' => [
                            'total_trades' => 32, 'profit_factor' => 0.80, 'net_profit_percent' => -1.0,
                            'max_drawdown_percent' => 4.2, 'winrate' => 35,
                            'mtf_pilot' => ['veto_count' => 140, 'context_counts' => ['trend_up' => 80, 'trend_down' => 42, 'range' => 20]],
                        ],
                    ],
                    'frozen_control' => [
                        'run_id' => 1,
                        'm15_only' => ['total_trades' => 90, 'profit_factor' => 1.20, 'net_profit_percent' => 4.0, 'max_drawdown_percent' => 4.0, 'winrate' => 48],
                        'official_mtf' => ['total_trades' => 32, 'profit_factor' => 1.10, 'net_profit_percent' => 2.0, 'max_drawdown_percent' => 4.5, 'winrate' => 42],
                    ],
                    'promotion_evidence' => false,
                ],
                'promotion_evidence' => false,
                'completed_at' => now()->subMinutes(3 - $index),
            ]);
        }

        $report = app(MtfStrategyResearchReportService::class)->report('XAUUSD', 720);

        $this->assertSame(3, $report['run_count']);
        $this->assertSame('mtf_entry_starvation', $report['runs'][0]['classification']);
        $this->assertTrue($report['runs'][0]['high_veto_pressure']);
        $this->assertSame('pause_research_family', $report['family_budget']['differential_router']['status']);
        $this->assertTrue(collect($report['next_research_actions'])->contains(fn (string $action): bool => str_contains(strtolower($action), 'never relax')));
        $this->assertFalse($report['promotion_evidence']);

        $this->expectException(LogicException::class);
        MtfStrategyResearchRun::query()->firstOrFail()->update(['status' => 'promoted']);
    }

    public function test_council_research_is_lighthouse_only(): void
    {
        $this->artisan('trading:mtf-council-research', ['--symbol' => 'EURUSD'])
            ->assertExitCode(1);
    }

    public function test_mtf_snapshot_is_immutable_and_integrity_checked(): void
    {
        $service = app(MtfResearchSnapshotService::class);
        $runKey = str_repeat('a', 64);
        $reference = $service->store(
            $runKey,
            'XAUUSD',
            [['time' => '2025-08-01 00:00:00', 'open' => 1, 'high' => 2, 'low' => 0.5, 'close' => 1.5, 'volume' => 1, 'volume_available' => true]],
            [['time' => '2025-08-01 00:00:00', 'open' => 1, 'high' => 2, 'low' => 0.5, 'close' => 1.5, 'volume' => 1, 'volume_available' => true]],
            ['status' => 'passed', 'symbol' => 'XAUUSD'],
            ['spread_points' => 35],
            str_repeat('b', 64),
            str_repeat('c', 64),
        );
        $run = new MtfAblationRun([
            'run_key' => $runKey,
            'data_hash' => str_repeat('b', 64),
            'execution_hash' => str_repeat('c', 64),
            'snapshot_reference' => $reference,
        ]);

        $this->assertNotNull($service->load($run));
        $snapshotPath = storage_path('app/'.$reference['path']);
        $snapshot = json_decode((string) File::get($snapshotPath), true);
        $snapshot['volume_context']['status'] = 'tampered';
        File::put($snapshotPath, json_encode($snapshot, JSON_UNESCAPED_SLASHES));
        $this->assertNull($service->load($run));
        File::delete($snapshotPath);
    }
}
