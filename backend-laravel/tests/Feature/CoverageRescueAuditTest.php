<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\CandidateGateDecision;
use App\Services\CoverageRescueAuditService;
use App\Services\LabPopulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoverageRescueAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_falls_back_to_newest_completed_generation_with_eligible_sparse_coverage(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'XAUUSD Lab',
            'timeframe' => 'H1',
            'strategy_families' => ['breakout'],
            'is_active' => true,
        ]);

        $older = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 10,
            'trigger_type' => 'full_validation',
            'status' => 'completed',
            'population_size' => 20,
        ]);
        LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 11,
            'trigger_type' => 'screening',
            'status' => 'completed',
            'population_size' => 20,
        ]);

        $model = ModelVersion::create([
            'name' => 'coverage_parent_g10',
            'status' => 'testing',
            'parameters' => ['breakout_lookback' => 20],
            'metadata' => [],
            'evidence_status' => 'valid',
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $older->id,
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'breakout',
            'origin' => 'full_validation',
            'lifecycle_status' => 'challenger',
            'sample_count' => 40,
        ]);
        ModelMarketPerformance::create([
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'breakout',
            'status' => 'challenger',
            'evidence_status' => 'valid',
            'forward_score' => 80,
            'sample_count' => 40,
            'rolling_windows_count' => 4,
            'rolling_forward_wins' => 4,
            'metrics' => [
                'profit_factor' => 1.6,
                'is_overfit' => false,
                'total_trades' => 40,
                'pf_attribution' => ['stress_cost' => ['profit_factor' => 1.2]],
                'regime_performance' => [
                    'trend_up' => ['trades' => 12],
                    'trend_down' => ['trades' => 14],
                    'range' => ['trades' => 14],
                ],
                'certified_coverage_passport' => [
                    'status' => 'assessed',
                    'certified_cells' => 0,
                    'uncertified_cells' => 1,
                    'cells' => [
                        'trend_up|normal_volatility|1|BUY' => [
                            'regime' => 'trend_up',
                            'volatility' => 'normal_volatility',
                            'session_utc_hour' => '1',
                            'direction' => 'BUY',
                            'trade_permission' => 'NOT_CERTIFIED',
                            'abstain_permission' => 'NOT_CERTIFIED',
                            'trade_count' => 4,
                            'missed_profitable_opportunities' => 2,
                        ],
                    ],
                ],
            ],
        ]);

        $audit = app(CoverageRescueAuditService::class)->audit('XAUUSD');

        $this->assertTrue($audit['eligible']);
        $this->assertSame(10, $audit['generation']);
        $this->assertSame([$agent->id], $audit['parent_agent_ids']);
        $this->assertCount(2, $audit['searched_generations']);
        $this->assertSame('newest_generation_with_valid_full_replay_parent_with_uncertified_cells', $audit['edge_evidence']['selection']);
    }

    public function test_council_keeps_a_rejected_failure_source_as_context_but_uses_a_validated_family_parent(): void
    {
        $service = app(LabPopulationService::class);
        $seed = $service->build('XAUUSD', 'parent_selection_seed', true);
        $seed->update(['status' => 'completed']);

        $defaults = app(\App\Services\StrategyParameterSchemaService::class)->defaults('differential_router');
        $rejectedSource = ModelVersion::create([
            'name' => 'rejected-context-source',
            'strategy' => 'xauusd_rejected_context_source',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => $defaults,
            'metadata' => [],
            'evidence_status' => 'valid',
        ]);
        $sourcePerformance = ModelMarketPerformance::create([
            'model_version_id' => $rejectedSource->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'differential_router',
            'status' => 'rejected',
            'evidence_status' => 'valid',
            'metrics' => [
                'profit_factor' => 0.8,
                'entry_funnel' => ['dominant_rejection' => 'loss_cooldown'],
                'pf_attribution' => [
                    'breakdown' => ['by_regime_volatility' => [
                        'trend_up|high_volatility' => ['trades' => 10, 'net_pf' => 0.8],
                        'trend_down|normal_volatility' => ['trades' => 10, 'net_pf' => 0.8],
                        'range|low_volatility' => ['trades' => 10, 'net_pf' => 0.8],
                    ]],
                ],
                'is_overfit' => true,
            ],
        ]);
        CandidateGateDecision::create([
            'model_market_performance_id' => $sourcePerformance->id,
            'stage' => 'statistical_forward_gate',
            'decision' => 'failed',
            'reason_codes' => ['FAILED_OVERFIT'],
            'metrics' => [],
            'evaluated_at' => now(),
        ]);

        $validatedParent = ModelVersion::create([
            'name' => 'validated-differential-parent',
            'strategy' => 'xauusd_validated_differential_parent',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => $defaults,
            'metadata' => [
                'lab_symbol' => 'XAUUSD',
                'lab_timeframe' => 'H1',
                'semantic_group' => app(\App\Services\StrategySemanticGroupService::class)->descriptor(
                    'XAUUSD',
                    'H1',
                    'differential_router',
                    [
                        'role' => 'trend_up_specialist',
                        'specialist_role' => 'trend_up_specialist',
                        'regime' => 'trend_up',
                        'volatility' => 'high_volatility',
                    ],
                ),
            ],
            'evidence_status' => 'valid',
        ]);
        ModelMarketPerformance::create([
            'model_version_id' => $validatedParent->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'differential_router',
            'status' => 'challenger',
            'evidence_status' => 'valid',
            'forward_score' => 70,
            'sample_count' => 40,
            'rolling_windows_count' => 4,
            'rolling_forward_wins' => 4,
            'metrics' => [
                'profit_factor' => 1.5,
                'max_drawdown_percent' => 8,
                'is_overfit' => false,
                'monte_carlo' => ['risk_of_ruin_percent' => 0],
                'statistical_evidence' => [
                    'edge_quality' => [
                        'bootstrap_pf' => ['status' => 'assessed', 'pf_5_percentile_lower_bound' => 1.1],
                        'worst_regime_sampled' => true,
                        'worst_regime_pf' => 1.1,
                    ],
                ],
                'behavioral_diversity' => ['status' => 'distinct'],
                'forward_protocol' => [
                    'status' => 'confirmed',
                    'independent_windows' => 3,
                ],
            ],
        ]);

        $generation = $service->build('XAUUSD', 'parent_selection_role_complete', true, 'H1', [], true);
        $roleAgent = $generation->agents->firstWhere('origin', 'council_role_complete');

        $this->assertNotNull($roleAgent);
        $this->assertSame($validatedParent->id, $roleAgent->parent_a_model_version_id);
        $this->assertSame($sourcePerformance->id, data_get($roleAgent->modelVersion->metadata, 'portfolio_council_parent_selection.requested_failure_source_performance_id'));
        $this->assertSame('validated_frontier_fallback_from_failure_context', data_get($roleAgent->modelVersion->metadata, 'portfolio_council_parent_selection.selection'));
        $this->assertTrue(data_get($roleAgent->modelVersion->metadata, 'portfolio_council_parent_selection.failure_context_remains_diagnostic_only'));
    }

    public function test_eligible_coverage_rescue_can_open_at_the_screened_handoff_boundary(): void
    {
        $service = app(LabPopulationService::class);
        $seed = $service->build('XAUUSD', 'coverage_rescue_seed', true);
        $seed->update(['status' => 'screened']);
        $parent = ModelVersion::create([
            'name' => 'coverage-rescue-frozen-parent',
            'strategy' => 'xauusd_breakout_coverage_parent',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => ['breakout_lookback' => 20],
            'metadata' => [],
            'evidence_status' => 'valid',
        ]);

        $audit = [
            'protocol' => CoverageRescueAuditService::PROTOCOL,
            'eligible' => true,
            'failure' => 'operating_envelope_coverage_sparse',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'uncertified_cells' => [[
                'key' => 'trend_up|normal_volatility|1|BUY',
                'regime' => 'trend_up',
                'volatility' => 'normal_volatility',
                'session_utc_hour' => '1',
                'direction' => 'BUY',
                'parent_model_version_id' => null,
            ]],
            'parent_model_version_ids' => [$parent->id],
        ];

        $generation = $service->build('XAUUSD', 'coverage_rescue', true, 'H1', $audit);

        $this->assertNotNull($generation);
        $this->assertSame('coverage_rescue', $generation->trigger_type);
        $this->assertCount(20, $generation->agents);
    }
}
