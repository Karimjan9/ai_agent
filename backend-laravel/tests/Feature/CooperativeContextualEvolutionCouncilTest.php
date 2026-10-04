<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\ContextualInstrumentBundleEffect;
use App\Models\ContextualSpecialistCapsule;
use App\Models\CooperativeExperimentSettlement;
use App\Models\CooperativeModuleSpeciesMember;
use App\Models\InstrumentInvocationLedger;
use App\Models\LabAgent;
use App\Models\LabEvolutionArchiveEntry;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchIdeaInboxEntry;
use App\Services\CandidateGateDecisionService;
use App\Services\ContextualCapsuleArchiveService;
use App\Services\ContextualCouncilAllocatorService;
use App\Services\ContextualInstrumentBundleGraphService;
use App\Services\CooperativeContextualEvolutionCouncilService;
use App\Services\CooperativeExperimentSettlementService;
use App\Services\CooperativeModuleSpeciesService;
use App\Services\GenerationSnapshotAdmissionService;
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

    public function test_causal_triplet_and_contextual_blocks_share_one_twenty_seat_generation(): void
    {
        $lab = $this->lab();
        $plan = $this->plan();
        foreach (['hypothesis_guided', 'blinded', 'frozen_control'] as $offset => $role) {
            data_set($plan[$offset + 3], 'niche.causal_learning_cohort', [
                'protocol' => 'causal_learning_counterfactual_cohort_v1',
                'experiment_id' => 77,
                'role' => $role,
                'promotion_evidence' => false,
            ]);
        }

        $allocation = app(ContextualCouncilAllocatorService::class)->allocate($plan, $lab);

        $this->assertCount(20, $allocation['plan']);
        $this->assertSame([4, 5, 6], data_get($allocation, 'contract.protected_causal_proof_slots'));
        $this->assertSame(16, data_get($allocation, 'contract.cooperative_seats'));
        $this->assertSame(8, data_get($allocation, 'contract.pair_budget'));
        $this->assertSame([20], data_get($allocation, 'contract.uncertainty_abstain_slots'));
        $this->assertSame(
            ['hypothesis_guided', 'blinded', 'frozen_control'],
            collect($allocation['plan'])->pluck('niche.causal_learning_cohort.role')->filter()->values()->all(),
        );
        $this->assertSame(16, collect($allocation['plan'])->filter(fn (array $slot): bool => data_get($slot, 'niche.cooperative_experiment_block.protocol') === CooperativeContextualEvolutionCouncilService::PROTOCOL
        )->count());
        $this->assertSame('WAIT', data_get($allocation, 'plan.19.niche.outside_scope_action'));
        $this->assertTrue((bool) data_get($allocation, 'plan.19.niche.uncertainty_abstain'));

        $paired = app(ResearchAllocationPolicyService::class)->materializeNormalControlPairing(
            $allocation['plan'], 'XAUUSD', 'H1', 77,
        );

        $this->assertTrue((bool) data_get($paired, 'contract.allowed'));
        $this->assertSame('cooperative_experiment_blocks', data_get($paired, 'contract.mode'));
        $this->assertSame([4, 5, 6], data_get($paired, 'contract.primary_proof_slots'));
        $this->assertSame(8, data_get($paired, 'contract.pair_count'));
        $this->assertSame([20], data_get($paired, 'contract.uncertainty_abstain_slots'));
        $this->assertTrue((bool) data_get($allocation, 'contract.seat_ownership_complete'));
        $this->assertCount(20, (array) data_get($allocation, 'contract.seat_ownership'));
        $this->assertSame(3, collect(data_get($allocation, 'contract.seat_ownership'))
            ->where('kind', 'protected_causal_proof')->count());
        $this->assertSame(1, collect(data_get($allocation, 'contract.seat_ownership'))
            ->where('kind', 'uncertainty_abstain')->count());
        $this->assertSame(
            ['hypothesis_guided', 'blinded', 'frozen_control'],
            collect($paired['plan'])->pluck('niche.causal_learning_cohort.role')->filter()->values()->all(),
        );
    }

    public function test_valid_negative_repair_receipt_changes_one_successor_pair_and_seals_its_source(): void
    {
        $lab = $this->lab();
        $settlement = $this->predecessorSettlement($lab, 'repair_pair', 'settled_negative_or_null');
        $allocation = app(ContextualCouncilAllocatorService::class)->allocate($this->protectedPlan(), $lab);
        $contract = $allocation['contract'];

        $this->assertSame(16, $contract['cooperative_seats']);
        $this->assertSame(8, data_get($contract, 'pair_budget'));
        $this->assertSame(8, data_get($contract, 'seat_counts.repair_pair'));
        $this->assertSame(6, data_get($contract, 'seat_counts.novelty_pair'));
        $this->assertSame(2, data_get($contract, 'seat_counts.adversarial_guard'));
        $this->assertSame($settlement->id, data_get($contract, 'settlement_feedback.decision.source_settlement_id'));
        $this->assertSame('negative_repair_diversification', data_get($contract, 'settlement_feedback.decision.reason'));
        $this->assertSame('repair_pair', data_get($contract, 'settlement_feedback.decision.from_block_type'));
        $this->assertSame('novelty_pair', data_get($contract, 'settlement_feedback.decision.to_block_type'));
        $this->assertSame([1, 2], data_get($contract, 'settlement_feedback.decision.final_seat_numbers'));
        $this->assertSame(data_get($contract, 'experiment_blocks.0.block_key'),
            data_get($contract, 'settlement_feedback.decision.final_block_key'));
        $this->assertSame([$settlement->id], data_get($contract, 'settlement_feedback.valid_settlement_ids'));
        $this->assertSame(64, strlen((string) data_get($contract, 'settlement_feedback.settlement_digest')));
        $this->assertSame(64, strlen((string) data_get($contract, 'allocation_manifest_hash')));
        $this->assertCount(20, $contract['seat_ownership']);
        $this->assertTrue($contract['seat_ownership_complete']);
        $this->assertSame([4, 5, 6], $contract['protected_causal_proof_slots']);
        $this->assertSame([20], $contract['uncertainty_abstain_slots']);
    }

    public function test_reserved_constructor_generation_uses_its_own_number_not_max_plus_one(): void
    {
        $lab = $this->lab();
        $settlement = $this->predecessorSettlement($lab, 'repair_pair', 'settled_negative_or_null');
        $reserved = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 2,
            'trigger_type' => 'test', 'trigger_context' => [], 'population_size' => 20, 'status' => 'draft']);
        $allocation = app(ContextualCouncilAllocatorService::class)->allocate(
            $this->protectedPlan(), $lab, [], (int) $reserved->generation,
        );
        $this->assertSame(2, data_get($allocation, 'contract.generation_number'));
        $this->assertSame(1, data_get($allocation, 'contract.settlement_feedback.source_generation_number'));
        $this->assertSame($settlement->id,
            data_get($allocation, 'contract.settlement_feedback.decision.source_settlement_id'));
        $this->assertSame(8, data_get($allocation, 'contract.seat_counts.repair_pair'));
        $reserved->update(['trigger_context' => ['generation_plan' => $allocation['plan'],
            'population_group_contract' => ['contextual_allocator' => $allocation['contract']]]]);
        $this->assertSame([], app(CooperativeContextualEvolutionCouncilService::class)
            ->allocationReasons($reserved->fresh(['laboratory'])));
    }

    public function test_underpowered_invalid_and_positive_signals_have_distinct_non_credit_allocation_effects(): void
    {
        $lab = $this->lab();
        $cases = [
            ['activation_factorial', 'underpowered_activation', true, 'underpowered_activation_coverage',
                ['repair_pair' => 8, 'novelty_pair' => 4, 'adversarial_guard' => 2, 'coverage_guard' => 2]],
            ['repair_pair', 'invalid_arm_evidence', false, 'technical_invalid_diagnostic_guard',
                ['repair_pair' => 10, 'novelty_pair' => 2, 'adversarial_guard' => 2, 'coverage_guard' => 2]],
            ['novelty_pair', 'settled_negative_or_null', true, 'negative_novelty_refocus',
                ['repair_pair' => 12, 'novelty_pair' => 2, 'adversarial_guard' => 2]],
            ['repair_pair', 'settled_positive_signal', true, 'sealed',
                ['repair_pair' => 10, 'novelty_pair' => 4, 'adversarial_guard' => 2]],
        ];
        foreach ($cases as [$type, $outcome, $complete, $reason, $counts]) {
            $settlement = $this->predecessorSettlement($lab, $type, $outcome, $complete);
            $contract = app(ContextualCouncilAllocatorService::class)->allocate($this->protectedPlan(), $lab)['contract'];
            foreach ($counts as $block => $seats) {
                $this->assertSame($seats, data_get($contract, 'seat_counts.'.$block));
            }
            $this->assertSame($reason, data_get($contract, 'settlement_feedback.decision.reason'));
            $this->assertFalse((bool) data_get($contract, 'settlement_feedback.decision.credit_allowed', false));
            $this->assertFalse($contract['promotion_evidence']);
            $this->assertSame($settlement->id, data_get($contract, 'settlement_feedback.settlement_receipts.0.settlement_id'));
        }
    }

    public function test_unsealed_or_active_predecessor_cannot_steer_successor_allocation(): void
    {
        $lab = $this->lab();
        $settlement = $this->predecessorSettlement($lab, 'repair_pair', 'settled_negative_or_null');
        $sealed = app(ContextualCouncilAllocatorService::class)->allocate($this->protectedPlan(), $lab)['contract'];
        $settlement->update(['settlement_key' => hash('sha256', 'wrong-source-key')]);
        $contract = app(ContextualCouncilAllocatorService::class)->allocate($this->protectedPlan(), $lab)['contract'];
        $this->assertNotSame($sealed['allocation_manifest_hash'], $contract['allocation_manifest_hash']);
        $this->assertSame('unchanged', data_get($contract, 'settlement_feedback.decision.status'));
        $this->assertSame(10, data_get($contract, 'seat_counts.repair_pair'));
        $this->assertSame('not_actionable', data_get($contract, 'settlement_feedback.settlement_receipts.0.classification'));

        $settlement->generation->update(['status' => 'screening']);
        $active = app(ContextualCouncilAllocatorService::class)->allocate($this->protectedPlan(), $lab)['contract'];
        $this->assertSame('predecessor_not_terminal', data_get($active, 'settlement_feedback.status'));
        $this->assertSame(10, data_get($active, 'seat_counts.repair_pair'));

        LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 2,
            'trigger_type' => 'test', 'trigger_context' => [], 'population_size' => 20, 'status' => 'screened']);
        $newer = app(ContextualCouncilAllocatorService::class)->allocate($this->protectedPlan(), $lab)['contract'];
        $this->assertSame([], data_get($newer, 'settlement_feedback.settlement_receipts'));
        $this->assertSame(10, data_get($newer, 'seat_counts.repair_pair'));
    }

    public function test_queue_admission_rejects_feedback_or_owner_drift_after_successor_freeze(): void
    {
        $lab = $this->lab();
        $settlement = $this->predecessorSettlement($lab, 'repair_pair', 'settled_negative_or_null');
        $allocation = app(ContextualCouncilAllocatorService::class)->allocate($this->protectedPlan(), $lab);
        $successor = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 2,
            'trigger_type' => 'test', 'trigger_context' => [
                'generation_plan' => $allocation['plan'],
                'population_group_contract' => ['contextual_allocator' => $allocation['contract']],
            ], 'population_size' => 20, 'status' => 'queued']);
        $council = app(CooperativeContextualEvolutionCouncilService::class);
        $this->assertSame([], $council->allocationReasons($successor));

        $settlement->update(['outcome_status' => 'settled_positive_signal']);
        $this->assertContains('ALLOCATION_SOURCE_SETTLEMENT_DRIFT',
            $council->allocationReasons($successor->fresh(['laboratory'])));
        $this->assertContains('ALLOCATION_SOURCE_SETTLEMENT_DRIFT',
            app(GenerationSnapshotAdmissionService::class)->inspect($successor->fresh(['laboratory']))['reasons']);

        $context = (array) $successor->trigger_context;
        data_set($context, 'generation_plan.0.niche.cooperative_experiment_block.arm', 'tampered_arm');
        $successor->update(['trigger_context' => $context]);
        $this->assertContains('ALLOCATION_PLAN_OWNER_DRIFT',
            $council->allocationReasons($successor->fresh(['laboratory'])));
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

    public function test_idea_uses_declared_repair_design_and_unsupported_factorial_remains_pending(): void
    {
        $service = app(ResearchIdeaInboxService::class);
        $base = ['title' => 'Specific repair', 'hypothesis' => 'Bounded lookback repairs this phase.',
            'bounded_genes' => [['key' => 'lookback', 'minimum' => 1, 'maximum' => 20]],
            'required_block_type' => 'repair_pair', 'context_scope' => ['venue_phase' => 'london_interfix']];
        $repair = $service->submit($base);
        $factorial = $service->submit([...$base, 'required_block_type' => 'factorial']);
        $allocation = app(ContextualCouncilAllocatorService::class)->allocate($this->plan(), $this->lab());
        $seats = collect($allocation['plan'])->filter(fn (array $slot): bool => data_get($slot,
            'niche.cooperative_evolution_capsule.idea_reference.idea_key') === $repair['idea_key']);
        $this->assertCount(2, $seats);
        $this->assertTrue($seats->every(fn (array $slot): bool => data_get($slot,
            'niche.cooperative_experiment_block.block_type') === 'repair_pair'
            && data_get($slot, 'niche.contextual_specialist_cell.venue_phase') === 'london_interfix'
            && data_get($slot, 'niche.cooperative_experiment_block.idea_design.required_block_type') === 'repair_pair'));
        $this->assertSame('ready_for_experiment', ResearchIdeaInboxEntry::findOrFail($factorial['entry_id'])->status);
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
                    'component_b' => 'cost_aware_exit', 'context_cell_key' => 'cell-1',
                    'changed_species' => in_array($index, ['a_only', 'b_only'], true) ? 'toolbox_instrument' : null],
                    'specialist_council_membership' => ['contextual_cell' => $context],
                    'cooperative_evolution_capsule' => [
                        'context_cell_hash' => 'cell-1', 'components' => ['toolbox_instrument' => $bundle]]]]);
            $last = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
                'origin' => 'test', 'lifecycle_status' => 'rejected', 'parameter_diff' => []]);
            app(CooperativeModuleSpeciesService::class)->recordMembers(
                $last,
                (array) data_get($model->metadata, 'cooperative_evolution_capsule'),
            );
            foreach ($bundle as $instrumentKey) {
                InstrumentInvocationLedger::create([
                    'invocation_key' => hash('sha256', implode('|', ['factorial-runtime', $last->id, $instrumentKey])),
                    'lab_agent_id' => $last->id,
                    'lab_generation_id' => $generation->id,
                    'instrument_key' => $instrumentKey,
                    'symbol' => 'XAUUSD',
                    'timeframe' => 'H1',
                    'state_key' => 'cell-1',
                    'input_hash' => str_repeat('i', 64),
                    'output_hash' => str_repeat('o', 64),
                    'used_in_decision' => true,
                    'used_in_execution' => false,
                    'verdict' => 'awaiting_paired_control',
                    'metadata' => [
                        'declaration' => ['causal_candidate' => true],
                        'runtime_trace' => [
                            'status' => 'consumed',
                            'decision_path_activated' => true,
                        ],
                        'promotion_evidence' => false,
                    ],
                    'invoked_at' => now(),
                ]);
            }
            $dataHash = str_repeat('d', 64);
            $run = app(LabImmutableEvidenceService::class)->beginRun($last, 'screening', 'incremental');
            app(LabImmutableEvidenceService::class)->attachRequest($run, [
                'strategies' => [[
                    'lab_agent_id' => $last->id,
                    'strategy' => $model->strategy,
                    'specialist_context_contract' => $context,
                ]],
                'execution_contract' => ['execution_hash' => str_repeat('e', 64)],
            ], ['data_hash' => $dataHash, 'dataset_manifest' => ['data_hash' => $dataHash,
                'mtf_bundle_hash' => str_repeat('m', 64)]]);
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
        $this->assertSame(2, CooperativeModuleSpeciesMember::query()->where('authority_level', 'repair_credit')->count());
        $this->assertSame(26, CooperativeModuleSpeciesMember::query()->where('authority_level', 'hypothesis')->count());
        $this->assertTrue(CooperativeModuleSpeciesMember::query()->where('authority_level', 'repair_credit')->get()
            ->every(fn (CooperativeModuleSpeciesMember $member): bool => $member->species === 'toolbox_instrument'));

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

    public function test_planned_bundle_without_runtime_activation_receives_no_effect_credit(): void
    {
        $lab = $this->lab();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 2,
            'trigger_type' => 'test',
            'trigger_context' => [],
            'population_size' => 2,
            'status' => 'screened',
        ]);
        $blockKey = hash('sha256', 'planned-without-runtime-activation');
        $agents = collect();

        foreach (['control', 'candidate'] as $arm) {
            $model = ModelVersion::create([
                'name' => 'planned-only-'.$arm,
                'strategy' => 'hybrid',
                'version' => 'v2',
                'generation' => 2,
                'status' => 'testing',
                'parameters' => [],
                'evidence_status' => 'valid',
                'metadata' => [
                    'cooperative_experiment_block' => [
                        'protocol' => CooperativeContextualEvolutionCouncilService::PROTOCOL,
                        'block_key' => $blockKey,
                        'block_type' => 'pair',
                        'arm' => $arm,
                        'required_arms' => ['control', 'candidate'],
                        'changed_species' => $arm === 'candidate' ? 'toolbox_instrument' : null,
                    ],
                    'cooperative_evolution_capsule' => [
                        'context_cell_hash' => 'cell-planned-only',
                        'components' => [
                            'toolbox_instrument' => $arm === 'candidate'
                                ? ['atr_risk_envelope', 'cost_aware_exit']
                                : [],
                        ],
                    ],
                ],
            ]);
            $agents->push(LabAgent::create([
                'lab_generation_id' => $generation->id,
                'model_version_id' => $model->id,
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'strategy_family' => 'hybrid',
                'origin' => 'test',
                'lifecycle_status' => 'rejected',
                'parameter_diff' => [],
            ]));
            app(CooperativeModuleSpeciesService::class)->recordMembers(
                $agents->last(),
                (array) data_get($model->metadata, 'cooperative_evolution_capsule'),
            );
        }

        $settlement = CooperativeExperimentSettlement::create([
            'settlement_key' => hash('sha256', 'planned-only-settlement'),
            'block_key' => $blockKey,
            'lab_generation_id' => $generation->id,
            'block_type' => 'pair',
            'context_cell_key' => 'cell-planned-only',
            'arm_results' => [],
            'component_effects' => ['candidate_delta' => 0.5],
            'pareto_vectors' => [],
            'outcome_status' => 'settled_positive_signal',
            'evidence_complete' => true,
            'promotion_evidence' => false,
        ]);

        $result = app(ContextualInstrumentBundleGraphService::class)->record(
            $settlement,
            $agents,
            ['candidate_delta' => 0.5],
        );

        $this->assertSame('no_runtime_activation_no_credit', $result['status']);
        $this->assertSame(0, $result['recorded']);
        $this->assertFalse($result['promotion_evidence']);
        $this->assertSame(0, ContextualInstrumentBundleEffect::query()->count());

        $method = new \ReflectionMethod(CooperativeExperimentSettlementService::class, 'settleModuleSpecies');
        $method->setAccessible(true);
        $method->invoke(
            app(CooperativeExperimentSettlementService::class),
            $agents,
            'pair',
            ['candidate_delta' => 0.5],
            $settlement->id,
        );
        $this->assertSame(14, CooperativeModuleSpeciesMember::query()->where('authority_level', 'hypothesis')->count());
        $this->assertSame(0, CooperativeModuleSpeciesMember::query()->where('authority_level', '!=', 'hypothesis')->count());
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

    private function protectedPlan(): array
    {
        $plan = $this->plan();
        foreach (['hypothesis_guided', 'blinded', 'frozen_control'] as $offset => $role) {
            data_set($plan[$offset + 3], 'niche.causal_learning_cohort', [
                'protocol' => 'causal_learning_counterfactual_cohort_v1',
                'experiment_key' => hash('sha256', 'protected-proof'), 'role' => $role,
                'source_pair_id' => 42, 'value' => 25.0,
                'promotion_evidence' => false,
            ]);
        }

        return $plan;
    }

    private function predecessorSettlement(
        AiLaboratory $lab, string $type, string $outcome, bool $complete = true,
    ): CooperativeExperimentSettlement {
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id,
            'generation' => ((int) ($lab->generations()->max('generation') ?? 0)) + 1,
            'trigger_type' => 'test', 'trigger_context' => [], 'population_size' => 20, 'status' => 'screened']);
        $blockKey = hash('sha256', implode('|', [$lab->id, $type, $outcome]));
        $arms = [];
        for ($index = 0; $index < ($type === 'activation_factorial' ? 4 : 2); $index++) {
            $arms['arm_'.$index] = ['evidence_status' => $complete ? 'eligible' : 'invalid',
                'evidence_run_id' => 'sealed-run-'.$index];
        }

        return CooperativeExperimentSettlement::create([
            'settlement_key' => hash('sha256', implode('|', [
                CooperativeExperimentSettlementService::PROTOCOL, $generation->id, $blockKey,
            ])),
            'block_key' => $blockKey, 'lab_generation_id' => $generation->id,
            'block_type' => $type, 'context_cell_key' => hash('sha256', 'cell'),
            'arm_results' => $arms, 'component_effects' => [], 'pareto_vectors' => [],
            'outcome_status' => $outcome, 'evidence_complete' => $complete,
            'promotion_evidence' => false,
        ]);
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
