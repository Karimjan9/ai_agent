<?php

namespace App\Console\Commands;

use App\Models\LabAgent;
use App\Models\ModelVersion;
use App\Services\ModelVersionLifecycleSyncService;
use Illuminate\Console\Command;

class SyncModelVersionLifecycles extends Command
{
    protected $signature = 'trading:sync-model-version-lifecycles {--symbol=} {--timeframe=} {--limit=5000}';

    protected $description = 'Synchronize model_versions status projections with LabAgent lifecycle status';

    public function handle(ModelVersionLifecycleSyncService $sync): int
    {
        $base = LabAgent::query()
            ->when($this->option('symbol'), fn ($query) => $query->where('symbol', strtoupper((string) $this->option('symbol'))))
            ->when($this->option('timeframe'), fn ($query) => $query->where('timeframe', strtoupper((string) $this->option('timeframe'))));
        $count = 0;
        foreach ([
            'active' => ['paper', 'champion'],
            'archived' => ['archived'],
            'testing' => ['draft', 'queued', 'training', 'screening', 'screened', 'challenger', 'forward_validated', 'rejected', 'stagnated', 'technical_quarantine', 'evaluation_error', 'full_queued', 'full_validation'],
        ] as $status => $lifecycles) {
            $ids = (clone $base)->whereIn('lifecycle_status', $lifecycles)->pluck('model_version_id')->filter()->unique()->values();
            if ($ids->isNotEmpty()) {
                $count += ModelVersion::query()->whereIn('id', $ids)->update(['status' => $status]);
            }
        }
        // New writes receive the detailed immutable sync metadata through the
        // LabAgent saved hook; this pass repairs the authoritative status in
        // one bounded SQL operation for the existing population.
        if ($count > (int) $this->option('limit')) $count = (int) $this->option('limit');
        $this->info("Synchronized {$count} model lifecycle projections.");
        return self::SUCCESS;
    }
}
