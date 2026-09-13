<?php

namespace Tests\Feature;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningLesson;
use App\Models\AiLaboratory;
use App\Models\CausalCapabilityEscrow;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\LearningProtocolEpochLink;
use App\Models\ModelVersion;
use App\Services\CausalCapabilityLatticeService;
use App\Services\CausalGoldenWorldHarnessService;
use App\Services\EvidenceSalvageConveyorService;
use App\Services\LearningProtocolEpochService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PostV2LearningProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_golden_worlds_prove_positive_chain_and_reject_null_poison_and_context_leakage(): void
    {
        $result = app(CausalGoldenWorldHarnessService::class)->run();

        $this->assertTrue($result['passed']);
        $this->assertSame('causally_confirmed_component', data_get($result, 'worlds.positive.component.lattice_state'));
        $this->assertFalse(data_get($result, 'worlds.positive.component.organism_viable'));
        $this->assertTrue(data_get($result, 'worlds.positive.composition.organism_viable'));
        $this->assertTrue(data_get($result, 'worlds.positive.mentor.eligible'));
        $this->assertTrue(data_get($result, 'worlds.positive.economic_parent.eligible'));
        $this->assertSame(0, data_get($result, 'worlds.null.false_confirmed'));
        $this->assertFalse(data_get($result, 'worlds.poisoned.result.component_confirmed'));
        $this->assertSame('abstain', data_get($result, 'worlds.context_switch.asia_action'));
        $this->assertFalse(data_get($result, 'worlds.context_switch.global_inheritance_allowed'));
    }

    public function test_post_v2_epoch_is_non_retroactive_and_requires_an_exact_linked_chain(): void
    {
        [$lab, $legacy] = $this->labAndGeneration([]);
        $epochs = app(LearningProtocolEpochService::class);

        $excluded = $epochs->link($legacy, $legacy, 'XAUUSD', 'H1', [
            'linkage_complete' => true, 'exact_frozen_control' => true,
            'data_hash' => 'data-1', 'execution_hash' => 'execution-1',
        ]);
        $this->assertSame('legacy_or_unscoped_excluded', $excluded['status']);
        $this->assertDatabaseCount('learning_protocol_epoch_links', 0);

        $current = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 2, 'trigger_type' => 'test',
            'trigger_context' => ['learning_protocol_epoch' => $epochs->generationContract()],
            'population_size' => 20, 'status' => 'draft',
        ]);
        $open = $epochs->registerGeneration($current, 'XAUUSD', 'H1');
        $this->assertFalse($open['eligible_for_v2_denominator']);
        $verified = $epochs->link($current, $current, 'XAUUSD', 'H1', [
            'linkage_complete' => true, 'exact_frozen_control' => true,
            'data_hash' => 'data-2', 'execution_hash' => 'execution-2',
            'source_ids' => ['generation_id' => $current->id],
        ]);
        $this->assertTrue($verified['eligible_for_v2_denominator']);
        $this->assertSame(1, LearningProtocolEpochLink::query()->where('eligible_for_v2_denominator', true)->count());
    }

    public function test_component_credit_does_not_require_absolute_profit_or_grant_parent_authority(): void
    {
        $result = app(CausalCapabilityLatticeService::class)->evaluate([
            'post_v2_epoch' => true, 'exact_frozen_control' => true,
            'intent_sealed_before_mutation' => true, 'beats_control' => true,
            'beats_blinded' => true, 'declared_target_improved' => true,
            'independent_windows' => 3, 'positive_windows' => 2,
            'hard_risk_safe' => true, 'non_target_corridor_safe' => true,
            'intent_run_outcome_linked' => true, 'context_and_gene_scoped' => true,
            'proof_carried' => true, 'composition_absolute_settlement' => -0.20,
        ]);

        $this->assertTrue($result['component_confirmed']);
        $this->assertTrue($result['composition_eligible']);
        $this->assertFalse($result['organism_viable']);
        $this->assertFalse($result['reproductive_authority']);
        $this->assertSame('research_only', $result['authority_ceiling']);
        $this->assertDatabaseCount('causal_capability_escrows', 0);
    }

    public function test_post_v2_relative_win_is_persisted_in_research_escrow_without_parent_authority(): void
    {
        $epochs = app(LearningProtocolEpochService::class);
        [$lab, $generation] = $this->labAndGeneration(['learning_protocol_epoch' => $epochs->generationContract()]);
        $controlModel = ModelVersion::create(['name' => 'escrow-control', 'strategy' => 'hybrid', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => ['entry_threshold' => 1],
            'evidence_status' => 'valid', 'metadata' => []]);
        $candidateModel = ModelVersion::create(['name' => 'escrow-candidate', 'strategy' => 'hybrid', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => ['entry_threshold' => 2],
            'evidence_status' => 'valid', 'metadata' => []]);
        $control = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $controlModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test',
            'lifecycle_status' => 'rejected', 'parameter_diff' => []]);
        $candidate = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $candidateModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test',
            'lifecycle_status' => 'rejected', 'parameter_diff' => ['entry_threshold' => ['old' => 1, 'new' => 2]]]);
        $dataHash = str_repeat('d', 64);
        $executionHash = str_repeat('e', 64);
        $controlMap = LabMutationResponseMap::create(['response_key' => hash('sha256', 'escrow-control-map'),
            'stage' => 'screening', 'status' => 'control', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'lab_agent_id' => $control->id,
            'metadata' => ['control_contract' => ['protocol' => 'frozen_control_v2', 'control_only' => true,
                'role' => 'control', 'generation_id' => $generation->id, 'data_hash' => $dataHash,
                'execution_hash' => $executionHash]]]);
        $candidateMap = LabMutationResponseMap::create(['response_key' => hash('sha256', 'escrow-candidate-map'),
            'stage' => 'screening', 'status' => 'screen_observed', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'lab_agent_id' => $candidate->id]);
        $pair = LabLearningLanePair::create(['pair_key' => hash('sha256', 'escrow-pair'),
            'lab_generation_id' => $generation->id, 'candidate_agent_id' => $candidate->id,
            'control_agent_id' => $control->id, 'candidate_response_map_id' => $candidateMap->id,
            'control_response_map_id' => $controlMap->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'baseline_source' => 'control',
            'status' => 'lesson_compiled', 'candidate_data_hash' => $dataHash, 'control_data_hash' => $dataHash,
            'candidate_execution_hash' => $executionHash, 'control_execution_hash' => $executionHash,
            'pair_integrity_status' => 'verified', 'same_generation' => true,
            'target_delta' => ['delta' => .2, 'improved' => true],
            'non_target_regression' => ['status' => 'passed', 'safe' => true],
            'metadata' => ['context_scope' => ['regime' => 'trend_up', 'venue_phase' => 'london_comex_overlap']]]);
        $experiment = AgentLearningCausalExperiment::create(['experiment_key' => hash('sha256', 'escrow-experiment'),
            'lab_generation_id' => $generation->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'gene_key' => 'entry_threshold',
            'guided_agent_id' => $candidate->id, 'control_agent_id' => $control->id, 'status' => 'provisional',
            'evidence' => ['context_scope' => ['regime' => 'trend_up', 'venue_phase' => 'london_comex_overlap']]]);
        $effect = ['passed' => true, 'common_window_count' => 3, 'positive_delta_windows' => 2,
            'target_effect' => ['passed' => true]];

        $result = app(CausalCapabilityLatticeService::class)->projectExperiment(
            $experiment, $pair, null, $effect, $effect, ['GUIDED_ABSOLUTE_VIABILITY_FAILED'], true, true,
        );

        $this->assertTrue($result['component_confirmed']);
        $this->assertTrue($result['composition_eligible']);
        $this->assertFalse($result['organism_viable']);
        $this->assertFalse($result['reproductive_authority']);
        $escrow = CausalCapabilityEscrow::query()->firstOrFail();
        $this->assertSame('contextual_shadow_capability', $escrow->lattice_state);
        $this->assertFalse($escrow->reproductive_authority);
        $this->assertContains('settlement', data_get($result, 'protocol_epoch_link.missing_or_unverified_roles'));
    }

    public function test_salvage_conveyor_prioritizes_closest_signal_but_forces_fresh_v2_reproduction(): void
    {
        [$lab, $generation] = $this->labAndGeneration([]);
        $agent = $this->agent($generation);
        $weak = $this->lesson($agent, 'weak_gene', 1, .0);
        $strong = $this->lesson($agent, 'strong_gene', 3, .20);
        AgentLearningCausalExperiment::create([
            'experiment_key' => hash('sha256', 'weak'), 'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'gene_key' => 'weak_gene', 'source_lesson_id' => $weak->id,
            'guided_agent_id' => $agent->id, 'control_agent_id' => $agent->id, 'status' => 'provisional',
            'evidence' => [],
        ]);
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => hash('sha256', 'strong'), 'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'gene_key' => 'strong_gene', 'source_lesson_id' => $strong->id,
            'guided_agent_id' => $agent->id, 'control_agent_id' => $agent->id, 'status' => 'provisional',
            'evidence' => ['component_effect' => ['passed' => true, 'target_effect' => ['passed' => true]],
                'selector_effect' => ['passed' => true, 'target_effect' => ['passed' => true]]],
        ]);
        $strong->update(['evidence' => [...(array) $strong->evidence, 'causal_experiment_id' => $experiment->id]]);

        $ranked = app(EvidenceSalvageConveyorService::class)->rankLessons(
            collect([$weak->fresh(), $strong->fresh()]),
            AgentLearningCausalExperiment::query()->get()->keyBy('id'),
        );
        $selected = $ranked->first();

        $this->assertSame($strong->id, data_get($selected, 'lesson_id'));
        $this->assertSame('target_aligned_pre_epoch_reproduction', data_get($selected, 'evidence_class'));
        $this->assertSame('fresh_v2_causal_triplet', data_get($selected, 'next_experiment'));
        $this->assertFalse(data_get($selected, 'authority_allowed'));
        $this->assertFalse(data_get($selected, 'contract_complete'));
        $this->assertContains('executable_cartridge', data_get($selected, 'contract_blockers'));
    }

    public function test_terminal_generation_cannot_permanently_own_a_ready_causal_trial(): void
    {
        [$lab, $generation] = $this->labAndGeneration([]);
        $generation->update(['status' => 'technical_quarantine', 'completed_at' => now()]);
        $agent = $this->agent($generation);
        $agent->update(['lifecycle_status' => 'technical_quarantine']);
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => hash('sha256', 'stranded-trial'), 'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'gene_key' => 'entry_threshold',
            'guided_agent_id' => $agent->id, 'blinded_agent_id' => $agent->id,
            'control_agent_id' => $agent->id, 'status' => 'ready_for_replay',
            'evidence' => ['construction_validation' => ['status' => 'ready_for_replay']],
        ]);
        $service = app(EvidenceSalvageConveyorService::class);

        $dry = $service->reconcileTerminalTrialOwnership('XAUUSD', 'H1', false);
        $this->assertSame('would_invalidate_terminal_trials', $dry['status']);
        $this->assertSame('ready_for_replay', $experiment->fresh()->status);
        $applied = $service->reconcileTerminalTrialOwnership('XAUUSD', 'H1', true);
        $this->assertSame(1, $applied['invalidated']);
        $this->assertSame('invalid_counterfactual_contract', $experiment->fresh()->status);
        $this->assertFalse($applied['immutable_evidence_rewritten']);
    }

    /** @return array{0:AiLaboratory,1:LabGeneration} */
    private function labAndGeneration(array $context): array
    {
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'name' => 'Post-v2 proof', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'test', 'trigger_context' => $context, 'population_size' => 20, 'status' => 'draft']);

        return [$lab, $generation];
    }

    private function agent(LabGeneration $generation): LabAgent
    {
        $model = ModelVersion::create(['name' => 'salvage', 'strategy' => 'hybrid', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [], 'evidence_status' => 'valid', 'metadata' => []]);

        return LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'rejected', 'parameter_diff' => []]);
    }

    private function lesson(LabAgent $agent, string $gene, int $windows, float $lowerBound): AgentLearningLesson
    {
        return AgentLearningLesson::create([
            'lesson_id' => (string) Str::uuid(), 'lesson_hash' => hash('sha256', $gene),
            'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'lesson_type' => 'skill_lesson', 'status' => 'provisional', 'failure_class' => 'profit_factor',
            'parameter_key' => $gene, 'regime' => 'trend_up', 'outcome' => 'beneficial',
            'independent_window_count' => $windows, 'confirmation_count' => 1,
            'lower_confidence_bound' => $lowerBound, 'source_run_ids' => [], 'evidence' => [], 'observed_at' => now(),
        ]);
    }
}
