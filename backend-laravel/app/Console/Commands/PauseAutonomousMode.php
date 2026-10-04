<?php

namespace App\Console\Commands;

use App\Services\AutonomousModeService;
use Illuminate\Console\Command;

class PauseAutonomousMode extends Command
{
    protected $signature = 'ai:pause
        {--symbol=XAUUSD : Governed organism symbol}
        {--timeframe=H1 : Compatibility storage coordinate}
        {--actor=operator : Auditable caller identity}
        {--reason=operator_pause : Auditable pause reason}
        {--json : Print machine-readable status}';

    protected $description = 'Pause new autonomous research without cancelling the current generation';

    public function handle(AutonomousModeService $mode): int
    {
        $result = $mode->pause(
            (string) $this->option('symbol'),
            (string) $this->option('timeframe'),
            (string) $this->option('actor'),
            (string) $this->option('reason'),
        );
        $this->line($this->option('json')
            ? (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : 'Autonomous research '.strtoupper((string) ($result['state'] ?? 'unavailable')).'; existing generation and evidence remain durable.');

        return in_array(($result['state'] ?? null), ['pausing', 'paused'], true) ? self::SUCCESS : self::FAILURE;
    }
}
