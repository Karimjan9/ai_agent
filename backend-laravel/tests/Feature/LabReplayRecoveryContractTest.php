<?php

namespace Tests\Feature;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\LabAgentEvaluationService;
use App\Services\LabAgentPreflightService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Services\LabReplayRecoveryService;
use App\Services\LabQueueJobInspector;
use App\Services\StaleLabScreeningRecoveryService;
use App\Services\CandidateHandoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use RuntimeException;

class LabReplayRecoveryContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_agent_screen_recovery_cannot_bypass_failed_frozen_control(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Recovery control admission', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'new_data',
            'population_size' => 2, 'status' => 'screening', 'trigger_context' => [],
        ]);
        $controlModel = ModelVersion::create([
            'name' => 'failed-control', 'strategy' => 'trend', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [],
            'metadata' => ['control_contract' => [
                'protocol' => 'frozen_control_v2', 'control_only' => true,
                'role' => 'control', 'generation_id' => $generation->id,
            ]],
        ]);
        $control = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $controlModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'origin' => 'test', 'lifecycle_status' => 'technical_quarantine',
            'parameter_diff' => [], 'decision_reason' => 'Control replay timed out.',
        ]);
        LabEvaluationRun::create([
            'run_id' => 'failed-control-run', 'lab_generation_id' => $generation->id,
            'lab_agent_id' => $control->id, 'model_version_id' => $controlModel->id,
            'phase' => 'screening', 'mode' => 'screen', 'status' => 'technical_error',
            'error_class' => 'RuntimeException', 'error_message' => 'Bounded replay timeout',
            'started_at' => now()->subMinute(), 'finished_at' => now(),
        ]);
        $candidateModel = ModelVersion::create([
            'name' => 'dependent-candidate', 'strategy' => 'trend', 'version' => 'v2',
            'generation' => 1, 'status' => 'testing', 'parameters' => [],
            'metadata' => ['control_pair_contract' => ['control_agent_id' => $control->id]],
        ]);
        $candidate = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $candidateModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'origin' => 'test', 'lifecycle_status' => 'queued', 'parameter_diff' => [],
        ]);

        $job = new EvaluateLabAgentJob($candidate->id, 'XAUUSD', 'screen');
        $job->handle(
            app(LabAgentEvaluationService::class),
            app(CandidateHandoffService::class),
            app(LabImmutableEvidenceService::class),
            app(LabAgentPreflightService::class),
            app(LabReplayRecoveryService::class),
        );

        $this->assertSame('technical_quarantine', $candidate->fresh()->lifecycle_status);
        $run = LabEvaluationRun::query()->where('lab_agent_id', $candidate->id)->latest('id')->first();
        $this->assertSame('skipped', $run?->status);
        $this->assertSame('FROZEN_CONTROL_REPLAY_INCOMPLETE', data_get($run?->metadata, 'reason_code'));
        $this->assertNull(data_get($candidate->modelVersion->fresh()->metadata, 'last_screen_result'));
    }

    public function test_recovery_contract_rejects_tampered_snapshot_and_wrong_generation(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'recovery_contract', true);
        $agent = $generation->agents->first();
        $path = storage_path('app/recovery-contract-'.uniqid('', true).'.csv');
        File::put($path, "time,open,high,low,close,volume\n2026-01-01T00:00:00Z,1,1,1,1,0\n");
        $hash = hash_file('sha256', $path);
        $context = (array) $generation->trigger_context;
        data_set($context, 'canonical_dataset_snapshots.price', [
            'path' => $path,
            'sha256' => $hash,
            'generation_id' => $generation->id,
        ]);
        $generation->update(['trigger_context' => $context]);
        $foundation = app(\App\Services\LabDatasetExportService::class)
            ->ensureGenerationFoundationSnapshot($generation->fresh(['laboratory']));

        $contract = [
            'protocol' => LabReplayRecoveryService::PROTOCOL,
            'mode' => 'screen',
            'agent_id' => $agent->id,
            'generation_id' => $generation->id,
            'symbol' => $agent->symbol,
            'timeframe' => $agent->timeframe,
            'include_volume' => false,
            'dataset_hashes' => ['price' => $hash, 'foundation' => $foundation['sha256'], 'regime' => ''],
        ];
        $service = app(LabReplayRecoveryService::class);
        $service->assertContract($agent->fresh(), $contract);

        File::put($path, "tampered\n");
        try {
            $service->assertContract($agent->fresh(), $contract);
            $this->fail('Tampered recovery snapshot was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('RECOVERY_DATASET_SNAPSHOT_HASH_MISMATCH', $exception->getMessage());
        } finally {
            File::delete($path);
        }

    }

    public function test_recovery_job_quarantines_when_generation_identity_changes(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'recovery_generation_identity', true);
        $agent = $generation->agents->first();
        $job = new EvaluateLabAgentJob($agent->id, $agent->symbol, 'screen', [
            'protocol' => LabReplayRecoveryService::PROTOCOL,
            'mode' => 'screen',
            'agent_id' => $agent->id,
            'generation_id' => $generation->id + 999,
            'symbol' => $agent->symbol,
            'timeframe' => $agent->timeframe,
            'include_volume' => false,
            'dataset_hashes' => ['price' => str_repeat('a', 64)],
        ]);

        $job->handle(
            app(LabAgentEvaluationService::class),
            app(CandidateHandoffService::class),
            app(LabImmutableEvidenceService::class),
            app(LabAgentPreflightService::class),
            app(LabReplayRecoveryService::class),
        );

        $this->assertSame('technical_quarantine', $agent->fresh()->lifecycle_status);
        $run = LabEvaluationRun::query()->where('lab_agent_id', $agent->id)->latest('id')->first();
        $this->assertSame('technical_error', $run?->status);
        $this->assertSame('RECOVERY_CONTRACT_INVALID', data_get($run?->metadata, 'reason_code'));
    }

    public function test_recovery_does_not_create_a_new_snapshot_for_a_missing_original(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'recovery_missing_snapshot', true);
        $agent = $generation->agents->first();

        try {
            app(LabReplayRecoveryService::class)->prepare($agent, 'screen');
            $this->fail('Recovery accepted a generation without its frozen dataset snapshot.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('RECOVERY_DATASET_SNAPSHOT_MISSING_OR_HASH_MISMATCH:price', $exception->getMessage());
        }

        $this->assertSame([], (array) data_get($generation->fresh()->trigger_context, 'canonical_dataset_snapshots', []));
    }

    public function test_stale_run_is_closed_without_overwriting_a_later_terminal_agent_attempt(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'terminal_attempt_orphan', true);
        $agent = $generation->agents->first();
        $run = app(LabImmutableEvidenceService::class)->beginRun($agent, 'screening', 'screen', [
            'attempt' => 1,
            'source' => 'worker_killed_before_retry',
        ]);
        $run->forceFill(['started_at' => now()->subHours(2)])->save();
        $agent->update([
            'lifecycle_status' => 'evaluation_error',
            'decision_reason' => 'Later bounded attempt reached a terminal technical error.',
        ]);

        $this->mock(LabQueueJobInspector::class, function ($mock): void {
            $mock->shouldReceive('generationQueueBacklog')->andReturn([
                'backend' => 'redis',
                'available' => true,
                'total' => 0,
                'queues' => [],
                'rows' => [],
            ]);
        });

        $result = app(StaleLabScreeningRecoveryService::class)->recover($generation->fresh(), 30);

        $this->assertSame(1, $result['reclaimed_runs']);
        $this->assertSame(0, $result['reclaimed_agents']);
        $this->assertSame('technical_error', $run->fresh()->status);
        $this->assertSame(
            'STALE_SCREENING_RUN_CLOSED_AFTER_TERMINAL_ATTEMPT',
            data_get($run->fresh()->metadata, 'reason_code'),
        );
        $this->assertSame('evaluation_error', $agent->fresh()->lifecycle_status);
        $this->assertSame(
            'Later bounded attempt reached a terminal technical error.',
            $agent->fresh()->decision_reason,
        );
    }
}
