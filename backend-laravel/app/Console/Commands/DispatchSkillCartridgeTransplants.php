<?php

namespace App\Console\Commands;

use App\Models\LabAgent;
use App\Models\LabSkillZooEntry;
use App\Services\CanonicalSkillCartridgeService;
use Illuminate\Console\Command;

class DispatchSkillCartridgeTransplants extends Command
{
    protected $signature = 'trading:dispatch-skill-cartridge-transplants {cartridge?} {--baseline-model=} {--limit=1} {--reverse} {--dry-run}';
    protected $description = 'Materialize frozen-control, exact, refinement and blinded canonical Skill Cartridge transplant cohorts.';

    public function handle(CanonicalSkillCartridgeService $cartridges): int
    {
        $limit = max(1, min(20, (int) $this->option('limit')));
        $entries = filled($this->argument('cartridge'))
            ? LabSkillZooEntry::query()->whereKey((int) $this->argument('cartridge'))->get()
            : collect($cartridges->rankedForReplay('XAUUSD', 'H1', $limit));
        $rows = [];
        foreach ($entries as $entry) {
            $baseline = (int) ($this->option('baseline-model') ?: LabAgent::query()->find($entry->causal_baseline_agent_id)?->model_version_id);
            if ($this->option('dry-run')) { $rows[] = [$entry->id, $baseline ?: null, 'dry_run']; continue; }
            $result = $baseline > 0 ? $cartridges->materializeTransplant($entry, $baseline, [], (bool) $this->option('reverse')) : ['status' => 'blocked', 'reason' => 'CAUSAL_BASELINE_MODEL_MISSING'];
            $rows[] = [$entry->id, $baseline ?: null, $result['status'] ?? 'unknown'];
        }
        $this->table(['cartridge_id', 'baseline_model_id', 'status'], $rows);
        return self::SUCCESS;
    }
}
