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
        $price = (array) data_get($generation->trigger_context, 'canonical_dataset_snapshots.price', []);
        $path = (string) data_get($price, 'path', '');
        $declaredHash = (string) data_get($price, 'sha256', data_get($price, 'manifest.snapshot_sha256', ''));
        $manifest = (array) data_get($price, 'manifest', []);
        $reasons = [];
        if ($path === '' || ! is_file($path)) $reasons[] = 'GENERATION_PRICE_SNAPSHOT_PATH_MISSING';
        if ($declaredHash === '') $reasons[] = 'GENERATION_PRICE_SNAPSHOT_HASH_MISSING';
        if ($manifest === []) $reasons[] = 'GENERATION_PRICE_SNAPSHOT_MANIFEST_MISSING';
        if ($path !== '' && is_file($path) && $declaredHash !== '') {
            $actualHash = hash_file('sha256', $path);
            if (! is_string($actualHash) || ! hash_equals($declaredHash, $actualHash)) {
                $reasons[] = 'GENERATION_PRICE_SNAPSHOT_HASH_INVALID';
            }
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
}
