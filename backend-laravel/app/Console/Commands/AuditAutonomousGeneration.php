<?php

namespace App\Console\Commands;

use App\Models\LabGeneration;
use App\Services\GenerationAutonomyAuditService;
use Illuminate\Console\Command;

class AuditAutonomousGeneration extends Command
{
    protected $signature = 'trading:audit-autonomous-generation
        {symbol=XAUUSD}
        {--timeframe=H1}
        {--generation= : Generation number; latest when omitted}
        {--json}
        {--strict : Return a non-zero exit code unless the generation passed}';

    protected $description = 'Read-only cross-feature acceptance audit for one unattended generation';

    public function handle(GenerationAutonomyAuditService $audit): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $query = LabGeneration::query()->whereHas('laboratory', fn ($q) => $q
            ->where('symbol', $symbol)->where('timeframe', $timeframe));
        if ($number = $this->option('generation')) {
            $query->where('generation', (int) $number);
        }
        $generation = $query->orderByDesc('generation')->orderByDesc('id')->first();
        if (! $generation) {
            $this->error("{$symbol} {$timeframe} generation topilmadi.");

            return self::FAILURE;
        }

        $report = $audit->audit($generation);
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                '%s %s G%d: %s',
                $symbol,
                $timeframe,
                (int) $generation->generation,
                strtoupper((string) $report['state']),
            ));
            $this->table(
                ['Check', 'Status', 'Reasons'],
                collect($report['checks'])->map(fn (array $check): array => [
                    $check['name'],
                    $check['status'],
                    implode(', ', (array) $check['reason_codes']),
                ])->all(),
            );
        }

        return $this->option('strict') && ! $report['unattended_ready'] ? self::FAILURE : self::SUCCESS;
    }
}
