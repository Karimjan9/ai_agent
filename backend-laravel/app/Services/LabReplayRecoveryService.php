<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use RuntimeException;

/**
 * Seals the recovery contract before an evaluator job is requeued.
 *
 * Recovery is allowed to repair infrastructure, not to silently move an
 * agent onto a newer generation or a different dataset.  The returned
 * contract is serialized into the queue job and checked again immediately
 * before replay.
 */
class LabReplayRecoveryService
{
    public const PROTOCOL = 'same_generation_replay_recovery_v1';

    public function __construct(private LabDatasetExportService $datasets) {}

    /** @return array<string, mixed> */
    public function prepare(LabAgent $agent, string $mode): array
    {
        if (! in_array($mode, ['screen', 'full'], true)) {
            throw new RuntimeException('Recovery mode must be screen or full.');
        }

        $agent->loadMissing('modelVersion', 'generation');
        $generation = $agent->generation?->fresh(['laboratory']);
        if (! $generation || ! $generation->laboratory) {
            throw new RuntimeException('Recovery generation/laboratory topilmadi.');
        }

        $includeVolume = $this->volumeEnabled($agent);
        $context = (array) $generation->trigger_context;
        $priceKey = $includeVolume ? 'volume' : 'price';
        $this->assertFrozenSnapshotContext($context, $priceKey);
        $price = $this->datasets->ensureGenerationSnapshot($generation, $includeVolume);
        $foundation = null;
        if ($mode === 'full') {
            $this->assertFrozenSnapshotContext($context, 'foundation');
            $foundation = $this->datasets->ensureGenerationFoundationSnapshot($generation);
        }
        $regime = null;
        if (strtoupper((string) $generation->laboratory->timeframe) === 'M15') {
            $this->assertFrozenSnapshotContext($context, 'regime');
            $regime = $this->datasets->ensureGenerationRegimeSnapshot($generation);
        }

        $contract = [
            'protocol' => self::PROTOCOL,
            'mode' => $mode,
            'agent_id' => (int) $agent->id,
            'generation_id' => (int) $generation->id,
            'generation' => (int) $generation->generation,
            'symbol' => (string) $generation->laboratory->symbol,
            'timeframe' => (string) $generation->laboratory->timeframe,
            'include_volume' => $includeVolume,
            'dataset_hashes' => [
                'price' => (string) ($price['sha256'] ?? ''),
                'foundation' => (string) ($foundation['sha256'] ?? ''),
                'regime' => (string) ($regime['sha256'] ?? ''),
            ],
            'snapshot_paths' => [
                'price' => (string) ($price['path'] ?? ''),
                'foundation' => (string) ($foundation['path'] ?? ''),
                'regime' => (string) ($regime['path'] ?? ''),
            ],
            'prepared_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ];

        $this->assertContractSnapshots($generation, $contract);
        $this->assertPriorRunDidNotChangeDataset($agent, $mode, $contract);

        return $contract;
    }

    /** Refuse a queued recovery job if its generation or frozen hashes moved. */
    public function assertContract(LabAgent $agent, array $contract): void
    {
        if (data_get($contract, 'protocol') !== self::PROTOCOL) {
            throw new RuntimeException('RECOVERY_CONTRACT_PROTOCOL_INVALID');
        }

        $agent->loadMissing('generation');
        $generation = $agent->generation?->fresh(['laboratory']);
        if (! $generation || (int) $agent->lab_generation_id !== (int) data_get($contract, 'generation_id')) {
            throw new RuntimeException('RECOVERY_GENERATION_ID_MISMATCH');
        }
        if ((int) data_get($contract, 'agent_id') !== (int) $agent->id
            || strtoupper((string) data_get($contract, 'symbol')) !== strtoupper((string) $agent->symbol)
            || strtoupper((string) data_get($contract, 'timeframe')) !== strtoupper((string) $agent->timeframe)) {
            throw new RuntimeException('RECOVERY_AGENT_SCOPE_MISMATCH');
        }

        $this->assertContractSnapshots($generation, $contract);
    }

    private function assertContractSnapshots(object $generation, array $contract): void
    {
        $context = (array) $generation->trigger_context;
        $includeVolume = (bool) data_get($contract, 'include_volume', false);
        $priceKey = $includeVolume ? 'volume' : 'price';
        $snapshots = [
            'price' => (array) data_get($context, "canonical_dataset_snapshots.{$priceKey}", []),
        ];
        if ((string) data_get($contract, 'mode') === 'full') {
            $snapshots['foundation'] = (array) data_get($context, 'canonical_dataset_snapshots.foundation', []);
        }
        if (strtoupper((string) data_get($contract, 'timeframe')) === 'M15') {
            $snapshots['regime'] = (array) data_get($context, 'canonical_dataset_snapshots.regime', []);
        }

        foreach ($snapshots as $name => $snapshot) {
            $expected = (string) data_get($contract, "dataset_hashes.{$name}", '');
            $stored = (string) data_get($snapshot, 'sha256', '');
            $path = (string) data_get($snapshot, 'path', '');
            if (! $this->isSha256($expected) || ! $this->isSha256($stored) || $expected !== $stored
                || $path === '' || ! is_file($path)) {
                throw new RuntimeException('RECOVERY_DATASET_SNAPSHOT_MISSING_OR_HASH_MISMATCH:'.$name);
            }
            $actual = hash_file('sha256', $path);
            if (! is_string($actual) || ! hash_equals($expected, $actual)) {
                throw new RuntimeException('RECOVERY_DATASET_SNAPSHOT_HASH_MISMATCH:'.$name);
            }
        }
    }

    private function assertPriorRunDidNotChangeDataset(LabAgent $agent, string $mode, array $contract): void
    {
        $phase = $mode === 'full' ? 'full_validation' : 'screening';
        $run = LabEvaluationRun::query()
            ->where('lab_agent_id', $agent->id)
            ->where('phase', $phase)
            ->latest('id')
            ->first();
        if (! $run) return;
        if ((int) $run->lab_generation_id !== (int) $agent->lab_generation_id) {
            throw new RuntimeException('RECOVERY_PRIOR_RUN_GENERATION_MISMATCH');
        }

        $manifest = (array) data_get($run->request_meta, 'dataset_manifest', []);
        $previous = [
            'price' => data_get($manifest, 'snapshot_sha256', data_get($manifest, 'data_hash')),
            'foundation' => data_get($manifest, 'foundation.sha256', data_get($manifest, 'foundation.snapshot_sha256')),
            'regime' => data_get($manifest, 'regime.sha256', data_get($manifest, 'regime_snapshot_sha256')),
        ];
        foreach ($previous as $name => $hash) {
            if (! $this->isSha256((string) $hash)) continue;
            $expected = (string) data_get($contract, "dataset_hashes.{$name}", '');
            if ($expected !== '' && ! hash_equals($expected, (string) $hash)) {
                throw new RuntimeException('RECOVERY_PRIOR_DATASET_HASH_MISMATCH:'.$name);
            }
        }
    }

    private function volumeEnabled(LabAgent $agent): bool
    {
        $model = $agent->modelVersion;

        return data_get($model?->metadata, 'volume_research_contract.protocol') === 'volume_council_v1'
            || (bool) data_get($model?->metadata, 'volume_research_contract.enabled', false)
            || data_get($model?->parameters, 'volume_lane', 'none') !== 'none';
    }

    /**
     * Recovery may validate an immutable snapshot, but it must never create
     * a replacement snapshot from today's rolling data. Without the original
     * path and hash there is no proof that a replay is the same experiment.
     */
    private function assertFrozenSnapshotContext(array $context, string $name): void
    {
        $snapshot = (array) data_get($context, "canonical_dataset_snapshots.{$name}", []);
        $path = (string) data_get($snapshot, 'path', '');
        $hash = (string) data_get($snapshot, 'sha256', '');
        if ($path === '' || ! is_file($path) || ! $this->isSha256($hash)) {
            throw new RuntimeException('RECOVERY_DATASET_SNAPSHOT_MISSING_OR_HASH_MISMATCH:'.$name);
        }
    }

    private function isSha256(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/i', trim($value)) === 1;
    }
}
