<?php

namespace App\Console\Commands;

use App\Services\LabFailureStudyService;
use Illuminate\Console\Command;

class StudyLabFailures extends Command
{
    protected $signature = 'trading:study-lab-failures {symbol?} {--timeframe=} {--persist : Store the diagnostic study in system_events} {--json}';
    protected $description = 'Group evidence-complete screening failures into bounded research cells without changing gates';

    public function handle(LabFailureStudyService $study): int
    {
        $result = $study->study(
            $this->argument('symbol') ? strtoupper((string) $this->argument('symbol')) : null,
            $this->option('timeframe') ? strtoupper((string) $this->option('timeframe')) : null,
            (bool) $this->option('persist'),
        );
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        foreach ($result['reports'] as $report) {
            $this->line(sprintf(
                '%s %s G%s: actionable=%d, gate_hits=%d, dominant=%s, next=%s',
                $report['symbol'], $report['timeframe'], $report['source_generation'] ?? '-',
                $report['distinct_actionable_failed_agents'], $report['gate_hit_count'],
                $report['dominant_failure'] ?? '-', $report['next_action'] ?? '-',
            ));
        }
        return self::SUCCESS;
    }
}
