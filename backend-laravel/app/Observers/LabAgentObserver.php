<?php

namespace App\Observers;

use App\Models\LabAgent;
use App\Services\LabImmutableEvidenceService;

class LabAgentObserver
{
    public function created(LabAgent $agent): void
    {
        app(LabImmutableEvidenceService::class)->recordAgentCreated($agent);
    }

    public function updated(LabAgent $agent): void
    {
        if (! $agent->wasChanged('lifecycle_status')) return;
        app(LabImmutableEvidenceService::class)->recordAgentStatusChanged(
            $agent,
            $agent->getOriginal('lifecycle_status'),
            $agent->lifecycle_status,
        );
    }
}
