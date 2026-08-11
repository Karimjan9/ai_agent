<?php

namespace App\Console\Commands;

use App\Services\LabGenerationReportService;
use Illuminate\Console\Command;

class LabGenerationKpi extends Command
{
    protected $signature = 'trading:lab-kpi {symbol?} {--timeframe= : Restrict to one timeframe} {--json : Print the machine-readable KPI packet}';

    protected $description = 'Show the current AI Lab generation funnel, promotion KPIs and next action';

    public function handle(LabGenerationReportService $reports): int
    {
        $rows = $reports->currentKpis($this->argument('symbol'), $this->option('timeframe'));
        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->warn('Active laboratory topilmadi.');
            return self::SUCCESS;
        }

        $this->table(
            ['Market', 'TF', 'Generation', 'Status', 'Safe', 'Technical %', 'Screen pass %', 'Full %', 'Forward-valid', 'Confirmed mutations', 'Parent links', 'Paper eligible', 'Screen diagnosis', 'Next action'],
            collect($rows)->map(fn (array $row): array => [
                $row['symbol'],
                $row['timeframe'],
                $row['generation'] ?? '-',
                $row['status'] ?? '-',
                data_get($row, 'kpis.evolution_safe', false) ? 'true' : 'false',
                data_get($row, 'kpis.technical_completion_rate', 0),
                data_get($row, 'kpis.screening_pass_rate', 0),
                data_get($row, 'kpis.full_validation_completion_rate', 0),
                data_get($row, 'kpis.forward_valid_agents', 0),
                data_get($row, 'kpis.independently_confirmed_mutations', 0),
                data_get($row, 'kpis.parent_links', 0),
                data_get($row, 'kpis.paper_eligible', 0),
                data_get($row, 'kpis.screening_failure_classification', '-'),
                $row['next_action'],
            ])->all(),
        );

        return self::SUCCESS;
    }
}
