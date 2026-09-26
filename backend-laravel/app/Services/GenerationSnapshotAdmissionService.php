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
        $reasons = $this->snapshotReasons(
            (array) data_get($generation->trigger_context, 'canonical_dataset_snapshots.price', []),
            'PRICE',
        );
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

        return [
            'protocol' => self::PROTOCOL,
            'allowed' => $reasons === [],
            'reasons' => $reasons,
            'generation_id' => $generation->id,
            'promotion_evidence' => false,
        ];
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
