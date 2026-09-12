<?php

namespace Tests\Feature;

use App\Models\AgentLearningLesson;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\CausalLearningCohortPlannerService;
use App\Services\GenerationAdmissionDecisionService;
use App\Services\LabGenerationTerminalBoundaryService;
use App\Services\LabQueueJobInspector;
use App\Services\LearningLaneService;
use App\Services\LearningVelocityGateService;
use App\Services\MutationResponseMapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LearningIntegrityRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_constructor_control_only_always_records_control_response_map(): void
    {
        [$lab, $generation] = $this->scope();
        $model = ModelVersion::create([
            'name' => 'constructor-control', 'strategy' => 'constructor-control', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [],
            'metadata' => ['mutation_constructor_invariant' => ['control_only' => true], 'control_contract' => ['protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control', 'generation_id' => $generation->id, 'data_hash' => str_repeat('d', 64), 'execution_hash' => str_repeat('e', 64)]],
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => [],
        ]);

        $map = app(MutationResponseMapService::class)->recordScreening($agent, [
            'evidence_run_id' => 'control-evidence-1', 'screen_decision' => 'passed',
            'data_manifest' => ['sha256' => str_repeat('d', 64)],
            'execution_contract' => ['execution_hash' => str_repeat('e', 64)],
            'profit_factor' => 1.0,
        ]);

        $this->assertSame('control', $map['status']);
        $this->assertDatabaseHas('lab_mutation_response_maps', ['id' => $map['id'], 'status' => 'control']);
    }

    public function test_old_pair_without_exact_hash_contract_is_not_learning_paired(): void
    {
        [$lab, $generation] = $this->scope();
        $candidateModel = ModelVersion::create(['name' => 'legacy-candidate', 'strategy' => 'legacy-candidate', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $controlModel = ModelVersion::create(['name' => 'legacy-control', 'strategy' => 'legacy-control', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $candidate = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $candidateModel->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => ['x' => ['old' => 1, 'new' => 2]]]);
        $control = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $controlModel->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => []]);
        $candidateMap = LabMutationResponseMap::create(['response_key' => 'legacy-candidate-map', 'stage' => 'screening', 'status' => 'screen_observed', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'lab_agent_id' => $candidate->id, 'observed_metrics' => ['profit_factor' => 1.2]]);
        $controlMap = LabMutationResponseMap::create(['response_key' => 'legacy-control-map', 'stage' => 'screening', 'status' => 'control', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'lab_agent_id' => $control->id, 'observed_metrics' => ['profit_factor' => 1.0]]);
        LabLearningLanePair::create(['pair_key' => 'legacy-pair', 'lab_generation_id' => $generation->id, 'candidate_agent_id' => $candidate->id, 'control_agent_id' => $control->id, 'candidate_response_map_id' => $candidateMap->id, 'control_response_map_id' => $controlMap->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'status' => 'screen_paired', 'candidate_metrics' => ['profit_factor' => 1.2], 'control_metrics' => ['profit_factor' => 1.0], 'metadata' => ['same_snapshot' => true, 'same_execution_contract' => true]]);

        $status = app(LearningLaneService::class)->status('XAUUSD', 'H1');

        $this->assertSame(0, $status['paired']);
        $this->assertGreaterThan(0, $status['missing_control']);
    }

    public function test_monitor_percentages_expose_scope_period_and_denominators_without_false_birth_rates(): void
    {
        $status = app(LearningLaneService::class)->status('XAUUSD', 'H1');

        $this->assertArrayNotHasKey('provisional_skill_birth_rate_percent', $status['kpis']);
        $this->assertArrayNotHasKey('confirmed_mentor_birth_rate_percent', $status['kpis']);
        $this->assertArrayNotHasKey('forward_confirmation_rate_percent', $status['kpis']);
        $this->assertSame('density_not_probability', data_get($status, 'kpis.provisional_skill_lesson_density.interpretation'));
        $this->assertSame('lessons_per_verified_control_pair', data_get($status, 'kpis.provisional_skill_lesson_density.unit'));

        foreach (['paired_delta_coverage_percent', 'target_improvement_rate_percent',
            'repeat_failure_occurrence_share_percent', 'confirmed_skill_share_percent',
            'observed_pair_confirmation_rate_percent'] as $key) {
            $metric = $status['kpis'][$key];
            $this->assertSame('percent', $metric['unit']);
            $this->assertSame('XAUUSD', data_get($metric, 'scope.symbol'));
            $this->assertSame('H1', data_get($metric, 'scope.laboratory_timeframe'));
            $this->assertSame('all_time', data_get($metric, 'period.kind'));
            $this->assertArrayHasKey('unique_subject_type', $metric['numerator']);
            $this->assertArrayHasKey('unique_subject_type', $metric['denominator']);
            $this->assertNull($metric['value']);
            $this->assertSame('no_denominator', $metric['status']);
        }
    }

    public function test_three_consecutive_zero_pass_generations_open_strategy_deadlock_not_learning_starvation(): void
    {
        [$lab, $generation] = $this->scope();
        config()->set('services.lab_selection.zero_pass_circuit_breaker_generations', 3);
        $firstDecision = null;
        foreach (range(1, 3) as $number) {
            $current = $number === 1
                ? $generation
                : LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => $number, 'trigger_type' => 'test', 'population_size' => 1, 'status' => 'screened', 'trigger_context' => []]);
            $model = ModelVersion::create(['name' => 'zero-pass-model-'.$number, 'strategy' => 'zero-pass-model-'.$number, 'version' => 'v1', 'generation' => $number, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
            $agent = LabAgent::create(['lab_generation_id' => $current->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => []]);
            CandidateGateDecision::create(['lab_agent_id' => $agent->id, 'stage' => 'screening', 'decision' => 'failed', 'reason_codes' => ['FAILED_PROFIT_FACTOR'], 'metrics' => [], 'evaluated_at' => now()]);
            if ($number === 1) {
                $firstDecision = app(GenerationAdmissionDecisionService::class)->decide(
                    $lab,
                    $current,
                    ['trigger' => 'new_data', 'force' => true],
                    false,
                );
            }
        }

        $summary = app(LearningVelocityGateService::class)->summary('XAUUSD', 'H1');
        $escapeDecision = app(GenerationAdmissionDecisionService::class)->decide(
            $lab,
            LabGeneration::query()->where('ai_laboratory_id', $lab->id)->latest('generation')->firstOrFail(),
            ['trigger' => 'new_data'],
            false,
        );

        $this->assertSame(GenerationAdmissionDecisionService::OPEN_NORMAL_GENERATION, $firstDecision['decision']);
        $this->assertTrue($firstDecision['allowed']);
        $this->assertContains('AUTONOMOUS_ZERO_PASS_ACCUMULATION_REQUIRES_FRESH_DATA', $firstDecision['reason_codes']);
        $this->assertFalse($summary['allowed']);
        $this->assertSame('strategy_deadlock', $summary['status']);
        $this->assertTrue($summary['health_layers']['strategy_deadlock']['active']);
        $this->assertSame(3, $summary['health_layers']['strategy_deadlock']['consecutive_zero_pass_generations']);
        $this->assertFalse($summary['health_layers']['live_learning_backlog']['active']);
        $this->assertSame(GenerationAdmissionDecisionService::OPEN_STRUCTURAL_ESCAPE, $escapeDecision['decision']);
        $this->assertTrue($escapeDecision['allowed']);
    }

    public function test_terminal_failed_job_audit_does_not_become_live_learning_backlog(): void
    {
        [$lab, $generation] = $this->scope();
        $model = ModelVersion::create([
            'name' => 'terminal-failed-job-model', 'strategy' => 'terminal-failed-job-model',
            'version' => 'v1', 'generation' => 1, 'status' => 'testing',
            'parameters' => [], 'metadata' => [],
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'rejected', 'parameter_diff' => [],
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'terminal-failed-job-audit', 'connection' => 'redis', 'queue' => 'lab-full-validation',
            'payload' => json_encode(['data' => ['command' => 's:10:"labAgentId";i:'.$agent->id.';']]),
            'exception' => 'historical transport failure', 'failed_at' => now(),
        ]);

        $status = app(LearningVelocityGateService::class)->inspect($lab);

        $this->assertSame(0, data_get($status, 'learning_starvation.failed_lab_jobs'));
        $this->assertFalse(data_get($status, 'health_layers.live_learning_backlog.active'));
        $this->assertTrue($status['allowed']);
    }

    public function test_learning_confirmation_consumes_dispatch_learning_without_bypassing_other_blocks(): void
    {
        [$lab, $generation] = $this->scope();
        $generation->update(['status' => 'abandoned', 'completed_at' => now()]);
        $this->mock(LearningVelocityGateService::class, function ($mock): void {
            $mock->shouldReceive('inspect')->once()->andReturn([
                'allowed' => false,
                'status' => 'live_learning_backlog',
                'learning_starvation' => [
                    'actionable_pending_dojo' => 0,
                    'active_dispatches' => 0,
                ],
                'observations' => [],
            ]);
        });

        $decision = app(GenerationAdmissionDecisionService::class)->decide(
            $lab,
            $generation->fresh(),
            ['trigger' => 'learning_confirmation', 'learning_confirmation' => true],
            false,
        );

        $this->assertSame(GenerationAdmissionDecisionService::DISPATCH_LEARNING, $decision['decision']);
        $this->assertTrue($decision['allowed']);
        $this->assertContains('CAUSAL_CONFIRMATION_SATISFIES_LEARNING_DISPATCH', $decision['reason_codes']);
    }

    public function test_target_aligned_lesson_preempts_a_generic_generation_constructor(): void
    {
        [$lab, $generation] = $this->scope();
        $lesson = new AgentLearningLesson([
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'failure_class' => 'regime_coverage',
            'parameter_key' => 'state_machine_variant',
        ]);
        $lesson->id = 77;
        $this->mock(CausalLearningCohortPlannerService::class, function ($mock) use ($lesson): void {
            $mock->shouldReceive('eligibleLesson')->once()->with('XAUUSD', 'H1')->andReturn($lesson);
        });
        $this->mock(LearningVelocityGateService::class, function ($mock): void {
            $mock->shouldReceive('inspect')->once()->andReturn([
                'allowed' => false,
                'status' => 'strategy_deadlock',
                'learning_starvation' => ['actionable_pending_dojo' => 0, 'active_dispatches' => 0],
                'observations' => [],
            ]);
        });

        $decision = app(GenerationAdmissionDecisionService::class)->decide(
            $lab,
            $generation,
            ['trigger' => 'candidate_handoff'],
            false,
        );

        $this->assertFalse($decision['allowed']);
        $this->assertSame(GenerationAdmissionDecisionService::DISPATCH_LEARNING, $decision['decision']);
        $this->assertContains('TARGET_ALIGNED_CAUSAL_LESSON_HAS_GENERATION_PRIORITY', $decision['reason_codes']);
        $this->assertSame(77, data_get($decision, 'causal_confirmation_priority.lesson_id'));
        $this->assertSame('regime_coverage', data_get($decision, 'causal_confirmation_priority.target'));
    }

    public function test_model_version_status_follows_agent_lifecycle(): void
    {
        [$lab, $generation] = $this->scope();
        $model = ModelVersion::create(['name' => 'lifecycle-model', 'strategy' => 'lifecycle-model', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => []]);
        $agent->update(['lifecycle_status' => 'champion']);

        $this->assertSame('active', $model->fresh()->status);
        $this->assertSame('model_version_lifecycle_sync_v1', data_get($model->fresh()->metadata, 'lifecycle_sync.protocol'));
    }

    public function test_terminal_screening_boundary_closes_only_after_agent_run_and_queue_ownership_are_clear(): void
    {
        [$lab, $generation] = $this->scope();
        $generation->update(['status' => 'screening', 'completed_at' => null]);
        $screenedModel = ModelVersion::create(['name' => 'boundary-screened', 'strategy' => 'boundary-screened', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $technicalModel = ModelVersion::create(['name' => 'boundary-technical', 'strategy' => 'boundary-technical', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $screenedModel->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => []]);
        LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $technicalModel->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'technical_quarantine', 'parameter_diff' => []]);

        $this->mock(LabQueueJobInspector::class, function ($mock): void {
            $mock->shouldReceive('generationQueueBacklog')->andReturn([
                'backend' => 'redis', 'available' => true, 'total' => 0, 'queues' => [], 'rows' => [],
            ]);
        });

        $result = app(LabGenerationTerminalBoundaryService::class)->closeIfTerminal($generation);

        $this->assertTrue($result['closed']);
        $this->assertSame('screened', $generation->fresh()->status);
        $this->assertNotNull($generation->fresh()->completed_at);
        $this->assertSame(
            LabGenerationTerminalBoundaryService::PROTOCOL,
            data_get($generation->fresh()->trigger_context, 'screening_terminal_recovery.protocol'),
        );
        $this->assertFalse((bool) data_get($generation->fresh()->trigger_context, 'screening_terminal_recovery.promotion_evidence', true));
    }

    public function test_terminal_screening_boundary_fails_closed_while_an_immutable_run_is_open(): void
    {
        [$lab, $generation] = $this->scope();
        $generation->update(['status' => 'screening', 'completed_at' => null]);
        $model = ModelVersion::create(['name' => 'boundary-open-run', 'strategy' => 'boundary-open-run', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => []]);
        LabEvaluationRun::create([
            'run_id' => 'boundary-open-run-1',
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $model->id,
            'phase' => 'screening',
            'status' => 'started',
            'started_at' => now()->subHour(),
        ]);

        $result = app(LabGenerationTerminalBoundaryService::class)->closeIfTerminal($generation);

        $this->assertFalse($result['closed']);
        $this->assertSame('OPEN_EVIDENCE_RUNS_REMAIN', $result['reason_code']);
        $this->assertSame('screening', $generation->fresh()->status);
        $this->assertNull($generation->fresh()->completed_at);
    }

    /** @return array{0:AiLaboratory,1:LabGeneration} */
    private function scope(): array
    {
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'name' => 'Integrity test lab', 'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test', 'population_size' => 1, 'status' => 'screened', 'trigger_context' => []]);

        return [$lab, $generation];
    }
}
