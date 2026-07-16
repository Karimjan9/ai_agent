<?php

namespace App\Console\Commands;

use App\Services\PaperEvidenceReadinessService;
use Illuminate\Console\Command;

class CheckPaperEvidenceReadiness extends Command
{
    protected $signature = 'paper:evidence-readiness {--json}';
    protected $description = 'Check the 90-day paper observation and operational promotion gates';

    public function handle(PaperEvidenceReadinessService $readiness): int
    {
        $result = $readiness->inspect();
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->info('Paper evidence status: '.$result['status']);
            $this->table(['Metric', 'Value'], collect($result['metrics'])->map(fn ($value, $key) => [$key, is_array($value) ? json_encode($value) : $value])->values()->all());
            if ($result['blocking_reasons']) $this->components->warn('Blocked by: '.implode(', ', $result['blocking_reasons']));
        }
        return $result['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
