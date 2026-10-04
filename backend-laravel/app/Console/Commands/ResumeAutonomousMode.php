<?php

namespace App\Console\Commands;

use App\Services\AutonomousModeService;
use Illuminate\Console\Command;

class ResumeAutonomousMode extends Command
{
    protected $signature = 'ai:resume
        {--symbol=XAUUSD : Governed organism symbol}
        {--timeframe=H1 : Compatibility storage coordinate}
        {--actor=operator : Auditable caller identity}
        {--reason=operator_resume : Auditable resume reason}
        {--json : Print machine-readable status}';

    protected $description = 'Resume the existing durable research lineage through the arbiter';

    public function handle(AutonomousModeService $mode): int
    {
        $result = $mode->resume(
            (string) $this->option('symbol'),
            (string) $this->option('timeframe'),
            (string) $this->option('actor'),
            (string) $this->option('reason'),
        );
        $this->line($this->option('json')
            ? (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : 'Autonomous research RESUMED; the arbiter will reconcile the existing generation first.');

        return ($result['state'] ?? null) === 'running' ? self::SUCCESS : self::FAILURE;
    }
}
