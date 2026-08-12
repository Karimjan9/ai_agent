<?php

namespace App\Console\Commands;

use App\Services\MtfStrategyResearchReportService;
use Illuminate\Console\Command;

class ReportMtfStrategyResearch extends Command
{
    protected $signature = 'trading:mtf-research-report
        {--symbol=XAUUSD : Lighthouse symbol}
        {--lookback-hours=720 : Research history window}
        {--json : Print the full diagnostic report as JSON}';

    protected $description = 'Summarize MTF hypotheses, gate deltas, failure-to-mutation actions and evidence budget';

    public function handle(MtfStrategyResearchReportService $reporter): int
    {
        $report = $reporter->report(
            (string) $this->option('symbol'),
            max(1, (int) $this->option('lookback-hours')),
        );
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->info(sprintf('%s MTF research report: %d immutable run(s)', $report['symbol'], $report['run_count']));
        $this->table(['Hypothesis', 'Classification', 'MTF PF', 'Frozen M15 PF', 'MTF DD %', 'Frozen M15 DD %', 'Next action'], array_map(
            fn (array $row): array => [
                $row['hypothesis_key'], $row['classification'],
                data_get($row, 'h1_veto_m15_risk.profit_factor', 0),
                data_get($row, 'frozen_m15_control.profit_factor', 0),
                data_get($row, 'h1_veto_m15_risk.max_drawdown_percent', 0),
                data_get($row, 'frozen_m15_control.max_drawdown_percent', 0),
                $row['next_action'],
            ],
            $report['runs'],
        ));
        foreach ($report['next_research_actions'] as $action) {
            $this->line('→ '.$action);
        }
        $this->comment('Hisobot promotion evidence emas; family pause ham faqat research budget signali.');

        return self::SUCCESS;
    }
}
