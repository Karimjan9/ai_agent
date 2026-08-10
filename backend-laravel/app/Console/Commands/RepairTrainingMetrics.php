<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RepairTrainingMetrics extends Command
{
    protected $signature = 'training:repair-metrics {--dry-run}';

    protected $description = 'Deprecated compatibility alias; canonical Lab metrics are immutable';

    public function handle(): int
    {
        $this->warn('training:repair-metrics deprecated: legacy TrainingSession/StrategyScore projection o\'zgartirilmaydi.');
        $this->line('Canonical Lab metrics LabEvaluationRun immutable evidence plane orqali o\'qiladi.');

        return self::SUCCESS;
    }
}
