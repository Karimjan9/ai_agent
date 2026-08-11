<?php

namespace Tests\Feature;

use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\LabPopulationService;
use App\Services\LabGenerationReportService;
use App\Services\LabCandidateSelectionService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ScreeningLearningOutboxService;
use App\Services\CandidateHandoffService;
use Illuminate\Support\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $groupContract = (array) data_get($xau->trigger_context, 'population_group_contract');
        $this->assertSame('population_group_checkpoint_v1', $groupContract['protocol']);
        $this->assertSame(5, $groupContract['core_group_count']);
        $this->assertSame(4, $groupContract['core_seats_per_group']);
        $this->assertTrue($groupContract['balanced_core']);
        $this->assertTrue(data_get($xau->trigger_context, 'specialist_council_contract.global_champion_forbidden'));
        $this->assertTrue($xau->agents->every(
            fn (LabAgent $agent): bool => data_get($agent->modelVersion->metadata, 'population_group.protocol') === 'population_group_checkpoint_v1'
        ));
        $this->assertTrue($xau->agents->groupBy(
            fn (LabAgent $agent): string => (string) data_get($agent->modelVersion->metadata, 'population_group.key')
        )->every(fn ($members): bool => $members->count() === 4));
        $this->assertTrue($xau->agents->groupBy(
            fn (LabAgent $agent): string => (string) data_get($agent->modelVersion->metadata, 'population_group.key')
        )->every(fn ($members): bool => $members->pluck('modelVersion')->filter(
            fn (ModelVersion $model): bool => data_get($model->metadata, 'population_group.search_mode') === 'depth'
        )->count() === 2));
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

    public function test_dispatcher_does_not_reexport_an_already_screening_generation(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'dispatch_lock_test', true);
        $generation->update(['status' => 'screening']);
        $generation->agents()->update(['lifecycle_status' => 'queued']);

        $this->artisan('trading:dispatch-lab', ['symbol' => 'XAUUSD'])
            ->expectsOutput('XAUUSD: generation is already dispatched or evaluated.')
            ->assertExitCode(0);

        $this->assertSame('screening', $generation->fresh()->status);
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
        $this->assertArrayHasKey('population_group_checkpoints', $report);
        $this->assertCount(5, $report['population_group_checkpoints']);
        $this->assertTrue($report['council']['global_champion_forbidden']);
        $this->assertTrue(collect($report['population_group_checkpoints'])->every(
            fn (array $checkpoint): bool => data_get($checkpoint, 'protocol') === 'population_group_checkpoint_v1'
                && data_get($checkpoint, 'checkpoint.singleton_forbidden') === true
                && count((array) data_get($checkpoint, 'frontier_members')) === 4
        ));
        $this->assertArrayHasKey('technical_completion_rate', $report['kpis']);
        $this->assertFalse($report['kpis']['evolution_safe']);
        $this->assertSame(0, $report['kpis']['screening_pass_rate']);
        $this->assertSame('pipeline_not_working', $report['kpis']['screening_failure_classification']);
        $this->assertSame(0, $report['kpis']['independently_confirmed_mutations']);
        $this->assertArrayHasKey('parent_links', $report['kpis']);
        $this->assertArrayHasKey('paper_eligible', $report['kpis']);
        $this->assertSame('screening_completed', data_get($generation->fresh()->trigger_context, 'latest_generation_report.phase'));
    }

    public function test_current_kpis_refreshes_legacy_generation_reports(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'legacy_report_refresh', true);
        $generation->update(['trigger_context' => [
            ...($generation->trigger_context ?? []),
            'latest_generation_report' => [
                'protocol' => 'lab_generation_report_v0',
                'kpis' => ['technical_completion_rate' => 100],
            ],
        ]]);

        $rows = app(LabGenerationReportService::class)->currentKpis('XAUUSD', 'H1');
        $kpis = (array) data_get($rows, '0.kpis', []);

        $this->assertTrue(data_get($rows, '0.next_action') !== 'generation_report_pending');
        foreach ([
            'evolution_safe',
            'screening_pass_rate',
            'full_validation_completion_rate',
            'forward_valid_agents',
            'independently_confirmed_mutations',
            'parent_links',
            'paper_eligible',
            'screening_failure_classification',
        ] as $key) {
            $this->assertArrayHasKey($key, $kpis);
        }
        $this->assertSame('kpi_refresh', data_get($generation->fresh()->trigger_context, 'latest_generation_report.phase'));
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

    public function test_bounded_root_recovery_reaches_full_replay_without_a_forward_score(): void
    {
        $agents = collect([1, 2])->map(function (int $id): object {
            return (object) [
                'id' => $id,
                'origin' => 'lineage_root_rebuild',
                'strategy_family' => $id === 1 ? 'trend' : 'hybrid',
                'lifecycle_status' => 'screened',
                'sample_count' => 20,
                'profit_factor' => 1.0,
                'forward_score' => 0,
                'max_drawdown' => 10,
                'risk_of_ruin' => 5,
                'modelVersion' => (object) ['metadata' => [
                    'recovery_protocol' => ['protocol' => 'bounded_root_recovery_v1'],
                    'last_screen_result' => [
                        'opportunity_metrics' => ['valid_signal_opportunities' => 20],
                    ],
                ]],
            ];
        });

        $selection = app(LabCandidateSelectionService::class)->selectValidationLanes($agents);

        $this->assertEqualsCanonicalizing([1, 2], $selection['agents']->pluck('id')->all());
        $this->assertSame('root_recovery_full_replay', $selection['lanes'][1]);
        $this->assertSame('root_recovery_full_replay', $selection['lanes'][2]);
    }

    public function test_recall_research_reserves_each_regime_before_global_dominance(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'recall_selector_test', true);
        $agents = $generation->agents->take(2)->values();

        foreach ([
            [$agents[0], 'trend_up', ['FAILED_CALENDAR_MONTH_SURVIVAL']],
            [$agents[1], 'trend_down', ['FAILED_STRESS_COST', 'FAILED_CALENDAR_MONTH_SURVIVAL']],
        ] as [$agent, $regime, $reasons]) {
            $model = $agent->modelVersion;
            $metadata = (array) $model->metadata;
            $metadata['g98_council_lane'] = [
                'protocol' => LabPopulationService::GENERATION_PROTOCOL,
                'lane' => 'opportunity_recall',
            ];
            $metadata['portfolio_council_lane'] = [
                'protocol' => 'portfolio_council_v1',
                'regime' => $regime,
                'volatility' => 'normal_volatility',
                'specialist_role' => $regime.'_specialist',
            ];
            $metadata['last_screen_result'] = [
                'screening_survival' => [
                    'status' => 'failed',
                    'reason_codes' => $reasons,
                    'stress_cost_pf' => .90,
                ],
                'opportunity_metrics' => ['valid_signal_opportunities' => 100],
                'pf_attribution' => [
                    'breakdown' => [
                        'by_regime_volatility' => [
                            $regime.'|normal_volatility' => ['trades' => 12, 'net_pf' => 1.20],
                        ],
                    ],
                ],
            ];
            $model->update(['metadata' => $metadata]);
            $agent->update([
                'sample_count' => 40,
                'profit_factor' => 1.35,
                'forward_score' => 20,
                'max_drawdown' => 8,
                'risk_of_ruin' => 4,
            ]);
            \App\Models\CandidateGateDecision::create([
                'lab_agent_id' => $agent->id,
                'stage' => 'screening',
                'decision' => 'failed',
                'reason_codes' => $reasons,
                'metrics' => [],
                'evaluated_at' => now(),
            ]);
        }

        $selection = app(LabCandidateSelectionService::class)->selectValidationLanes(
            LabAgent::with('modelVersion')->whereIn('id', $agents->pluck('id'))->get()
        );

        $this->assertEqualsCanonicalizing($agents->pluck('id')->all(), $selection['agents']->pluck('id')->all());
        $this->assertSame('targeted_research', $selection['lanes'][$agents[0]->id]);
        $this->assertSame('targeted_research', $selection['lanes'][$agents[1]->id]);
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

        $hybridModel = new ModelVersion(['metadata' => ['base_strategy' => 'differential_router_v1']]);
        $hybridOutput = $method->invoke($service, $hybridModel, $result, 'hybrid');

        $this->assertArrayNotHasKey('differential_no_regression', $hybridOutput);
    }

    public function test_full_replay_projection_preserves_sealed_cohort_cache_metadata(): void
    {
        $model = ModelVersion::create([
            'name' => 'sealed-cache-refresh',
            'strategy' => 'sealed-cache-refresh',
            'version' => 'test',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => [],
            'metadata' => ['seed' => true],
        ]);
        DB::table('model_versions')->where('id', $model->id)->update([
            'metadata' => json_encode([
                'seed' => true,
                'full_validation_batch' => [
                    'protocol' => 'sealed_replay_cache_v2',
                    'full_replay_runtime_policy' => ['promotion_evidence' => false],
                ],
            ]),
        ]);

        $service = app(LabAgentEvaluationService::class);
        $method = new \ReflectionMethod($service, 'mergeRefreshedModelMetadata');
        $method->setAccessible(true);
        $metadata = $method->invoke($service, $model, ['last_result' => ['score' => 1]]);

        $this->assertTrue($metadata['seed']);
        $this->assertSame('sealed_replay_cache_v2', data_get($metadata, 'full_validation_batch.protocol'));
        $this->assertFalse(data_get($metadata, 'full_validation_batch.full_replay_runtime_policy.promotion_evidence'));
        $this->assertSame(1, data_get($metadata, 'last_result.score'));
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
        $regimeCoverage = $method->invoke($service, [...$base, 'trend_up_strength_min' => 12], 1, 'trend_up', 'regime_coverage');
        $recall = $method->invoke($service, $base, 1, 'trend_up', 'opportunity_recall');
        $recallEven = $method->invoke($service, $base, 6, 'trend_up', 'opportunity_recall');
        $stateCooldown = $method->invoke($service, $base, 7, 'trend_down', 'opportunity_recall', 'state_conditioned_cooldown');
        $cooldownShortening = $method->invoke($service, $base, 9, 'trend_up', 'opportunity_recall', 'loss_cooldown_shortening');
        $transitionWaitShortening = $method->invoke($service, $base, 11, 'trend_down', 'opportunity_recall', 'transition_wait_shortening');
        $evAblation = $method->invoke($service, $base, 8, 'range', 'opportunity_recall', 'negative_ev_lower_bound_ablation');
        $spreadProbe = $method->invoke($service, $base, 10, 'range', 'opportunity_recall', 'spread_atr_recall_probe');

        $this->assertSame('trend_up', $monthly['differential_target_regime']);
        $this->assertSame('v2', $monthly['differential_router_version']);
        $this->assertEqualsWithDelta((float) $base['trend_up_roc_threshold'] - .05, (float) $monthly['trend_up_roc_threshold'], 0.0001);
        $this->assertSame((int) $base['trend_up_roc_period'] + 2, (int) $temporal['trend_up_roc_period']);
        $this->assertSame((int) $base['trend_up_ema_period'] + 10, (int) $calendar['trend_up_ema_period']);
        $this->assertSame($base['trend_up_strength_min'], $monthly['trend_up_strength_min']);
        $this->assertSame($base['trend_up_pullback_atr_fraction'], $calendar['trend_up_pullback_atr_fraction']);
        $this->assertTrue($transition['transition_firewall_enabled']);
        $this->assertGreaterThanOrEqual(10, $regimeCoverage['trend_up_strength_min']);
        $this->assertEqualsWithDelta(
            (float) $base['differential_target_min_signal_confidence'] - .05,
            (float) $recall['differential_target_min_signal_confidence'],
            0.0001,
        );
        $this->assertEqualsWithDelta(
            (float) $base['differential_target_min_signal_confidence'] - .05,
            (float) $recallEven['differential_target_min_signal_confidence'],
            0.0001,
        );
        $this->assertArrayHasKey('dynamic_cooldown_enabled', $stateCooldown);
        $this->assertNotSame($base['dynamic_cooldown_enabled'], $stateCooldown['dynamic_cooldown_enabled']);
        $this->assertSame($base['trend_down_strength_min'], $stateCooldown['trend_down_strength_min']);
        $this->assertLessThan($base['loss_cooldown_candles'], $cooldownShortening['loss_cooldown_candles']);
        $this->assertSame($base['dynamic_cooldown_enabled'], $cooldownShortening['dynamic_cooldown_enabled']);
        $this->assertLessThan($base['transition_wait_candles'], $transitionWaitShortening['transition_wait_candles']);
        $this->assertSame($base['loss_cooldown_candles'], $transitionWaitShortening['loss_cooldown_candles']);
        $this->assertArrayHasKey('confidence_ev_lower_bound_enabled', $evAblation);
        $this->assertNotSame($base['confidence_ev_lower_bound_enabled'], $evAblation['confidence_ev_lower_bound_enabled']);
        $this->assertSame($base['range_deviation'], $evAblation['range_deviation']);
        $this->assertGreaterThan($base['max_spread_atr_ratio'], $spreadProbe['max_spread_atr_ratio']);
        $this->assertSame($base['confidence_ev_lower_bound_enabled'], $spreadProbe['confidence_ev_lower_bound_enabled']);
    }

    public function test_state_cluster_uses_context_evidence_and_excludes_calendar_months(): void
    {
        $service = app(LabPopulationService::class);
        $method = new \ReflectionMethod($service, 'stateClusterForPerformance');
        $method->setAccessible(true);
        $performance = new \App\Models\ModelMarketPerformance([
            'metrics' => [
                'entry_funnel' => ['dominant_rejection' => 'regime_transition_wait'],
                'veto_regret' => [
                    'by_regime_context' => [
                        'regime_transition_wait|trend_down|normal_volatility|low_liquidity' => [
                            'shadow_trades' => 78,
                        ],
                    ],
                ],
                'transition_homework' => [
                    'transition_events' => 12,
                    'false_entry_rate' => .25,
                ],
                // A month may be present in the source report, but it must
                // never become part of the state-cluster identity.
                'failed_month' => '2026-04',
            ],
        ]);

        $cluster = $method->invoke($service, $performance, 'trend_down', 'normal_volatility');

        $this->assertSame('state_cluster_v1', $cluster['protocol']);
        $this->assertSame('trend_down', $cluster['regime']);
        $this->assertSame('normal_volatility', $cluster['volatility']);
        $this->assertSame('transition_wait', $cluster['transition_state']);
        $this->assertSame('low_liquidity', $cluster['spread_liquidity_state']);
        $this->assertSame('regime_transition_wait', $cluster['veto_reason']);
        $this->assertTrue($cluster['month_labels_are_diagnostic_only']);
        $this->assertArrayNotHasKey('failed_month', $cluster);
        $this->assertArrayNotHasKey('month', $cluster);
    }

    public function test_state_cluster_monthly_mutation_is_one_bounded_context_gene(): void
    {
        $service = app(LabPopulationService::class);
        $schemaService = app(\App\Services\StrategyParameterSchemaService::class);
        $method = new \ReflectionMethod($service, 'stateClusterMonthlyMutation');
        $method->setAccessible(true);
        $base = $schemaService->defaults('differential_router');

        $child = $method->invoke(
            $service,
            $base,
            $schemaService->schema('differential_router'),
            [
                'protocol' => 'state_cluster_v1',
                'status' => 'assessed',
                'transition_state' => 'transition_wait',
                'spread_liquidity_state' => 'low_liquidity',
                'veto_reason' => 'regime_transition_wait',
            ],
        );

        $changed = array_keys(array_filter(
            $child,
            fn ($value, $key): bool => ($base[$key] ?? null) !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        $this->assertSame(['transition_firewall_enabled'], $changed);
        $this->assertTrue($child['transition_firewall_enabled']);
    }

    public function test_agent_knowledge_card_records_lessons_skills_and_child_contract(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'knowledge_card_test', true);
        $agent = $generation->agents->first();
        $model = $agent->modelVersion;
        $parameterKey = array_key_first($agent->parameter_diff);
        $this->assertSame('agent_knowledge_card_v1', data_get($model->metadata, 'agent_knowledge_contract.protocol'));
        $this->assertFalse((bool) data_get($model->metadata, 'agent_knowledge_contract.promotion_evidence'));

        $screen = [
            'entry_funnel' => ['raw_strategy_signals' => 10, 'accepted_entries' => 5],
            'opportunity_metrics' => ['recall' => .10],
            'transition_homework' => ['transition_trades' => 20, 'false_entry_rate' => .70, 'abstention_quality' => 30],
            'pf_attribution' => ['breakdown' => [
                'by_regime' => [
                    'trend_up' => ['trades' => 12, 'net_pf' => 1.40],
                    'range' => ['trades' => 11, 'net_pf' => 1.35],
                ],
                'by_regime_volatility' => [
                    'trend_up|high_volatility' => ['trades' => 12, 'net_pf' => 1.40],
                    'range|low_volatility' => ['trades' => 11, 'net_pf' => 1.35],
                ],
            ]],
            'epistemic_boundary' => ['unknown_state_action' => 'WAIT'],
        ];
        $screen['evidence_run_id'] = $this->completeEvidenceRun($agent);
        $screenCard = app(\App\Services\AgentKnowledgeService::class)->recordScreening(
            $agent->fresh(['modelVersion', 'generation']), $screen, $screen['evidence_run_id']
        );

        $this->assertSame('novice', $screenCard->skill_stage);
        $this->assertCount(2, $screenCard->strong_state_clusters);
        $this->assertSame('provisional', $screenCard->abstention_status);
        $this->assertSame('WAIT', $screenCard->unknown_state_action);

        \App\Models\MutationMemory::create([
            'lab_agent_id' => $agent->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => $agent->strategy_family, 'parameter_key' => $parameterKey,
            'old_value' => ['value' => 1], 'new_value' => ['value' => 2],
            'forward_delta' => -8, 'market_regime' => 'trend_up', 'outcome' => 'harmful',
            'confidence' => 85, 'decision' => 'confirmed harmful lesson',
            'independent_confirmation_count' => 2,
            'behavioral_effect' => ['causal_credit' => ['status' => 'independently_confirmed']],
        ]);
        $full = [
            ...$screen,
            'total_trades' => 40, 'profit_factor' => 1.35,
            'max_drawdown_percent' => 8,
            'monte_carlo' => ['risk_of_ruin_percent' => 4],
            'pf_attribution' => [
                'stress_cost' => ['profit_factor' => 1.20],
                'breakdown' => $screen['pf_attribution']['breakdown'],
            ],
            'elite_agent_passport' => ['status' => 'failed'],
            'no_change_control' => ['status' => 'assessed'],
            'statistical_evidence' => ['edge_quality' => ['bootstrap_pf' => [
                'status' => 'assessed', 'pf_5_percentile_lower_bound' => 1.15,
            ]]],
            'market_adaptive_replay' => ['checkpoint_windows' => [
                ['window' => 1, 'trades' => 12, 'profit_factor' => 1.40, 'net_profit_percent' => 2.0],
                ['window' => 2, 'trades' => 12, 'profit_factor' => 1.35, 'net_profit_percent' => 1.5],
            ]],
            'evidence_run_id' => $screen['evidence_run_id'],
        ];
        $performance = ModelMarketPerformance::create([
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => $agent->strategy_family, 'status' => 'challenger',
            'evidence_status' => 'valid', 'forward_score' => 35, 'sample_count' => 40,
            'rolling_windows_count' => 3, 'rolling_forward_wins' => 2, 'metrics' => $full,
        ]);
        $fullCard = app(\App\Services\AgentKnowledgeService::class)->recordFullReplay(
            $agent->fresh(['modelVersion', 'generation']), $performance, $full, $screen['evidence_run_id']
        );

        $this->assertSame('specialist', $fullCard->skill_stage);
        $this->assertContains($parameterKey, $fullCard->blocked_mutations);
        $this->assertTrue(\App\Models\AgentLearningLesson::where('lab_agent_id', $agent->id)
            ->where('lesson_type', 'harmful_lesson')->where('status', 'confirmed')->exists());

        $contract = app(\App\Services\AgentKnowledgeService::class)->childContract(
            'XAUUSD', 'H1', $agent->strategy_family, $model->fresh(),
            ['regime' => 'trend_up', 'state_cluster' => ['cluster_id' => 'cluster-test']],
            'monthly_survival',
        );
        $this->assertContains($parameterKey, $contract['blocked_mutations']);
        $this->assertContains('retention', $contract['required_exams']);
        $this->assertFalse($contract['promotion_evidence']);

        $baselineAgent = $generation->agents()->where('id', '!=', $agent->id)->first() ?: $generation->agents()->latest('id')->first();
        $baseline = app(\App\Services\AgentKnowledgeService::class)->recordBaseline($baselineAgent->fresh(['modelVersion', 'generation']));
        $this->assertSame('novice', $baseline->skill_stage);
        $this->assertSame('WAIT', $baseline->unknown_state_action);
        $this->assertFalse((bool) data_get($baseline->provenance, 'promotion_evidence'));
    }

    public function test_council_sequence_requires_specialists_then_router_before_combined_replay(): void
    {
        $service = app(\App\Services\EliteAgentPortfolioGateService::class);
        $method = new \ReflectionMethod($service, 'councilSequence');
        $method->setAccessible(true);
        $candidate = function (string $role, string $regime): \App\Models\ModelMarketPerformance {
            $performance = new \App\Models\ModelMarketPerformance();
            $performance->setRelation('modelVersion', new \App\Models\ModelVersion([
                'metadata' => [
                    'council_specialist_contract' => [
                        'protocol' => 'agent_council_v1',
                        'role' => $role,
                        'owner_regime' => $regime,
                    ],
                ],
            ]));
            return $performance;
        };

        $specialists = collect([
            $candidate('trend_up_specialist', 'trend_up'),
            $candidate('range_specialist', 'range'),
        ]);
        $waiting = $method->invoke($service, $specialists);
        $this->assertFalse($waiting['ready']);
        $this->assertSame('waiting_for_transition_router_passport', $waiting['status']);

        $ready = $method->invoke($service, $specialists->push(
            $candidate('transition_risk_router', 'trend_up'),
        ));
        $this->assertTrue($ready['ready']);
        $this->assertSame('ready_for_combined_replay', $ready['status']);
        $this->assertSame(2, $ready['specialist_count']);
        $this->assertSame(1, $ready['router_count']);
    }

    public function test_professional_exam_ledger_records_hidden_router_and_learning_contracts(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'professional_exam_test', true);
        $agent = $generation->agents->first();
        $result = [
            'pf_attribution' => ['breakdown' => ['by_regime_volatility' => [
                'trend_up|normal_volatility' => ['trades' => 12],
                'range|low_volatility' => ['trades' => 12],
            ]]],
            'permanent_unseen_challenge' => ['status' => 'sealed'],
            'temporal_firewall' => ['status' => 'passed'],
            'secret_adversarial_arena' => ['status' => 'passed'],
            'statistical_evidence' => ['edge_quality' => [
                'bootstrap_pf' => ['pf_5_percentile_lower_bound' => 1.10],
                'confidence_calibration' => ['status' => 'assessed', 'score' => 80, 'sample_count' => 20],
            ]],
            'opportunity_recall' => ['opportunities' => 20, 'abstention_precision' => .70],
            'router_evidence' => [
                'status' => 'assessed', 'objective_score' => 78,
                'calibration_score' => .80, 'abstention_precision' => .70,
                'disagreement_wait_invariant' => true,
            ],
            'evidence_run_id' => 'professional-exam-run',
        ];

        $projection = app(\App\Services\AgentProfessionalExamService::class)->assessAndRecord(
            $agent->fresh(['modelVersion', 'generation']), $agent->modelVersion, null, $result,
        );

        $this->assertSame('passed', data_get($projection, 'hidden_state_challenge.status'));
        $this->assertSame('assessed', data_get($projection, 'router_calibration.status'));
        $this->assertFalse((bool) data_get($projection, 'promotion_evidence'));
        $this->assertDatabaseCount('agent_professional_exams', 5);
        $this->assertDatabaseHas('agent_professional_exams', [
            'lab_agent_id' => $agent->id,
            'exam_type' => 'hidden_state_cluster_challenge',
            'status' => 'passed',
            'promotion_evidence' => 0,
        ]);
    }

    public function test_teacher_student_shadow_detects_capability_retention_without_using_pf(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'teacher_student_shadow_test', true);
        $agent = $generation->agents->first();
        $parent = ModelVersion::create([
            'name' => 'teacher-model', 'strategy' => 'teacher-model', 'version' => 'v1', 'generation' => 0,
            'status' => 'testing', 'parameters' => [], 'metadata' => ['capability_vector' => ['trend' => 80, 'range' => 70]],
            'evidence_status' => 'valid',
        ]);
        ModelMarketPerformance::create([
            'model_version_id' => $parent->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => $agent->strategy_family, 'status' => 'challenger', 'evidence_status' => 'valid',
            'metrics' => [
                'capability_vector' => ['trend' => 80, 'range' => 70],
                'statistical_evidence' => ['edge_quality' => ['confidence_calibration' => ['score' => 80]]],
                'opportunity_recall' => ['abstention_precision' => .70],
                'profit_factor' => 99.0,
            ],
        ]);
        $agent->update(['parent_a_model_version_id' => $parent->id]);

        $shadow = app(\App\Services\AgentProfessionalExamService::class)->teacherStudentShadow(
            $agent->fresh(), $agent->modelVersion, [
                'capability_vector' => ['trend' => 75, 'range' => 68],
                'statistical_evidence' => ['edge_quality' => ['confidence_calibration' => ['score' => 70]]],
                'opportunity_recall' => ['abstention_precision' => .65],
                'profit_factor' => .1,
            ],
        );

        $this->assertSame('passed', $shadow['status']);
        $this->assertSame([], $shadow['lost_skills']);
        $this->assertFalse((bool) $shadow['promotion_evidence']);
    }

    public function test_drift_expires_skill_and_mutation_budget_blocks_harmful_direction(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'professional_safety_test', true);
        $agent = $generation->agents->first();
        $card = app(\App\Services\AgentKnowledgeService::class)->recordBaseline(
            $agent->fresh(['modelVersion', 'generation'])
        );
        \App\Models\MarketDriftSnapshot::create([
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'psi_score' => .40,
            'volatility_ratio' => 1.8, 'mean_return_shift' => .2, 'status' => 'drift',
            'metrics' => ['source' => 'test'], 'detected_at' => now(),
        ]);
        $drift = app(\App\Services\AgentProfessionalExamService::class)->driftRecertification(
            'XAUUSD', 'H1', [], $card,
        );
        $this->assertSame('required', $drift['status']);
        $this->assertTrue($drift['recertification_required']);
        $this->assertSame('expired', $drift['skill_status']);

        $key = array_key_first($agent->modelVersion->parameters ?: ['minimum_signal_confidence' => .5]);
        \App\Models\AgentLearningLesson::create([
            'lesson_id' => (string) \Illuminate\Support\Str::uuid(),
            'lesson_hash' => hash('sha256', 'professional-harmful-'.$agent->id),
            'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $agent->strategy_family,
            'lesson_type' => 'harmful_lesson', 'status' => 'confirmed', 'parameter_key' => $key,
            'confirmation_count' => 2, 'source_run_ids' => ['professional-safety-run'],
            'evidence' => ['promotion_evidence' => false], 'observed_at' => now(),
        ]);
        $budget = app(\App\Services\AgentProfessionalExamService::class)->mutationBudget(
            'XAUUSD', 'H1', $agent->strategy_family,
        );
        $this->assertContains($key, $budget['confirmed_harmful_keys']);
        $this->assertNotContains($key, app(\App\Services\AgentProfessionalExamService::class)->allowedMutationKeys([$key, 'unrelated_gene'], $budget));
    }

    public function test_council_selection_includes_the_passed_router_in_combined_replay(): void
    {
        $service = app(\App\Services\EliteAgentPortfolioGateService::class);
        $method = new \ReflectionMethod($service, 'selectCouncilMembers');
        $method->setAccessible(true);
        $candidate = function (int $id, string $role, string $regime, float $pf): \App\Models\ModelMarketPerformance {
            $performance = new \App\Models\ModelMarketPerformance([
                'id' => $id,
                'forward_score' => 40,
                'strategy_family' => $role === 'transition_risk_router' ? 'hybrid' : 'differential_router',
                'metrics' => [
                    'profit_factor' => $pf,
                    'max_drawdown_percent' => 5,
                    'monte_carlo' => ['risk_of_ruin_percent' => 1],
                    'pf_attribution' => ['stress_cost' => ['profit_factor' => 1.2]],
                ],
            ]);
            $performance->setAttribute('id', $id);
            $performance->setRelation('modelVersion', new \App\Models\ModelVersion([
                'metadata' => [
                    'council_specialist_contract' => [
                        'protocol' => 'agent_council_v1',
                        'role' => $role,
                        'owner_regime' => $regime,
                    ],
                    'portfolio_research_contract' => [
                        'target_regime' => $regime,
                        'target_volatility' => 'normal_volatility',
                    ],
                ],
            ]));
            return $performance;
        };

        $selected = $method->invoke($service, collect([
            $candidate(101, 'trend_up_specialist', 'trend_up', 1.4),
            $candidate(102, 'range_specialist', 'range', 1.35),
            $candidate(103, 'transition_risk_router', 'trend_up', 1.3),
            tap(new \App\Models\ModelMarketPerformance(['id' => 104, 'strategy_family' => 'ordinary']), function ($ordinary): void {
                $ordinary->setRelation('modelVersion', new \App\Models\ModelVersion(['metadata' => []]));
            }),
        ]));

        $this->assertCount(3, $selected);
        $this->assertSame(2, $selected->filter(fn ($item): bool => str_ends_with(
            (string) data_get($item->modelVersion->metadata, 'council_specialist_contract.role'),
            '_specialist'
        ))->count());
        $this->assertSame(1, $selected->filter(fn ($item): bool =>
            data_get($item->modelVersion->metadata, 'council_specialist_contract.role') === 'transition_risk_router'
        )->count());
        $this->assertFalse($selected->contains(fn ($item): bool => $item->id === 104));
    }

    public function test_council_members_cannot_start_individual_paper_track(): void
    {
        $service = app(\App\Services\PaperTradingExecutionService::class);
        $method = new \ReflectionMethod($service, 'paperTrackAllowed');
        $method->setAccessible(true);

        $member = new \App\Models\ModelMarketPerformance(['metrics' => []]);
        $member->setRelation('modelVersion', new \App\Models\ModelVersion([
            'metadata' => [
                'council_specialist_contract' => ['protocol' => 'agent_council_v1'],
            ],
        ]));
        $this->assertFalse($method->invoke($service, $member));

        $ordinary = new \App\Models\ModelMarketPerformance(['metrics' => []]);
        $ordinary->setRelation('modelVersion', new \App\Models\ModelVersion(['metadata' => []]));
        $this->assertTrue($method->invoke($service, $ordinary));

        $proxy = new \App\Models\ModelMarketPerformance(['metrics' => ['portfolio_proxy' => true]]);
        $proxy->setRelation('modelVersion', new \App\Models\ModelVersion(['metadata' => []]));
        // A proxy may paper only after its sealed portfolio passport exists;
        // an unbound legacy/mock proxy must fail closed.
        $this->assertFalse($method->invoke($service, $proxy));
    }

    public function test_historical_novelty_cannot_add_a_second_gene_to_an_isolated_child(): void
    {
        $service = app(LabPopulationService::class);
        $schema = app(\App\Services\StrategyParameterSchemaService::class);
        $base = $schema->defaults('differential_router');

        $fingerprintMethod = new \ReflectionMethod($service, 'parameterFingerprint');
        $fingerprintMethod->setAccessible(true);
        $fingerprint = $fingerprintMethod->invoke($service, 'differential_router', $base);

        $history = new \ReflectionProperty($service, 'historicalParameterFingerprints');
        $history->setAccessible(true);
        $history->setValue($service, [
            'XAUUSD|H1|differential_router' => [$fingerprint],
        ]);

        $method = new \ReflectionMethod($service, 'ensureHistoricalNovelParameters');
        $method->setAccessible(true);
        $child = $method->invoke(
            $service,
            'XAUUSD',
            'H1',
            'differential_router',
            $base,
            7,
            'opportunity_recall',
            ['objective' => 'opportunity_recall'],
            'trend_up_strength_min',
        );

        $changed = array_keys(array_filter(
            $child,
            fn ($value, $key): bool => ($base[$key] ?? null) !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        $this->assertSame(['trend_up_strength_min'], $changed);
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

    public function test_role_complete_council_uses_safe_owner_mutations_and_wait_invariants(): void
    {
        $service = app(LabPopulationService::class);
        $schema = app(\App\Services\StrategyParameterSchemaService::class);
        $differential = $schema->defaults('differential_router');
        $hybrid = $schema->defaults('hybrid');
        $method = new \ReflectionMethod($service, 'differentialSingleGene');
        $method->setAccessible(true);

        $trendUp = $method->invoke($service, [
            ...$differential,
            'transition_firewall_enabled' => true,
        ], 1, 'trend_up', 'transition_firewall', null, [
            'specialist_role' => 'trend_up_specialist',
        ]);
        $this->assertTrue($trendUp['transition_firewall_enabled']);
        $this->assertSame(3, $trendUp['transition_wait_candles']);

        $trendDown = $method->invoke($service, [
            ...$differential,
            'transition_firewall_enabled' => true,
        ], 1, 'trend_down', 'opportunity_recall', null, [
            'specialist_role' => 'trend_down_specialist',
        ]);
        $this->assertTrue($trendDown['transition_firewall_enabled']);
        $this->assertSame(1, $trendDown['transition_wait_candles']);

        $rangeMethod = new \ReflectionMethod($service, 'rangeCouncilSingleGene');
        $rangeMethod->setAccessible(true);
        $range = $rangeMethod->invoke($service, [
            ...$hybrid,
            'transition_firewall_enabled' => true,
            'range_low_volatility_only' => true,
            'range_reentry_required' => true,
        ], 1, 'regime_coverage', null, [
            'specialist_role' => 'range_specialist',
        ]);
        $this->assertTrue($range['transition_firewall_enabled']);
        $this->assertTrue($range['range_low_volatility_only']);
        $this->assertTrue($range['range_reentry_required']);
        $this->assertSame('mean_reversion', $range['range_signal_mode']);

        $router = $rangeMethod->invoke($service, [
            ...$hybrid,
            'transition_firewall_enabled' => true,
            'high_volatility_wait' => false,
        ], 1, 'transition_firewall', null, [
            'specialist_role' => 'transition_risk_router',
        ]);
        $this->assertTrue($router['transition_firewall_enabled']);
        $this->assertSame(3, $router['transition_wait_candles']);
    }

    public function test_role_mutation_firewall_never_falls_back_to_another_owner_gene(): void
    {
        $service = app(LabPopulationService::class);
        $schema = app(\App\Services\StrategyParameterSchemaService::class);
        $method = new \ReflectionMethod($service, 'councilRoleMutationCandidate');
        $method->setAccessible(true);

        $rangeBase = $schema->defaults('hybrid');
        $rangeCandidate = $method->invoke(
            $service,
            'range_specialist',
            'hybrid',
            $rangeBase,
            [],
            collect(['mean_reversion', 'mid_cross', 'inverse_extreme', 'reentry'])
                ->map(fn (string $value): array => ['parameter_key' => 'range_signal_mode', 'new_value' => $value])
                ->all(),
        );
        $rangeChanged = array_values(array_filter(
            array_keys($rangeCandidate),
            fn (string $key): bool => ($rangeCandidate[$key] ?? null) !== ($rangeBase[$key] ?? null),
        ));
        $this->assertCount(1, $rangeChanged);
        $this->assertContains($rangeChanged[0], ['range_deviation', 'range_adx_max']);
        $this->assertNotContains('trend_roc_period', $rangeChanged);

        $routerBase = [...$rangeBase, 'transition_firewall_enabled' => true, 'high_volatility_wait' => false];
        $routerCandidate = $method->invoke(
            $service,
            'transition_risk_router',
            'hybrid',
            $routerBase,
            [],
            [
                ['parameter_key' => 'transition_wait_candles', 'new_value' => 1],
                ['parameter_key' => 'transition_wait_candles', 'new_value' => 3],
            ],
        );
        $routerChanged = array_values(array_filter(
            array_keys($routerCandidate),
            fn (string $key): bool => ($routerCandidate[$key] ?? null) !== ($routerBase[$key] ?? null),
        ));
        $this->assertCount(1, $routerChanged);
        $this->assertContains($routerChanged[0], ['transition_wait_candles', 'high_volatility_risk_multiplier']);
        $this->assertNotContains('trend_roc_period', $routerChanged);
    }

    public function test_full_validation_fairness_middleware_is_wired_into_evaluator_jobs(): void
    {
        $screen = new \App\Jobs\EvaluateLabAgentJob(1, 'XAUUSD', 'screen');
        $full = new \App\Jobs\EvaluateLabAgentJob(1, 'XAUUSD', 'full');
        $frontier = new \App\Jobs\EvaluateLabAgentJob(1, 'XAUUSD', 'screen', null, 'lab-frontier');

        $this->assertSame('lab-screening', $screen->queue);
        $this->assertSame('lab-full-validation', $full->queue);
        $this->assertSame('lab-frontier', $frontier->queue);

        $screenMiddleware = collect($screen->middleware());
        $fullMiddleware = collect($full->middleware());

        $this->assertTrue($screenMiddleware->contains(fn ($middleware): bool => $middleware instanceof \App\Jobs\Middleware\PreferFullValidationQueue));
        $this->assertTrue($fullMiddleware->contains(fn ($middleware): bool => $middleware instanceof \App\Jobs\Middleware\PreferFullValidationQueue));
    }

    public function test_targeted_failure_profile_plan_uses_four_distinct_repair_dimensions(): void
    {
        $lab = \App\Models\AiLaboratory::firstOrCreate(
            ['symbol' => 'XAUUSD', 'timeframe' => 'H1'],
            ['name' => 'XAUUSD Lab', 'strategy_families' => ['differential_router', 'hybrid'], 'is_active' => true],
        );
        $method = new \ReflectionMethod(app(LabPopulationService::class), 'targetedFailurePlan');
        $method->setAccessible(true);

        $plan = $method->invoke(app(LabPopulationService::class), $lab, ['stress_cost'], 4, [
            'source_generation_id' => 19,
            'source_generation' => 3,
            'profile_hash' => 'gen3-profile',
            'target_counts' => ['stress_cost' => 7],
        ]);

        $this->assertSame(['stress_cost', 'profit_factor', 'temporal_stability', 'regime_coverage'], array_column($plan, 'target'));
        $this->assertSame(4, count($plan));
        $this->assertTrue(collect($plan)->every(fn (array $seat): bool => $seat['origin'] === 'targeted_failure_profile'));
        $this->assertSame('targeted_failure_profile_v1', data_get($plan[0], 'niche.protocol'));
        $this->assertSame(19, data_get($plan[0], 'niche.source_generation_id'));
        $this->assertFalse((bool) data_get($plan[0], 'niche.promotion_evidence', false));
    }

    public function test_screen_retry_window_can_wait_behind_a_long_full_validation_lane(): void
    {
        $screen = new \App\Jobs\EvaluateLabAgentJob(1, 'XAUUSD', 'screen');
        $full = new \App\Jobs\EvaluateLabAgentJob(1, 'XAUUSD', 'full');

        $this->assertSame(
            360 * 60,
            $screen->retryUntil()->getTimestamp() - $screen->screenQueuedAt->getTimestamp(),
        );
        $this->assertSame(360 * 60, $screen->uniqueFor);
        $this->assertLessThan(
            $screen->retryUntil()->getTimestamp(),
            $full->retryUntil()->getTimestamp(),
        );

        $legacy = new \App\Jobs\EvaluateLabAgentJob(1, 'XAUUSD', 'screen');
        $legacy->retryDeadline = now()->addMinutes(90);
        unset($legacy->screenQueuedAt);

        $this->assertEqualsWithDelta(
            360 * 60,
            $legacy->retryUntil()->getTimestamp() - now()->getTimestamp(),
            2,
        );
    }

    public function test_screen_fairness_ignores_a_delayed_full_validation_job(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'lab-full-validation',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp + 300,
            'created_at' => now()->timestamp,
        ]);

        $middleware = new \App\Jobs\Middleware\PreferFullValidationQueue('screen');
        $waiting = new \ReflectionMethod($middleware, 'fullValidationIsWaiting');
        $waiting->setAccessible(true);

        $this->assertFalse($waiting->invoke($middleware));
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
        $evidenceRunId = $this->completeEvidenceRun($agent, [
            'total_trades' => 25,
            'trade_ledger_hash' => hash('sha256', 'outbox-ledger'),
            'trade_ledger' => array_fill(0, 25, ['entry_time' => '2026-01-01T00:00:00Z']),
            'trades' => array_fill(0, 25, ['entry_time' => '2026-01-01T00:00:00Z']),
            'displayed_trade_count' => 25,
        ]);

        app(ScreeningLearningOutboxService::class)->enqueue($agent, [
            'evidence_run_id' => $evidenceRunId,
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

    public function test_screen_learning_outbox_blocks_missing_evidence_instead_of_teaching_from_it(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'outbox_incomplete_contract', true);
        $agent = $generation->agents->first();

        app(ScreeningLearningOutboxService::class)->enqueue($agent, [
            'total_trades' => 25,
            'profit_factor' => 1.47,
            'screening_survival' => [
                'status' => 'rescue_case',
                'reason_codes' => ['FAILED_TRAIN_FORWARD_GAP'],
            ],
        ], 0.0);

        $this->assertSame(0, app(ScreeningLearningOutboxService::class)->process());
        $this->assertDatabaseHas('screening_learning_outbox', [
            'lab_agent_id' => $agent->id,
            'status' => 'blocked',
        ]);
        $this->assertDatabaseMissing('mutation_memories', ['lab_agent_id' => $agent->id]);
        $this->assertDatabaseMissing('agent_memories', ['source_id' => $agent->id]);
    }

    private function completeEvidenceRun(LabAgent $agent, array $overrides = []): string
    {
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($agent, 'screening', 'incremental', ['source' => 'feature_test']);
        $evidence->attachRequest($run, [
            'symbol' => $agent->symbol,
            'timeframe' => $agent->timeframe,
            'candles' => [['time' => '2026-01-01T00:00:00Z', 'close' => 2000]],
        ], ['request_id' => 'feature-test-'.$agent->id]);
        $evidence->finishRun($run, 'completed', [
            'total_trades' => 0,
            'trade_ledger_hash' => hash('sha256', 'empty-ledger'),
            'trade_ledger' => [],
            'trades' => [],
            'displayed_trade_count' => 0,
            'decision_trace' => [[
                'candle_time' => '2026-01-01T00:00:00Z',
                'event_type' => 'signal_evaluation',
                'action' => 'WAIT',
                'accepted' => false,
            ]],
            'data_quality' => [
                'decision_trace' => [
                    'requested' => true,
                    'complete' => true,
                    'evaluated_candle_count' => 1,
                ],
            ],
            ...$overrides,
        ]);

        return $run->run_id;
    }
}
