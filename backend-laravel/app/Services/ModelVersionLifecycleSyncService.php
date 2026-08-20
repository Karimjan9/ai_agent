<?php

namespace App\Services;

use App\Models\LabAgent;

/** Keeps the model projection truthful to the owning laboratory lifecycle. */
class ModelVersionLifecycleSyncService
{
    public const PROTOCOL = 'model_version_lifecycle_sync_v1';

    public function sync(LabAgent $agent): void
    {
        $model = $agent->modelVersion;
        if (! $model || ! filled($agent->lifecycle_status)) return;

        $lifecycle = strtolower((string) $agent->lifecycle_status);
        $desired = match ($lifecycle) {
            'paper', 'champion' => 'active',
            'archived' => 'archived',
            default => 'testing',
        };
        if ((string) $model->status === $desired
            && data_get($model->metadata, 'lifecycle_sync.protocol') === self::PROTOCOL
            && data_get($model->metadata, 'lifecycle_sync.agent_status') === $lifecycle) return;

        $metadata = (array) $model->metadata;
        $metadata['lifecycle_sync'] = [
            'protocol' => self::PROTOCOL,
            'agent_id' => (int) $agent->id,
            'agent_status' => $lifecycle,
            'model_status' => $desired,
            'synced_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ];
        $model->update(['status' => $desired, 'metadata' => $metadata]);
    }
}
