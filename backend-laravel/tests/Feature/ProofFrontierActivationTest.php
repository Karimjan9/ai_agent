<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\ContextualInstrumentBundleEffect;
use App\Models\CooperativeModuleSpeciesMember;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentReceipt;
use App\Models\ResearchExperimentWorkItem;
use App\Services\ActivationFactorialContractService;
use App\Services\CompositionAuthorityKernelService;
use App\Services\CooperativeContextualEvolutionCouncilService;
use App\Services\CooperativeExperimentSettlementService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Services\ProofFrontierService;
use App\Services\ResearchAllocationPolicyService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProofFrontierActivationTest extends TestCase
{
    use RefreshDatabase;

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
    private function source(): array
    {
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'name' => 'Proof frontier',
            'timeframe' => 'H1', 'strategy_families' => ['breakout'], 'is_active' => true,
            'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'test', 'trigger_context' => [], 'population_size' => 1, 'status' => 'screened']);
        $passport = app(CompositionAuthorityKernelService::class)->freeze([
            'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_id' => 'str_003_donchian_breakout',
            'tactic_id' => 'breakout_retest',
            'risk_id' => 'atr_risk_envelope',
            'management_id' => 'breakout_measured_move',
        ]);
        $model = ModelVersion::create(['name' => 'source', 'strategy' => 'breakout', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing',
            'parameters' => app(StrategyParameterSchemaService::class)->defaults('breakout'),
            'evidence_status' => 'valid',
            'metadata' => ['smart_composition' => ['composition_passport' => $passport],
                'strategy_architecture' => 'breakout_retest',
                'specialist_council_membership' => ['contextual_cell' =>
                    ['venue_phase' => 'london_comex_overlap']]]]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id,
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'breakout', 'origin' => 'test',
            'lifecycle_status' => 'rejected', 'parameter_diff' => []]);
        $this->screen($agent, ['cell_hash' => 'source-cell',
            'session_instance_id' => 'source-session', 'outside_scope_action' => 'WAIT',
            'venue_phase' => 'london_comex_overlap'], 0, 30);

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

    private function screen(LabAgent $agent, array $cell, int $accepted, int $signals): void
    {
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($agent, 'screening', 'incremental');
        $dataHash = str_repeat('d', 64);
        $evidence->attachRequest($run, ['strategies' => [[
            'lab_agent_id' => $agent->id, 'strategy' => 'breakout',
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
                'complete' => true, 'evaluated_candle_count' => 1]],
            'trade_ledger' => [], 'trade_ledger_hash' => hash('sha256', json_encode([])),
            'total_trades' => 0, 'displayed_trade_count' => 0,
            'composition_runtime_trace' => $trace,
        ]);
        CandidateGateDecision::create(['lab_agent_id' => $agent->id, 'stage' => 'screening',
            'decision' => 'failed', 'reason_codes' => [], 'evaluated_at' => now(),
            'metrics' => ['evidence_run_id' => $run->run_id,
                'composition_runtime_trace' => $trace]]);
    }
}
