<?php

namespace Tests\Feature;

use App\Models\AgentLearningLesson;
use App\Models\EvolutionLearningReceipt;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvolutionArchiveEntry;
use App\Models\LabGeneration;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Models\MutationMemory;
use App\Services\DescendantTraitCreditService;
use App\Services\EvolutionGovernorService;
use App\Services\EvolutionVelocityService;
use App\Services\FailureCurriculumService;
use App\Services\LearningReceiptService;
use App\Services\TraitEvidenceLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningFirstArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_evolution_child_carries_an_immutable_learning_receipt(): void
    {
        [$agent] = $this->agent();
        $packet = ['packet_id' => 'packet-1', 'positive_lessons' => [['lesson_id' => 7, 'parameter_key' => 'entry_threshold']], 'harmful_lessons' => [['lesson_id' => 8, 'parameter_key' => 'entry_threshold']]];

        $receipt = app(LearningReceiptService::class)->issue($agent, $packet);

        $this->assertSame('learning_receipt_v2', $receipt['protocol']);
        $this->assertSame('profit_factor', $receipt['declared_target']);
        $this->assertSame('entry_threshold', $receipt['changed_gene']);
        $this->assertSame([7, 8], $receipt['consumed_lesson_ids']);
        $this->assertSame([7, 8], $receipt['retrieved_lesson_ids']);
        $this->assertSame([7, 8], $receipt['selected_lesson_ids']);
        $this->assertSame([], $receipt['causally_applied_lesson_ids']);
        $this->assertSame([], $receipt['rejected_lesson_ids']);
        $this->assertTrue($receipt['integrity']['receipt_gene_matches_parameter_diff']);
        $this->assertNotEmpty($receipt['intent_hash']);
        $this->assertSame($receipt['intent_hash'], app(LearningReceiptService::class)->issue($agent->fresh(['modelVersion']), $packet)['intent_hash']);
    }

    public function test_trait_ledger_preserves_local_benefit_without_promotion_credit(): void
    {
        [$agent] = $this->agent();
        $trait = app(TraitEvidenceLedgerService::class)->assess($agent, 'entry_threshold', [
            'declared_target' => 'stress_cost', 'classification' => 'observable_effect', 'observable_effect' => true,
            'control_delta' => .06, 'mutation_contract' => ['control_pair_status' => 'available'],
            'non_target_regression' => ['safe' => true], 'contextual_bandit' => ['cell_key' => 'stress-cell'],
        ]);

        $this->assertSame('locally_beneficial', $trait['status']);
        $this->assertSame(.06, $trait['target_delta']);
        $this->assertFalse($trait['promotion_evidence']);
        $this->assertGreaterThan(.5, $trait['posterior_probability_improvement']);
    }

    public function test_failure_curriculum_freezes_repeated_harmful_direction(): void
    {
        [$agent] = $this->agent();
        foreach ([1, 2] as $i) {
            MutationMemory::create([
                'lab_agent_id' => $agent->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
                'parameter_key' => 'entry_threshold', 'old_value' => ['value' => 1], 'new_value' => ['value' => 2],
                'forward_delta' => -.06, 'outcome' => 'provisional_harmful', 'confidence' => 50,
                'behavioral_effect' => ['trait_ledger' => ['status' => 'harmful']],
            ]);
        }

        $circuit = app(FailureCurriculumService::class)->mutationCircuit('XAUUSD', 'H1', 'hybrid');

        $this->assertSame([], $circuit['blocked_keys']);
        $this->assertSame('entry_threshold', $circuit['blocked_directions'][0]['parameter_key']);
        $this->assertSame('REPEATED_HARMFUL_DIRECTION', $circuit['blocked_directions'][0]['reason']);
    }

    public function test_descendant_trait_credit_waits_for_forward_confirmation(): void
    {
        [$child, $generation] = $this->agent();
        $parentModel = ModelVersion::create(['name' => 'trait-parent', 'strategy' => 'trait-parent', 'version' => 'v1', 'generation' => 0, 'status' => 'testing', 'parameters' => ['entry_threshold' => 1]]);
        $parent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $parentModel->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened']);
        $child->update(['parent_a_model_version_id' => $parentModel->id]);
        MutationMemory::create(['lab_agent_id' => $parent->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'parameter_key' => 'entry_threshold', 'old_value' => ['value' => 1], 'new_value' => ['value' => 2], 'forward_delta' => .02, 'outcome' => 'beneficial', 'confidence' => 80, 'behavioral_effect' => []]);

        $credit = app(DescendantTraitCreditService::class)->record($child->fresh(['modelVersion']), [
            'evidence_run_id' => 'descendant-proof-1',
            'mutation_observability' => ['control_delta' => .06, 'non_target_regression' => ['safe' => true]],
        ], (object) ['decision' => 'passed']);

        $this->assertSame('recorded', $credit['status']);
        $this->assertSame('independently_confirmed', $credit['events'][0]['status']);
        $this->assertSame(1.0, $credit['events'][0]['amount']);
        $this->assertSame(1, $credit['events'][0]['lineage_depth']);
    }

    public function test_velocity_measures_knowledge_and_behavior_without_promotion_credit(): void
    {
        [$agent, $generation] = $this->agent();
        LabEvolutionArchiveEntry::create(['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'island_key' => 'behavior:test', 'archive_type' => 'behavioral_map_elites', 'model_version_id' => $agent->model_version_id, 'lab_agent_id' => $agent->id, 'lab_generation_id' => $generation->id, 'rank' => 1, 'novelty_score' => 1, 'behavior_signature' => 'behavior-test', 'fitness_snapshot' => [], 'metadata' => [], 'status' => 'active']);
        LabMutationResponseMap::create(['response_key' => 'velocity-response', 'stage' => 'full_replay', 'status' => 'confirmed', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'parameter_key' => 'entry_threshold', 'direction' => 'increase', 'sibling_kind' => 'candidate', 'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id, 'evidence_run_id' => 'velocity-proof']);
        AgentLearningLesson::create(['lesson_id' => 'velocity-lesson', 'lesson_hash' => 'velocity-lesson-hash', 'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'lesson_type' => 'mutation', 'status' => 'confirmed', 'failure_class' => 'profit_factor', 'parameter_key' => 'entry_threshold', 'outcome' => 'beneficial', 'observed_at' => now()]);
        EvolutionLearningReceipt::create([
            'receipt_key' => hash('sha256', 'velocity-receipt'), 'claim_key' => hash('sha256', 'velocity-claim'),
            'lab_agent_id' => $agent->id, 'lab_generation_id' => $generation->id,
            'source_type' => 'canonical_test', 'source_key' => 'velocity-canonical-source',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'component' => 'strategy_parameter',
            'action' => 'prefer', 'status' => 'confirmed', 'claim' => 'entry threshold canonical claim',
            'causal_uplift_r' => .1, 'confidence' => .9, 'support' => 3, 'scope' => ['strategy_family' => 'hybrid'],
            'source_experiments' => ['velocity-causal'], 'evidence' => ['canonical' => true],
            'expires_at' => now()->addDays(30), 'compiled_at' => now(),
        ]);

        $velocity = app(EvolutionVelocityService::class)->snapshot($generation->laboratory);

        $this->assertSame('available', $velocity['status']);
        $this->assertSame(1, $velocity['archive_coverage_growth']['new_behavioral_cells']);
        $this->assertSame(1, $velocity['north_star']['validated_new_knowledge_artifacts']);
        $this->assertSame(1, $velocity['knowledge_authority']['confirmed_receipt_claims']);
        $this->assertSame(1, $velocity['knowledge_authority']['legacy_or_projection_lessons_excluded']);
        $this->assertFalse($velocity['promotion_evidence']);
    }

    public function test_governor_excludes_legacy_confirmed_lesson_labels_from_skill_authority(): void
    {
        [$agent, $generation] = $this->agent();
        AgentLearningLesson::create(['lesson_id' => 'legacy-governor-label', 'lesson_hash' => 'legacy-governor-label-hash', 'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'lesson_type' => 'mutation', 'status' => 'confirmed', 'failure_class' => 'profit_factor', 'parameter_key' => 'entry_threshold', 'outcome' => 'beneficial', 'observed_at' => now()]);

        $snapshot = app(EvolutionGovernorService::class)->generationSnapshot($generation->laboratory);

        $this->assertSame(0, $snapshot['learning_telemetry']['confirmed_skill_count']);
        $this->assertTrue($snapshot['learning_telemetry']['legacy_labels_excluded']);
        $this->assertSame(1, $snapshot['learning_telemetry']['excluded_lesson_projection_count']);
    }

    /** @return array{0: LabAgent, 1: LabGeneration} */
    private function agent(): array
    {
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'name' => 'Learning first', 'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test', 'population_size' => 1, 'status' => 'draft', 'data_fingerprint' => 'snapshot-hash', 'trigger_context' => []]);
        $model = ModelVersion::create(['name' => 'receipt-child', 'strategy' => 'receipt-child', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => ['entry_threshold' => 2], 'metadata' => [
            'generation_target' => 'profit_factor', 'hypothesis_contract' => ['changed_gene' => 'entry_threshold', 'expected_behavioral_delta' => 'accepted entries rise', 'falsifiable_statement' => 'trade ledger unchanged'],
            'execution_contract' => ['protocol' => 'test'],
        ]]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'draft', 'parameter_diff' => ['entry_threshold' => ['old' => 1, 'new' => 2]]]);

        return [$agent->fresh(['modelVersion', 'generation']), $generation];
    }
}
