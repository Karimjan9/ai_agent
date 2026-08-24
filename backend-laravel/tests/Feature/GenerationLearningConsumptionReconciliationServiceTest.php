<?php

namespace Tests\Feature;

use App\Models\AgentLearningLesson;
use App\Models\AgentLearningRetrieval;
use App\Models\AgentLearningSettlement;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\GenerationLearningConsumptionReconciliationService;
use App\Services\LearningKernelService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GenerationLearningConsumptionReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_appends_post_hoc_provenance_without_claiming_consumption_or_changing_the_mutation(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Reconciliation lab', 'timeframe' => 'H1',
            'strategy_families' => ['differential_router'], 'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 2, 'trigger_type' => 'operator_successor',
            'population_size' => 2, 'status' => 'queued', 'trigger_context' => [],
        ]);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('differential_router');
        $candidateModel = $this->model('canonical-retrieval-candidate', $parameters);
        $controlModel = $this->model('canonical-retrieval-control', $parameters);
        $candidate = $this->agent($generation, $candidateModel, [
            'state_machine_variant' => ['old' => 'none', 'new' => 'neutral_transition_cooldown_reentry_v1'],
        ]);
        $control = $this->agent($generation, $controlModel, []);
        $dataHash = str_repeat('d', 64);
        $executionHash = str_repeat('e', 64);
        $controlMap = LabMutationResponseMap::create([
            'response_key' => str_repeat('1', 64), 'stage' => 'screening', 'status' => 'control',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'target' => 'regime_coverage', 'lab_agent_id' => $control->id,
            'metadata' => ['control_contract' => [
                'protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control',
                'generation_id' => $generation->id, 'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
            ]],
        ]);
        $candidateMap = LabMutationResponseMap::create([
            'response_key' => str_repeat('2', 64), 'stage' => 'screening', 'status' => 'screen_observed',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'target' => 'regime_coverage', 'lab_agent_id' => $candidate->id,
        ]);
        $pair = LabLearningLanePair::create([
            'pair_key' => str_repeat('3', 64), 'lab_generation_id' => $generation->id,
            'candidate_agent_id' => $candidate->id, 'control_agent_id' => $control->id,
            'candidate_response_map_id' => $candidateMap->id, 'control_response_map_id' => $controlMap->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'target' => 'regime_coverage', 'baseline_source' => 'control', 'status' => 'lesson_compiled',
            'candidate_data_hash' => $dataHash, 'control_data_hash' => $dataHash,
            'candidate_execution_hash' => $executionHash, 'control_execution_hash' => $executionHash,
            'pair_integrity_status' => 'verified', 'same_generation' => true,
            'candidate_metrics' => [], 'control_metrics' => [], 'target_delta' => ['improved' => true],
        ]);
        $canonical = AgentLearningLesson::create([
            'lesson_id' => (string) Str::uuid(), 'lesson_hash' => str_repeat('a', 128),
            'lab_agent_id' => $candidate->id, 'model_version_id' => $candidateModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'lesson_type' => 'skill_lesson', 'status' => 'provisional', 'failure_class' => 'regime_coverage',
            'parameter_key' => 'state_machine_variant', 'regime' => 'trend_up',
            'volatility' => 'normal_volatility', 'transition_state' => 'transition_observed',
            'state_cluster_id' => 'cluster-1', 'outcome' => 'beneficial',
            'source_run_ids' => ['canonical-run'], 'evidence' => ['pair_id' => $pair->id], 'observed_at' => now(),
        ]);
        $episode = app(LearningKernelService::class)->openEpisode($candidate, [
            'decision_key' => 'generation-reconciliation-test', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'differential_router', 'decision' => 'MUTATE',
            'context' => ['regime' => 'trend_up', 'volatility' => 'normal_volatility'],
        ]);
        AgentLearningSettlement::create([
            'settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id,
            'source_key' => 'generation-reconciliation-settlement', 'source_type' => LabLearningLanePair::class,
            'source_id' => $pair->id, 'outcome_status' => 'settled', 'failure_class' => 'regime_coverage',
            'evidence_state' => 'positive', 'selection_reward' => 1, 'hard_failure' => false,
            'outcome' => [], 'settled_at' => now(),
        ]);
        AgentLearningRetrieval::create([
            'retrieval_id' => (string) Str::uuid(), 'packet_id' => (string) Str::uuid(),
            'episode_id' => $episode->id, 'lab_agent_id' => $candidate->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'retrieval_state' => 'rejected', 'context' => [
                'regime' => 'trend_up', 'volatility' => 'normal_volatility',
                'state_cluster_id' => ['protocol' => 'state_cluster_v1', 'cluster_id' => 'cluster-1'],
            ],
            'metadata' => ['provenance' => 'legacy_prior'],
        ]);
        $metadata = (array) $candidateModel->metadata;
        $metadata['learning_decision'] = ['selected_gene' => 'state_machine_variant', 'episode_id' => $episode->id];
        $candidateModel->update(['metadata' => $metadata]);
        $before = $candidate->parameter_diff;

        $result = app(GenerationLearningConsumptionReconciliationService::class)->reconcile($generation);

        $this->assertSame(0, $result['consumed_agents']);
        $this->assertSame(1, $result['provenance_only_agents']);
        $this->assertTrue(AgentLearningRetrieval::query()
            ->where('lab_agent_id', $candidate->id)
            ->where('agent_learning_lesson_id', $canonical->id)
            ->where('retrieval_state', 'reconciled_post_hoc')
            ->whereNull('consumed_at')
            ->where('metadata->causal_application', false)
            ->where('metadata->provenance', 'canonical_settled')->exists());
        $this->assertSame($before, $candidate->fresh()->parameter_diff);
        $this->assertFalse((bool) data_get($candidateModel->fresh()->metadata, 'learning_decision.canonical_provenance_reconciliation.mutation_was_changed'));
    }

    private function model(string $name, array $parameters): ModelVersion
    {
        return ModelVersion::create([
            'name' => $name, 'strategy' => $name, 'version' => 'v1', 'generation' => 2,
            'status' => 'testing', 'parameters' => $parameters, 'metadata' => [], 'evidence_status' => 'valid',
        ]);
    }

    private function agent(LabGeneration $generation, ModelVersion $model, array $diff): LabAgent
    {
        return LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'origin' => 'operator_successor', 'lifecycle_status' => 'draft', 'parameter_diff' => $diff,
        ]);
    }
}
