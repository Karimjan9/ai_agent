<?php

namespace App\Console\Commands;

use App\Services\SmartDisciplineEngineService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ReportSmartDiscipline extends Command
{
    protected $signature = 'trading:discipline-report
        {--symbol= : Filter by market symbol}
        {--timeframe= : Filter by timeframe}
        {--days=30 : Lookback in calendar days}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Report process adherence, discipline vetoes and outcome-quality quadrants';

    public function __construct(private SmartDisciplineEngineService $discipline)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $report = $this->discipline->summary(
            $this->option('symbol') ?: null,
            $this->option('timeframe') ?: null,
            CarbonImmutable::now()->subDays($days),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Smart Discipline / Process Integrity');
        $this->table(['Metric', 'Value'], [
            ['Entries assessed', $report['entries_assessed']],
            ['Approved / shrunk / vetoed', "{$report['approved']} / {$report['shrunk']} / {$report['vetoed']}"],
            ['Veto rate', $report['veto_rate_percent'].'%'],
            ['Settled reviews', $report['settled_reviews']],
            ['Average setup quality', $report['average_setup_quality_score'] ?? 'n/a'],
            ['Average pre-trade gate pass', $report['average_gate_pass_score'] ?? 'n/a'],
            ['Average process adherence', $report['average_process_adherence_score'] ?? 'n/a'],
            ['Invalid-trade rate', $report['invalid_trade_rate_percent'].'%'],
            ['Management attestation rate', $report['management_attestation_rate_percent'].'%'],
            ['Stop-widening rate', $report['stop_widening_rate_percent'].'%'],
            ['Average realized R / MFE R / MAE R', implode(' / ', [
                $report['average_realized_r_multiple'] ?? 'n/a',
                $report['average_mfe_r'] ?? 'n/a',
                $report['average_mae_r'] ?? 'n/a',
            ])],
            ['Policy versions observed', count($report['policy_hashes'])],
        ]);
        $this->table(['Outcome quality', 'Count'], collect($report['classifications'])->map(fn ($count, $name): array => [$name, $count])->values()->all());
        $this->table(['Top veto reason', 'Count'], collect($report['top_veto_reasons'])->map(fn ($count, $name): array => [$name, $count])->values()->all());
        $this->table(['Process violation', 'Count'], collect($report['top_process_violations'])->map(fn ($count, $name): array => [$name, $count])->values()->all());
        $this->table(['Process error class', 'Count'], collect($report['process_error_taxonomy'])->map(fn ($count, $name): array => [$name, $count])->values()->all());

        return self::SUCCESS;
    }
}
