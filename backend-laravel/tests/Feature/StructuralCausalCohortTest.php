<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\LearningLaneService;
use App\Services\LearningProtocolSafetyService;
use App\Services\MicroReplayService;
use App\Services\MutationResponseMapService;
use App\Services\StrategyParameterSchemaService;
use App\Services\StructuralResearchCohortService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StructuralCausalCohortTest extends TestCase
{
    use RefreshDatabase;

    public function test_structural_cohort_has_twenty_control_paired_diverse_seats(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'Structural test lab',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid', 'differential_router'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $service = app(StructuralResearchCohortService::class);
        $plan = $service->plan($lab, ['source_generation_id' => 1, 'profile_hash' => 'test']);
        $validation = $service->validatePlan($plan);

        $this->assertTrue($validation['allowed']);
        $this->assertCount(20, $plan);
        $this->assertSame(5, $validation['controls']);
        $this->assertGreaterThanOrEqual(5, count(array_filter(
            $validation['families'],
            fn (int $count, string $family): bool => $family !== 'frozen_control' && $count > 0,
            ARRAY_FILTER_USE_BOTH,
        )));
        foreach ($plan as $seat) {
            $this->assertTrue((bool) data_get($seat, 'niche.frozen_control_pair_required'));
            $this->assertTrue((bool) data_get($seat, 'niche.causal_micro_probe_required'));
            $this->assertTrue((bool) data_get($seat, 'niche.independent_evidence_required'));
        }
    }

    public function test_safety_service_rejects_a_non_structural_twenty_seat_shape(): void
    {
        $service = app(LearningProtocolSafetyService::class);
        $contract = app(StructuralResearchCohortService::class)->contract();
        $profile = [
            'cohort_mode' => StructuralResearchCohortService::COHORT_MODE,
            'rescue_protocol' => LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL,
            'temporary' => true,
            'promotion_evidence' => false,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'group_plan' => ['bad' => ['targets' => ['only-one']]],
            'structural_research_contract' => $contract,
        ];

        $this->assertFalse($service->controlledRescueAllowed('candidate_handoff', 20, $profile));
    }

    public function test_safety_service_accepts_the_dynamic_ten_pair_rescue_constitution(): void
    {
        $structural = app(StructuralResearchCohortService::class);
        $profile = [
            'cohort_mode' => StructuralResearchCohortService::COHORT_MODE,
            'rescue_protocol' => LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL,
            'temporary' => true,
            'promotion_evidence' => false,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'group_plan' => $structural->groupPlan(),
            'structural_research_contract' => $structural->contract(),
        ];

        $this->assertTrue(app(LearningProtocolSafetyService::class)
            ->controlledRescueAllowed('candidate_handoff', 20, $profile));
    }

    public function test_micro_probe_rejects_parameter_hash_without_behavior_delta(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Micro test lab', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 2, 'status' => 'screened', 'trigger_context' => [],
        ]);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('hybrid');
        $candidateModel = ModelVersion::create([
            'name' => 'micro-candidate', 'strategy' => 'micro-candidate', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => $parameters, 'metadata' => [],
        ]);
        $controlModel = ModelVersion::create([
            'name' => 'micro-control', 'strategy' => 'micro-control', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => $parameters, 'metadata' => [],
        ]);
        $candidate = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $candidateModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => ['x' => ['old' => 1, 'new' => 2]],
        ]);
        $control = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $controlModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => [],
        ]);
        $metrics = [
            'profit_factor' => 1.0, 'total_trades' => 4,
            'trade_ledger_hash' => 'same-trades', 'event_ledger_hash' => 'same-events', 'signal_decision_hash' => 'same-signals',
            'parameter_hash' => 'same-parameter', 'entry_funnel' => ['accepted_entries' => 4],
            'exit_funnel' => ['accepted_exits' => 4], 'abstention_count' => 0,
            'screening_survival' => ['temporal_chunk_survival' => ['window_profit_factors' => [1.1, 1.1, 1.1]]],
        ];
        $candidateMap = LabMutationResponseMap::create([
            'response_key' => 'micro-candidate-key', 'stage' => 'screening', 'status' => 'candidate',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'lab_agent_id' => $candidate->id, 'model_version_id' => $candidateModel->id,
            'observed_metrics' => [...$metrics, 'parameter_hash' => 'new-parameter'],
        ]);
        $controlMap = LabMutationResponseMap::create([
            'response_key' => 'micro-control-key', 'stage' => 'screening', 'status' => 'control',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'lab_agent_id' => $control->id, 'model_version_id' => $controlModel->id,
            'observed_metrics' => $metrics,
        ]);
        $pair = LabLearningLanePair::create([
            'pair_key' => 'micro-parameter-only-pair', 'lab_generation_id' => $generation->id,
            'candidate_agent_id' => $candidate->id, 'control_agent_id' => $control->id,
            'candidate_response_map_id' => $candidateMap->id, 'control_response_map_id' => $controlMap->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'status' => 'screen_paired',
            'candidate_metrics' => [...$metrics, 'parameter_hash' => 'new-parameter'],
            'control_metrics' => $metrics,
            'metadata' => ['same_snapshot' => true, 'same_execution_contract' => true],
        ]);

        $assessment = app(MicroReplayService::class)->assessPair($pair, false);
        $this->assertSame('failed', $assessment['status']);
        $this->assertSame('PARAMETER_ONLY_NO_CAUSAL_EFFECT', $assessment['reason']);
        $this->assertTrue($assessment['causal_probe']['parameter_hash_alone_is_insufficient']);
    }

    public function test_screening_response_maps_preserve_causal_payload_through_pair_and_micro_replay(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Causal map integration lab', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 2, 'status' => 'screened', 'data_fingerprint' => 'snapshot-hash', 'trigger_context' => [],
        ]);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('hybrid');
        $controlParameters = [...$parameters, 'entry_threshold' => 1];
        $candidateParameters = [...$parameters, 'entry_threshold' => 2];
        $controlModel = ModelVersion::create([
            'name' => 'causal-control', 'strategy' => 'causal-control', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => $controlParameters, 'metadata' => ['control_contract' => [
                'protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control',
            ]],
        ]);
        $candidateModel = ModelVersion::create([
            'name' => 'causal-candidate', 'strategy' => 'causal-candidate', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => $candidateParameters, 'metadata' => ['generation_target' => 'profit_factor'],
        ]);
        $control = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $controlModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened',
        ]);
        $candidate = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $candidateModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened',
            'parameter_diff' => ['entry_threshold' => ['old' => 1, 'new' => 2]],
        ]);
        $base = [
            'evidence_run_id' => 'causal-run', 'data_manifest' => ['sha256' => 'snapshot-hash'],
            'execution_contract' => ['execution_hash' => 'execution-hash'], 'total_trades' => 4,
            'profit_factor' => 1.0, 'trade_ledger_hash' => 'control-trades', 'event_ledger_hash' => 'control-events',
            'signal_decision_hash' => 'control-signals', 'parameter_hash' => 'control-parameters',
            'entry_funnel' => ['accepted_entries' => 4], 'exit_funnel' => ['accepted_exits' => 4], 'abstention_count' => 0,
            'screening_survival' => ['temporal_chunk_survival' => ['window_profit_factors' => [1.1, 1.1, 1.1]]],
        ];
        $maps = app(MutationResponseMapService::class);
        $controlMap = $maps->recordScreening($control->fresh(['modelVersion']), $base);
        $candidateResult = [...$base, 'evidence_run_id' => 'causal-run-candidate', 'profit_factor' => 1.3,
            'trade_ledger_hash' => 'candidate-trades', 'event_ledger_hash' => 'candidate-events',
            'signal_decision_hash' => 'candidate-signals', 'parameter_hash' => 'candidate-parameters',
            'entry_funnel' => ['accepted_entries' => 5], 'exit_funnel' => ['accepted_exits' => 5],
        ];
        $candidateMap = $maps->recordScreening($candidate->fresh(['modelVersion']), $candidateResult);

        $this->assertSame('causal_observation_v1', data_get(LabMutationResponseMap::findOrFail($candidateMap['id'])->observed_metrics, 'causal_observation.protocol'));
        $pair = app(LearningLaneService::class)->pairScreeningObservation($candidate->fresh(['modelVersion', 'generation']), $candidateResult, $candidateMap);
        $this->assertSame('screen_paired', $pair['status']);
        $assessment = app(MicroReplayService::class)->assessPair(LabLearningLanePair::findOrFail($pair['id']), false);
        $this->assertNotSame('CAUSAL_OBSERVATION_INCOMPLETE', $assessment['reason']);
        $this->assertTrue((bool) data_get($assessment, 'causal_probe.causal_observation_complete'));
        $this->assertNotNull($controlMap);
    }
}
