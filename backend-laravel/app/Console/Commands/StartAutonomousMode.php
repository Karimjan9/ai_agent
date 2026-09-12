<?php

namespace App\Console\Commands;

use App\Services\AutonomousModeService;
use Illuminate\Console\Command;

class StartAutonomousMode extends Command
{
    protected $signature = 'ai:start
        {--symbol=XAUUSD : Governed organism symbol}
        {--timeframe=H1 : Compatibility input; XAUUSD is canonicalized to H1 storage}
        {--actor=monitor-controller : Auditable caller identity}
        {--reason=operator_start : Auditable start reason}
        {--controller=lightweight : Monitoring profile: strong or lightweight}
        {--json : Print machine-readable status}';

    protected $description = 'Start persistent autonomous research admission; returns immediately and lets the scheduler own the work';

    public function handle(AutonomousModeService $mode): int
    {
        try {
            $result = $mode->start(
                (string) $this->option('symbol'),
                (string) $this->option('timeframe'),
                (string) $this->option('actor'),
                (string) $this->option('reason'),
                (string) $this->option('controller'),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                '%s/%s autonomy RUNNING (%s); scheduler owns the next safe action. changed=%s',
                $result['symbol'],
                $result['timeframe'],
                $result['controller_profile'],
                ($result['changed'] ?? false) ? 'yes' : 'no',
            ));
        }

        return ($result['enabled'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
