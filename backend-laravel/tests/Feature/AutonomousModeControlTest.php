<?php

namespace Tests\Feature;

use App\Jobs\EvaluateMtfPlaybookPriorJob;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\SystemEvent;
use App\Services\AutonomousModeService;
use App\Services\GenerationAdmissionDecisionService;
use App\Services\LabPopulationService;
use App\Services\LabQueueJobInspector;
use App\Services\LearningIntelligenceAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutonomousModeControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_stop_and_status_are_persistent_idempotent_and_xauusd_canonical(): void
    {
        $this->laboratory();

        $this->assertSame(0, Artisan::call('ai:stop', ['--timeframe' => 'M5', '--json' => true]));
        $stopped = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($stopped['enabled']);
        $this->assertSame('MTF', $stopped['timeframe']);
        $this->assertSame('H1', $stopped['laboratory_storage_timeframe']);
        $this->assertSame('single_multi_timeframe_lineage', data_get($stopped, 'organism.scope'));
        $this->assertSame('stopped', $stopped['state']);
        $this->assertTrue($stopped['changed']);

        $this->assertSame(0, Artisan::call('ai:stop', ['--timeframe' => 'H4', '--json' => true]));
        $repeated = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($repeated['changed']);

        $this->assertSame(0, Artisan::call('ai:start', ['--timeframe' => 'M15', '--json' => true]));
        $started = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($started['enabled']);
        $this->assertSame('running', $started['state']);
        $this->assertSame('MTF', $started['timeframe']);
        $this->assertSame('H1', $started['laboratory_storage_timeframe']);

        Cache::put('system:scheduler-heartbeat', now()->toIso8601String(), now()->addMinute());
        $this->assertSame(0, Artisan::call('ai:status', ['--timeframe' => 'M5', '--json' => true]));
        $status = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($status['enabled']);
        $this->assertSame('scheduler_owns_generation_learning_and_recovery', $status['next_action']);
        $this->assertSame('MTF', $status['timeframe']);
        $this->assertSame('H1', $status['laboratory_storage_timeframe']);
        $this->assertSame('entry_and_execution', data_get($status, 'organism.timeframe_roles.M5'));
        $this->assertSame('start_stop_and_monitor_only', data_get($status, 'controller_contract.role'));
        $this->assertSame('lightweight_monitor', $status['effective_controller_profile']);
        $this->assertSame('compact', data_get($status, 'controller_contract.diagnostic_depth'));
        $this->assertFalse(data_get($status, 'controller_contract.manual_generation_commands_allowed'));
        $this->assertFalse(data_get($status, 'controller_contract.force_or_gate_bypass_allowed'));
        $this->assertTrue(data_get($status, 'monitor.scheduler_healthy'));
        $this->assertFalse(data_get($status, 'monitor.runtime_attention_required'));
        $this->assertSame([], data_get($status, 'monitor.runtime_attention_reasons'));
        $this->assertSame(
            'gated_awaiting_confirmed_cartridge',
            data_get($status, 'monitor.instrument_learning.block_1_candidate_inventory.autonomous_invention.status'),
        );
        $this->assertSame(2, SystemEvent::query()->where('event_type', 'autonomous_mode_transition')->count());
    }

    public function test_strong_and_lightweight_are_monitoring_profiles_over_the_same_governed_engine(): void
    {
        $this->laboratory();

        $this->assertSame(0, Artisan::call('ai:start', [
            '--controller' => 'strong',
            '--json' => true,
        ]));
        $started = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('strong_supervisor', $started['controller_profile']);

        $this->assertSame(0, Artisan::call('ai:status', ['--json' => true]));
        $strong = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('strong_supervisor', $strong['stored_controller_profile']);
        $this->assertSame('strong_supervisor', $strong['effective_controller_profile']);
        $this->assertSame('supervised_diagnostic_monitor', data_get($strong, 'controller_contract.role'));
        $this->assertSame('deep', data_get($strong, 'controller_contract.diagnostic_depth'));
        $this->assertTrue(data_get($strong, 'monitor.supervision.available'));
        $this->assertSame(
            LearningIntelligenceAuditService::PROTOCOL,
            data_get($strong, 'monitor.supervision.learning_intelligence.protocol'),
        );
        $this->assertTrue(data_get($strong, 'monitor.supervision.learning_intelligence.available'));
        $this->assertSame(
            'no_causal_positive_component_observed',
            data_get($strong, 'monitor.supervision.learning_intelligence.learning_status'),
        );
        $this->assertTrue(data_get($strong, 'monitor.supervision.learning_intelligence.attention_required'));
        $this->assertSame(
            0,
            data_get($strong, 'monitor.supervision.learning_intelligence.canonical_learning.positive_absolute_settlements'),
        );
        $this->assertFalse(data_get($strong, 'monitor.supervision.learning_intelligence.promotion_evidence'));
        $this->assertFalse(data_get($strong, 'controller_contract.manual_generation_commands_allowed'));
        $this->assertFalse(data_get($strong, 'controller_contract.force_or_gate_bypass_allowed'));

        $this->assertSame(0, Artisan::call('ai:status', [
            '--controller' => 'weak',
            '--json' => true,
        ]));
        $lightweight = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('strong_supervisor', $lightweight['stored_controller_profile']);
        $this->assertSame('lightweight_monitor', $lightweight['effective_controller_profile']);
        $this->assertSame('start_stop_and_monitor_only', data_get($lightweight, 'controller_contract.role'));
        $this->assertArrayNotHasKey('supervision', $lightweight['monitor']);
        $this->assertTrue(data_get($lightweight, 'controller_contract.same_governed_engine_for_both_profiles'));
    }

    public function test_invalid_controller_profile_is_rejected_without_changing_autonomy(): void
    {
        $this->laboratory();

        $this->assertSame(2, Artisan::call('ai:start', [
            '--controller' => 'uncontrolled',
            '--json' => true,
        ]));
        $this->assertStringContainsString('Unsupported controller profile', Artisan::output());
        $this->assertSame(0, SystemEvent::query()->where('event_type', 'autonomous_mode_transition')->count());
    }

    public function test_lightweight_monitor_exposes_a_stranded_technical_generation_without_granting_recovery_authority(): void
    {
        $lab = $this->laboratory();
        $generation = LabGeneration::query()->create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 212,
            'trigger_type' => 'shadow_research',
            'population_size' => 1,
            'status' => 'screening',
            'trigger_context' => [],
        ]);
        $model = ModelVersion::query()->create([
            'name' => 'stranded-technical-model',
            'strategy' => 'hybrid',
            'version' => 'v212',
            'generation' => 212,
            'status' => 'testing',
            'parameters' => [],
            'metadata' => [],
        ]);
        LabAgent::query()->create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'origin' => 'test',
            'lifecycle_status' => 'evaluation_error',
            'parameter_diff' => [],
        ]);
        Cache::put('system:scheduler-heartbeat', now()->toIso8601String(), now()->addMinute());

        $this->assertSame(0, Artisan::call('ai:status', [
            '--controller' => 'lightweight',
            '--json' => true,
        ]));
        $status = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue(data_get($status, 'monitor.runtime_attention_required'));
        $this->assertContains(
            'ACTIVE_GENERATION_HAS_TERMINAL_TECHNICAL_AGENTS_WITHOUT_QUEUED_RECOVERY',
            data_get($status, 'monitor.runtime_attention_reasons'),
        );
        $this->assertSame('failed', data_get($status, 'monitor.generation.technical_process_acceptance.state'));
        $this->assertSame(1, data_get($status, 'monitor.generation.technical_process_acceptance.technical_agents'));
        $this->assertContains(
            'TECHNICAL_AGENT_RECORDED',
            data_get($status, 'monitor.generation.technical_process_acceptance.reason_codes'),
        );
        $this->assertTrue(data_get($status, 'monitor.generation.technical_process_acceptance.manual_intervention_required'));
        $this->assertArrayNotHasKey('supervision', $status['monitor']);
        $this->assertFalse(data_get($status, 'controller_contract.manual_generation_commands_allowed'));
    }

    public function test_lightweight_monitor_marks_a_complete_active_population_as_running_clean(): void
    {
        $lab = $this->laboratory();
        $generation = LabGeneration::query()->create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 214,
            'trigger_type' => 'new_data',
            'population_size' => 1,
            'status' => 'screening',
            'trigger_context' => [],
        ]);
        $model = ModelVersion::query()->create([
            'name' => 'clean-running-model',
            'strategy' => 'hybrid',
            'version' => 'v214',
            'generation' => 214,
            'status' => 'testing',
            'parameters' => [],
            'metadata' => [],
        ]);
        LabAgent::query()->create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'origin' => 'test',
            'lifecycle_status' => 'queued',
            'parameter_diff' => [],
        ]);
        Cache::put('system:scheduler-heartbeat', now()->toIso8601String(), now()->addMinute());

        $status = app(AutonomousModeService::class)->monitor('XAUUSD', 'H1', 'lightweight');

        $this->assertSame('running_clean', data_get($status, 'monitor.generation.technical_process_acceptance.state'));
        $this->assertSame(0, data_get($status, 'monitor.generation.technical_process_acceptance.technical_evaluation_runs'));
        $this->assertFalse(data_get($status, 'monitor.generation.technical_process_acceptance.manual_intervention_required'));
    }

    public function test_lightweight_monitor_uses_immutable_generation_plan_for_incomplete_population_truth(): void
    {
        $lab = $this->laboratory();
        LabGeneration::query()->create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 213,
            'trigger_type' => 'candidate_handoff',
            'population_size' => 16,
            'status' => 'technical_quarantine',
            'trigger_context' => [
                'generation_plan' => array_fill(0, 20, ['family' => 'hybrid']),
            ],
        ]);
        Cache::put('system:scheduler-heartbeat', now()->toIso8601String(), now()->addMinute());

        $status = app(AutonomousModeService::class)->monitor('XAUUSD', 'H1', 'lightweight');

        $this->assertSame(20, data_get($status, 'monitor.generation.planned_population'));
        $this->assertSame(0, data_get($status, 'monitor.generation.actual_population'));
        $this->assertFalse(data_get($status, 'monitor.generation.population_complete'));
        $this->assertTrue(data_get($status, 'monitor.runtime_attention_required'));
        $this->assertContains(
            'GENERATION_CONSTRUCTION_INCOMPLETE',
            data_get($status, 'monitor.runtime_attention_reasons'),
        );
    }

    public function test_monitor_degrades_to_explicit_unknown_state_when_runtime_backends_are_unavailable(): void
    {
        $lab = $this->laboratory();
        LabGeneration::query()->create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'new_data',
            'population_size' => 0,
            'status' => 'completed',
            'trigger_context' => [],
        ]);
        $this->mock(LabPopulationService::class, function ($mock): void {
            $mock->shouldReceive('constructorStatus')->once()->andThrow(new \RuntimeException('cache unavailable'));
        });
        $this->mock(LabQueueJobInspector::class, function ($mock): void {
            $mock->shouldReceive('labQueueBacklog')->once()->andThrow(new \RuntimeException('queue unavailable'));
        });
        Cache::shouldReceive('get')
            ->once()
            ->with('system:scheduler-heartbeat')
            ->andThrow(new \RuntimeException('cache unavailable'));

        $status = app(AutonomousModeService::class)->monitor('XAUUSD', 'H1', 'lightweight');

        $this->assertFalse(data_get($status, 'monitor.scheduler_healthy'));
        $this->assertNull(data_get($status, 'monitor.queue.total'));
        $this->assertFalse(data_get($status, 'monitor.generation.construction.available', false));
        $this->assertTrue(data_get($status, 'monitor.runtime_attention_required'));
        $this->assertContains('CONSTRUCTOR_STATE_UNKNOWN', data_get($status, 'monitor.runtime_attention_reasons'));
        $this->assertContains('SCHEDULER_HEARTBEAT_UNKNOWN', data_get($status, 'monitor.runtime_attention_reasons'));
        $this->assertContains('QUEUE_STATE_UNKNOWN', data_get($status, 'monitor.runtime_attention_reasons'));
    }

    public function test_stop_blocks_new_terminal_admission_until_start(): void
    {
        $lab = $this->laboratory();
        app(AutonomousModeService::class)->stop('XAUUSD', 'M15', 'test', 'test_stop');

        $blocked = app(GenerationAdmissionDecisionService::class)->decide(
            $lab,
            null,
            ['trigger' => 'new_data', 'source' => 'test'],
            false,
        );

        $this->assertSame(GenerationAdmissionDecisionService::BLOCK_HARD, $blocked['decision']);
        $this->assertFalse($blocked['allowed']);
        $this->assertContains('AUTONOMOUS_MODE_STOPPED', $blocked['reason_codes']);

        $specialBlocked = app(GenerationAdmissionDecisionService::class)->decide(
            $lab,
            null,
            ['trigger' => 'candidate_handoff', 'source' => 'scheduled_special_lane'],
            false,
        );
        $this->assertSame(GenerationAdmissionDecisionService::BLOCK_HARD, $specialBlocked['decision']);
        $this->assertFalse($specialBlocked['allowed']);
        $this->assertContains('AUTONOMOUS_MODE_STOPPED', $specialBlocked['reason_codes']);

        $population = app(LabPopulationService::class);
        $this->assertNull($population->build('XAUUSD', 'candidate_handoff', true, 'H1'));
        $this->assertSame('GENERATION_ADMISSION_BLOCK_HARD', $population->lastBuildOutcome()['reason_code']);

        app(AutonomousModeService::class)->start('XAUUSD', 'H4', 'test', 'test_start');
        $open = app(GenerationAdmissionDecisionService::class)->decide(
            $lab,
            null,
            ['trigger' => 'new_data', 'source' => 'test'],
            false,
        );

        $this->assertSame(GenerationAdmissionDecisionService::OPEN_NORMAL_GENERATION, $open['decision']);
        $this->assertTrue($open['allowed']);
    }

    public function test_enabled_mode_can_accumulate_a_zero_pass_observation_on_fresh_data(): void
    {
        $lab = $this->laboratory();
        $generation = LabGeneration::query()->create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'new_data',
            'population_size' => 1,
            'status' => 'screened',
            'trigger_context' => [],
        ]);
        $model = ModelVersion::query()->create([
            'name' => 'autonomy-zero-pass-model',
            'strategy' => 'autonomy-zero-pass-model',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => [],
            'metadata' => [],
        ]);
        $agent = LabAgent::query()->create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'origin' => 'test',
            'lifecycle_status' => 'screened',
            'parameter_diff' => [],
        ]);
        CandidateGateDecision::query()->create([
            'lab_agent_id' => $agent->id,
            'stage' => 'screening',
            'decision' => 'failed',
            'reason_codes' => ['FAILED_PROFIT_FACTOR'],
            'metrics' => [],
            'evaluated_at' => now(),
        ]);

        $decision = app(GenerationAdmissionDecisionService::class)->decide(
            $lab,
            $generation,
            ['trigger' => 'new_data', 'source' => 'test'],
            false,
        );

        $this->assertSame(GenerationAdmissionDecisionService::OPEN_NORMAL_GENERATION, $decision['decision']);
        $this->assertTrue($decision['allowed']);
        $this->assertContains('AUTONOMOUS_ZERO_PASS_ACCUMULATION_REQUIRES_FRESH_DATA', $decision['reason_codes']);
    }

    public function test_stop_prevents_a_scheduled_mtf_research_job_from_being_queued(): void
    {
        Queue::fake();
        $this->laboratory();
        app(AutonomousModeService::class)->stop('XAUUSD', 'H1', 'test', 'test_stop');

        $this->artisan('trading:dispatch-mtf-playbook-prior', ['symbol' => 'XAUUSD'])
            ->expectsOutput('MTF prior deferred: autonomous mode is stopped; monitoring remains available.')
            ->assertSuccessful();

        Queue::assertNotPushed(EvaluateMtfPlaybookPriorJob::class);
    }

    private function laboratory(): AiLaboratory
    {
        return AiLaboratory::query()->create([
            'symbol' => 'XAUUSD',
            'name' => 'XAUUSD organism',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
    }
}
