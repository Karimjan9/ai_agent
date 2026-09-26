<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\CompositionComponentPosterior;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\CanonicalSkillCartridgeService;
use App\Services\CompositionAuthorityKernelService;
use App\Services\CompositionLibrarySettlementService;
use App\Services\CompositionSettlementFanoutService;
use App\Services\ExecutionContractService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabInstrumentResearchService;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\StrategyLibraryCompilerService;
use App\Services\StrategyTacticRiskCompositionPlannerService;
use App\Services\TacticCatalogueService;
use App\Services\TradeManagementLibraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StrategyTacticRiskCompositionPlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_cohort_receives_four_auditable_causal_packets_with_frozen_passports(): void
    {
        $plan = collect(range(1, 20))->map(fn (int $slot): array => [
            'family' => $slot % 2 ? 'hybrid' : 'differential_router',
            'target' => 'portfolio_router',
            'niche' => $slot <= 3 ? ['structural_research' => true] : [],
        ])->all();

        $result = app(StrategyTacticRiskCompositionPlannerService::class)->materialize($plan);

        $this->assertSame('admitted', $result['contract']['status']);
        $this->assertSame(['hypothesis_packets' => 4, 'arms_per_packet' => 5, 'seats' => 20], $result['contract']['generation_structure']);
        $this->assertSame(.40, $result['contract']['prior_budget_ceiling']);
        $lanes = collect($result['plan'])->countBy(fn (array $seat): string => (string) data_get($seat, 'niche.composition_lane'));
        $this->assertSame(6, $lanes['strategy_composition']);
        $this->assertSame(6, $lanes['tactic_mutation']);
        $this->assertSame(5, $lanes['risk_management_mutation']);
        $this->assertSame(3, $lanes['structural_topology_experiment']);
        $this->assertCount(5, collect($result['plan'])->filter(fn (array $seat): bool => data_get($seat, 'niche.risk_library_contract.paired_control_required') === true));
        $strategySeat = collect($result['plan'])->firstWhere('niche.composition_lane', 'strategy_composition');
        $tacticSeat = collect($result['plan'])->firstWhere('niche.composition_lane', 'tactic_mutation');
        $this->assertContains($strategySeat['family'], ['trend', 'breakout', 'volatility', 'mean_reversion', 'session', 'hybrid']);
        $this->assertNotEmpty(data_get($strategySeat, 'niche.composition_architecture'));
        $this->assertSame('trend', $tacticSeat['family']);
        $this->assertSame('trend_pullback', data_get($tacticSeat, 'niche.composition_architecture'));
        $this->assertCount(4, collect($result['plan'])->pluck('niche.causal_packet.packet_id')->unique());
        $this->assertTrue(collect($result['plan'])->every(fn (array $seat): bool => data_get($seat, 'niche.composition_passport.protocol') === 'xauusd_composition_authority_kernel_v1'));
        $this->assertTrue(collect($result['plan'])->every(fn (array $seat): bool => data_get($seat, 'niche.composition_passport.validation.m1_false_precision_blocked') === true));
        $this->assertTrue(collect($result['plan'])->every(fn (array $seat): bool => data_get($seat, 'niche.composition_passport.strategy_signal_scope.protocol') === 'strategy_signal_scope_v1'));
        $this->assertTrue(collect($result['plan'])->every(fn (array $seat): bool => data_get($seat, 'niche.composition_passport.typed_program.runtime_protocol') === 'xauusd_executable_composition_program_v2'));
    }

    public function test_freeze_rejects_an_empty_strategy_tactic_activation_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('COMPOSITION_ACTIVATION_SCOPE_EMPTY');

        app(CompositionAuthorityKernelService::class)->freeze([
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_id' => 'str_001_ema_adx_pullback',
            'tactic_id' => 'regime_consensus',
            'risk_id' => 'atr_risk_envelope',
            'management_id' => 'balanced_professional',
        ]);
    }

    public function test_recovery_cohort_is_not_silently_rewritten_as_a_full_composition_cohort(): void
    {
        $result = app(StrategyTacticRiskCompositionPlannerService::class)->materialize(array_fill(0, 4, ['niche' => []]));

        $this->assertSame('not_applicable_bounded_cohort', $result['contract']['status']);
    }

    public function test_frozen_passport_reclaims_final_constructor_identity_after_plan_copying(): void
    {
        $planner = app(StrategyTacticRiskCompositionPlannerService::class);
        $materialized = $planner->materialize(collect(range(1, 20))->map(fn (int $slot): array => [
            'family' => $slot % 2 ? 'hybrid' : 'trend',
            'target' => 'portfolio_router',
            'niche' => $slot <= 3 ? ['structural_research' => true] : [],
        ])->all());
        $drifted = $materialized['plan'];
        $drifted[0]['family'] = 'breakout';
        data_set($drifted[0], 'niche.composition_architecture', 'breakout_retest');
        data_set($drifted[0], 'niche.tactic_library_key', 'breakout_retest');

        $bound = $planner->bindRuntimeOwnership($drifted);
        $passport = (array) data_get($bound, 'plan.0.niche.composition_passport');
        $runtime = app(StrategyLibraryCompilerService::class)->runtime(
            (string) data_get($passport, 'components.strategy_id'),
        );

        $this->assertSame('bound', data_get($bound, 'contract.status'));
        $this->assertSame($runtime['family'], data_get($bound, 'plan.0.family'));
        $this->assertSame($runtime['architecture'], data_get($bound, 'plan.0.niche.composition_architecture'));
        $this->assertSame(data_get($passport, 'components.tactic_id'), data_get($bound, 'plan.0.niche.tactic_library_key'));
        $this->assertSame('bound', data_get($bound, 'plan.0.niche.composition_runtime_owner.status'));
    }

    public function test_causal_confirmation_reissues_a_cross_family_passport_for_the_exact_source_runtime(): void
    {
        $authority = app(CompositionAuthorityKernelService::class);
        $planner = app(StrategyTacticRiskCompositionPlannerService::class);
        $stalePassport = $authority->freeze([
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_id' => 'mix_001_trend_beast',
            'tactic_id' => 'trend_pullback',
            'risk_id' => 'atr_risk_envelope',
            'management_id' => 'balanced_professional',
            'data_hash' => 'source-data',
            'execution_hash' => 'source-execution',
        ]);

        $passport = $planner->freezeConfirmationBaseline(
            'differential_router',
            'H1',
            'source-data',
            'source-execution',
            'frozen_parent_differential_router',
            'frozen_parent_differential_router',
            $stalePassport,
        );

        $this->assertNotSame($stalePassport['composition_id'], $passport['composition_id']);
        $this->assertSame('mix_011_differential_router', data_get($passport, 'components.strategy_id'));
        $this->assertSame('frozen_parent_differential_router', data_get($passport, 'components.tactic_id'));
        $this->assertSame('differential_router', data_get($passport, 'strategy_contract.strategy_spec.family'));
        $this->assertSame('source-data', data_get($passport, 'provenance.data_hash'));
        $this->assertSame('source-execution', data_get($passport, 'provenance.execution_hash'));

        $bound = $planner->bindRuntimeOwnership([[
            'family' => 'differential_router',
            'target' => 'portfolio_router',
            'niche' => ['composition_passport' => $passport],
        ]]);
        $this->assertSame('bound', data_get($bound, 'contract.status'));
        $this->assertSame('differential_router', data_get($bound, 'plan.0.family'));
        $this->assertSame(
            'frozen_parent_differential_router',
            data_get($bound, 'plan.0.niche.composition_architecture'),
        );
    }

    public function test_causal_confirmation_fails_planning_when_no_exact_family_adapter_exists(): void
    {
        $passport = app(StrategyTacticRiskCompositionPlannerService::class)->freezeConfirmationBaseline(
            'unregistered_family',
            'H1',
            'source-data',
            'source-execution',
            'unregistered_runtime',
            'unregistered_tactic',
        );

        $this->assertSame([], $passport);
    }

    public function test_structural_architecture_is_frozen_into_its_matching_strategy_passport(): void
    {
        $plan = collect(range(1, 20))->map(fn (int $slot): array => [
            'family' => $slot <= 3 ? 'trend' : ($slot % 2 ? 'hybrid' : 'trend'),
            'target' => 'portfolio_router',
            'niche' => $slot <= 3 ? [
                'structural_research' => true,
                'architecture_variant' => $slot === 2 ? 'trend_breakout_retest' : 'trend_pullback',
            ] : [],
        ])->all();

        $result = app(StrategyTacticRiskCompositionPlannerService::class)->materialize($plan);
        $strategyId = (string) data_get($result, 'plan.1.niche.composition_passport.components.strategy_id');
        $runtime = app(StrategyLibraryCompilerService::class)->runtime($strategyId);

        $this->assertSame('admitted', data_get($result, 'contract.status'));
        $this->assertSame('trend', data_get($runtime, 'family'));
        $this->assertSame('trend_breakout_retest', data_get($runtime, 'architecture'));
    }

    public function test_management_library_compiles_an_executable_profile_adapter(): void
    {
        $contract = app(TradeManagementLibraryService::class)->compile('balanced_professional');

        $this->assertSame('trade_management_runtime_adapter_v1', data_get($contract, 'runtime_adapter.protocol'));
        $this->assertSame('balanced_professional', data_get($contract, 'runtime_adapter.profile'));
        $this->assertSame(.4, data_get($contract, 'runtime_adapter.partial_close_fraction'));
        $this->assertSame(2.0, data_get($contract, 'runtime_adapter.final_target_r'));

        $rangeContext = app(TradeManagementLibraryService::class)->compile('balanced_professional', 'range');
        $this->assertSame('balanced_professional', $rangeContext['profile']);
        $this->assertSame($contract['plan'], $rangeContext['plan']);
    }

    public function test_replay_contract_binds_management_and_carries_executable_tactic(): void
    {
        $lab = AiLaboratory::create(['name' => 'Runtime binding', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['trend'], 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test', 'status' => 'completed']);
        $passport = app(CompositionAuthorityKernelService::class)->freeze([
            'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_id' => 'str_001_ema_adx_pullback',
            'tactic_id' => 'trend_pullback',
            'risk_id' => 'atr_risk_envelope',
            'management_id' => 'balanced_professional',
        ]);
        $tactic = app(TacticCatalogueService::class)->for('trend', 'trend_pullback', 'portfolio_router');
        $model = ModelVersion::create([
            'name' => 'runtime-bound', 'strategy' => 'trend', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing',
            'parameters' => ['atr_stop_multiplier' => 1.5],
            'metadata' => [
                'base_strategy' => 'trend_v1',
                'strategy_architecture' => 'trend_pullback',
                'tactic_contract' => $tactic,
                'smart_composition' => ['composition_passport' => $passport],
            ],
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'origin' => 'test', 'lifecycle_status' => 'challenger', 'parameter_diff' => [],
        ])->fresh('modelVersion');
        $assignment = app(LabInstrumentResearchService::class)->assignment($agent);
        $bundleHash = str_repeat('b', 64);
        $bundle = [
            'bundle_hash' => $bundleHash,
            'manifest' => [
                'protocol' => MultiTimeframeSnapshotService::PROTOCOL,
                'bundle_hash' => $bundleHash,
                'streams' => collect(['M5', 'M15', 'H1', 'H4'])
                    ->mapWithKeys(fn (string $timeframe): array => [
                        $timeframe => ['sha256' => hash('sha256', $timeframe)],
                    ])->all(),
            ],
        ];
        $method = new \ReflectionMethod(LabAgentEvaluationService::class, 'compositionRuntimeContract');
        $contract = $method->invoke(
            app(LabAgentEvaluationService::class),
            $agent,
            $assignment,
            'M5',
            $bundle,
            $bundleHash,
        );

        $this->assertSame('xauusd_composition_runtime_contract_v3', data_get($contract, 'protocol'));
        $this->assertTrue(data_get($contract, 'runtime_bindings.management.bound'));
        $this->assertSame('exact_runtime_profile_adapter_bound', data_get($contract, 'runtime_bindings.management.reason'));
        $this->assertSame('trade_management_runtime_adapter_v1', data_get($contract, 'runtime_bindings.management.adapter.protocol'));
        $this->assertSame('audited_tactic_catalogue_v1', data_get($contract, 'tactic_contract.protocol'));
        $this->assertSame('trend_pullback', data_get($contract, 'tactic_contract.architecture'));
        $this->assertSame('trend', data_get($contract, 'runtime_bindings.strategy.expected_family'));
        $this->assertSame('trend', data_get($contract, 'runtime_bindings.strategy.actual_family'));
        $this->assertSame('trend_pullback', data_get($contract, 'runtime_bindings.strategy.expected_architecture'));
        $this->assertSame('trend_pullback', data_get($contract, 'runtime_bindings.strategy.actual_architecture'));
        $this->assertTrue(data_get($contract, 'strategy_scope_binding.bound'));
        $this->assertSame('trend_pullback', data_get($contract, 'runtime_bindings.tactic.declared'));
        $this->assertSame('trend_pullback', data_get($contract, 'runtime_bindings.tactic.actual_architecture'));
        $this->assertTrue(data_get($contract, 'runtime_bindings.risk.bound'));
        $this->assertSame(1.5, data_get($contract, 'runtime_bindings.risk.value'));
        $this->assertSame('M5', data_get($contract, 'execution_timeframe'));
        $this->assertTrue(data_get($contract, 'execution_authority.dataset.bound'));
        $this->assertTrue(data_get($contract, 'execution_authority.execution.bound'));
        $this->assertTrue(data_get($contract, 'execution_authority.instrument.bound'));
        $this->assertSame($assignment['assignment_hash'], data_get($contract, 'execution_authority.instrument.assignment_hash'));
        $this->assertTrue(data_get($contract, 'execution_authority.mtf.bound'));
        $this->assertSame($bundleHash, data_get($contract, 'execution_authority.mtf.bundle_hash'));
        $this->assertSame(
            data_get(app(ExecutionContractService::class)->for('XAUUSD', 'M5'), 'execution_hash'),
            data_get($contract, 'execution_authority.execution.execution_hash'),
        );
        $this->assertSame(
            [
                'mtf_context_gate', 'regime_detector',
                'strategy_runtime', 'tactic_runtime', 'location_model', 'setup_model',
                'confirmation_engine', 'entry_model', 'invalidation_model',
                'instrument_context_gate', 'mtf_permission_gate',
                'central_risk_governor', 'order_execution', 'management_policy',
            ],
            array_column((array) data_get($contract, 'typed_program_nodes', []), 'module'),
        );
        $this->assertSame(
            'instrument_context_gate',
            data_get($contract, 'execution_authority.runtime.nodes.0.module'),
        );
        $this->assertNotEmpty(data_get($contract, 'contract_hash'));

        $python = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
        $process = new Process([
            $python,
            '-c',
            <<<'PYTHON'
import json
import sys

from app.services.composition_runtime import validate_composition_runtime_contract

payload = json.load(sys.stdin)
validate_composition_runtime_contract(
    payload["contract"],
    base_strategy=payload["base_strategy"],
    parameters=payload["parameters"],
    execution_timeframe=payload["runtime_authority"]["execution_timeframe"],
    runtime_authority=payload["runtime_authority"],
)
print("validated")
PYTHON,
        ], base_path('../ai-service-python'));
        $process->setInput(json_encode([
            'contract' => $contract,
            'base_strategy' => 'trend_v1',
            'parameters' => $model->parameters,
            'runtime_authority' => [
                'symbol' => 'XAUUSD',
                'execution_timeframe' => 'M5',
                'replay_dataset_hash' => $bundleHash,
                'execution_hash' => data_get($contract, 'execution_authority.execution.execution_hash'),
                'instrument_assignment' => $assignment,
                'mtf_snapshot_manifest' => $bundle['manifest'],
            ],
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $this->assertSame('validated', trim($process->getOutput()));
    }

    public function test_composition_settlement_fails_closed_without_explicit_independent_control_proof(): void
    {
        $lab = AiLaboratory::create(['name' => 'Composition', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test', 'status' => 'completed']);
        $passport = ['protocol' => CompositionAuthorityKernelService::PROTOCOL, 'composition_id' => 'xau-comp-test', 'components' => ['strategy_id' => 'mix_001_trend_beast', 'tactic_id' => 'trend_pullback', 'risk_id' => 'atr_risk_envelope', 'management_id' => 'balanced_professional']];
        $model = ModelVersion::create(['name' => 'composition-child', 'strategy' => 'composition-child', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => ['smart_composition' => ['protocol' => StrategyTacticRiskCompositionPlannerService::PROTOCOL, 'strategy_library_id' => 'mix_001_trend_beast', 'tactic_library_key' => 'trend_pullback', 'risk_library_id' => 'atr_risk_envelope', 'composition_passport' => $passport]]]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'challenger', 'parameter_diff' => []])->fresh('modelVersion');
        $service = app(CompositionLibrarySettlementService::class);

        $blocked = $service->consolidateConfirmed($agent, ['pair_id' => 1, 'lesson_id' => 1]);
        $this->assertSame('not_independently_confirmed', $blocked['status']);
        $this->assertDatabaseCount('composition_settlements', 0);
        $this->assertDatabaseCount('lab_skill_zoo_entries', 0);

        $unattested = $service->consolidateConfirmed($agent, ['pair_id' => 1, 'lesson_id' => 1, 'paired_control' => true, 'independent_confirmation' => true]);
        $this->assertSame('runtime_composition_not_attested', $unattested['status']);

        $attestedTrace = [
                'protocol' => 'xauusd_composition_runtime_trace_v3',
                'status' => 'consumed',
                'composition_id' => 'xau-comp-test',
                'contract_hash_valid' => true,
                'execution_receipt_valid' => true,
                'program_compiled' => true,
                'program_valid' => true,
                'node_receipts_valid' => true,
                'decision_receipts_valid' => true,
                'complete_decision_witness' => true,
                'component_bindings_valid' => true,
                'authority_bindings_valid' => true,
                'required_nodes_observed' => true,
                'execution_completed' => true,
            ];
        $withoutWitness = $attestedTrace;
        $withoutWitness['complete_decision_witness'] = false;
        $this->assertSame('runtime_composition_not_attested', $service->consolidateConfirmed($agent, [
            'pair_id' => 1,
            'lesson_id' => 1,
            'paired_control' => true,
            'independent_confirmation' => true,
            'composition_runtime_trace' => $withoutWitness,
        ])['status']);

        $confirmed = $service->consolidateConfirmed($agent, [
            'pair_id' => 1,
            'lesson_id' => 1,
            'paired_control' => true,
            'independent_confirmation' => true,
            'composition_runtime_trace' => $attestedTrace,
        ]);
        $this->assertSame('confirmed_composition_consolidated', $confirmed['status']);
        $this->assertDatabaseCount('composition_settlements', 1);
        $this->assertDatabaseCount('lab_skill_zoo_entries', 1);
    }

    public function test_component_fanout_credits_only_explicit_paired_ablation_and_is_idempotent(): void
    {
        $passport = ['protocol' => CompositionAuthorityKernelService::PROTOCOL, 'composition_id' => 'causal-fanout', 'components' => [
            'strategy_id' => 'hybrid', 'tactic_id' => 'trend_pullback', 'risk_id' => 'atr_risk_envelope', 'management_id' => 'balanced_professional',
        ]];
        $service = app(CompositionSettlementFanoutService::class);
        $blocked = $service->settle([
            'source_key' => 'fanout-1', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'state_key' => 'trend_up|london',
            'after_cost_r' => .4, 'composition_passport' => $passport,
        ]);
        $this->assertSame('settled_packet_only_awaiting_component_ablation', $blocked['status']);
        $this->assertDatabaseCount('composition_component_posteriors', 0);

        $packet = [
            'source_key' => 'fanout-2', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'state_key' => 'trend_up|london',
            'after_cost_r' => .4, 'composition_passport' => $passport,
            'component_effects' => ['tactic' => [
                'component_id' => 'trend_pullback', 'incremental_after_cost_r' => .12,
                'paired_control' => true, 'same_data_hash' => true, 'same_execution_hash' => true, 'non_target_safe' => true,
                'runtime_attested' => true,
            ]],
        ];
        $settled = $service->settle($packet);
        $service->settle($packet);

        $this->assertSame('settled_causal_component_effects', $settled['status']);
        $this->assertSame(['tactic'], array_keys($settled['component_posteriors']));
        $posterior = CompositionComponentPosterior::firstOrFail();
        $this->assertSame(1, $posterior->observations);
        $this->assertSame(.12, (float) $posterior->after_cost_value);
        $this->assertFalse($settled['whole_packet_credit_fanned_out']);
    }

    public function test_combination_synergy_uses_factorial_interaction_not_ab_beating_one_arm(): void
    {
        $method = new \ReflectionMethod(CanonicalSkillCartridgeService::class, 'factorialInteraction');
        $service = app(CanonicalSkillCartridgeService::class);

        $subAdditive = $method->invoke($service, 1.0, 1.2, 1.2, 1.3);
        $superAdditive = $method->invoke($service, 1.0, 1.1, 1.1, 1.35);

        $this->assertSame('antagonistic', $subAdditive['status']);
        $this->assertEqualsWithDelta(-.1, $subAdditive['interaction_delta'], .000001);
        $this->assertSame('synergistic', $superAdditive['status']);
        $this->assertEqualsWithDelta(.15, $superAdditive['interaction_delta'], .000001);
    }
}
