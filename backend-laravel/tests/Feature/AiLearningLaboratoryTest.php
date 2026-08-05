<?php

namespace Tests\Feature;

use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\LabPopulationService;
use App\Services\LabGenerationReportService;
use App\Services\LabCandidateSelectionService;
use App\Services\LabAgentEvaluationService;
use App\Services\ScreeningLearningOutboxService;
use App\Services\CandidateHandoffService;
use Illuminate\Support\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiLearningLaboratoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_pair_gets_a_bounded_owned_twenty_agent_population(): void
    {
        $service = app(LabPopulationService::class);
        $xau = $service->build('XAUUSD', 'new_data', true);
        $eur = $service->build('EURUSD', 'new_data', true);

        $this->assertCount(20, $xau->agents);
        $this->assertCount(20, $eur->agents);
        // G98 is a bounded failure-eliminator population: all 20 slots are
        // one-gene robustness experiments, with four seats per layer. It no
        // longer spends promotion budget on random/PF-only explorers.
        $this->assertSame(20, $xau->agents->where('origin', 'g98_council')->count());
        foreach (['monthly_survival', 'regime_coverage', 'volatility_session_stability', 'exit_topology', 'portfolio_router'] as $target) {
            $this->assertSame(4, $xau->agents->where('origin', 'g98_council')->filter(
                fn (LabAgent $agent) => data_get($agent->modelVersion->metadata, 'generation_target') === $target
            )->count());
        }
        $isolated = $xau->agents->firstWhere('origin', 'g98_council');
        $this->assertCount(1, $isolated->parameter_diff);
        $this->assertSame('isolated_single_gene', data_get($isolated->modelVersion->metadata, 'causal_experiment_lane.status'));
        $this->assertTrue(data_get($isolated->modelVersion->metadata, 'g98_council_lane.parent_lane_freeze'));
        $this->assertSame('g98_failure_eliminator_v1', data_get($xau->trigger_context, 'generation_protocol'));
        $this->assertTrue($xau->agents->every(fn (LabAgent $agent) => $agent->lifecycle_status === 'draft'));
        $this->assertContains(data_get($xau->agents->first()->modelVersion->metadata, 'generation_target'), ['monthly_survival', 'regime_coverage', 'volatility_session_stability', 'exit_topology', 'portfolio_router']);
        $this->assertTrue($xau->agents->every(fn (LabAgent $agent) => str_starts_with($agent->modelVersion->strategy, 'xauusd_')));
        $this->assertTrue($eur->agents->every(fn (LabAgent $agent) => str_starts_with($agent->modelVersion->strategy, 'eurusd_')));
        $this->assertEqualsCanonicalizing(['breakout', 'differential_router', 'hybrid', 'regime_ensemble', 'trend', 'volatility'], $xau->agents->pluck('strategy_family')->unique()->all());
        $this->assertEqualsCanonicalizing(['hybrid', 'mean_reversion', 'regime_ensemble', 'session', 'trend'], $eur->agents->pluck('strategy_family')->unique()->all());
    }

    public function test_generation_is_not_repeated_without_enough_new_data(): void
    {
        $service = app(LabPopulationService::class);
        $this->assertNotNull($service->build('GBPUSD', 'new_data', false));
        $this->assertNull($service->build('GBPUSD', 'new_data', false));
        $this->assertDatabaseCount('lab_generations', 1);
    }

    public function test_single_hour_drift_does_not_create_a_generation_storm(): void
    {
        $service = app(LabPopulationService::class);
        $this->assertNotNull($service->build('GBPUSD', 'new_data', true));

        $this->assertNull($service->build('GBPUSD', 'market_drift'));
        $this->assertDatabaseCount('lab_generations', 1);
    }

    public function test_force_cannot_create_a_second_generation_while_the_current_one_is_active(): void
    {
        $service = app(LabPopulationService::class);
        $first = $service->build('XAUUSD', 'new_data', true);

        $this->assertNotNull($first);
        $this->assertNull($service->build('XAUUSD', 'operator_retry', true));
        $this->assertDatabaseCount('lab_generations', 1);
    }

    public function test_candidate_handoff_can_create_targeted_generation_after_screening_finishes(): void
    {
        $service = app(LabPopulationService::class);
        $source = $service->build('XAUUSD', 'new_data', true);
        $source->update(['status' => 'screened']);

        $targeted = $service->build('XAUUSD', 'candidate_handoff');

        $this->assertNotNull($targeted);
        $this->assertSame('candidate_handoff', $targeted->trigger_type);
        $this->assertSame(2, $targeted->generation);
    }

    public function test_data_edge_audit_trigger_requires_durable_audit_evidence(): void
    {
        $service = app(LabPopulationService::class);
        $generation = $service->build('XAUUSD', 'new_data', true);
        $generation->update([
            'status' => 'completed',
            'trigger_context' => [
                ...($generation->trigger_context ?? []),
                'latest_generation_report' => ['next_action' => 'data_edge_audit_required'],
            ],
        ]);

        $this->assertNull($service->build('XAUUSD', 'new_data', true));
        $this->assertNull($service->build('XAUUSD', 'data_edge_audit', true));

        $generation->update(['trigger_context' => [
            ...($generation->trigger_context ?? []),
            'data_edge_audit' => [
                'protocol' => 'data_edge_audit_v1',
                'finding' => 'regime and session edge audit completed',
            ],
        ]]);

        $this->assertNotNull($service->build('XAUUSD', 'data_edge_audit', true));
    }

    public function test_data_edge_audit_can_unlock_a_completed_screened_generation(): void
    {
        $service = app(LabPopulationService::class);
        $generation = $service->build('XAUUSD', 'new_data', true);
        $generation->update(['status' => 'screened']);
        $generation->agents()->update(['lifecycle_status' => 'screened']);

        $this->artisan('trading:lab-data-edge-audit', [
            'symbol' => 'XAUUSD',
            '--timeframe' => 'H1',
            '--generation' => 1,
            '--finding' => 'Historical H1 data is gap-free; G103 failed calendar, stress, temporal, and regime gates; next research must diversify families and niches.',
        ])->assertExitCode(0);

        $this->assertSame('data_edge_audit_v1', data_get($generation->fresh()->trigger_context, 'data_edge_audit.protocol'));
    }

    public function test_forward_gate_failure_opens_a_targeted_evolution_handoff(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'new_data', true);
        $generation->agents()->update(['lifecycle_status' => 'challenger']);

        $event = app(CandidateHandoffService::class)->noForwardCandidate($generation);

        $this->assertSame('waiting_for_targeted_generation', $event->stage);
        $this->assertSame('NO_FORWARD_VALIDATED_CANDIDATE', $event->terminal_reason);
        $this->assertSame('forward_failure_profile_v1', data_get($event->payload, 'forward_failure_profile.protocol'));
        $this->assertDatabaseHas('agent_failure_cases', ['failure_type' => 'edge_pf_signal_quality']);
    }

    public function test_generation_report_is_durable_and_contains_required_kpis(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'report_contract', true);
        $report = app(LabGenerationReportService::class)->record($generation, 'screening_completed');

        $this->assertSame('lab_generation_report_v1', $report['protocol']);
        $this->assertArrayHasKey('best_agent', $report);
        $this->assertArrayHasKey('parent_delta', $report);
        $this->assertArrayHasKey('gate_failures', $report);
        $this->assertArrayHasKey('technical_errors', $report);
        $this->assertArrayHasKey('mutation_targets', $report);
        $this->assertArrayHasKey('technical_completion_rate', $report['kpis']);
        $this->assertSame('screening_completed', data_get($generation->fresh()->trigger_context, 'latest_generation_report.phase'));
    }

    public function test_pair_laboratory_dashboard_renders_learning_evidence(): void
    {
        app(LabPopulationService::class)->build('XAUUSD', 'market_drift', true);
        $this->get(route('ai-laboratory.show', 'XAUUSD'))
            ->assertOk()->assertSee('XAUUSD Lab')->assertSee('Generation population')
            ->assertSee('Generation bo‘yicha forward performance')->assertSee('Full replay funnel')
            ->assertSee('Candidate gate decision ledger')->assertSee('20/20');
    }

    public function test_low_quality_challenger_is_never_used_as_a_parent(): void
    {
        $weak = ModelVersion::create([
            'name' => 'weak-trend', 'strategy' => 'xauusd_trend_g1_a01', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => app(\App\Services\StrategyParameterSchemaService::class)->defaults('trend'),
            'metadata' => [], 'evidence_status' => 'valid',
        ]);
        ModelMarketPerformance::create([
            'model_version_id' => $weak->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'status' => 'challenger', 'evidence_status' => 'valid', 'forward_score' => 99, 'sample_count' => 80,
            'rolling_windows_count' => 3, 'rolling_forward_wins' => 3,
            'metrics' => ['profit_factor' => 1.1, 'max_drawdown_percent' => 8, 'is_overfit' => false, 'monte_carlo' => ['risk_of_ruin_percent' => 5]],
        ]);

        $generation = app(LabPopulationService::class)->build('XAUUSD', 'market_drift', true);
        $this->assertFalse($generation->agents->contains(
            fn (LabAgent $agent) => $agent->parent_a_model_version_id === $weak->id
        ));
    }

    public function test_laboratory_dashboard_explains_a_candidate_forward_gate_failure(): void
    {
        $model = ModelVersion::create([
            'name' => 'weak-breakout', 'strategy' => 'xauusd_breakout_g1_a01', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => app(\App\Services\StrategyParameterSchemaService::class)->defaults('breakout'),
            'metadata' => [], 'evidence_status' => 'valid',
        ]);
        ModelMarketPerformance::create([
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'breakout',
            'status' => 'challenger', 'evidence_status' => 'valid', 'forward_score' => 50, 'sample_count' => 80,
            'rolling_windows_count' => 3, 'rolling_forward_wins' => 3,
            'metrics' => ['profit_factor' => 1.1, 'max_drawdown_percent' => 8, 'is_overfit' => false, 'monte_carlo' => ['risk_of_ruin_percent' => 5]],
        ]);

        $this->get(route('ai-laboratory.show', 'XAUUSD'))
            ->assertOk()->assertSee('Forward-gate diagnostics')->assertSee('PF >= 1.30');
    }

    public function test_strategy_architecture_is_a_dynamic_gene_not_just_a_parameter_label(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'architecture_evolution', true);
        $trend = $generation->agents->where('strategy_family', 'trend')->map(
            fn (LabAgent $agent) => data_get($agent->modelVersion->metadata, 'strategy_architecture')
        )->unique()->values();

        $this->assertContains('trend_pullback', $trend);
        $this->assertContains('trend_breakout_retest', $trend);
        $this->assertTrue($generation->agents->every(fn (LabAgent $agent) => filled(data_get($agent->modelVersion->metadata, 'base_strategy'))));
    }

    public function test_dynamic_frontier_does_not_spend_full_replay_on_two_trade_luck(): void
    {
        $agents = collect([
            (object) ['id' => 1, 'sample_count' => 2, 'profit_factor' => 17.8, 'forward_score' => 35, 'max_drawdown' => .1, 'risk_of_ruin' => 0, 'modelVersion' => null],
            (object) ['id' => 2, 'sample_count' => 12, 'profit_factor' => 1.2, 'forward_score' => 12, 'max_drawdown' => 2, 'risk_of_ruin' => 0, 'modelVersion' => null],
        ]);

        $selected = app(LabCandidateSelectionService::class)->select($agents);

        $this->assertSame([2], $selected->pluck('id')->all());
    }

    public function test_causal_probe_lane_can_replay_a_near_miss_without_promotion(): void
    {
        $agents = collect([
            (object) ['id' => 11, 'origin' => 'causal_isolation', 'sample_count' => 12, 'profit_factor' => 1.5, 'forward_score' => 10, 'max_drawdown' => 3, 'modelVersion' => (object) ['metadata' => ['last_screen_result' => ['opportunity_metrics' => ['valid_signal_opportunities' => 80]]]]],
            (object) ['id' => 12, 'origin' => 'gate_targeted', 'sample_count' => 18, 'profit_factor' => .8, 'max_drawdown' => 2, 'modelVersion' => (object) ['metadata' => ['last_screen_result' => ['opportunity_metrics' => ['valid_signal_opportunities' => 80]]]]],
        ]);

        $probes = app(LabCandidateSelectionService::class)->selectCausalProbes($agents);

        $this->assertSame([11], $probes->pluck('id')->all());
    }

    public function test_causal_probe_bundle_adds_a_same_family_control(): void
    {
        $agents = collect([
            (object) ['id' => 21, 'origin' => 'causal_isolation', 'strategy_family' => 'trend', 'sample_count' => 12, 'profit_factor' => 1.5, 'forward_score' => 10, 'max_drawdown' => 3, 'modelVersion' => (object) ['metadata' => ['last_screen_result' => ['opportunity_metrics' => ['valid_signal_opportunities' => 80]]]]],
            (object) ['id' => 22, 'origin' => 'gate_targeted', 'strategy_family' => 'trend', 'sample_count' => 12, 'profit_factor' => .9, 'max_drawdown' => 4, 'modelVersion' => (object) ['metadata' => ['last_screen_result' => ['opportunity_metrics' => ['valid_signal_opportunities' => 80]]]]],
        ]);

        $bundle = app(LabCandidateSelectionService::class)->selectCausalProbeBundle($agents);

        $this->assertEqualsCanonicalizing([21, 22], $bundle->pluck('id')->all());
    }

    public function test_strong_calendar_near_miss_can_enter_sealed_portfolio_research_lane(): void
    {
        $model = ModelVersion::create([
            'name' => 'calendar-near-miss', 'strategy' => 'xauusd_hybrid_g95_a01', 'version' => 'v1', 'generation' => 95,
            'status' => 'testing', 'parameters' => app(\App\Services\StrategyParameterSchemaService::class)->defaults('hybrid'),
            'metadata' => [
                'portfolio_council_lane' => [
                    'protocol' => 'portfolio_council_v1', 'regime' => 'range', 'volatility' => 'low_volatility',
                ],
                'parameter_fingerprint' => 'calendar-near-miss-fingerprint',
                'last_screen_result' => [
                    'screening_survival' => [
                        'reason_codes' => ['FAILED_CALENDAR_MONTH_SURVIVAL'],
                        'stress_cost_pf' => 1.15, 'train_forward_gap' => 6,
                    ],
                    'opportunity_metrics' => ['valid_signal_opportunities' => 120],
                    'pf_attribution' => [
                        'breakdown' => ['by_regime_volatility' => [
                            'range|low_volatility' => ['trades' => 12, 'net_pf' => 2.10],
                        ]],
                    ],
                ],
            ],
            'evidence_status' => 'valid',
        ]);
        $agent = (object) [
            'id' => 9901, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'gate_targeted', 'sample_count' => 60, 'profit_factor' => 1.80,
            'forward_score' => 30, 'max_drawdown' => 8, 'risk_of_ruin' => 0, 'modelVersion' => $model,
        ];
        $badModel = ModelVersion::create([
            'name' => 'stress-near-miss', 'strategy' => 'xauusd_hybrid_g95_a02', 'version' => 'v1', 'generation' => 95,
            'status' => 'testing', 'parameters' => app(\App\Services\StrategyParameterSchemaService::class)->defaults('hybrid'),
            'metadata' => [
                'portfolio_council_lane' => ['protocol' => 'portfolio_council_v1', 'regime' => 'range', 'volatility' => 'low_volatility'],
                'parameter_fingerprint' => 'stress-near-miss-fingerprint',
                'last_screen_result' => [
                    'screening_survival' => ['reason_codes' => ['FAILED_STRESS_COST'], 'stress_cost_pf' => 0.99, 'train_forward_gap' => 4],
                    'opportunity_metrics' => ['valid_signal_opportunities' => 120],
                    'pf_attribution' => ['breakdown' => ['by_regime_volatility' => ['range|low_volatility' => ['trades' => 12, 'net_pf' => 2.10]]]],
                ],
            ],
            'evidence_status' => 'valid',
        ]);
        $badAgent = (object) [
            'id' => 9902, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'breakout',
            'origin' => 'gate_targeted', 'sample_count' => 60, 'profit_factor' => 1.80,
            'forward_score' => 30, 'max_drawdown' => 8, 'risk_of_ruin' => 0, 'modelVersion' => $badModel,
        ];

        $members = app(LabCandidateSelectionService::class)->selectPortfolioMembers(collect([$agent, $badAgent]));

        $this->assertSame([9901], $members->pluck('id')->all());
        $this->assertSame('portfolio_member_research_v1', data_get($model->fresh()->metadata, 'portfolio_research_contract.protocol'));
        $this->assertSame('range', data_get($model->fresh()->metadata, 'portfolio_research_contract.target_regime'));
    }

    public function test_directional_temporal_rescue_is_research_only_and_keeps_side_evidence(): void
    {
        $lab = app(LabPopulationService::class)->build('XAUUSD', 'directional_evidence', true)->laboratory;
        $model = ModelVersion::create([
            'name' => 'directional-source', 'strategy' => 'xauusd_hybrid_directional_source', 'version' => 'v3', 'generation' => 72,
            'status' => 'testing', 'parameters' => app(\App\Services\StrategyParameterSchemaService::class)->defaults('hybrid'),
            'metadata' => ['statistical_gate_version' => 3], 'evidence_status' => 'valid',
        ]);
        ModelMarketPerformance::create([
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'status' => 'stagnated', 'evidence_status' => 'valid', 'forward_score' => 60, 'sample_count' => 80,
            'rolling_windows_count' => 3, 'rolling_forward_wins' => 2,
            'metrics' => [
                'pf_attribution' => [
                    'stress_cost' => ['profit_factor' => 1.13],
                    'breakdown' => [
                        'by_temporal_chunk' => [
                            'chunk_1' => ['trades' => 20, 'net_pf' => .79],
                            'chunk_2' => ['trades' => 30, 'net_pf' => 1.80],
                        ],
                        'by_regime_volatility_direction' => [
                            'trend_up|normal_volatility' => [
                                'SELL' => ['trades' => 10, 'net_pf' => 1.73],
                                'BUY' => ['trades' => 15, 'net_pf' => .972],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $service = app(LabPopulationService::class);
        $method = new \ReflectionMethod($service, 'evidenceDirectionalComplements');
        $method->setAccessible(true);
        $complements = $method->invoke($service, $lab, []);

        $this->assertCount(1, $complements);
        $this->assertSame('SELL', $complements[0]['direction']);
        $this->assertSame('evidence_directional_temporal_rescue', $complements[0]['reason']);
        $this->assertSame(.972, $complements[0]['opposite_profit_factor']);
    }

    public function test_non_differential_agent_does_not_receive_a_paired_regression_failure(): void
    {
        $service = app(LabAgentEvaluationService::class);
        $method = new \ReflectionMethod($service, 'appendDifferentialNoRegressionEvidence');
        $method->setAccessible(true);
        $model = new ModelVersion(['metadata' => ['base_strategy' => 'regime_ensemble_v1']]);
        $result = ['total_trades' => 24, 'profit_factor' => 1.4];

        $output = $method->invoke($service, $model, $result);

        $this->assertArrayNotHasKey('differential_no_regression', $output);
    }

    public function test_generated_trend_parameters_preserve_indicator_relationships(): void
    {
        $schema = app(\App\Services\StrategyParameterSchemaService::class);
        $normalized = $schema->normalizeForGeneration('trend', [
            'ema_fast' => 172, 'ema_slow' => 13,
            'rsi_period' => 14, 'rsi_buy_min' => 72.0, 'rsi_buy_max' => 41.0,
            'rsi_sell_min' => 65.0, 'rsi_sell_max' => 28.0,
        ] + $schema->defaults('trend'));

        $this->assertLessThan($normalized['ema_slow'], $normalized['ema_fast']);
        $this->assertLessThanOrEqual($normalized['rsi_buy_max'], $normalized['rsi_buy_min']);
        $this->assertLessThanOrEqual($normalized['rsi_sell_max'], $normalized['rsi_sell_min']);
    }

    public function test_trend_up_council_uses_directional_single_gene_rescue_variants(): void
    {
        $service = app(LabPopulationService::class);
        $method = new \ReflectionMethod($service, 'differentialSingleGene');
        $method->setAccessible(true);
        $base = app(\App\Services\StrategyParameterSchemaService::class)->defaults('differential_router');

        $monthly = $method->invoke($service, $base, 1, 'trend_up', 'monthly_survival');
        $temporal = $method->invoke($service, $base, 2, 'trend_up', 'temporal_stability');
        $calendar = $method->invoke($service, $base, 3, 'trend_up', 'calendar_context_rescue');
        $transition = $method->invoke($service, $base, 4, 'trend_up', 'transition_firewall');

        $this->assertSame('trend_up', $monthly['differential_target_regime']);
        $this->assertSame('v2', $monthly['differential_router_version']);
        $this->assertEqualsWithDelta((float) $base['trend_up_roc_threshold'] - .05, (float) $monthly['trend_up_roc_threshold'], 0.0001);
        $this->assertSame((int) $base['trend_up_roc_period'] + 2, (int) $temporal['trend_up_roc_period']);
        $this->assertSame((int) $base['trend_up_ema_period'] + 10, (int) $calendar['trend_up_ema_period']);
        $this->assertSame($base['trend_up_strength_min'], $monthly['trend_up_strength_min']);
        $this->assertSame($base['trend_up_pullback_atr_fraction'], $calendar['trend_up_pullback_atr_fraction']);
        $this->assertTrue($transition['transition_firewall_enabled']);
    }

    public function test_council_objectives_select_distinct_range_topologies(): void
    {
        $service = app(LabPopulationService::class);
        $method = new \ReflectionMethod($service, 'rangeCouncilSingleGene');
        $method->setAccessible(true);
        $base = app(\App\Services\StrategyParameterSchemaService::class)->defaults('hybrid');

        $monthly = $method->invoke($service, $base, 4, 'monthly_survival');
        $temporal = $method->invoke($service, $base, 5, 'temporal_stability');
        $context = $method->invoke($service, $base, 6, 'calendar_context_rescue');
        $transition = $method->invoke($service, $base, 7, 'transition_firewall');

        $this->assertSame('mean_reversion', $monthly['range_signal_mode']);
        $this->assertSame((float) $base['range_deviation'] - .2, (float) $temporal['range_deviation']);
        $this->assertFalse($context['range_reentry_required']);
        $this->assertSame($base['trend_weight'], $context['trend_weight']);
        $this->assertSame($base['breakout_weight'], $context['breakout_weight']);
        $this->assertTrue($transition['transition_firewall_enabled']);
    }

    public function test_screening_curriculum_keeps_ranked_bottlenecks_for_generation_planning(): void
    {
        $service = app(LabPopulationService::class);
        $method = new \ReflectionMethod($service, 'screeningTargetsForReasons');
        $method->setAccessible(true);

        $targets = $method->invoke($service, [
            'FAILED_CALENDAR_MONTH_SURVIVAL' => 4,
            'FAILED_REGIME_COVERAGE' => 4,
            'FAILED_PROFIT_FACTOR' => 3,
            'FAILED_STRESS_COST' => 3,
        ]);

        $this->assertSame(['monthly_survival', 'regime_coverage', 'profit_factor', 'stress_cost'], $targets);
    }

    public function test_screen_learning_outbox_keeps_temporal_screen_failure_inconclusive_and_non_blocking(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'outbox_contract', true);
        $agent = $generation->agents->first();

        app(ScreeningLearningOutboxService::class)->enqueue($agent, [
            'total_trades' => 25,
            'profit_factor' => 1.47,
            'entry_funnel' => ['flat_signal_opportunities' => 50, 'accepted_entries' => 25],
            'screening_survival' => [
                'status' => 'rescue_case',
                'reason_codes' => ['FAILED_TRAIN_FORWARD_GAP'],
            ],
        ], 0.0);

        $this->assertSame(1, app(ScreeningLearningOutboxService::class)->process());
        $this->assertDatabaseHas('screening_learning_outbox', [
            'lab_agent_id' => $agent->id,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('mutation_memories', [
            'lab_agent_id' => $agent->id,
            'outcome' => 'screen_inconclusive',
        ]);
        $memory = \App\Models\MutationMemory::where('lab_agent_id', $agent->id)->latest('id')->firstOrFail();
        $this->assertStringContainsString('screen_survival_failed_train_forward_gap;', (string) $memory->decision);
        $this->assertStringContainsString('no causal credit', (string) $memory->decision);
    }
}
