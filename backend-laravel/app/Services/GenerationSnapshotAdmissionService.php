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
        $generation->loadMissing('agents.modelVersion');
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
        foreach ($generation->agents as $agent) {
            $execution = data_get($agent->modelVersion?->metadata, 'execution_contract');
            if (! is_array($execution) || $execution === []) {
                $reasons[] = 'AGENT_EXECUTION_CONTRACT_MISSING:'.$agent->id;
            }
        }

        return [
            'protocol' => self::PROTOCOL,
            'allowed' => $reasons === [],
            'reasons' => $reasons,
            'generation_id' => $generation->id,
            'promotion_evidence' => false,
        ];
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
