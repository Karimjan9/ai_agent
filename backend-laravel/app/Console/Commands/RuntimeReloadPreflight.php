<?php

namespace App\Console\Commands;

use App\Services\RuntimeReloadPreflightService;
use Illuminate\Console\Command;

class RuntimeReloadPreflight extends Command
{
    protected $signature = 'system:runtime-reload-preflight {--json}
        {--terminal-projection-recovery= : Exact sole observed council generation; cold maintenance only, never rolling reload}
        {--unsealed-draft-maintenance= : Exact sole fully constructed unused draft; cold maintenance only, never rolling reload}';

    protected $description = 'Refuse a rolling runtime reload while durable replay work can be interrupted.';

    public function handle(RuntimeReloadPreflightService $preflight): int
    {
        $target = $this->option('terminal-projection-recovery');
        $draftTarget = $this->option('unsealed-draft-maintenance');
        if ($target !== null && (! is_string($target) || ! preg_match('/^[1-9][0-9]{0,9}$/D', $target) || (int) $target > 2147483647)) {
            $result = ['protocol' => RuntimeReloadPreflightService::PROTOCOL, 'safe' => false,
                'reason' => 'TERMINAL_PROJECTION_RECOVERY_TARGET_INVALID', 'promotion_evidence' => false];
        } elseif ($draftTarget !== null && (! is_string($draftTarget) || ! preg_match('/^[1-9][0-9]{0,9}$/D', $draftTarget) || (int) $draftTarget > 2147483647)) {
            $result = ['protocol' => RuntimeReloadPreflightService::PROTOCOL, 'safe' => false,
                'reason' => 'UNSEALED_DRAFT_MAINTENANCE_TARGET_INVALID', 'promotion_evidence' => false];
        } else {
            $result = $preflight->inspect($target === null ? null : (int) $target,
                $draftTarget === null ? null : (int) $draftTarget);
        }
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(($result['safe'] ? 'SAFE' : 'REFUSED').': '.$result['reason']);
        }

        return $result['safe'] ? self::SUCCESS : self::FAILURE;
    }
}
