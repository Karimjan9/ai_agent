<?php

namespace App\Services;

use App\Models\LabGeneration;

/** Validates immutable evidence before a generation may enter a queue. */
class GenerationSnapshotAdmissionService
{
    public const PROTOCOL = 'generation_snapshot_admission_v1';

    /** @return array{allowed:bool,reasons:array<int,string>,promotion_evidence:bool} */
    public function inspect(LabGeneration $generation): array
    {
        $generation->loadMissing('agents.modelVersion', 'laboratory');
        $releaseReasons = [];
        try {
            app(ResearchReleaseSealService::class)->assertCurrent($generation);
        } catch (\RuntimeException $error) {
            $releaseReasons[] = $error->getMessage();
        }
        $reasons = $this->snapshotReasons(
            (array) data_get($generation->trigger_context, 'canonical_dataset_snapshots.price', []),
            'PRICE',
        );
        $reasons = [...$releaseReasons, ...$reasons];
        $reasons = [...$reasons, ...$this->historicalResearchReasons($generation)];
        $requiresVolume = $generation->agents->contains(
            fn ($agent): bool => $this->requiresVolume($agent->modelVersion),
        );
        if ($requiresVolume) {
            $reasons = array_merge($reasons, $this->snapshotReasons(
                (array) data_get($generation->trigger_context, 'canonical_dataset_snapshots.volume', []),
                'VOLUME',
            ));
        }
        if ($this->requiresClosedMtfBundle($generation)) {
            $reasons = array_merge($reasons, $this->mtfBundleReasons(
                (string) data_get($generation->trigger_context, 'mtf_bundle_hash', ''),
                (array) data_get($generation->trigger_context, 'mtf_bundle_manifest', []),
            ));
        }
        foreach ($generation->agents as $agent) {
            $execution = data_get($agent->modelVersion?->metadata, 'execution_contract');
            if (! is_array($execution) || $execution === []) {
                $reasons[] = 'AGENT_EXECUTION_CONTRACT_MISSING:'.$agent->id;
            }
        }
        $reasons = array_merge($reasons,
            app(ActivationFactorialContractService::class)->reasons($generation));
        $reasons = array_merge($reasons,
            app(PhaseScopeProbeContractService::class)->reasons($generation));
        $reasons = array_merge($reasons,
            app(CooperativeContextualEvolutionCouncilService::class)->allocationReasons($generation));
        $dataReadiness = $this->historicalDatasetReadiness((array) data_get($generation->trigger_context, 'mtf_bundle_manifest', []));
        $reasons = [...$reasons, ...$dataReadiness['reasons']];

        return [
            'protocol' => self::PROTOCOL,
            'allowed' => $reasons === [],
            'reasons' => $reasons,
            'generation_id' => $generation->id,
            'data_readiness' => $dataReadiness,
            'promotion_evidence' => false,
        ];
    }

    /** A new evaluator/manifest label cannot make the identical bad source bytes healthy. */
    public function historicalDatasetReadiness(array $manifest): array
    {
        $primary = (string) data_get($manifest, 'streams.M5.sha256', '');
        $base = ['protocol' => 'frozen_dataset_continuity_readiness_v1', 'allowed' => true,
            'reasons' => [], 'strategy_verdict' => 'withheld', 'promotion_evidence' => false];
        if (preg_match('/^[a-f0-9]{64}$/D', $primary) !== 1
            || ! \Illuminate\Support\Facades\Schema::hasTable('lab_evaluation_runs')) return $base;
        $runs = \App\Models\LabEvaluationRun::query()->where('status', 'technical_error')
            ->where('request_meta->payload->mtf_snapshot_manifest->streams->M5->sha256', $primary)
            ->where('error_message', 'like', '%unexpected candle gaps.%')->oldest('id')->get();
        foreach ($runs as $run) {
            $dependency = app(TechnicalFailureClassifierService::class)->historicalGapDependency($run);
            if ($dependency === null || ($dependency['primary_stream_sha256'] ?? null) !== $primary) continue;

            return [...$base, 'allowed' => false, 'reasons' => ['GENERATION_MTF_M5_KNOWN_CANDLE_GAP'],
                'primary_stream_sha256' => $primary, 'source_dependency' => $dependency,
                'next_action' => 'REPAIR_PROVIDER_DATA_AND_RESEAL_PROSPECTIVE_DATASET',
                'source_hash_change_is_not_data_repair' => true, 'same_evidence_replay_forbidden' => true];
        }

        return $base;
    }

    /** @return array<int, string> */
    public function historicalResearchReasons(LabGeneration $generation): array
    {
        $admission = data_get($generation->trigger_context, 'historical_research_admission');
        if ($admission === null && $generation->trigger_type !== GenerationAdmissionDecisionService::HISTORICAL_TRIGGER) {
            return [];
        }
        if (! app(GenerationAdmissionDecisionService::class)->isHistoricalGeneration($generation)) {
            return ['HISTORICAL_RESEARCH_ADMISSION_INVALID'];
        }
        $foundation = (array) data_get($generation->trigger_context, 'canonical_dataset_snapshots.foundation', []);
        $reasons = $this->snapshotReasons($foundation, 'FOUNDATION');
        if (! hash_equals((string) $admission['archive_sha256'], (string) ($foundation['sha256'] ?? ''))) {
            $reasons[] = 'HISTORICAL_RESEARCH_ARCHIVE_IDENTITY_MISMATCH';
        }
        $manifests = ['FOUNDATION' => (array) ($foundation['manifest'] ?? [])];
        foreach (['M5', 'H4', 'H1', 'M15'] as $timeframe) {
            $manifests[$timeframe] = (array) data_get($generation->trigger_context, "mtf_bundle_manifest.streams.{$timeframe}", []);
        }
        foreach ($manifests as $label => $manifest) {
            try {
                $first = $manifest['first_candle_at'] ?? null;
                $last = $manifest['last_candle_at'] ?? null;
                if (! $first || ! $last
                    || \Carbon\CarbonImmutable::parse($first, 'UTC')->gt(\Carbon\CarbonImmutable::parse($last, 'UTC'))
                    || \Carbon\CarbonImmutable::parse($last, 'UTC')->gte(\Carbon\CarbonImmutable::parse(GenerationAdmissionDecisionService::RESEARCH_CUTOFF))) {
                    $reasons[] = "HISTORICAL_RESEARCH_{$label}_PERIOD_INVALID";
                }
            } catch (\Throwable) {
                $reasons[] = "HISTORICAL_RESEARCH_{$label}_PERIOD_INVALID";
            }
        }

        // The separate canonical price snapshot is paper-only. Its 2026
        // rows are deliberately not treated as research/validation evidence.
        return array_values(array_unique($reasons));
    }

    /** @return array<int, string> */
    private function mtfBundleReasons(string $bundleHash, array $manifest): array
    {
        $reasons = [];
        if ($bundleHash === '' || strlen($bundleHash) !== 64) {
            $reasons[] = 'GENERATION_MTF_BUNDLE_HASH_MISSING';
        }
        if ($manifest === []) {
            return [...$reasons, 'GENERATION_MTF_BUNDLE_MANIFEST_MISSING'];
        }
        if ((string) data_get($manifest, 'protocol') !== MultiTimeframeSnapshotService::PROTOCOL
            || (string) data_get($manifest, 'validation_bundle_protocol') !== 'agent_owned_mtf_foundation_bundle_v1') {
            $reasons[] = 'GENERATION_MTF_BUNDLE_PROTOCOL_INVALID';
        }
        if ($bundleHash !== '' && ! hash_equals($bundleHash, (string) data_get($manifest, 'bundle_hash', ''))) {
            $reasons[] = 'GENERATION_MTF_BUNDLE_IDENTITY_MISMATCH';
        }
        foreach (['M5', 'H4', 'H1', 'M15'] as $timeframe) {
            $path = (string) data_get($manifest, "streams.{$timeframe}.path", '');
            $hash = (string) data_get($manifest, "streams.{$timeframe}.sha256", '');
            if ($path === '' || ! is_file($path)) {
                $reasons[] = "GENERATION_MTF_{$timeframe}_PATH_MISSING";

                continue;
            }
            $actual = hash_file('sha256', $path);
            if ($hash === '' || ! is_string($actual) || ! hash_equals($hash, $actual)) {
                $reasons[] = "GENERATION_MTF_{$timeframe}_HASH_INVALID";
            }
        }

        return $reasons;
    }

    private function requiresClosedMtfBundle(LabGeneration $generation): bool
    {
        return strtoupper((string) $generation->laboratory?->symbol)
                === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))
            && strtoupper((string) $generation->laboratory?->timeframe)
                === strtoupper((string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1'));
    }

    /** @return array<int, string> */
    private function snapshotReasons(array $snapshot, string $label): array
    {
        $path = (string) data_get($snapshot, 'path', '');
        $declaredHash = (string) data_get(
            $snapshot,
            'sha256',
            data_get($snapshot, 'manifest.snapshot_sha256', ''),
        );
        $manifest = (array) data_get($snapshot, 'manifest', []);
        $reasons = [];
        if ($path === '' || ! is_file($path)) {
            $reasons[] = "GENERATION_{$label}_SNAPSHOT_PATH_MISSING";
        }
        if ($declaredHash === '') {
            $reasons[] = "GENERATION_{$label}_SNAPSHOT_HASH_MISSING";
        }
        if ($manifest === []) {
            $reasons[] = "GENERATION_{$label}_SNAPSHOT_MANIFEST_MISSING";
        }
        if ($path !== '' && is_file($path) && $declaredHash !== '') {
            $actualHash = hash_file('sha256', $path);
            if (! is_string($actualHash) || ! hash_equals($declaredHash, $actualHash)) {
                $reasons[] = "GENERATION_{$label}_SNAPSHOT_HASH_INVALID";
            }
        }

        return $reasons;
    }

    private function requiresVolume($model): bool
    {
        $metadata = (array) ($model?->metadata ?? []);

        return data_get($metadata, 'volume_research_contract.protocol') === 'volume_council_v1'
            || (bool) data_get($metadata, 'volume_research_contract.enabled', false)
            || (bool) data_get($metadata, 'risk_bounded_evolution.volume_shadow', false)
            || (bool) data_get($metadata, 'portfolio_council_lane.volume_shadow', false)
            || data_get($metadata, 'portfolio_council_lane.role') === 'volume_m15_specialist'
            || data_get($metadata, 'portfolio_council_lane.specialist_role') === 'volume_m15_specialist'
            || data_get($model?->parameters, 'volume_lane', 'none') !== 'none';
    }
}
