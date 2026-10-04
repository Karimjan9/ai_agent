<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\ContextualInstrumentBundleEffect;
use App\Models\CooperativeExperimentSettlement;
use App\Models\CooperativeModuleSpeciesMember;
use App\Models\LabAgent;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentReceipt;
use App\Models\ResearchExperimentWorkItem;
use App\Services\ActivationFactorialContractService;
use App\Services\ActivationValidationPlanService;
use App\Services\CompositionAuthorityKernelService;
use App\Services\CandidateGateDecisionService;
use App\Services\CooperativeContextualEvolutionCouncilService;
use App\Services\CooperativeExperimentSettlementService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabPopulationService;
use App\Services\PhaseScopeProbeContractService;
use App\Services\ProofFrontierService;
use App\Services\ResearchAllocationPolicyService;
use App\Services\StrategyParameterSchemaService;
use App\Services\StrategyLibraryCompilerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProofFrontierActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_immutable_json_projection_keeps_large_numeric_identifiers_distinct(): void
    {
        $evidence = app(LabImmutableEvidenceService::class);
        $this->assertTrue($evidence->equivalentJsonValue(['target' => 1], ['target' => 1.0]));
        $this->assertFalse($evidence->equivalentJsonValue(
            ['target' => 9007199254740993], ['target' => 9007199254740992.0],
        ));
        $this->assertFalse($evidence->equivalentJsonValue(
            ['target' => '1'], ['target' => 1],
        ));
    }

    public function test_phase_probe_cannot_turn_a_strong_screen_into_a_promotion_pass(): void
    {
        [$lab] = $this->source(false);
        $agent = $lab->generations()->firstOrFail()->agents()->with('modelVersion')->firstOrFail();
        $model = $agent->modelVersion;
        $metadata = (array) $model->metadata;
        data_set($metadata, 'cooperative_experiment_block.block_type', 'phase_scope_probe');
        data_set($metadata, 'cooperative_experiment_block.block_key', hash('sha256', 'research-only-test'));
        $model->update(['metadata' => $metadata]);
        $decision = app(CandidateGateDecisionService::class)->recordScreening($agent->fresh(['modelVersion']), [
            'total_trades' => 100, 'profit_factor' => 9.0,
            'composition_runtime_trace' => ['observations' => ['strategy_signals_before_tactic' => 30]],
        ]);

        $this->assertSame('failed', $decision->decision);
        $this->assertContains('PHASE_SCOPE_RESEARCH_ONLY', (array) $decision->reason_codes);
        $this->assertTrue((bool) data_get($decision->metrics, 'phase_scope_research_only'));
        $this->assertSame(0, CandidateGateDecision::query()
            ->where('stage', 'diagnostic_rescue_replay')->count());
    }

    public function test_unphased_hypothesis_creates_a_prospective_phase_bound_exact_control_pair(): void
    {
        [$lab, $passport] = $this->source(false);
        $proposal = app(ProofFrontierService::class)->proposePhaseScope($lab, $this->plan($passport));
        $this->assertSame('proposed', $proposal['status']);
        $this->assertSame(ProofFrontierService::PHASE_PROBE_REFREEZE_PROTOCOL,
            $proposal['protocol']);
        $this->assertNotSame(data_get($proposal, 'proposal.source_composition_id'),
            data_get($proposal, 'proposal.prospective_composition_id'));
        $this->assertSame('london_comex_overlap', data_get($proposal, 'proposal.venue_phase'));
        $this->assertFalse($proposal['economic_credit_allowed']);

        $allocation = app(CooperativeContextualEvolutionCouncilService::class)
            ->allocate($this->plan($passport), $lab, [], 2);
        $this->assertSame(2, data_get($allocation, 'contract.seat_counts.phase_scope_probe'));
        $this->assertCount(20, $allocation['plan']);
        $this->assertSame('not_proposed', data_get($allocation, 'contract.proof_frontier.status'));
        $this->assertSame('proposed', data_get($allocation, 'contract.phase_scope_probe.status'));
        $seats = collect($allocation['plan'])->filter(fn (array $seat): bool =>
            data_get($seat, 'niche.cooperative_experiment_block.block_type') === 'phase_scope_probe'
        )->values();
        $this->assertSame(['phase_control', 'diagnostic_candidate'],
            $seats->pluck('niche.cooperative_experiment_block.arm')->all());
        $this->assertTrue($seats->every(fn (array $seat): bool =>
            data_get($seat, 'niche.contextual_specialist_cell.venue_phase') === 'london_comex_overlap'
            && data_get($seat, 'niche.composition_passport.components') === $passport['components']
            && data_get($seat, 'niche.phase_scope_probe.credit_allowed') === false));

        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id,
            'generation' => 2, 'trigger_type' => 'test',
            'trigger_context' => ['generation_plan' => $allocation['plan'],
                'population_group_contract' => ['contextual_allocator' => $allocation['contract']],
                'specialist_council_contract' => ['contextual_allocator' => [
                    'phase_scope_probe' => $allocation['contract']['phase_scope_probe'],
                ]],
                'mtf_bundle_manifest' => ['streams' => ['M5' => [
                    'last_candle_at' => '2025-12-31 21:50:00']]]],
            'population_size' => 20, 'status' => 'draft']);
        $this->assertSame([], app(CooperativeContextualEvolutionCouncilService::class)
            ->allocationReasons($generation->fresh(['laboratory'])));
        $sealedContext = (array) $generation->trigger_context;
        $tamperedManifest = $sealedContext;
        data_set($tamperedManifest,
            'population_group_contract.contextual_allocator.settlement_feedback.phase_probe_replaced_block_indexes', [3]);
        $generation->update(['trigger_context' => $tamperedManifest]);
        $this->assertContains('ALLOCATION_MANIFEST_HASH_INVALID',
            app(CooperativeContextualEvolutionCouncilService::class)
                ->allocationReasons($generation->fresh(['laboratory'])));
        $conflictingFrontiers = $sealedContext;
        data_set($conflictingFrontiers,
            'population_group_contract.contextual_allocator.settlement_feedback.activation_replaced_block_indexes', [2]);
        $generation->update(['trigger_context' => $conflictingFrontiers]);
        $this->assertContains('ALLOCATION_FRONTIER_REPLACEMENT_CONFLICT',
            app(CooperativeContextualEvolutionCouncilService::class)
                ->allocationReasons($generation->fresh(['laboratory'])));
        $generation->update(['trigger_context' => $sealedContext]);
        $paired = app(ResearchAllocationPolicyService::class)->materializeNormalControlPairing(
            $allocation['plan'], 'XAUUSD', 'H1', $generation->id,
        );
        $this->assertTrue((bool) data_get($paired, 'contract.allowed'));
        $constructor = new \ReflectionMethod(LabPopulationService::class, 'createAgent');
        foreach (array_slice($paired['plan'], 0, 2) as $index => $slot) {
            $failure = null;
            $arguments = [$generation, (string) $slot['family'], (string) $slot['origin'],
                $index + 1, (string) $slot['target'], (array) $slot['niche'], null,
                (string) data_get($slot, 'research_group'),
                (int) data_get($slot, 'group_seat', 0), &$failure];
            $this->assertTrue((bool) $constructor->invokeArgs(
                app(LabPopulationService::class), $arguments,
            ), (string) $failure);
        }
        $this->assertSame([], app(PhaseScopeProbeContractService::class)->reasons(
            $generation->fresh(['agents.modelVersion']),
        ));
        $context = (array) $generation->trigger_context;
        $tampered = $context;
        data_set($tampered, 'generation_plan.0.niche.phase_scope_probe.venue_phase', 'asia_sge_day');
        $generation->update(['trigger_context' => $tampered]);
        $this->assertContains('PHASE_PROBE_PLANNED_IDENTITY_DRIFT',
            app(PhaseScopeProbeContractService::class)->reasons($generation->fresh(['agents.modelVersion'])));
        $generation->update(['trigger_context' => $context]);
        $unplanned = $context;
        data_set($unplanned, 'generation_plan', []);
        $generation->update(['trigger_context' => $unplanned]);
        $this->assertContains('PHASE_PROBE_PLANNED_BLOCK_MISSING',
            app(PhaseScopeProbeContractService::class)->reasons($generation->fresh(['agents.modelVersion'])));
        $generation->update(['trigger_context' => $context]);
        $control = $generation->fresh(['agents.modelVersion'])->agents->first();
        $this->assertSame(data_get($proposal, 'proposal.prospective_composition_id'),
            data_get($control->modelVersion->metadata,
                'smart_composition.composition_passport.composition_id'));
        $this->assertSame('strategy_signal_scope_v1', data_get($control->modelVersion->metadata,
            'smart_composition.composition_passport.strategy_signal_scope.protocol'));
        $runtimeContract = (new \ReflectionMethod(LabAgentEvaluationService::class,
            'compositionRuntimeContract'))->invoke(app(LabAgentEvaluationService::class),
                $control->fresh(['modelVersion']), [], 'M5', [], str_repeat('d', 64));
        $this->assertTrue((bool) data_get($runtimeContract, 'runtime_bindings.strategy.bound'));
        $this->assertTrue((bool) data_get($runtimeContract, 'strategy_scope_binding.bound'));
        $this->assertSame($lab->generations()->where('generation', 1)->firstOrFail()
            ->agents()->firstOrFail()->modelVersion->parameters, $control->modelVersion->parameters);
        foreach ($generation->fresh(['agents.modelVersion'])->agents as $agent) {
            $this->screen($agent, (array) data_get($agent->modelVersion->metadata,
                'specialist_council_membership.contextual_cell'), 0, 30);
        }
        $last = $generation->fresh(['agents.modelVersion'])->agents->last()->load('generation');
        $settlement = app(CooperativeExperimentSettlementService::class)->observe($last);
        $this->assertSame('phase_scope_tactic_veto_reproduced', $settlement['status']);
        $this->assertTrue($settlement['evidence_complete']);
        $this->assertFalse($settlement['credit_allowed']);
        $this->assertSame(ProofFrontierService::PHASE_PROBE_REFREEZE_PROTOCOL,
            data_get($settlement, 'component_effects.protocol'));
        $this->assertSame(0, ResearchExperimentReceipt::query()->count());
        $this->assertSame(0, ContextualInstrumentBundleEffect::query()->count());

        $generation->update(['status' => 'screened']);
        $followup = app(ProofFrontierService::class)->propose($lab, $this->plan($passport));
        $this->assertSame('proposed', $followup['status']);
        $this->assertTrue((bool) data_get($followup, 'proposal.source_owned_probe'));
        $this->assertSame($control->id, data_get($followup, 'proposal.source_agent_id'));
        $nextAllocation = app(CooperativeContextualEvolutionCouncilService::class)
            ->allocate($this->plan($passport), $lab, [], 3);
        $this->assertSame(4, data_get($nextAllocation, 'contract.seat_counts.activation_factorial'));
        $this->assertSame($control->id,
            data_get($nextAllocation, 'contract.proof_frontier.proposal.source_agent_id'));
        $nextGeneration = LabGeneration::create(['ai_laboratory_id' => $lab->id,
            'generation' => 3, 'trigger_type' => 'test',
            'trigger_context' => ['generation_plan' => $nextAllocation['plan'],
                'population_group_contract' => ['contextual_allocator' => $nextAllocation['contract']]],
            'population_size' => 20, 'status' => 'draft']);
        $this->assertSame([], app(CooperativeContextualEvolutionCouncilService::class)
            ->allocationReasons($nextGeneration->fresh(['laboratory'])));
        $nextPairs = app(ResearchAllocationPolicyService::class)->materializeNormalControlPairing(
            $nextAllocation['plan'], 'XAUUSD', 'H1', $nextGeneration->id,
        );
        $this->assertTrue((bool) data_get($nextPairs, 'contract.allowed'));
        foreach (array_slice($nextPairs['plan'], 0, 4) as $index => $slot) {
            $failure = null;
            $arguments = [$nextGeneration, (string) $slot['family'], (string) $slot['origin'],
                $index + 1, (string) $slot['target'], (array) $slot['niche'], null,
                (string) data_get($slot, 'research_group'),
                (int) data_get($slot, 'group_seat', 0), &$failure];
            $this->assertTrue((bool) $constructor->invokeArgs(
                app(LabPopulationService::class), $arguments,
            ), (string) $failure);
        }
        $this->assertSame([], app(ActivationFactorialContractService::class)->reasons(
            $nextGeneration->fresh(['agents.modelVersion']),
        ));

        $context = (array) $generation->trigger_context;
        data_set($context, 'specialist_council_contract.contextual_allocator.phase_scope_probe',
            $allocation['contract']['phase_scope_probe']);
        $generation->update(['trigger_context' => $context]);
        $this->assertSame('not_proposed', data_get(
            app(ProofFrontierService::class)->proposePhaseScope($lab, $this->plan($passport)), 'status'));

        $candidate = $last->modelVersion;
        $candidate->update(['parameters' => [...$candidate->parameters, 'lookback' => 99]]);
        $this->assertContains('PHASE_PROBE_EXECUTABLE_DELTA_MISMATCH',
            app(PhaseScopeProbeContractService::class)->reasons($generation->fresh(['agents.modelVersion'])));
        $context = (array) $generation->trigger_context;
        data_set($context, 'mtf_bundle_manifest.streams.M5.last_candle_at', '2026-09-01 00:00:00');
        $generation->update(['trigger_context' => $context]);
        $this->assertContains('PHASE_PROBE_PAPER_EPOCH_FORBIDDEN',
            app(PhaseScopeProbeContractService::class)->reasons($generation->fresh(['agents.modelVersion'])));
        LabEvidenceArtifact::query()->where('run_id', data_get($allocation,
            'contract.phase_scope_probe.proposal.source_run_id'))
            ->where('artifact_type', 'evaluation_request')->update(['sha256' => str_repeat('0', 64)]);
        $this->assertContains('PHASE_PROBE_SOURCE_EVIDENCE_INVALID',
            app(PhaseScopeProbeContractService::class)->reasons($generation->fresh(['agents.modelVersion'])));
        $metadata = (array) $control->modelVersion->metadata;
        data_set($metadata,
            'smart_composition.composition_passport.strategy_signal_scope.regimes.0', 'forged_regime');
        $control->modelVersion->update(['metadata' => $metadata]);
        $this->assertContains('PHASE_PROBE_PROSPECTIVE_PASSPORT_INVALID',
            app(PhaseScopeProbeContractService::class)->reasons($generation->fresh(['agents.modelVersion'])));
    }

    public function test_real_constructor_materializes_the_sealed_four_arm_genomes(): void
    {
        [$lab, $passport] = $this->source();
        $allocation = app(CooperativeContextualEvolutionCouncilService::class)
            ->allocate($this->plan($passport), $lab);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id,
            'generation' => 2, 'trigger_type' => 'test', 'trigger_context' => [],
            'population_size' => 20, 'status' => 'draft']);
        $paired = app(ResearchAllocationPolicyService::class)->materializeNormalControlPairing(
            $allocation['plan'], 'XAUUSD', 'H1', $generation->id,
        );
        $this->assertTrue((bool) data_get($paired, 'contract.allowed'));
        $constructor = new \ReflectionMethod(LabPopulationService::class, 'createAgent');
        foreach (array_slice($paired['plan'], 0, 4) as $index => $slot) {
            $failure = null;
            $arguments = [$generation, (string) $slot['family'], (string) $slot['origin'],
                $index + 1, (string) $slot['target'], (array) $slot['niche'], null,
                (string) data_get($slot, 'research_group'),
                (int) data_get($slot, 'group_seat', 0), &$failure];
            $this->assertTrue((bool) $constructor->invokeArgs(
                app(LabPopulationService::class), $arguments,
            ), (string) $failure);
        }
        $this->assertSame([], app(ActivationFactorialContractService::class)->reasons(
            $generation->fresh(['agents.modelVersion']),
        ));
    }

    public function test_a_verified_source_opens_one_research_only_four_arm_block_and_never_economic_credit(): void
    {
        [$lab, $passport] = $this->source();
        $allocation = app(CooperativeContextualEvolutionCouncilService::class)->allocate($this->plan($passport), $lab);
        $this->assertSame('proposed', data_get($allocation, 'contract.proof_frontier.status'));
        $this->assertSame(4, data_get($allocation, 'contract.seat_counts.activation_factorial'));
        $this->assertSame(8, data_get($allocation, 'contract.seat_counts.repair_pair'));
        $this->assertCount(20, $allocation['plan']);
        $seats = collect($allocation['plan'])->filter(fn (array $seat): bool =>
            data_get($seat, 'niche.cooperative_experiment_block.block_type') === 'activation_factorial'
        )->values();
        $this->assertSame(['control', 'a_only', 'b_only', 'a_plus_b'],
            $seats->pluck('niche.cooperative_experiment_block.arm')->all());
        $this->assertTrue($seats->every(fn (array $seat): bool =>
            data_get($seat, 'niche.activation_factorial.credit_allowed') === false
            && data_get($seat, 'niche.composition_passport.components') === $passport['components']));
        $plans = $seats->pluck('niche.activation_factorial.validation_plan');
        $this->assertCount(1, $plans->unique(fn (array $plan): string => (string) $plan['plan_hash']));
        $this->assertSame('2027-01-01T00:00:00+00:00', data_get($plans->first(), 'validation_start_inclusive'));
        $this->assertFalse((bool) data_get($plans->first(), 'paper_2026_eligible'));

        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 2,
            'trigger_type' => 'test', 'trigger_context' => [], 'population_size' => 4, 'status' => 'screened']);
        $agents = collect();
        $models = [];
        $schema = app(StrategyParameterSchemaService::class);
        foreach ($seats as $seat) {
            $arm = (string) data_get($seat, 'niche.cooperative_experiment_block.arm');
            $block = (array) data_get($seat, 'niche.cooperative_experiment_block');
            $cell = (array) data_get($seat, 'niche.contextual_specialist_cell');
            $parameters = $schema->defaults('breakout');
            if (in_array($arm, ['a_only', 'a_plus_b'], true)) {
                $parameters[data_get($block, 'factor_a.gene')] = data_get($block, 'factor_a.value');
            }
            if (in_array($arm, ['b_only', 'a_plus_b'], true)) {
                $parameters[data_get($block, 'factor_b.gene')] = data_get($block, 'factor_b.value');
            }
            $hash = hash('sha256', json_encode([
                ProofFrontierService::PROTOCOL, 'breakout',
                $schema->canonicalizeForIdentity('breakout', $parameters),
                $passport['components'], $cell['cell_hash'],
            ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            $baseline = $arm === 'a_only' ? ($models['control']->id ?? null)
                : ($arm === 'a_plus_b' ? ($models['b_only']->id ?? null) : null);
            $model = ModelVersion::create(['name' => 'activation-'.$arm, 'strategy' => 'breakout',
                'version' => 'v2', 'generation' => 2, 'status' => 'testing',
                'parameters' => $parameters, 'evidence_status' => 'valid', 'metadata' => [
                    'cooperative_experiment_block' => $block,
                    'smart_composition' => ['composition_passport' => $passport],
                    'specialist_council_membership' => ['contextual_cell' => $cell],
                    'cooperative_evolution_capsule' => ['context_cell_hash' => $cell['cell_hash']],
                    'activation_factorial' => [...(array) data_get($seat, 'niche.activation_factorial'),
                        'executable_hash' => $hash],
                    'causal_baseline_model_version_id' => $baseline,
                    'activation_factorial_baseline_model_version_id' => $arm === 'b_only'
                        ? $models['control']->id : ($arm === 'control'
                            ? (int) data_get($allocation, 'contract.proof_frontier.proposal.source_model_version_id')
                            : null),
                ]]);
            $models[$arm] = $model;
            $agent = LabAgent::create(['lab_generation_id' => $generation->id,
                'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'strategy_family' => 'breakout', 'origin' => 'test',
                'lifecycle_status' => 'rejected', 'parameter_diff' => []]);
            $agents->push($agent);
            $this->screen($agent, $cell, $arm === 'a_plus_b' ? 3 : 0,
                $arm === 'a_plus_b' ? 25 : 20);
        }
        $this->assertSame([], app(ActivationFactorialContractService::class)->reasons($generation->fresh()));
        $last = $agents->last()->fresh(['generation', 'modelVersion']);
        $settlement = app(CooperativeExperimentSettlementService::class)->observe($last);
        $this->assertSame('joint_tactic_activation_hypothesis', $settlement['status']);
        $this->assertTrue($settlement['evidence_complete']);
        $this->assertFalse($settlement['credit_allowed']);
        $this->assertSame('not_evaluated', data_get($settlement, 'component_effects.economic_claim'));
        $this->assertSame('recorded', data_get($settlement, 'frontier_conversion.status'));
        $this->assertSame('BEHAVIORAL_ACTIVATION_HYPOTHESIS',
            ResearchExperimentReceipt::query()->sole()->classification);
        $this->assertSame('SEMANTIC', DB::table('research_knowledge_entries')->value('knowledge_type'));
        $this->assertSame('blocked', ResearchExperimentWorkItem::query()->sole()->status);
        $this->assertFalse((bool) data_get(ResearchExperimentWorkItem::query()->sole()->payload, 'executable'));
        $this->assertSame('reserved_awaiting_authorized_research_epoch',
            data_get(ResearchExperimentWorkItem::query()->sole()->payload, 'validation_window_status'));
        $this->assertSame(data_get($plans->first(), 'plan_hash'),
            data_get(ResearchExperimentWorkItem::query()->sole()->payload, 'validation_plan.plan_hash'));
        $this->assertSame(data_get($plans->first(), 'plan_hash'),
            data_get(ResearchExperimentReceipt::query()->sole()->payload, 'contract.identity.window_plan_hash'));
        $this->assertSame(0, ContextualInstrumentBundleEffect::query()->count());
        $this->assertSame(0, CooperativeModuleSpeciesMember::query()->where('authority_level', 'repair_credit')->count());
        $replayedDelivery = app(CooperativeExperimentSettlementService::class)->observe($last);
        $this->assertSame(data_get($settlement, 'frontier_conversion.receipt_key'),
            data_get($replayedDelivery, 'frontier_conversion.receipt_key'));
        $this->assertSame(1, ResearchExperimentReceipt::query()->count());
        $this->assertSame(1, ResearchExperimentWorkItem::query()->count());

        $tampered = $models['a_plus_b'];
        $tampered->update(['parameters' => [...$tampered->parameters, 'lookback' => 99]]);
        $this->assertContains('ACTIVATION_FACTORIAL_ARM_IDENTITY_MISMATCH',
            app(ActivationFactorialContractService::class)->reasons($generation->fresh()));
    }

    public function test_same_discovery_hypothesis_is_not_replayed_on_the_same_frozen_data(): void
    {
        [$lab, $passport] = $this->source();
        $plan = $this->plan($passport);
        $first = app(ProofFrontierService::class)->propose($lab, $plan);
        $this->assertSame('proposed', $first['status']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 2,
            'trigger_type' => 'test', 'trigger_context' => [
                'specialist_council_contract' => ['contextual_allocator' => [
                    'proof_frontier' => ['proposal' => [
                        'hypothesis_key' => data_get($first, 'proposal.hypothesis_key'),
                    ]],
                ]],
            ], 'population_size' => 1, 'status' => 'screened']);
        $model = ModelVersion::create(['name' => 'already-discovered', 'strategy' => 'breakout',
            'version' => 'v2', 'generation' => 2, 'status' => 'testing', 'parameters' => [],
            'evidence_status' => 'valid', 'metadata' => ['activation_factorial' => [
                'hypothesis_key' => data_get($first, 'proposal.hypothesis_key')]]]);
        LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'breakout',
            'origin' => 'test', 'lifecycle_status' => 'rejected', 'parameter_diff' => []]);

        $again = app(ProofFrontierService::class)->propose($lab, $plan);
        $this->assertSame('not_proposed', $again['status']);
        $this->assertSame('discovery_trial_budget_exhausted_on_frozen_data', $again['reason']);
    }

    public function test_second_intervention_cannot_come_from_another_composition_passport(): void
    {
        [$lab, $passport] = $this->source();
        $plan = $this->plan($passport);
        foreach (array_keys($plan) as $index) {
            if ($index === 0) {
                continue;
            }
            data_set($plan[$index], 'niche.composition_passport.components.strategy.id', 'another_strategy');
        }

        $proposal = app(ProofFrontierService::class)->propose($lab, $plan);

        $this->assertSame('not_proposed', $proposal['status']);
        $this->assertSame('no_compatible_source_and_legal_two_axis_probe', $proposal['reason']);
        $this->assertSame('two_distinct_legal_upstream_axes', $proposal['first_missing_proof']);
    }

    public function test_an_unphased_signal_source_reports_the_missing_context_without_opening_a_block(): void
    {
        [$lab, $passport] = $this->source();
        $agent = $lab->generations()->firstOrFail()->agents()->firstOrFail();
        $model = $agent->modelVersion;
        $metadata = (array) $model->metadata;
        data_set($metadata, 'specialist_council_membership.contextual_cell', null);
        $model->update(['metadata' => $metadata]);

        $proposal = app(ProofFrontierService::class)->propose($lab, $this->plan($passport));

        $this->assertSame('not_proposed', $proposal['status']);
        $this->assertSame('eligible_source_venue_phase', $proposal['first_missing_proof']);
        $this->assertSame(1, data_get($proposal, 'eligibility_funnel.runtime_signal_gap_sources'));
        $this->assertSame(0, data_get($proposal, 'eligibility_funnel.phase_bound_gap_sources'));
        $this->assertSame([$agent->id], data_get($proposal, 'eligibility_funnel.unphased_signal_gap_agent_ids'));
        $this->assertFalse($proposal['promotion_evidence']);

        $allocation = app(CooperativeContextualEvolutionCouncilService::class)
            ->allocate($this->plan($passport), $lab);
        $this->assertCount(20, $allocation['plan']);
        $this->assertSame(0, data_get($allocation, 'contract.seat_counts.activation_factorial', 0));
        $this->assertSame('eligible_source_venue_phase',
            data_get($allocation, 'contract.proof_frontier.first_missing_proof'));
    }

    public function test_one_legal_axis_per_passport_is_not_a_two_axis_activation_experiment(): void
    {
        [$lab, $passport] = $this->source();
        $plan = $this->plan($passport);
        foreach ($plan as &$slot) {
            data_set($slot, 'niche.declared_gene', 'lookback');
            data_set($slot, 'niche.declared_value', 30);
        }
        unset($slot);

        $proposal = app(ProofFrontierService::class)->propose($lab, $plan);

        $this->assertSame('not_proposed', $proposal['status']);
        $this->assertSame('two_distinct_legal_upstream_axes', $proposal['first_missing_proof']);
        $this->assertSame(1, data_get($proposal, 'eligibility_funnel.same_passport_plan_sources'));
        $this->assertSame(0, data_get($proposal, 'eligibility_funnel.two_legal_axis_sources'));
    }

    public function test_missing_liquidity_is_a_dependency_not_a_factorial_mutation_opportunity(): void
    {
        [$lab, $passport] = $this->source();
        $decision = CandidateGateDecision::firstOrFail();
        $metrics = (array) $decision->metrics;
        data_set($metrics, 'data_quality.specialist_signal_scope', [
            'raw_strategy_signal_count' => 30, 'accepted_signal_count' => 0,
            'signal_predicate_failure_counts' => ['liquidity_observation_missing' => 30],
        ]);
        $decision->update(['metrics' => $metrics]);
        $proposal = app(ProofFrontierService::class)->propose($lab, $this->plan($passport));
        $this->assertSame('not_proposed', $proposal['status']);
        $this->assertSame('observed_liquidity_data', $proposal['first_missing_proof']);
        $this->assertSame(1, data_get($proposal, 'eligibility_funnel.missing_observed_liquidity_sources'));
        $this->assertFalse($proposal['promotion_evidence']);
    }

    public function test_zero_attested_context_opportunities_does_not_spend_four_replay_seats(): void
    {
        [$lab, $passport] = $this->source(true, [
            'raw_strategy_signal_count' => 30, 'accepted_signal_count' => 0,
            'signal_predicate_failure_counts' => ['phase_outside_scope' => 30],
        ]);

        $proposal = app(ProofFrontierService::class)->propose($lab, $this->plan($passport));
        $this->assertSame('not_proposed', $proposal['status']);
        $this->assertSame('sealed_source_not_learnable_for_two_axes', $proposal['reason']);
        $this->assertSame('source_data_stage_and_control_path_for_both_axes', $proposal['first_missing_proof']);
        $this->assertSame(0, data_get($proposal, 'eligibility_funnel.learnable_axis_pairs'));
        $this->assertFalse($proposal['promotion_evidence']);
    }

    public function test_intervention_is_checked_against_frozen_source_vector_not_schema_defaults(): void
    {
        $schema = app(StrategyParameterSchemaService::class);
        $slot = ['family' => 'trend', 'niche' => [
            'declared_gene' => 'trend_strength_min', 'declared_value' => 25.0,
        ]];
        $validSource = $schema->defaults('trend');
        $method = new \ReflectionMethod(ProofFrontierService::class, 'legalIntervention');
        $this->assertSame(['gene' => 'trend_strength_min', 'value' => 25.0],
            $method->invoke(app(ProofFrontierService::class), $slot, $validSource));

        // The proposed gene is legal against defaults, but normalizing this
        // source also changes ema_slow. That is a hidden second intervention.
        $invertedSource = [...$validSource, 'ema_fast' => 200, 'ema_slow' => 100];
        $this->assertNull($method->invoke(app(ProofFrontierService::class), $slot, $invertedSource));
    }

    public function test_joint_intervention_rejects_a_normalized_third_gene(): void
    {
        $source = [...app(StrategyParameterSchemaService::class)->defaults('trend'),
            'ema_fast' => 50, 'ema_slow' => 70];
        $method = new \ReflectionMethod(ProofFrontierService::class, 'exactIntervention');

        $this->assertNull($method->invoke(app(ProofFrontierService::class), 'trend', $source, [
            'ema_fast' => 80, 'trend_strength_min' => 25.0,
        ]));
        $this->assertNotNull($method->invoke(app(ProofFrontierService::class), 'trend', $source, [
            'ema_fast' => 60, 'trend_strength_min' => 25.0,
        ]));
    }

    public function test_validation_plan_is_preregistered_and_source_tamper_fails_closed(): void
    {
        [$lab, $passport] = $this->source();
        $proposal = (array) data_get(app(ProofFrontierService::class)->propose($lab, $this->plan($passport)), 'proposal');
        $plan = (array) data_get($proposal, 'validation_plan');
        $this->assertTrue(app(ActivationValidationPlanService::class)->valid($plan, $proposal));
        $this->assertSame('reserved_awaiting_authorized_research_epoch', $plan['status']);
        $this->assertSame('2027-07-01T00:00:00+00:00', $plan['validation_end_exclusive']);
        $this->assertFalse($plan['executable']);
        $this->assertFalse(app(ActivationValidationPlanService::class)->valid($plan, [
            ...$proposal, 'source_data_hash' => str_repeat('0', 64),
        ]));
        $this->assertFalse(app(ActivationValidationPlanService::class)->valid([
            ...$plan, 'paper_2026_eligible' => true,
        ], $proposal));
    }

    public function test_feedback_reallocation_survives_activation_block_replacement(): void
    {
        [$lab, $passport] = $this->source();
        $source = $lab->generations()->where('generation', 1)->firstOrFail();
        $blockKey = hash('sha256', 'activation-feedback-source');
        $settlement = CooperativeExperimentSettlement::create([
            'settlement_key' => hash('sha256', implode('|', [
                CooperativeExperimentSettlementService::PROTOCOL, $source->id, $blockKey,
            ])),
            'block_key' => $blockKey, 'lab_generation_id' => $source->id,
            'block_type' => 'repair_pair', 'context_cell_key' => hash('sha256', 'source-cell'),
            'arm_results' => [
                'exact_frozen_control' => ['evidence_status' => 'eligible', 'evidence_run_id' => 'control-run'],
                'candidate' => ['evidence_status' => 'eligible', 'evidence_run_id' => 'candidate-run'],
            ],
            'component_effects' => ['candidate_delta' => -0.1], 'pareto_vectors' => [],
            'outcome_status' => 'settled_negative_or_null', 'evidence_complete' => true,
            'promotion_evidence' => false,
        ]);
        $contract = app(CooperativeContextualEvolutionCouncilService::class)
            ->allocate($this->plan($passport), $lab)['contract'];

        $this->assertSame(4, data_get($contract, 'seat_counts.activation_factorial'));
        $this->assertSame($settlement->id,
            data_get($contract, 'settlement_feedback.decision.source_settlement_id'));
        $this->assertSame([5, 6], data_get($contract, 'settlement_feedback.decision.final_seat_numbers'));
        $this->assertSame('novelty_pair', data_get($contract, 'experiment_blocks.1.block_type'));
        $this->assertSame(20, count((array) data_get($contract, 'seat_ownership')));
        $this->assertFalse($contract['promotion_evidence']);
    }

    public function test_planned_activation_cannot_disappear_during_constructor_recovery(): void
    {
        [$lab, $passport] = $this->source();
        $allocation = app(CooperativeContextualEvolutionCouncilService::class)
            ->allocate($this->plan($passport), $lab);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id,
            'generation' => 2, 'trigger_type' => 'test',
            'trigger_context' => ['generation_plan' => $allocation['plan']],
            'population_size' => 20, 'status' => 'draft']);

        $this->assertContains('ACTIVATION_FACTORIAL_PLANNED_BLOCK_MISSING',
            app(ActivationFactorialContractService::class)->reasons($generation));
    }

    /** @return array{AiLaboratory,array<string,mixed>} */
    private function source(bool $phaseBound = true, ?array $signalScope = null): array
    {
        $family = $phaseBound ? 'breakout' : 'hybrid';
        $architecture = $phaseBound ? 'breakout_retest' : 'regime_consensus';
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'name' => 'Proof frontier',
            'timeframe' => 'H1', 'strategy_families' => [$family], 'is_active' => true,
            'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'test', 'trigger_context' => [], 'population_size' => 1, 'status' => 'screened']);
        $passport = app(CompositionAuthorityKernelService::class)->freeze([
            'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_id' => $phaseBound ? 'str_003_donchian_breakout' : 'str_032_choch_reversal',
            'tactic_id' => $architecture,
            'risk_id' => 'atr_risk_envelope',
            'management_id' => $phaseBound ? 'breakout_measured_move' : 'balanced_professional',
        ]);
        if (! $phaseBound) {
            // Emulate the immutable pre-scope G234 passport. The successor
            // must receive a new ID; never attach a new field to this source.
            unset($passport['strategy_signal_scope']);
            $passport['composition_id'] = 'xau-comp-'.substr(hash('sha256', 'legacy-unphased-source'), 0, 24);
        }
        $sourceParameters = app(StrategyParameterSchemaService::class)->defaults($family);
        if (! $phaseBound) {
            // A newly introduced default must not be smuggled into an exact
            // historical control vector during prospective phase scoping.
            unset($sourceParameters['architecture_interaction_variant']);
        }
        $model = ModelVersion::create(['name' => 'source', 'strategy' => $family, 'version' => 'v1',
            'generation' => 1, 'status' => 'testing',
            'parameters' => $sourceParameters,
            'evidence_status' => 'valid',
            'metadata' => ['smart_composition' => ['composition_passport' => $passport],
                'base_strategy' => app(StrategyLibraryCompilerService::class)->runtimeBaseStrategy(
                    $phaseBound ? 'str_003_donchian_breakout' : 'str_032_choch_reversal'),
                'strategy_architecture' => $architecture,
                'specialist_council_membership' => ['contextual_cell' => $phaseBound
                    ? ['venue_phase' => 'london_comex_overlap'] : null]]]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id,
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => $family, 'origin' => 'test',
            'lifecycle_status' => 'rejected', 'parameter_diff' => []]);
        $this->screen($agent, ['cell_hash' => 'source-cell',
            'session_instance_id' => 'source-session', 'outside_scope_action' => 'WAIT',
            ...($phaseBound ? ['venue_phase' => 'london_comex_overlap'] : [])], 0, 30, $signalScope);

        return [$lab, $passport];
    }

    /** @return array<int,array<string,mixed>> */
    private function plan(array $passport): array
    {
        return array_map(static fn (int $i): array => [
            'origin' => 'proof_frontier', 'family' => 'breakout', 'target' => 'bootstrap',
            'niche' => ['composition_passport' => $passport,
                'composition_lane' => 'strategy_composition',
                'composition_architecture' => 'breakout_retest',
                'tactic_library_key' => 'breakout_retest',
                'declared_gene' => $i % 2 ? 'trend_strength_min' : 'lookback',
                'declared_value' => $i % 2 ? 25.0 : 30,
                'regime' => 'trend_up', 'volatility' => 'normal_volatility'],
        ], range(0, 19));
    }

    private function screen(LabAgent $agent, array $cell, int $accepted, int $signals, ?array $signalScope = null): void
    {
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($agent, 'screening', 'incremental');
        $dataHash = str_repeat('d', 64);
        $evidence->attachRequest($run, ['strategies' => [[
            'lab_agent_id' => $agent->id, 'strategy' => $agent->strategy_family,
            'parameters' => $agent->modelVersion->parameters,
            'composition_runtime_contract' => ['composition_id' => data_get(
                $agent->modelVersion->metadata, 'smart_composition.composition_passport.composition_id')],
            'specialist_context_contract' => $cell,
        ]], 'execution_contract' => ['execution_hash' => str_repeat('e', 64)]],
            ['data_hash' => $dataHash, 'dataset_manifest' => [
                'data_hash' => $dataHash, 'mtf_bundle_hash' => str_repeat('m', 64),
            ]]);
        $receipts = array_map(static fn (int $index): array => [
            'paired_context_id' => hash('sha256', 'context-'.$index),
            'tactic_signal' => $index < $accepted ? 'BUY' : 'WAIT',
            'preentry_accepted' => false,
        ], range(0, $signals - 1));
        $trace = ['protocol' => 'xauusd_composition_runtime_trace_v3',
                    'composition_id' => data_get($agent->modelVersion->metadata,
                        'smart_composition.composition_passport.composition_id'),
                    'execution_receipt_valid' => true, 'component_bindings_valid' => true,
                    'authority_bindings_valid' => true, 'decision_receipts_valid' => true,
                    'observations' => ['strategy_signals_before_tactic' => $signals,
                        'accepted_entries' => 0, 'rows' => 100],
                    'component_execution' => ['tactic' => ['accepted_count' => $accepted],
                        'management' => ['completed_trade_count' => 0]],
                    'decision_receipts' => ['decision_digest' => str_repeat('a', 64),
                        'decision_count' => $signals, 'receipts' => $receipts,
                        'opportunity_count' => 100,
                        'opportunity_universe_hash' => str_repeat('u', 64),
                        'no_signal_count' => 100 - $signals,
                        'rejection_counts' => []]];
        $evidence->finishRun($run, 'completed', [
            'decision_trace' => [['event_type' => 'test', 'action' => 'WAIT']],
            'data_quality' => ['decision_trace' => ['requested' => true,
                'complete' => true, 'evaluated_candle_count' => 1],
                ...($signalScope === null ? [] : ['specialist_signal_scope' => $signalScope])],
            'trade_ledger' => [], 'trade_ledger_hash' => hash('sha256', json_encode([])),
            'total_trades' => 0, 'displayed_trade_count' => 0,
            'composition_runtime_trace' => $trace,
        ]);
        CandidateGateDecision::create(['lab_agent_id' => $agent->id, 'stage' => 'screening',
            'decision' => 'failed', 'reason_codes' => [], 'evaluated_at' => now(),
            'metrics' => ['evidence_run_id' => $run->run_id,
                'data_quality' => $signalScope === null ? [] : ['specialist_signal_scope' => $signalScope],
                'composition_runtime_trace' => $trace]]);
    }
}
