<?php

namespace Tests\Feature;

use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningLesson;
use App\Models\AgentLearningSettlement;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\LearningKernelService;
use App\Services\LearningProtocolSafetyService;
use App\Services\LearningVelocityGateService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class LearningProtocolSafetyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_repair_bootstrap_is_auditable_without_falsifying_full_funnel_checks(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Safety lighthouse', 'timeframe' => 'H1',
            'strategy_families' => ['differential_router'], 'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        foreach (range(1, 4) as $number) {
            $generation = LabGeneration::create([
                'ai_laboratory_id' => $lab->id, 'generation' => $number,
                'trigger_type' => 'test', 'population_size' => 2,
                'status' => 'screened', 'trigger_context' => [],
            ]);
        }

        $controlParameters = app(StrategyParameterSchemaService::class)
            ->defaults('differential_router');
        $candidateParameters = $controlParameters;
        $candidateParameters['state_machine_variant'] = 'neutral_transition_cooldown_reentry_v1';
        $candidateModel = $this->model('repair-candidate', $candidateParameters);
        $controlModel = $this->model('repair-control', $controlParameters);
        $candidate = $this->agent($generation, $candidateModel, [
            'state_machine_variant' => [
                'old' => 'none',
                'new' => 'neutral_transition_cooldown_reentry_v1',
            ],
        ]);
        $control = $this->agent($generation, $controlModel);
        $dataHash = str_repeat('d', 64);
        $executionHash = str_repeat('e', 64);
        $controlMap = LabMutationResponseMap::create([
            'response_key' => str_repeat('c', 64), 'stage' => 'screening', 'status' => 'control',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'lab_agent_id' => $control->id, 'observed_metrics' => ['profit_factor' => 1.0],
            'metadata' => ['control_contract' => [
                'protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control',
                'generation_id' => $generation->id, 'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
            ]],
        ]);
        $pair = LabLearningLanePair::create([
            'pair_key' => str_repeat('p', 64), 'lab_generation_id' => $generation->id,
            'candidate_agent_id' => $candidate->id, 'control_agent_id' => $control->id,
            'control_response_map_id' => $controlMap->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'differential_router', 'target' => 'regime_coverage',
            'baseline_source' => 'control', 'status' => 'canonical_episode_settled',
            'candidate_data_hash' => $dataHash, 'control_data_hash' => $dataHash,
            'candidate_execution_hash' => $executionHash, 'control_execution_hash' => $executionHash,
            'pair_integrity_status' => 'verified', 'same_generation' => true,
            'candidate_metrics' => ['profit_factor' => 1.1], 'control_metrics' => ['profit_factor' => 1.0],
        ]);
        $safety = app(LearningProtocolSafetyService::class);

        $this->assertFalse($safety->resumeReadiness()['ready']);

        $episode = AgentLearningEpisode::create([
            'episode_id' => '00000000-0000-0000-0000-000000000101',
            'decision_key' => 'safety-repair-bootstrap', 'lab_agent_id' => $candidate->id,
            'model_version_id' => $candidateModel->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'differential_router', 'stage' => 'full_replay', 'status' => 'settled',
            'context_hash' => str_repeat('1', 64), 'decision_context' => [], 'opened_at' => now(),
            'settled_at' => now(),
        ]);
        AgentLearningSettlement::create([
            'settlement_id' => '00000000-0000-0000-0000-000000000102',
            'episode_id' => $episode->id, 'source_key' => 'safety-repair-settlement',
            'source_type' => LabLearningLanePair::class, 'source_id' => $pair->id,
            'outcome_status' => 'settled', 'evidence_state' => 'positive',
            'hard_failure' => false, 'outcome' => [], 'settled_at' => now(),
        ]);

        $readiness = $safety->resumeReadiness();

        $this->assertTrue($readiness['ready']);
        $this->assertSame('canonical_learning_repair_bootstrap', $readiness['labs'][0]['resume_mode']);
        $this->assertNotEmpty($readiness['labs'][0]['failed_checks']);
        $canonicalProgress = new ReflectionMethod(LearningVelocityGateService::class, 'canonicalProgressCount');
        $canonicalProgress->setAccessible(true);
        $this->assertSame(1, $canonicalProgress->invoke(
            app(LearningVelocityGateService::class),
            collect([$candidate->id]),
        ));

        $canonicalLesson = AgentLearningLesson::create([
            'lesson_id' => '00000000-0000-0000-0000-000000000103',
            'lesson_hash' => str_repeat('a', 128), 'lab_agent_id' => $candidate->id,
            'model_version_id' => $candidateModel->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'differential_router', 'lesson_type' => 'skill_lesson',
            'status' => 'provisional', 'failure_class' => 'regime_coverage',
            'parameter_key' => 'state_machine_variant', 'outcome' => 'beneficial',
            'source_run_ids' => ['canonical-run'],
            'evidence' => ['pair_id' => $pair->id, 'promotion_evidence' => false],
            'observed_at' => now(),
        ]);
        AgentLearningLesson::create([
            'lesson_id' => '00000000-0000-0000-0000-000000000104',
            'lesson_hash' => str_repeat('b', 128), 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'differential_router', 'lesson_type' => 'skill_lesson',
            'status' => 'confirmed', 'failure_class' => 'regime_coverage',
            'parameter_key' => 'legacy_gene', 'outcome' => 'beneficial',
            'source_run_ids' => ['legacy-run'], 'evidence' => [], 'observed_at' => now(),
        ]);

        $packet = app(LearningKernelService::class)->retrieveForGeneration(
            'XAUUSD', 'H1', 'differential_router', [], null, null, 1,
        );

        $this->assertSame($canonicalLesson->id, $packet['positive_lessons'][0]['lesson_id']);
        $this->assertSame('canonical_settled', $packet['positive_lessons'][0]['provenance']);
    }

    private function model(string $name, array $parameters): ModelVersion
    {
        return ModelVersion::create([
            'name' => $name, 'strategy' => $name, 'version' => 'v1',
            'generation' => 4, 'status' => 'testing', 'parameters' => $parameters, 'metadata' => [],
        ]);
    }

    private function agent(LabGeneration $generation, ModelVersion $model, array $diff = []): LabAgent
    {
        return LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => $diff,
        ]);
    }
}
