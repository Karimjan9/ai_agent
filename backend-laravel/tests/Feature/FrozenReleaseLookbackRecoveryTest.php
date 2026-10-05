<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLifecycleEvent;
use App\Models\ModelVersion;
use App\Models\SystemEvent;
use App\Services\AutonomousModeService;
use App\Services\ExecutionContractService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabLifecycleOrchestrator;
use App\Services\LabQueueJobInspector;
use App\Services\LabReplayRecoveryService;
use App\Services\LearningVelocityGateService;
use App\Services\ResearchReleaseSealService;
use App\Services\ResearchLoopArbiterService;
use App\Services\ScheduledCommandOutcomeClassifierService;
use App\Services\TechnicalGenerationRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FrozenReleaseLookbackRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lifecycle_orchestrator.autonomous_technical_recovery_enabled' => true,
            'services.lifecycle_orchestrator.autonomous_technical_recovery_max_dispatch' => 2,
            'services.lifecycle_orchestrator.autonomous_technical_recovery_daily_limit' => 2,
            'services.lab_selection.learning_velocity_lookback_generations' => 3]);
        $this->partialMock(LabImmutableEvidenceService::class, fn ($mock) => $mock->shouldReceive('codeHash')->andReturn(str_repeat('c', 64)));
        $this->partialMock(ResearchReleaseSealService::class, fn ($mock) => $mock->shouldReceive('pythonHash')->andReturn(str_repeat('d', 64)));
        $this->partialMock(LabQueueJobInspector::class, fn ($mock) => $mock->shouldReceive('labQueueBacklog')->andReturn(['total' => 0, 'queues' => ['lab-screening' => 0]]));
        $this->mock(TechnicalGenerationRecoveryService::class, fn ($mock) => $mock->shouldReceive('readiness')->andReturn(['ready' => true, 'idle' => true]));
        $this->mock(AutonomousModeService::class, fn ($mock) => $mock->shouldReceive('status')->andReturn(['enabled' => true, 'state' => 'running']));
        Http::fake(['*/api/strategies' => Http::response(['strategies' => []])]);
        Bus::fake();
    }

    public function test_shared_gate_targets_older_generation_and_bounded_retirement_appends_without_replay(): void
    {
        [$generation, $agents] = $this->cohort(255, 3);
        $this->cohort(256, 1);
        [$latest] = $this->cohort(257, 1, true);
        $beforeRuns = LabEvaluationRun::all()->map(fn ($run) => $run->getAttributes())->all();
        $beforeSeal = $generation->trigger_context;
        $gate = app(LearningVelocityGateService::class)->inspect('XAUUSD', 'H1');
        $this->assertSame('blocked_technical_recovery', $gate['status']);
        $this->assertSame(256, $gate['technical_recovery_targets'][0]['generation']);
        $this->assertNotSame($latest->id, $gate['technical_recovery_targets'][0]['generation_id']);

        $this->assertSame(0, Artisan::call('trading:recover-lab-evaluation-errors', $this->arguments(255)));
        $result = json_decode(Artisan::output(), true);
        $this->assertSame('terminally_reconciled', $result['status']);
        $this->assertSame(0, $result['dispatched']);
        $this->assertSame($agents->take(2)->pluck('id')->all(), $result['terminally_reconciled_agent_ids']);
        $this->assertSame($beforeRuns, LabEvaluationRun::all()->map(fn ($run) => $run->getAttributes())->all());
        $this->assertSame($beforeSeal, $generation->fresh()->trigger_context);
        $this->assertSame(2, SystemEvent::where('event_type', 'lab_autonomous_recovery_terminal_disposition')->count());
        $this->assertSame(2, LabLifecycleEvent::where('event_type', 'technical_recovery_terminal_disposition')->count());
        $this->assertSame(0, SystemEvent::where('event_type', 'lab_autonomous_recovery_authorization')->count());
        $proof = data_get($agents[0]->modelVersion->fresh()->metadata, 'technical_recovery_terminal_disposition.source_retirement_proof');
        $this->assertSame(str_repeat('a', 64), $proof['original_source_hash']);
        $this->assertSame('withheld', $proof['strategy_verdict']);
        $this->assertFalse($proof['promotion_evidence']);
        $this->assertSame(2, app(LearningVelocityGateService::class)->inspect('XAUUSD', 'H1')['technical_recovery_agents']);
        Bus::assertNothingDispatched();

        $this->assertSame(0, Artisan::call('trading:recover-lab-evaluation-errors', $this->arguments(255)));
        $this->assertSame([$agents[2]->id], json_decode(Artisan::output(), true)['terminally_reconciled_agent_ids']);
        $this->assertSame(0, Artisan::call('trading:recover-lab-evaluation-errors', $this->arguments(255)));
        $this->assertSame('nothing_to_retire', json_decode(Artisan::output(), true)['status']);
        $this->assertSame(3, SystemEvent::where('event_type', 'lab_autonomous_recovery_terminal_disposition')->count());
    }

    #[DataProvider('invalidProofs')]
    public function test_unknown_or_invalid_original_proof_remains_explicit_dependency(string $defect, string $reason): void
    {
        [$generation, $agents] = $this->cohort(255, 1);
        $context = $generation->trigger_context;
        if ($defect === 'missing') unset($context['research_release']);
        elseif ($defect === 'canonical_hash') $context['research_release']['release_hash'] = str_repeat('f', 64);
        elseif ($defect === 'execution') $agents[0]->modelVersion->update(['metadata' => ['execution_contract' => ['parameters' => ['risk' => 2]]]]);
        elseif ($defect === 'same_source') {
            $context['research_release']['source_hash'] = str_repeat('c', 64);
            $context['research_release']['python_source_hash'] = str_repeat('d', 64);
            $context['research_release']['release_hash'] = $this->hashSeal($context['research_release']);
        } elseif ($defect === 'data') $context['mtf_bundle_hash'] = str_repeat('f', 64);
        elseif ($defect === 'run_source') LabEvaluationRun::query()->update(['code_hash' => str_repeat('f', 64)]);
        elseif ($defect === 'completed') LabEvaluationRun::query()->update(['status' => 'completed']);
        elseif ($defect === 'active') LabEvaluationRun::query()->update(['status' => 'started', 'finished_at' => null]);
        $generation->update(['trigger_context' => $context]);
        $before = $agents[0]->modelVersion->fresh()->getAttributes();
        $this->assertSame(1, Artisan::call('trading:recover-lab-evaluation-errors', $this->arguments(255)));
        $result = json_decode(Artisan::output(), true);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame($reason, $defect === 'active' ? $result['reason_code'] : $result['blocked_recovery_contracts'][0]['reason_code']);
        $this->assertSame($before, $agents[0]->modelVersion->fresh()->getAttributes());
        $this->assertSame(0, SystemEvent::where('event_type', 'lab_autonomous_recovery_terminal_disposition')->count());
        Bus::assertNothingDispatched();
    }

    public static function invalidProofs(): array
    {
        return [['missing', 'FROZEN_SOURCE_RELEASE_MISSING'], ['canonical_hash', 'FROZEN_RELEASE_ORIGINAL_IDENTITY_INVALID'],
            ['execution', 'FROZEN_RELEASE_ORIGINAL_EXECUTION_OR_DATA_IDENTITY_INVALID'],
            ['data', 'FROZEN_RELEASE_ORIGINAL_EXECUTION_OR_DATA_IDENTITY_INVALID'],
            ['same_source', 'FROZEN_RELEASE_CURRENT_SOURCE_MATCHES'],
            ['run_source', 'FROZEN_RELEASE_ORIGINAL_RUN_SOURCE_MISMATCH'],
            ['completed', 'FROZEN_RELEASE_COMPLETED_SCIENTIFIC_RUN_EXISTS'],
            ['active', 'FROZEN_RELEASE_RETIREMENT_REPLAY_NOT_IDLE']];
    }

    public function test_lifecycle_uses_actual_target_before_spent_daily_replay_budget_and_cooldown(): void
    {
        [$old, $agents] = $this->cohort(255, 1);
        [$latest] = $this->cohort(257, 1, true);
        SystemEvent::create(['event_type' => 'lab_autonomous_recovery_authorization', 'event_key' => 'spent-budget',
            'severity' => 'info', 'summary' => 'Prior actual dispatch budget', 'payload' => ['agent_ids' => [900, 901]], 'occurred_at' => now()]);
        Cache::put('lifecycle-technical-recovery:XAUUSD:H1', true, now()->addMinute());
        $method = new \ReflectionMethod(LabLifecycleOrchestrator::class, 'technicalRecovery');
        $outcome = $method->invoke(app(LabLifecycleOrchestrator::class), 'XAUUSD', 'H1', 'isolated-test-cycle', [
            'generation_admission' => ['latest_generation_id' => $latest->id, 'learning_velocity' => [
                'technical_recovery_targets' => [['generation_id' => $old->id, 'agent_ids' => [$agents[0]->id]]]]]]);
        $this->assertSame(0, $outcome['dispatched']);
        $this->assertSame(1, $outcome['terminally_reconciled']);
        $this->assertSame($old->id, $outcome['recovery_target']['generation_id']);
        $this->assertArrayNotHasKey('paused_reason', $outcome);
        $this->assertSame(1, SystemEvent::where('event_type', 'lab_autonomous_recovery_authorization')->count());
        Bus::assertNothingDispatched();
    }

    public function test_actual_target_progress_changes_watermark_but_zero_work_is_deferred(): void
    {
        [$old, $agents] = $this->cohort(255, 2);
        [$latest] = $this->cohort(257, 1, true);
        $method = new \ReflectionMethod(ResearchLoopArbiterService::class, 'operationalStateSnapshot');
        $base = ['generation' => ['id' => $latest->id, 'status' => $latest->status],
            'technical_recovery_target' => ['generation_id' => $old->id, 'generation' => 255,
                'agent_ids' => $agents->pluck('id')->all()]];
        $before = $method->invoke(app(ResearchLoopArbiterService::class), $base, 'scheduler-constructor', 'XAUUSD', 'H1');
        $base['technical_recovery_target']['agent_ids'] = [$agents[1]->id];
        $after = $method->invoke(app(ResearchLoopArbiterService::class), $base, 'scheduler-constructor', 'XAUUSD', 'H1');
        $this->assertSame($before['generation_status'], $after['generation_status']);
        $this->assertNotSame($before, $after);
        $classification = app(ScheduledCommandOutcomeClassifierService::class)->classify('trading:run-lifecycle-cycle', ['--json' => true], 0,
            json_encode(['status' => 'paused', 'data' => ['dispatched' => 0, 'terminally_reconciled' => 0,
                'paused_reason' => 'FROZEN_RELEASE_RETIREMENT_NOT_PROVEN']]));
        $this->assertSame('deferred', $classification['status']);
        $this->assertFalse($classification['throw']);
    }

    private function arguments(int $number): array
    {
        return ['symbol' => 'XAUUSD', '--timeframe' => 'H1', '--generation' => $number,
            '--limit' => 50, '--mode' => 'screen', '--retire-frozen-release' => true, '--apply' => true, '--autonomous' => true, '--json' => true];
    }

    private function hashSeal(array $seal): string
    {
        unset($seal['release_hash'], $seal['sealed_at'], $seal['promotion_evidence']);
        return app(ExecutionContractService::class)->hashParameters($seal);
    }

    private function cohort(int $number, int $count, bool $reconciled = false): array
    {
        $lab = AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD', 'timeframe' => 'H1'], [
            'name' => 'Frozen source retirement', 'strategy_families' => ['trend'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => $number,
            'trigger_type' => 'technical_test', 'population_size' => $count, 'status' => 'technical_quarantine',
            'trigger_context' => ['mtf_bundle_hash' => str_repeat('e', 64)]]);
        $agents = collect();
        $pins = [];
        for ($index = 0; $index < $count; $index++) {
            $parameters = ['risk' => 1, 'threshold' => $index];
            $metadata = ['execution_contract' => ['parameters' => $parameters]];
            if ($reconciled) $metadata['technical_recovery_terminal_disposition'] = [
                'protocol' => 'frozen_recovery_contract_terminal_v1', 'reason_code' => 'FROZEN_RECOVERY_CONTRACT_UNAVAILABLE',
                'strategy_verdict' => 'withheld', 'promotion_evidence' => false];
            $model = ModelVersion::create(['name' => 'Frozen '.$number.'/'.$index, 'strategy' => 'trend', 'version' => 'v1',
                'generation' => $number, 'status' => 'testing', 'parameters' => $parameters, 'metadata' => $metadata]);
            $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend', 'origin' => 'test',
                'lifecycle_status' => 'technical_quarantine', 'parameter_diff' => [],
                'decision_reason' => 'Unknown operational failure; strategy verdict withheld.']);
            LabEvaluationRun::create(['run_id' => 'frozen-'.$number.'-'.$index, 'lab_generation_id' => $generation->id,
                'lab_agent_id' => $agent->id, 'model_version_id' => $model->id, 'phase' => 'screening', 'mode' => 'screen',
                'status' => 'technical_error', 'code_hash' => str_repeat('a', 64), 'error_class' => 'RuntimeException',
                'error_message' => 'autonomous_mtf_manifest_invalid', 'started_at' => now()->subMinute(), 'finished_at' => now(),
                'metadata' => ['strategy_verdict' => 'withheld', 'promotion_evidence' => false]]);
            $pins[$agent->id] = app(ExecutionContractService::class)->hashParameters($parameters);
            $agents->push($agent);
        }
        $seal = ['protocol' => ResearchReleaseSealService::PROTOCOL, 'source_hash' => str_repeat('a', 64),
            'python_source_hash' => str_repeat('b', 64), 'php_version' => PHP_VERSION,
            'dataset_hash' => str_repeat('e', 64), 'agent_execution_hashes' => $pins];
        $seal['release_hash'] = $this->hashSeal($seal);
        $seal['sealed_at'] = now()->subMinutes(2)->utc()->toIso8601String();
        $seal['promotion_evidence'] = false;
        $generation->update(['trigger_context' => ['mtf_bundle_hash' => str_repeat('e', 64), 'research_release' => $seal]]);
        return [$generation, $agents];
    }
}
