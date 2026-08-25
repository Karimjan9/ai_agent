<?php

namespace Tests\Feature;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningLesson;
use App\Models\AgentLearningMutationIntent;
use App\Models\AgentLearningPolicy;
use App\Models\AgentLearningRetrieval;
use App\Models\AgentLearningSettlement;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\CausalLearningCohortPlannerService;
use App\Services\CausalLearningConfirmationService;
use App\Services\CausalLearningMutationIntentService;
use App\Services\LearningKernelService;
use App\Services\LearningPulseService;
use App\Services\LearningReceiptService;
use App\Services\TechnicalFailureClassifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CausalLearningLoopContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_memory_is_sealed_before_agent_persistence_and_receipt_reports_only_truthful_use(): void
    {
        [$generation, $sourceLesson] = $this->canonicalSource();
        $packetId = (string) Str::uuid();
        $retrievalId = (string) Str::uuid();
        AgentLearningRetrieval::create([
            'retrieval_id' => $retrievalId, 'packet_id' => $packetId,
            'agent_learning_lesson_id' => $sourceLesson->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'retrieval_state' => 'retrieved', 'context' => [],
            'metadata' => ['parameter_key' => 'entry_threshold', 'provenance' => 'canonical_settled'],
        ]);
        $packet = [
            'packet_id' => $packetId,
            'positive_lessons' => [[
                'lesson_id' => $sourceLesson->id, 'retrieval_id' => $retrievalId,
                'parameter_key' => 'entry_threshold', 'provenance' => 'canonical_settled',
            ]],
            'harmful_lessons' => [], 'uncertainty_lessons' => [], 'retrieval_count' => 1,
        ];
        $service = app(CausalLearningMutationIntentService::class);
        $diff = ['entry_threshold' => ['old' => 1, 'new' => 2]];
        $plan = $service->plan(
            $generation->fresh('laboratory'), $packet, 'hybrid', 'profit_factor',
            ['entry_threshold' => 1], ['entry_threshold' => 2], $diff, 'memory_guided',
        );
        $this->assertSame('memory_guided', $plan['influence_type']);
        $this->assertSame([$sourceLesson->id], $plan['causally_applied_lesson_ids']);
        $model = $this->model('guided-child', ['entry_threshold' => 2], [
            'generation_target' => 'profit_factor',
            'hypothesis_contract' => ['changed_gene' => 'entry_threshold'],
            'execution_contract' => ['protocol' => 'test'],
        ]);
        $intent = $service->seal($plan, $generation, $model);
        $this->assertInstanceOf(AgentLearningMutationIntent::class, $intent);
        $agent = $this->agent($generation, $model, $diff);
        $episode = app(LearningKernelService::class)->openEpisode($agent, [
            'decision_key' => 'causal-intent-test', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'context' => [],
        ]);
        $binding = $service->bind($intent, $agent, $episode->id);
        $this->assertSame('bound', $binding['status']);
        app(LearningKernelService::class)->recordConsumption($packet, [
            'parameter_key' => 'entry_threshold',
            'causally_applied_retrieval_ids' => [$retrievalId],
        ], $agent, $episode);
        $receipt = app(LearningReceiptService::class)->issue($agent->fresh(['modelVersion', 'generation']), $packet);

        $this->assertSame('learning_receipt_v2', $receipt['protocol']);
        $this->assertSame([$sourceLesson->id], $receipt['retrieved_lesson_ids']);
        $this->assertSame([$sourceLesson->id], $receipt['selected_lesson_ids']);
        $this->assertSame([$sourceLesson->id], $receipt['causally_applied_lesson_ids']);
        $this->assertSame([], $receipt['rejected_lesson_ids']);
        $this->assertTrue($receipt['integrity']['valid']);
        $this->assertSame('CAUSAL_MUTATION_APPLIED', AgentLearningRetrieval::firstOrFail()->reason_code);
        $this->assertTrue((bool) data_get(AgentLearningRetrieval::firstOrFail()->metadata, 'causal_application'));
        $pulse = app(LearningPulseService::class)->pulse('XAUUSD', 'H1');
        $this->assertSame(1, $pulse['canonical_settlements']);
        $this->assertSame(1, $pulse['canonical_lessons_created']);
        $this->assertLessThan(
            data_get($binding, 'causal_order.agent_persistence_sequence'),
            data_get($binding, 'causal_order.mutation_seal_sequence'),
        );
    }

    public function test_receipt_gene_mismatch_is_invalid_and_cannot_settle_provisional(): void
    {
        [$generation] = $this->canonicalSource();
        $model = $this->model('mismatch-child', ['entry_threshold' => 2], [
            'generation_target' => 'profit_factor',
            'hypothesis_contract' => ['changed_gene' => 'wrong_gene'],
            'execution_contract' => ['protocol' => 'test'],
        ]);
        $agent = $this->agent($generation, $model, ['entry_threshold' => ['old' => 1, 'new' => 2]]);
        $receipt = app(LearningReceiptService::class)->issue($agent->fresh(['modelVersion', 'generation']));

        $this->assertSame('invalid_intent', $receipt['status']);
        $this->assertFalse($receipt['integrity']['receipt_gene_matches_parameter_diff']);
        $settled = app(LearningReceiptService::class)->settle($agent->fresh(['modelVersion']), [
            'evidence_run_id' => 'mismatch-run',
            'mutation_observability' => ['observable_effect' => true, 'control_delta' => .2, 'non_target_regression' => ['safe' => true]],
        ]);
        $this->assertSame('invalid_intent', $settled['status']);
    }

    public function test_planner_materializes_guided_blinded_and_frozen_control_triplet(): void
    {
        [$generation, $sourceLesson] = $this->canonicalSource();
        $sourceLesson->update([
            'parameter_key' => 'high_volatility_risk_multiplier',
            'evidence' => [...((array) $sourceLesson->evidence),
                'old_value' => ['value' => .5], 'new_value' => ['value' => .55],
            ],
        ]);
        $plan = collect(range(1, 4))->map(fn (int $slot): array => [
            'family' => 'hybrid', 'origin' => 'test', 'target' => 'profit_factor',
            'niche' => ['data_lane' => 'price', 'slot' => $slot],
        ])->all();

        $materialized = app(CausalLearningCohortPlannerService::class)->materialize(
            $plan, 'XAUUSD', 'H1', $generation->id,
        );

        $this->assertSame('materialized', $materialized['contract']['status']);
        $this->assertSame($sourceLesson->id, $materialized['contract']['source_lesson_id']);
        $roles = collect($materialized['plan'])->pluck('niche.causal_learning_cohort.role')->filter()->values()->all();
        $this->assertSame(['memory_guided', 'blinded', 'frozen_control'], $roles);
        $this->assertTrue((bool) data_get($materialized['plan'][2], 'niche.control_only'));
    }

    public function test_pre_registered_causal_retrieval_returns_only_the_exact_canonical_source(): void
    {
        [, $sourceLesson] = $this->canonicalSource();

        $packet = app(LearningKernelService::class)->retrieveCanonicalLesson(
            $sourceLesson->id,
            'XAUUSD',
            'H1',
            'hybrid',
            ['transition_state' => 'unknown'],
        );

        $this->assertSame('ok', $packet['status']);
        $this->assertSame('pre_registered_exact_causal_source', $packet['retrieval_mode']);
        $this->assertSame($sourceLesson->id, $packet['required_source_lesson_id']);
        $this->assertSame([$sourceLesson->id], collect($packet['positive_lessons'])->pluck('lesson_id')->all());
        $this->assertSame([], $packet['harmful_lessons']);
        $this->assertSame(1, $packet['retrieval_count']);
        $this->assertDatabaseHas('agent_learning_retrievals', [
            'agent_learning_lesson_id' => $sourceLesson->id,
            'match_level' => 'pre_registered_causal_source',
            'retrieval_state' => 'retrieved',
        ]);
    }

    public function test_memory_confirmation_requires_guided_to_beat_blinded_and_frozen_control(): void
    {
        [$generation, $sourceLesson] = $this->canonicalSource();
        $controlModel = $this->model('cohort-control', ['entry_threshold' => 1]);
        $guidedModel = $this->model('cohort-guided', ['entry_threshold' => 2], $this->validReceiptMetadata());
        $blindedModel = $this->model('cohort-blinded', ['entry_threshold' => 2], $this->validReceiptMetadata());
        $control = $this->agent($generation, $controlModel, []);
        $guided = $this->agent($generation, $guidedModel, ['entry_threshold' => ['old' => 1, 'new' => 2]]);
        $blinded = $this->agent($generation, $blindedModel, ['entry_threshold' => ['old' => 1, 'new' => 2]]);
        $guidedModel->update(['metadata' => [...$guidedModel->metadata, 'learning_receipt' => $this->validReceipt()]]);
        $blindedModel->update(['metadata' => [...$blindedModel->metadata, 'learning_receipt' => $this->validReceipt()]]);
        $guidedIntent = $this->intent($generation, $guided, 'memory_guided', [$sourceLesson->id]);
        $this->intent($generation, $blinded, 'blinded_counterfactual', []);
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => str_repeat('x', 128), 'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'gene_key' => 'entry_threshold', 'source_lesson_id' => $sourceLesson->id,
            'guided_agent_id' => $guided->id, 'blinded_agent_id' => $blinded->id, 'control_agent_id' => $control->id,
            'status' => 'ready_for_replay', 'evidence' => [
                'construction_validation' => ['status' => 'ready_for_replay'], 'promotion_evidence' => false,
            ],
        ]);
        [$guidedPair, $guidedLesson] = $this->outcomePair($generation, $guided, $control, .20, 'g');
        [$blindedPair] = $this->outcomePair($generation, $blinded, $control, -.05, 'b');
        $result = [
            'evidence_run_id' => 'forward-proof',
            'forward_window_protocol' => [
                'independence_verified' => true, 'overlap_detected' => false,
                'observed_windows' => 3, 'positive_windows' => 2,
                'windows' => [['window_key' => 'w1'], ['window_key' => 'w2'], ['window_key' => 'w3']],
                'purge_embargo_applied' => true, 'label_holding_period_purged' => true,
                'purge_bars' => 12, 'embargo_bars' => 1,
            ],
        ];
        $service = app(CausalLearningConfirmationService::class);
        $first = $service->recordOutcome($guided->fresh(['modelVersion']), $guidedPair, $result, ['delta' => .20, 'improved' => true], $guidedLesson);
        $this->assertFalse($first['confirmed']);
        $confirmed = $service->recordOutcome($blinded->fresh(['modelVersion']), $blindedPair, $result, ['delta' => -.05, 'improved' => false]);

        $this->assertTrue($confirmed['confirmed']);
        $this->assertSame('confirmed', $experiment->fresh()->status);
        $this->assertSame('confirmed', $guidedLesson->fresh()->status);
        $this->assertSame('skill_confirmed', $guidedPair->fresh()->status);
        $this->assertSame('settled', $guidedIntent->fresh()->status);
        $this->assertSame('shadow', AgentLearningPolicy::firstOrFail()->state);
        $this->assertSame('confirmed', data_get(
            $guided->fresh('modelVersion')->modelVersion->metadata,
            'causal_learning_experiment.status',
        ));
    }

    public function test_volume_quality_failure_is_capability_isolation_not_global_recovery(): void
    {
        $result = app(TechnicalFailureClassifierService::class)->classify(
            'XAUUSD H1 canonical volume quality gate failed.', 'RuntimeException',
        );

        $this->assertSame(TechnicalFailureClassifierService::CAPABILITY, $result['class']);
        $this->assertSame('volume', $result['capability']);
        $this->assertFalse($result['blocks_global_generation']);
    }

    public function test_immutable_constructor_invariant_failure_is_terminal_diagnostic(): void
    {
        $result = app(TechnicalFailureClassifierService::class)->classify(
            'Technical quarantine: strict lab preflight failed (ONE_GENE_INVARIANT_FAILED).',
        );

        $this->assertSame(TechnicalFailureClassifierService::TERMINAL, $result['class']);
        $this->assertFalse($result['blocks_global_generation']);
        $this->assertSame('TERMINAL_DIAGNOSTIC', $result['action']);
    }

    /** @return array{0: LabGeneration, 1: AgentLearningLesson} */
    private function canonicalSource(): array
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Causal lab', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 2, 'status' => 'completed', 'data_fingerprint' => str_repeat('d', 64), 'trigger_context' => [],
        ]);
        $sourceModel = $this->model('source-candidate', ['entry_threshold' => 2]);
        $controlModel = $this->model('source-control', ['entry_threshold' => 1]);
        $candidate = $this->agent($generation, $sourceModel, ['entry_threshold' => ['old' => 1, 'new' => 2]]);
        $control = $this->agent($generation, $controlModel, []);
        $pair = $this->pair($generation, $candidate, $control, .10, 's');
        $episode = app(LearningKernelService::class)->openEpisode($candidate, [
            'decision_key' => 'source-episode-'.Str::uuid(), 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'context' => [],
        ]);
        AgentLearningSettlement::create([
            'settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id,
            'source_key' => 'source-settlement-'.$pair->id, 'source_type' => LabLearningLanePair::class,
            'source_id' => $pair->id, 'outcome_status' => 'settled', 'failure_class' => 'profit_factor',
            'evidence_state' => 'positive', 'selection_reward' => 1, 'hard_failure' => false,
            'outcome' => [], 'settled_at' => now(),
        ]);
        $lesson = AgentLearningLesson::create([
            'lesson_id' => (string) Str::uuid(), 'lesson_hash' => hash('sha512', 'source-lesson-'.Str::uuid()),
            'lab_agent_id' => $candidate->id, 'model_version_id' => $sourceModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'lesson_type' => 'skill_lesson', 'status' => 'provisional', 'failure_class' => 'profit_factor',
            'parameter_key' => 'entry_threshold', 'outcome' => 'beneficial',
            'evidence' => ['pair_id' => $pair->id, 'old_value' => ['value' => 1], 'new_value' => ['value' => 2]],
            'observed_at' => now(),
        ]);

        return [$generation->fresh('laboratory'), $lesson];
    }

    /** @return array{0: LabLearningLanePair, 1: AgentLearningLesson} */
    private function outcomePair(LabGeneration $generation, LabAgent $candidate, LabAgent $control, float $delta, string $suffix): array
    {
        $pair = $this->pair($generation, $candidate, $control, $delta, $suffix);
        $lesson = AgentLearningLesson::create([
            'lesson_id' => (string) Str::uuid(), 'lesson_hash' => hash('sha512', 'outcome-'.$suffix.Str::uuid()),
            'lab_agent_id' => $candidate->id, 'model_version_id' => $candidate->model_version_id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'lesson_type' => 'skill_lesson', 'status' => 'provisional', 'failure_class' => 'profit_factor',
            'parameter_key' => 'entry_threshold', 'outcome' => 'beneficial',
            'evidence' => ['pair_id' => $pair->id], 'observed_at' => now(),
        ]);

        return [$pair, $lesson];
    }

    private function pair(LabGeneration $generation, LabAgent $candidate, LabAgent $control, float $delta, string $suffix): LabLearningLanePair
    {
        $dataHash = str_repeat('d', 64);
        $executionHash = str_repeat('e', 64);
        $controlMap = LabMutationResponseMap::create([
            'response_key' => hash('sha256', 'control-'.$suffix.Str::uuid()), 'stage' => 'screening', 'status' => 'control',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'target' => 'profit_factor',
            'lab_agent_id' => $control->id, 'metadata' => ['control_contract' => [
                'protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control',
                'generation_id' => $generation->id, 'data_hash' => $dataHash, 'execution_hash' => $executionHash,
            ]],
        ]);
        $candidateMap = LabMutationResponseMap::create([
            'response_key' => hash('sha256', 'candidate-'.$suffix.Str::uuid()), 'stage' => 'screening', 'status' => 'screen_observed',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'target' => 'profit_factor',
            'lab_agent_id' => $candidate->id,
        ]);

        return LabLearningLanePair::create([
            'pair_key' => hash('sha256', 'pair-'.$suffix.Str::uuid()), 'lab_generation_id' => $generation->id,
            'candidate_agent_id' => $candidate->id, 'control_agent_id' => $control->id,
            'candidate_response_map_id' => $candidateMap->id, 'control_response_map_id' => $controlMap->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'baseline_source' => 'control', 'status' => 'lesson_compiled',
            'candidate_data_hash' => $dataHash, 'control_data_hash' => $dataHash,
            'candidate_execution_hash' => $executionHash, 'control_execution_hash' => $executionHash,
            'pair_integrity_status' => 'verified', 'same_generation' => true,
            'candidate_metrics' => ['profit_factor' => 1 + $delta], 'control_metrics' => ['profit_factor' => 1],
            'target_delta' => ['delta' => $delta, 'improved' => $delta > 0],
        ]);
    }

    private function intent(LabGeneration $generation, LabAgent $agent, string $type, array $lessons): AgentLearningMutationIntent
    {
        return AgentLearningMutationIntent::create([
            'intent_id' => (string) Str::uuid(), 'intent_key' => hash('sha512', $type.$agent->id),
            'packet_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
            'model_version_id' => $agent->model_version_id, 'lab_agent_id' => $agent->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'selected_gene' => 'entry_threshold', 'influence_type' => $type,
            'status' => 'bound', 'retrieved_lesson_ids' => $lessons, 'selected_lesson_ids' => $lessons,
            'causally_applied_lesson_ids' => $lessons, 'rejected_lesson_ids' => [],
            'causally_applied_retrieval_ids' => [], 'old_value' => ['value' => 1], 'new_value' => ['value' => 2],
            'baseline_hash' => str_repeat('a', 128), 'parameter_hash' => str_repeat('b', 128),
            'mutation_hash' => hash('sha512', json_encode($agent->parameter_diff)),
            'sealed_at' => now(), 'bound_at' => now(),
            'metadata' => ['causal_order' => ['retrieval_sequence' => 1, 'mutation_seal_sequence' => 2, 'agent_persistence_sequence' => 3]],
        ]);
    }

    private function model(string $name, array $parameters, array $metadata = []): ModelVersion
    {
        return ModelVersion::create([
            'name' => $name, 'strategy' => $name, 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => $parameters, 'metadata' => $metadata, 'evidence_status' => 'valid',
        ]);
    }

    private function agent(LabGeneration $generation, ModelVersion $model, array $diff): LabAgent
    {
        return LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => $diff,
        ]);
    }

    private function validReceiptMetadata(): array
    {
        return ['generation_target' => 'profit_factor', 'hypothesis_contract' => ['changed_gene' => 'entry_threshold']];
    }

    private function validReceipt(): array
    {
        return [
            'protocol' => LearningReceiptService::PROTOCOL, 'status' => 'provisional',
            'integrity' => ['valid' => true], 'promotion_evidence' => false,
        ];
    }
}
