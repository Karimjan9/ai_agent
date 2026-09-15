<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\ContextualInstrumentBundleEffect;
use App\Models\ContextualSpecialistCapsule;
use App\Models\LabEvolutionArchiveEntry;
use App\Models\CooperativeExperimentSettlement;
use App\Models\CooperativeModuleSpeciesMember;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchIdeaInboxEntry;
use App\Services\ContextualCapsuleArchiveService;
use App\Services\ContextualCouncilAllocatorService;
use App\Services\CandidateGateDecisionService;
use App\Services\CooperativeContextualEvolutionCouncilService;
use App\Services\CooperativeExperimentSettlementService;
use App\Services\CooperativeModuleSpeciesService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchAllocationPolicyService;
use App\Services\ResearchIdeaInboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CooperativeContextualEvolutionCouncilTest extends TestCase
{
    use RefreshDatabase;

    public function test_cold_start_is_twenty_seats_of_dynamic_scientific_blocks_and_seven_species(): void
    {
        $lab = $this->lab();
        $allocation = app(ContextualCouncilAllocatorService::class)->allocate($this->plan(), $lab);
        $contract = $allocation['contract'];

        $this->assertSame(CooperativeContextualEvolutionCouncilService::PROTOCOL, $contract['protocol']);
        $this->assertCount(20, $allocation['plan']);
        $this->assertFalse($contract['permanent_semantic_group_quotas']);
        $this->assertSame(10, $contract['block_count']);
        $this->assertSame(12, data_get($contract, 'seat_counts.repair_pair'));
        $this->assertSame(6, data_get($contract, 'seat_counts.novelty_pair'));
        $this->assertSame(2, data_get($contract, 'seat_counts.adversarial_guard'));
        $this->assertNull(data_get($contract, 'seat_counts.factorial'));
        $this->assertTrue(data_get($contract, 'cold_start_constitution.factorial_deferred_until_positive_stepping_stone'));
        $this->assertSame(CooperativeModuleSpeciesService::SPECIES, array_keys(data_get($contract, 'module_species.species')));
        $this->assertTrue(collect($contract['priority_ledger'])->every(fn (array $row): bool => array_key_exists('expected_information_gain', $row) && array_key_exists('overfit_risk', $row)
        ));

        $paired = app(ResearchAllocationPolicyService::class)->materializeNormalControlPairing(
            $allocation['plan'], 'XAUUSD', 'H1', 1
        );
        $this->assertTrue(data_get($paired, 'contract.allowed'));
        $this->assertSame('cooperative_experiment_blocks', data_get($paired, 'contract.mode'));
        $this->assertSame(10, data_get($paired, 'contract.pair_count'));
        $this->assertTrue(collect(data_get($paired, 'contract.materialized_controls'))
            ->every(fn (array $pair): bool => ! $pair['factorial_baseline_intervention']));
    }

    public function test_ready_idea_is_compiled_into_a_novelty_block_but_gets_no_runtime_authority(): void
    {
        $lab = $this->lab();
        $submitted = app(ResearchIdeaInboxService::class)->submit([
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'source_type' => 'internet',
            'source_reference' => 'research-note', 'title' => 'Cost-aware London exit',
            'hypothesis' => 'A bounded cost-aware exit improves the London cell against an exact control.',
            'bounded_genes' => [['key' => 'time_stop_candles', 'minimum' => 3, 'maximum' => 12]],
            'context_scope' => ['venue_phase' => 'london_comex_overlap'],
        ]);
        $this->assertSame('ready_for_experiment', $submitted['status']);

        $allocation = app(ContextualCouncilAllocatorService::class)->allocate($this->plan(), $lab);
        $this->assertSame(1, data_get($allocation, 'contract.idea_inbox.assigned'));
        $entry = ResearchIdeaInboxEntry::query()->firstOrFail();
        $this->assertSame('assigned_to_frozen_experiment', $entry->status);
        $this->assertNotNull($entry->assigned_block_key);
        $ideaSeats = collect($allocation['plan'])->filter(fn (array $slot): bool => data_get($slot, 'niche.cooperative_evolution_capsule.idea_reference.idea_key') === $entry->idea_key
        );
        $this->assertCount(2, $ideaSeats);
        $this->assertTrue($ideaSeats->every(fn (array $slot): bool => data_get($slot, 'niche.cooperative_evolution_capsule.promotion_evidence') === false));
    }

    public function test_confirmed_local_elite_is_not_replaced_by_a_non_dominating_capsule(): void
    {
        [$generation, $first] = $this->agentWithCapsule('first', 1);
        $evidence = $this->confirmedEvidence(1.4, 1.1, 8.0);
        $firstResult = app(ContextualCapsuleArchiveService::class)->recordScreening($first, $evidence);
        $this->assertSame('elite', $firstResult['status']);

        [, $second] = $this->agentWithCapsule('second', 2, $generation);
        $secondResult = app(ContextualCapsuleArchiveService::class)->recordScreening($second, $this->confirmedEvidence(1.2, .8, 12.0));
        $this->assertSame('challenger', $secondResult['status']);
        $this->assertSame($first->model_version_id, ContextualSpecialistCapsule::query()->where('status', 'elite')->value('model_version_id'));
        $this->assertSame(14, CooperativeModuleSpeciesMember::query()->count());
    }

    public function test_factorial_settlement_records_marginal_interaction_and_whole_capsule_effects(): void
    {
        $lab = $this->lab();
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'test', 'trigger_context' => [], 'population_size' => 4, 'status' => 'screened']);
        $blockKey = hash('sha256', 'factorial-test');
        $values = ['control' => 1.0, 'a_only' => 1.3, 'b_only' => 1.2, 'a_plus_b' => 1.8];
        $context = ['protocol' => CooperativeContextualEvolutionCouncilService::PROTOCOL,
            'cell_hash' => 'cell-1', 'session_instance_id' => 'session-instance-1',
            'outside_scope_action' => 'WAIT'];
        $last = null;
        foreach ($values as $index => $value) {
            $bundle = match ($index) {
                'a_only' => ['atr_risk_envelope'],
                'b_only' => ['cost_aware_exit'],
                'a_plus_b' => ['atr_risk_envelope', 'cost_aware_exit'],
                default => [],
            };
            $model = ModelVersion::create(['name' => 'factorial-'.$index, 'strategy' => 'hybrid', 'version' => 'v1',
                'generation' => 1, 'status' => 'testing', 'parameters' => [], 'evidence_status' => 'valid',
                'metadata' => ['cooperative_experiment_block' => ['protocol' => CooperativeContextualEvolutionCouncilService::PROTOCOL,
                    'block_key' => $blockKey, 'block_type' => 'factorial', 'arm' => $index,
                    'required_arms' => array_keys($values), 'component_a' => 'atr_risk_envelope',
                    'component_b' => 'cost_aware_exit', 'context_cell_key' => 'cell-1'],
                    'specialist_council_membership' => ['contextual_cell' => $context],
                    'cooperative_evolution_capsule' => [
                        'context_cell_hash' => 'cell-1', 'components' => ['toolbox_instrument' => $bundle]]]]);
            $last = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
                'origin' => 'test', 'lifecycle_status' => 'rejected', 'parameter_diff' => []]);
            $dataHash = str_repeat('d', 64);
            $run = app(LabImmutableEvidenceService::class)->beginRun($last, 'screening', 'incremental');
            app(LabImmutableEvidenceService::class)->attachRequest($run, [
                'strategies' => [[
                    'lab_agent_id' => $last->id,
                    'strategy' => $model->strategy,
                    'specialist_context_contract' => $context,
                ]],
                'execution_contract' => ['execution_hash' => str_repeat('e', 64)],
            ], ['data_hash' => $dataHash, 'dataset_manifest' => ['data_hash' => $dataHash]]);
            app(LabImmutableEvidenceService::class)->finishRun($run, 'completed', [
                'decision_trace' => [['event_type' => 'test', 'action' => 'WAIT']],
                'data_quality' => ['decision_trace' => ['requested' => true, 'complete' => true, 'evaluated_candle_count' => 1]],
                'trade_ledger' => [],
                'trade_ledger_hash' => hash('sha256', json_encode([])),
                'total_trades' => 0,
                'displayed_trade_count' => 0,
            ]);
            CandidateGateDecision::create(['lab_agent_id' => $last->id, 'stage' => 'screening', 'decision' => 'failed',
                'reason_codes' => [], 'metrics' => ['after_cost_expectancy_r' => $value, 'evidence_run_id' => $run->run_id], 'evaluated_at' => now()]);
        }
        $settlement = app(CooperativeExperimentSettlementService::class)->observe($last->fresh(['generation', 'modelVersion']));
        $this->assertSame('settled_positive_signal', $settlement['status']);
        $this->assertEquals(.3, data_get($settlement, 'component_effects.component_a_marginal_effect'));
        $this->assertEquals(.2, data_get($settlement, 'component_effects.component_b_marginal_effect'));
        $this->assertEquals(.3, data_get($settlement, 'component_effects.interaction_effect'));
        $this->assertEquals(.8, data_get($settlement, 'component_effects.whole_capsule_effect'));
        $this->assertTrue(CooperativeExperimentSettlement::query()->firstOrFail()->evidence_complete);
        $this->assertSame(6, ContextualInstrumentBundleEffect::query()->count());
        $interaction = ContextualInstrumentBundleEffect::query()->where('effect_type', 'interaction')->firstOrFail();
        $this->assertEquals(.3, $interaction->interaction_effect);
        $this->assertSame('research_only', $interaction->authority_level);
        $this->assertFalse((bool) data_get($interaction->evidence, 'global_inheritance_allowed'));
        $this->assertCount(2, ContextualInstrumentBundleEffect::query()->where('effect_type', 'leave_one_out')->get());

        ContextualSpecialistCapsule::create([
            'capsule_key' => hash('sha256', 'factorial-last-capsule'),
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'context_cell_key' => 'cell-1',
            'model_version_id' => $last->model_version_id, 'lab_agent_id' => $last->id,
            'identity' => ['venue_phase' => 'london_comex_overlap'],
            'components' => ['toolbox_instrument' => ['atr_risk_envelope', 'cost_aware_exit']],
            'activation_contract' => ['outside_scope_action' => 'WAIT'],
            'pareto_vector' => ['after_cost_expectancy' => 1.8],
            'evidence' => ['promotion_evidence' => false],
            'authority_level' => 'research_only', 'status' => 'challenger',
        ]);
        LabEvolutionArchiveEntry::create([
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'island_key' => 'cell-1', 'archive_type' => 'contextual_capsule',
            'model_version_id' => $last->model_version_id, 'lab_agent_id' => $last->id,
            'lab_generation_id' => $generation->id, 'rank' => 0, 'novelty_score' => 1,
            'metadata' => ['promotion_evidence' => false], 'status' => 'challenger',
        ]);
        $retryIdea = app(ResearchIdeaInboxService::class)->submit([
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'source_type' => 'agent',
            'title' => 'Retry invalid factorial proof',
            'hypothesis' => 'The bounded bundle should be retried only in a fresh exact block.',
            'bounded_genes' => [['key' => 'time_stop_candles', 'minimum' => 3, 'maximum' => 12]],
        ]);
        app(ResearchIdeaInboxService::class)->assign((int) $retryIdea['entry_id'], $blockKey);

        $decision = CandidateGateDecision::query()->where('lab_agent_id', $last->id)->firstOrFail();
        $decision->update(['metrics' => ['after_cost_expectancy_r' => 1.8, 'evidence_run_id' => 'missing-run']]);
        $invalid = app(CooperativeExperimentSettlementService::class)->observe($last->fresh(['generation', 'modelVersion']));

        $this->assertSame('invalid_arm_evidence', $invalid['status']);
        $this->assertFalse($invalid['evidence_complete']);
        $this->assertSame(
            ContextualInstrumentBundleEffect::query()->count(),
            ContextualInstrumentBundleEffect::query()->where('authority_level', 'invalid_evidence')->count(),
        );
        $this->assertSame('invalid_evidence', ContextualSpecialistCapsule::query()
            ->where('lab_agent_id', $last->id)->value('status'));
        $this->assertSame('invalid_evidence', LabEvolutionArchiveEntry::query()
            ->where('lab_agent_id', $last->id)->where('archive_type', 'contextual_capsule')->value('status'));
        $idea = ResearchIdeaInboxEntry::query()->findOrFail((int) $retryIdea['entry_id']);
        $this->assertSame('ready_for_experiment', $idea->status);
        $this->assertNull($idea->assigned_block_key);
        $this->assertSame('invalid_evidence_retry_required', data_get($idea->evidence_receipt, 'status'));

        $this->assertSame(0, Artisan::call('trading:reconcile-cooperative-settlements', [
            'symbol' => 'XAUUSD', '--timeframe' => 'H1', '--json' => true,
        ]));
        $scheduled = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $scheduled['scanned']);
    }

    public function test_derived_cooperative_projection_failure_cannot_invalidate_the_screening_gate(): void
    {
        [, $agent] = $this->agentWithCapsule('projection-isolation', 1);
        $this->mock(CooperativeExperimentSettlementService::class, function ($mock): void {
            $mock->shouldReceive('observe')->once()->andThrow(new \RuntimeException('derived projection failed'));
        });
        $method = new \ReflectionMethod(CandidateGateDecisionService::class, 'cooperativeSettlement');
        $method->setAccessible(true);

        $result = $method->invoke(app(CandidateGateDecisionService::class), $agent);

        $this->assertSame(CooperativeExperimentSettlementService::PROTOCOL, $result['protocol']);
        $this->assertSame('projection_deferred', $result['status']);
        $this->assertSame('reconcile_cooperative_experiment_settlement', $result['retry_action']);
        $this->assertFalse($result['promotion_evidence']);
        $this->assertNotEmpty($result['error_fingerprint']);
    }

    private function lab(): AiLaboratory
    {
        return AiLaboratory::create(['symbol' => 'XAUUSD', 'name' => 'Cooperative council', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
    }

    /** @return array<int,array<string,mixed>> */
    private function plan(): array
    {
        $genes = ['lookback', 'minimum_signal_confidence', 'atr_stop_multiplier', 'time_stop_candles'];
        $plan = [];
        for ($i = 0; $i < 20; $i++) {
            $plan[] = ['origin' => 'g98_council', 'family' => 'hybrid', 'target' => 'bootstrap',
                'niche' => ['declared_gene' => $genes[$i % 4], 'declared_value' => $i + 1,
                    'regime' => $i % 2 ? 'range' : 'trend_up', 'volatility' => 'normal_volatility']];
        }

        return $plan;
    }

    /** @return array{0:LabGeneration,1:LabAgent} */
    private function agentWithCapsule(string $name, int $generationNumber, ?LabGeneration $generation = null): array
    {
        if ($generation === null) {
            $lab = $this->lab();
            $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
                'trigger_type' => 'test', 'trigger_context' => [], 'population_size' => 2, 'status' => 'screened']);
        }
        $identity = ['regime' => 'trend_up', 'venue_phase' => 'london_comex_overlap', 'session' => 'overlap',
            'session_instance_id' => 'instance-1', 'volatility' => 'normal_volatility', 'spread_liquidity' => 'liquid',
            'transition_state' => 'stable', 'direction' => 'BUY', 'trait' => 'breakout_confirmation',
            'instrument_bundle' => ['cost_aware_exit'], 'strategy' => 'donchian', 'tactic' => 'breakout_retest',
            'risk' => 'atr_risk_envelope', 'management' => 'balanced', 'calendar_version' => 'test-v1'];
        $hash = hash('sha256', json_encode($identity));
        $components = ['strategy' => 'donchian', 'model_regime_router' => 'regime_router', 'tactic' => 'breakout_retest',
            'toolbox_instrument' => ['cost_aware_exit'], 'risk' => 'atr_risk_envelope',
            'trade_management' => 'balanced', 'activation_router' => ['cell_hash' => $hash]];
        $model = ModelVersion::create(['name' => $name, 'strategy' => 'hybrid', 'version' => 'v'.$generationNumber,
            'generation' => $generationNumber, 'status' => 'testing', 'parameters' => [], 'evidence_status' => 'valid',
            'metadata' => ['contextual_specialist_identity' => ['identity' => $identity, 'identity_hash' => $hash],
                'cooperative_evolution_capsule' => ['components' => $components,
                    'component_hashes' => array_fill_keys(CooperativeModuleSpeciesService::SPECIES, $hash),
                    'context_cell_hash' => $hash, 'genome_hash' => hash('sha256', $name)]]]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'rejected', 'parameter_diff' => []]);

        return [$generation, $agent->fresh('modelVersion')];
    }

    /** @return array<string,mixed> */
    private function confirmedEvidence(float $pf, float $expectancy, float $drawdown): array
    {
        return ['profit_factor' => $pf, 'after_cost_expectancy_r' => $expectancy, 'max_drawdown_percent' => $drawdown,
            'tail_loss' => $drawdown + 2, 'session_local_stability' => .8, 'screening_survival' => ['status' => 'survivor'],
            'stress_test' => ['profit_factor' => $pf - .05], 'contextual_specialist_evidence' => [
                'screening_status' => 'passed', 'full_replay_status' => 'passed', 'exact_frozen_control' => true,
                'candidate_session_instance_ids' => ['i1'], 'control_session_instance_ids' => ['i1'],
                'frozen_control_superiority' => true, 'absolute_settlement' => $expectancy,
                'chronological_windows' => [['candidate_better_than_control' => true, 'absolute_settlement' => .2],
                    ['candidate_better_than_control' => true, 'absolute_settlement' => .3]],
                'qualified_dst_offset_states' => ['standard', 'dst'], 'spread_cost_stress_status' => 'passed',
                'local_positive_posterior_status' => 'passed', 'multiple_testing_validation_status' => 'passed',
                'other_session_regression_status' => 'passed', 'outside_scope_activation_count' => 0,
            ], 'outside_scope_activation_count' => 0];
    }
}
