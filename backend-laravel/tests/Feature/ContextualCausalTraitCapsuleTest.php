<?php

namespace Tests\Feature;

use App\Models\AgentLearningSettlement;
use App\Models\AgentLearningEpisode;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\LabSkillZooEntry;
use App\Models\ModelVersion;
use App\Services\CanonicalSkillCartridgeService;
use App\Services\ContextualCausalTraitCapsuleService;
use App\Services\LabInstrumentResearchService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContextualCausalTraitCapsuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_independent_context_attested_replications_form_one_durable_capsule(): void
    {
        $lab = AiLaboratory::create([
            'name' => 'Contextual capsule lab', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'trigger_context' => [], 'population_size' => 6, 'status' => 'screened',
        ]);
        $dataHash = str_repeat('d', 64);
        $executionHash = str_repeat('e', 64);
        $context = ['regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'london', 'direction' => 'BUY'];
        $latestCandidate = null;

        foreach (range(1, 3) as $replication) {
            [$candidate, $control, $pair, $map, $settlement] = $this->replication(
                $generation,
                $replication,
                $dataHash,
                $executionHash,
                $context,
            );
            $assignment = app(LabInstrumentResearchService::class)->assignment(
                $candidate->fresh(['modelVersion', 'generation.agents.modelVersion']),
            );
            $this->assertSame('assigned', $assignment['status']);
            $result = $this->attestedResult($assignment, $context, 'w'.$replication, $dataHash, $executionHash);
            $projected = app(CanonicalSkillCartridgeService::class)->project(
                $pair->fresh(['candidateAgent.modelVersion', 'controlAgent.modelVersion', 'candidateResponseMap', 'controlResponseMap']),
                $result,
                $map->fresh(),
                $settlement,
            );
            $this->assertNotNull($projected);
            $latestCandidate = $candidate;
        }

        $this->assertDatabaseCount('lab_skill_zoo_entries', 1);
        $entry = LabSkillZooEntry::query()->firstOrFail();
        $this->assertSame('confirmed', $entry->status);
        $this->assertSame('component_confirmed', $entry->component_status);
        $this->assertSame(3, data_get($entry->evidence, 'confirmation.powered_context_positive_observations'));
        $this->assertCount(3, (array) data_get($entry->evidence, 'confirmation.independent_window_keys'));
        $this->assertSame($latestCandidate->model_version_id, $entry->model_version_id);

        $resolved = app(CanonicalSkillCartridgeService::class)->traitCapsuleForMentor(
            $latestCandidate->modelVersion,
            $latestCandidate,
            'minimum_confidence',
            $context,
        );
        $this->assertTrue($resolved['valid']);
        $this->assertSame('exact_bundle_attested', data_get($resolved, 'capsule.instrument_bundle.status'));
        $this->assertSame(3, data_get($resolved, 'capsule.support.independent_windows'));

        $wrongContext = [...$context, 'session' => 'asia'];
        $this->assertFalse(app(ContextualCausalTraitCapsuleService::class)->assess(
            (array) data_get($resolved, 'capsule'),
            'minimum_confidence',
            $wrongContext,
        )['valid']);
    }

    public function test_positive_rows_without_powered_context_cannot_create_a_confirmed_capsule(): void
    {
        $lab = AiLaboratory::create([
            'name' => 'Fail closed capsule lab', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'trigger_context' => [], 'population_size' => 6, 'status' => 'screened',
        ]);
        $dataHash = str_repeat('d', 64);
        $executionHash = str_repeat('e', 64);
        $context = ['regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'london', 'direction' => 'BUY'];

        foreach (range(1, 3) as $replication) {
            [$candidate, , $pair, $map, $settlement] = $this->replication(
                $generation, $replication, $dataHash, $executionHash, $context,
            );
            $assignment = app(LabInstrumentResearchService::class)->assignment(
                $candidate->fresh(['modelVersion', 'generation.agents.modelVersion']),
            );
            $result = $this->attestedResult($assignment, $context, 'w'.$replication, $dataHash, $executionHash);
            $result['instrument_research_trace']['context_slices'][0]['powered'] = false;
            app(CanonicalSkillCartridgeService::class)->project(
                $pair->fresh(['candidateAgent.modelVersion', 'controlAgent.modelVersion', 'candidateResponseMap', 'controlResponseMap']),
                $result,
                $map->fresh(),
                $settlement,
            );
        }

        $entry = LabSkillZooEntry::query()->firstOrFail();
        $this->assertSame('provisional', $entry->status);
        $this->assertSame('paired_observed', $entry->component_status);
        $this->assertSame(0, data_get($entry->evidence, 'confirmation.powered_context_positive_observations'));
        $this->assertFalse(data_get($entry->evidence, 'trait_capsule.status') === 'sealed');
    }

    /** @return array{LabAgent,LabAgent,LabLearningLanePair,LabMutationResponseMap,AgentLearningSettlement} */
    private function replication(
        LabGeneration $generation,
        int $replication,
        string $dataHash,
        string $executionHash,
        array $context,
    ): array {
        $base = app(StrategyParameterSchemaService::class)->defaults('hybrid');
        $candidateParameters = [...$base, 'minimum_confidence' => 1.1];
        $pairKey = hash('sha512', 'capsule-pair-'.$generation->id.'-'.$replication);
        $controlModel = ModelVersion::create([
            'name' => 'capsule-control-'.$replication, 'strategy' => 'hybrid', 'version' => 'v1-c'.$replication,
            'generation' => 1, 'status' => 'testing', 'parameters' => $base,
            'metadata' => ['control_pair_contract' => ['pair_key' => $pairKey, 'role' => 'control']],
            'evidence_status' => 'valid',
        ]);
        $candidateModel = ModelVersion::create([
            'name' => 'capsule-candidate-'.$replication, 'strategy' => 'hybrid', 'version' => 'v1-x'.$replication,
            'generation' => 1, 'status' => 'testing', 'parameters' => $candidateParameters,
            'metadata' => ['control_pair_contract' => ['pair_key' => $pairKey, 'role' => 'candidate']],
            'evidence_status' => 'valid',
        ]);
        $control = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $controlModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'frozen_control', 'lifecycle_status' => 'screened', 'parameter_diff' => [],
        ]);
        $candidate = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $candidateModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'learning_lane', 'lifecycle_status' => 'screened',
            'parameter_diff' => ['minimum_confidence' => ['old' => 1.0, 'new' => 1.1]],
        ]);
        $candidateMap = LabMutationResponseMap::create([
            'response_key' => hash('sha256', 'capsule-candidate-map-'.$replication), 'stage' => 'full_replay',
            'status' => 'confirmed', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'parameter_key' => 'minimum_confidence',
            'old_value' => ['value' => 1.0], 'new_value' => ['value' => 1.1],
            'lab_agent_id' => $candidate->id, 'model_version_id' => $candidateModel->id,
            'metadata' => ['data_manifest_hash' => $dataHash, 'execution_hash' => $executionHash],
        ]);
        $controlMap = LabMutationResponseMap::create([
            'response_key' => hash('sha256', 'capsule-control-map-'.$replication), 'stage' => 'full_replay',
            'status' => 'control', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'lab_agent_id' => $control->id, 'model_version_id' => $controlModel->id,
            'metadata' => ['control_contract' => ['protocol' => 'frozen_control_v2', 'control_only' => true,
                'role' => 'control', 'generation_id' => $generation->id,
                'data_hash' => $dataHash, 'execution_hash' => $executionHash]],
        ]);
        $pair = LabLearningLanePair::create([
            'pair_key' => $pairKey, 'lab_generation_id' => $generation->id,
            'candidate_agent_id' => $candidate->id, 'control_agent_id' => $control->id,
            'candidate_response_map_id' => $candidateMap->id, 'control_response_map_id' => $controlMap->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'target' => 'profit_factor',
            'baseline_source' => 'control', 'status' => 'learning_observed', 'pair_integrity_status' => 'verified',
            'same_generation' => true, 'candidate_data_hash' => $dataHash, 'control_data_hash' => $dataHash,
            'candidate_execution_hash' => $executionHash, 'control_execution_hash' => $executionHash,
            'target_delta' => ['delta' => .2, 'improved' => true], 'independent_window_key' => 'w'.$replication,
            'failure_signature' => ['state' => $context],
        ]);
        $episode = AgentLearningEpisode::create([
            'episode_id' => (string) Str::uuid(), 'decision_key' => 'capsule-episode-'.$replication,
            'lab_agent_id' => $candidate->id, 'model_version_id' => $candidate->model_version_id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'stage' => 'full_replay', 'status' => 'settled', 'decision' => 'observe',
            'confidence' => 1.0, 'risk_veto' => false, 'context_hash' => hash('sha256', json_encode($context)),
            'data_hash' => $dataHash, 'execution_hash' => $executionHash,
            'decision_context' => $context, 'observations' => [], 'opened_at' => now(), 'settled_at' => now(),
        ]);
        $settlement = AgentLearningSettlement::create([
            'settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id,
            'source_key' => 'capsule-settlement-'.$replication, 'source_type' => LabLearningLanePair::class,
            'source_id' => $pair->id, 'outcome_status' => 'settled', 'failure_class' => 'profit_factor',
            'evidence_state' => 'positive', 'selection_reward' => 1, 'hard_failure' => false,
            'outcome' => [], 'settled_at' => now(),
        ]);

        return [$candidate, $control, $pair, $candidateMap, $settlement];
    }

    /** @return array<string,mixed> */
    private function attestedResult(array $assignment, array $context, string $window, string $dataHash, string $executionHash): array
    {
        return [
            'evidence_run_id' => 'capsule-'.$window,
            'data_manifest' => ['sha256' => $dataHash],
            'execution_contract' => ['execution_hash' => $executionHash],
            'profit_factor' => 1.3, 'max_drawdown_percent' => 6.0, 'total_trades' => 40,
            'forward_window_protocol' => ['window_keys' => [$window], 'observed_windows' => 1,
                'positive_windows' => 1, 'independence_verified' => true, 'overlap_detected' => false],
            'instrument_research_trace' => [
                'protocol' => 'lab_instrument_runtime_trace_v1', 'status' => 'consumed',
                'assignment_hash' => $assignment['assignment_hash'], 'assignment_hash_valid' => true,
                'parameter_hash_valid' => true, 'runtime_bindings_valid' => true,
                'instruments' => collect($assignment['selected'])->map(fn (array $instrument): array => [
                    'instrument_key' => $instrument['instrument_key'], 'status' => 'consumed',
                    'parameter_bindings' => $instrument['parameter_bindings'], 'promotion_evidence' => false,
                ])->values()->all(),
                'context_slices' => [[
                    'context_key' => 'trend_up|normal|london|BUY', 'context' => $context,
                    'metrics' => ['trades' => 20, 'net_pf' => 1.3, 'net_profit_percent' => 2.0,
                        'max_drawdown_percent' => 4.0, 'execution_cost_percent' => .1],
                    'powered' => true, 'promotion_evidence' => false,
                ]],
                'promotion_evidence' => false,
            ],
        ];
    }
}
