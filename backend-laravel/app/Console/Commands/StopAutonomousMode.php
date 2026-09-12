<?php

namespace App\Console\Commands;

use App\Services\AutonomousModeService;
use Illuminate\Console\Command;

class StopAutonomousMode extends Command
{
    protected $signature = 'ai:stop
        {--symbol=XAUUSD : Governed organism symbol}
        {--timeframe=H1 : Compatibility input; XAUUSD is canonicalized to H1 storage}
        {--actor=monitor-controller : Auditable caller identity}
        {--reason=operator_stop : Auditable stop reason}
        {--json : Print machine-readable status}';

    protected $description = 'Stop new autonomous work, drain already admitted work, and keep monitoring available';

    public function handle(AutonomousModeService $mode): int
    {
        $result = $mode->stop(
            (string) $this->option('symbol'),
            (string) $this->option('timeframe'),
            (string) $this->option('actor'),
            (string) $this->option('reason'),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->warn(sprintf(
                '%s/%s autonomy %s; new work denied, monitoring continues. changed=%s',
                $result['symbol'],
                $result['timeframe'],
                strtoupper((string) $result['state']),
                ($result['changed'] ?? false) ? 'yes' : 'no',
            ));
        }

        return ($result['enabled'] ?? true) ? self::FAILURE : self::SUCCESS;
    }
}
