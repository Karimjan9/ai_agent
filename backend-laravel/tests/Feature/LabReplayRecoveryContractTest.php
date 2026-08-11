<?php

namespace Tests\Feature;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\LabEvaluationRun;
use App\Services\LabAgentEvaluationService;
use App\Services\LabAgentPreflightService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Services\LabReplayRecoveryService;
use App\Services\CandidateHandoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use RuntimeException;

class LabReplayRecoveryContractTest extends TestCase
{
    use RefreshDatabase;

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

        $contract = [
            'protocol' => LabReplayRecoveryService::PROTOCOL,
            'mode' => 'screen',
            'agent_id' => $agent->id,
            'generation_id' => $generation->id,
            'symbol' => $agent->symbol,
            'timeframe' => $agent->timeframe,
            'include_volume' => false,
            'dataset_hashes' => ['price' => $hash, 'foundation' => '', 'regime' => ''],
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
}
