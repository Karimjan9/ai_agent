<?php

namespace App\Console\Commands;

use App\Models\LabSkillZooEntry;
use App\Services\CanonicalSkillCartridgeService;
use Illuminate\Console\Command;

class DispatchSkillCartridgeInteractions extends Command
{
    protected $signature = 'trading:dispatch-skill-cartridge-interactions {skillA} {skillB} {--baseline-model=} {--dry-run}';
    protected $description = 'Materialize a five-arm, frozen-baseline canonical Skill Cartridge interaction test.';

    public function handle(CanonicalSkillCartridgeService $cartridges): int
    {
        $a = LabSkillZooEntry::find((int) $this->argument('skillA')); $b = LabSkillZooEntry::find((int) $this->argument('skillB'));
        if (! $a || ! $b || ! $this->option('baseline-model')) { $this->error('Two cartridges and --baseline-model are required.'); return self::FAILURE; }
        if ($this->option('dry-run')) { $this->info('Interaction contract is valid only after materialization checks.'); return self::SUCCESS; }
        $result = $cartridges->materializeInteraction($a, $b, (int) $this->option('baseline-model'));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return ($result['status'] ?? null) === 'queued' ? self::SUCCESS : self::FAILURE;
    }
}
