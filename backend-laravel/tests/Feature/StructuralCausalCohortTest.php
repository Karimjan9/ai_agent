<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\MicroReplayService;
use App\Services\LearningProtocolSafetyService;
use App\Services\StructuralResearchCohortService;
use App\Services\StrategyParameterSchemaService;
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
        $this->assertSame(2, $validation['controls']);
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
            'trade_ledger_hash' => 'same-trades', 'event_ledger_hash' => 'same-events',
            'parameter_hash' => 'same-parameter', 'entry_funnel' => ['accepted_entries' => 4],
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
}
