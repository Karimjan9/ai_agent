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

    /** Read-only proof for terminal retirement, never a permit to replay with new source. */
    public function frozenSourceRetirementProof(LabAgent $agent): array
    {
        $agent->loadMissing(['generation', 'modelVersion']);
        $generation = $agent->generation;
        $seal = (array) data_get($generation?->trigger_context, 'research_release', []);
        if (! $generation || ! $agent->modelVersion || $seal === []) throw new RuntimeException('FROZEN_SOURCE_RELEASE_MISSING');
        $identity = $seal;
        unset($identity['release_hash'], $identity['sealed_at'], $identity['promotion_evidence']);
        foreach (['source_hash', 'python_source_hash', 'dataset_hash', 'release_hash'] as $field) {
            if (! $this->isSha256((string) ($seal[$field] ?? ''))) throw new RuntimeException('FROZEN_RELEASE_ORIGINAL_IDENTITY_INVALID');
        }
        if (($seal['protocol'] ?? null) !== ResearchReleaseSealService::PROTOCOL
            || ! is_string($seal['php_version'] ?? null) || ! is_array($seal['agent_execution_hashes'] ?? null)
            || ! hash_equals($seal['release_hash'], app(ExecutionContractService::class)->hashParameters($identity))) {
            throw new RuntimeException('FROZEN_RELEASE_ORIGINAL_IDENTITY_INVALID');
        }
        foreach ($seal['agent_execution_hashes'] as $agentId => $hash) {
            if (! ctype_digit((string) $agentId) || ! $this->isSha256((string) $hash)) {
                throw new RuntimeException('FROZEN_RELEASE_ORIGINAL_IDENTITY_INVALID');
            }
        }
        $contract = (array) data_get($agent->modelVersion->metadata, 'execution_contract', []);
        $execution = app(ExecutionContractService::class)->hashParameters(
            json_decode(json_encode((array) ($contract['parameters'] ?? $contract)), true));
        if (! $this->isSha256((string) data_get($seal, 'agent_execution_hashes.'.$agent->id, ''))
            || ! hash_equals((string) data_get($seal, 'agent_execution_hashes.'.$agent->id), $execution)
            || ! hash_equals($seal['dataset_hash'], (string) data_get($generation->trigger_context, 'mtf_bundle_hash',
                data_get($generation->trigger_context, 'canonical_dataset_snapshots.price.sha256', '')))) {
            throw new RuntimeException('FROZEN_RELEASE_ORIGINAL_EXECUTION_OR_DATA_IDENTITY_INVALID');
        }
        try {
            if (! is_string($seal['sealed_at'] ?? null) || \Carbon\CarbonImmutable::parse($seal['sealed_at'])->greaterThan(now()->utc())) {
                throw new RuntimeException('FROZEN_RELEASE_ORIGINAL_SEAL_TIME_INVALID');
            }
        } catch (\Throwable) { throw new RuntimeException('FROZEN_RELEASE_ORIGINAL_SEAL_TIME_INVALID'); }
        if (isset($seal['source_artifact'])) app(ResearchReleaseSealService::class)->verifySourceArtifact((array) $seal['source_artifact'], false);
        $currentPhp = app(LabImmutableEvidenceService::class)->codeHash();
        $currentPython = app(ResearchReleaseSealService::class)->pythonHash();
        if (! $this->isSha256($currentPhp) || ! $this->isSha256($currentPython)) throw new RuntimeException('FROZEN_RELEASE_CURRENT_SOURCE_UNVERIFIABLE');
        if (hash_equals($seal['source_hash'], $currentPhp) && hash_equals($seal['python_source_hash'], $currentPython)) {
            throw new RuntimeException('FROZEN_RELEASE_CURRENT_SOURCE_MATCHES');
        }
        $runs = LabEvaluationRun::query()->where('lab_agent_id', $agent->id)->orderBy('id')->get();
        foreach ($runs as $run) {
            if (in_array($run->status, ['started', 'running', 'processing'], true)) throw new RuntimeException('FROZEN_RELEASE_STARTED_RUN_ACTIVE');
            if ($run->status === 'completed') throw new RuntimeException('FROZEN_RELEASE_COMPLETED_SCIENTIFIC_RUN_EXISTS');
            if ($run->code_hash !== null && $run->code_hash !== '' && ! hash_equals($seal['source_hash'], (string) $run->code_hash)) {
                throw new RuntimeException('FROZEN_RELEASE_ORIGINAL_RUN_SOURCE_MISMATCH');
            }
        }
        return ['protocol' => 'frozen_research_source_retirement_v1', 'agent_id' => (int) $agent->id,
            'generation_id' => (int) $generation->id, 'release_hash' => $seal['release_hash'],
            'original_source_hash' => $seal['source_hash'], 'original_python_source_hash' => $seal['python_source_hash'],
            'current_source_hash' => $currentPhp, 'current_python_source_hash' => $currentPython,
            'original_run_ids' => $runs->pluck('run_id')->all(), 'dataset_hash' => $seal['dataset_hash'],
            'strategy_verdict' => 'withheld', 'promotion_evidence' => false];
    }

    /** @return array<string, mixed> */
    public function prepare(LabAgent $agent, string $mode, bool $allowPriorDatasetContractMismatch = false): array
    {
        if (! in_array($mode, ['screen', 'full'], true)) {
            throw new RuntimeException('Recovery mode must be screen or full.');
        }

        $agent->loadMissing('modelVersion', 'generation');
        $generation = $agent->generation?->fresh(['laboratory']);
        if (! $generation || ! $generation->laboratory) {
            throw new RuntimeException('Recovery generation/laboratory topilmadi.');
        }

        // Recovery is the original experiment, not permission to run yesterday's
        // frozen cohort with today's evaluator. Check before costly data restore.
        app(ResearchReleaseSealService::class)->assertCurrent($generation);

        $includeVolume = $this->volumeEnabled($agent);
        $context = (array) $generation->trigger_context;
        $priceKey = $includeVolume ? 'volume' : 'price';
        $this->assertFrozenSnapshotContext($context, $priceKey);
        $price = $this->datasets->ensureGenerationSnapshot($generation, $includeVolume);
        // Screening itself now runs against the pre-2026 foundation while
        // retaining the canonical snapshot only as the later paper/forward
        // reference. Both files must therefore be frozen on recovery.
        $this->assertFrozenSnapshotContext($context, 'foundation');
        $foundation = $this->datasets->ensureGenerationFoundationSnapshot($generation);
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
            'research_release_hash' => data_get($context, 'research_release.release_hash'),
            'dataset_hashes' => [
                'price' => (string) ($price['sha256'] ?? ''),
                'foundation' => (string) ($foundation['sha256'] ?? ''),
                'regime' => (string) ($regime['sha256'] ?? ''),
                // An MTF aggregate identifies four frozen streams, not the
                // unrelated H1 foundation file or canonical paper file.
                'mtf_bundle' => (string) data_get($context, 'mtf_bundle_hash', ''),
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
        $priorDatasetContractMismatches = $this->assertPriorRunDidNotChangeDataset(
            $agent,
            $mode,
            $contract,
            $allowPriorDatasetContractMismatch,
        );
        if ($priorDatasetContractMismatches !== []) {
            $contract['prior_run_dataset_contract_repair'] = [
                'protocol' => 'screening_dataset_contract_repair_v1',
                'mismatches' => $priorDatasetContractMismatches,
                'promotion_evidence' => false,
            ];
        }

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

        $releaseHash = data_get($generation->trigger_context, 'research_release.release_hash');
        if ($releaseHash !== data_get($contract, 'research_release_hash')) {
            throw new RuntimeException('RECOVERY_RESEARCH_RELEASE_IDENTITY_MISMATCH');
        }
        app(ResearchReleaseSealService::class)->assertCurrent($generation);

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
        $snapshots['foundation'] = (array) data_get($context, 'canonical_dataset_snapshots.foundation', []);
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
        $expectedBundle = (string) data_get($contract, 'dataset_hashes.mtf_bundle', '');
        $storedBundle = (string) data_get($context, 'mtf_bundle_hash', '');
        if ($expectedBundle !== '' || $storedBundle !== '') {
            if (! $this->isSha256($expectedBundle) || ! hash_equals($expectedBundle, $storedBundle)) {
                throw new RuntimeException('RECOVERY_DATASET_SNAPSHOT_HASH_MISMATCH:mtf_bundle');
            }
            $manifest = (array) data_get($context, 'mtf_bundle_manifest', []);
            $restored = app(MultiTimeframeSnapshotService::class)->restoreAgentOwnedConfirmationValidationBundle($manifest);
            if (! hash_equals($expectedBundle, (string) data_get($restored, 'bundle_hash', ''))) {
                throw new RuntimeException('RECOVERY_DATASET_SNAPSHOT_HASH_MISMATCH:mtf_bundle');
            }
            foreach (['M5', 'H4', 'H1', 'M15'] as $timeframe) {
                $original = (string) data_get($manifest, "streams.{$timeframe}.sha256", '');
                $reopened = (string) data_get($restored, "manifest.streams.{$timeframe}.sha256", '');
                if (! $this->isSha256($original) || ! hash_equals($original, $reopened)) {
                    throw new RuntimeException('RECOVERY_DATASET_SNAPSHOT_HASH_MISMATCH:mtf_'.$timeframe);
                }
            }
        }
    }

    private function assertPriorRunDidNotChangeDataset(
        LabAgent $agent,
        string $mode,
        array $contract,
        bool $allowPriorDatasetContractMismatch = false,
    ): array
    {
        $phase = $mode === 'full' ? 'full_validation' : 'screening';
        $run = LabEvaluationRun::query()
            ->where('lab_agent_id', $agent->id)
            ->where('phase', $phase)
            ->latest('id')
            ->first();
        if (! $run) return [];
        if ((int) $run->lab_generation_id !== (int) $agent->lab_generation_id) {
            throw new RuntimeException('RECOVERY_PRIOR_RUN_GENERATION_MISMATCH');
        }

        $manifest = (array) data_get($run->request_meta, 'dataset_manifest', []);
        $screening = $mode === 'screen';
        $mtf = data_get($manifest, 'mtf_bundle_hash') !== null
            || data_get($manifest, 'snapshot_protocol') === MultiTimeframeSnapshotService::PROTOCOL;
        $modernFull = ! $screening && data_get($manifest, 'paper.snapshot_sha256') !== null;
        $previous = [
            // Current MTF replay's primary hash is an aggregate. Current full
            // replay separately carries the paper and foundation identities;
            // keep the older canonical-primary shape only for legacy runs.
            'price' => $screening
                ? data_get($manifest, 'data_partition.paper_snapshot_sha256')
                : ($modernFull ? data_get($manifest, 'paper.snapshot_sha256')
                    : data_get($manifest, 'snapshot_sha256', data_get($manifest, 'data_hash'))),
            'foundation' => $screening && ! $mtf
                ? data_get($manifest, 'snapshot_sha256', data_get($manifest, 'data_hash'))
                : ($modernFull ? data_get($manifest, 'sha256')
                    : data_get($manifest, 'foundation.sha256', data_get($manifest, 'foundation.snapshot_sha256'))),
            'regime' => data_get($manifest, 'regime.sha256', data_get($manifest, 'regime_snapshot_sha256')),
        ];
        if ($mtf) {
            $bundle = (string) data_get($manifest, 'mtf_bundle_hash', '');
            $primary = (string) data_get($manifest, 'snapshot_sha256', '');
            $expected = (string) data_get($contract, 'dataset_hashes.mtf_bundle', '');
            if (! $this->isSha256($bundle) || ! $this->isSha256($expected)
                || ! hash_equals($bundle, $primary)
                || ($run->data_hash && ! hash_equals($bundle, (string) $run->data_hash))) {
                throw new RuntimeException('RECOVERY_PRIOR_DATASET_IDENTITY_AMBIGUOUS:mtf_bundle');
            }
            // The explicit legacy dataset-contract repair flag is not
            // permission to change a frozen MTF experiment's aggregate.
            if (! hash_equals($expected, $bundle)) {
                throw new RuntimeException('RECOVERY_PRIOR_DATASET_HASH_MISMATCH:mtf_bundle');
            }
            $payload = (array) data_get($run->request_meta, 'payload', []);
            $frozenStreams = (array) data_get($agent->generation?->trigger_context, 'mtf_bundle_manifest.streams', []);
            $entryHash = (string) data_get($frozenStreams, 'M5.sha256', '');
            if (! $this->isSha256($entryHash)
                || ! hash_equals($bundle, (string) data_get($payload, 'replay_dataset_hash', ''))
                || ! hash_equals($bundle, (string) data_get($payload, 'policy_context.snapshot_transport.mtf_bundle_hash', ''))
                || ! hash_equals($entryHash, (string) data_get($payload, 'policy_context.snapshot_transport.training_dataset_sha256', ''))
                || (string) data_get($payload, 'dataset_path', '') !== (string) data_get($frozenStreams, 'M5.path', '')) {
                throw new RuntimeException('RECOVERY_PRIOR_DATASET_IDENTITY_AMBIGUOUS:mtf_transport');
            }
            $previous['mtf_bundle'] = $bundle;
            $priorStreams = (array) data_get($manifest, 'mtf_bundle_manifest.streams',
                data_get($manifest, 'mtf_foundation_bundle.streams', []));
            foreach (['M5', 'H4', 'H1', 'M15'] as $timeframe) {
                $prior = (string) data_get($priorStreams, "{$timeframe}.sha256", '');
                $frozen = (string) data_get($agent->generation?->trigger_context, "mtf_bundle_manifest.streams.{$timeframe}.sha256", '');
                $payloadHash = (string) data_get($payload, "mtf_snapshot_manifest.streams.{$timeframe}.sha256", '');
                if (! $this->isSha256($prior) || ! hash_equals($prior, $frozen)
                    || ! hash_equals($prior, $payloadHash)) {
                    throw new RuntimeException('RECOVERY_PRIOR_DATASET_HASH_MISMATCH:mtf_'.$timeframe);
                }
            }
        }
        $mismatches = [];
        foreach ($previous as $name => $hash) {
            if (! $this->isSha256((string) $hash)) continue;
            $expected = (string) data_get($contract, "dataset_hashes.{$name}", '');
            if ($expected !== '' && ! hash_equals($expected, (string) $hash)) {
                if (! $allowPriorDatasetContractMismatch) {
                    throw new RuntimeException('RECOVERY_PRIOR_DATASET_HASH_MISMATCH:'.$name);
                }
                $mismatches[] = [
                    'dataset' => $name,
                    'prior_hash' => (string) $hash,
                    'expected_hash' => $expected,
                ];
            }
        }

        return $mismatches;
    }

    private function volumeEnabled(LabAgent $agent): bool
    {
        $model = $agent->modelVersion;
        $metadata = (array) ($model?->metadata ?? []);

        return data_get($metadata, 'volume_research_contract.protocol') === 'volume_council_v1'
            || (bool) data_get($metadata, 'volume_research_contract.enabled', false)
            || (bool) data_get($metadata, 'risk_bounded_evolution.volume_shadow', false)
            || (bool) data_get($metadata, 'portfolio_council_lane.volume_shadow', false)
            || data_get($metadata, 'portfolio_council_lane.role') === 'volume_m15_specialist'
            || data_get($metadata, 'portfolio_council_lane.specialist_role') === 'volume_m15_specialist'
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
