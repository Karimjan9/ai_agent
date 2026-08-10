<?php

namespace App\Console\Commands;

use App\Services\MarketData\MarketVolumeService;
use Illuminate\Console\Command;

class AuditCanonicalMarketVolume extends Command
{
    protected $signature = 'market-data:volume-audit {symbol} {--timeframe=H1} {--from=} {--to=}';

    protected $description = 'Audit coverage, zero ratio, units and source contract for canonical volume';

    public function handle(MarketVolumeService $volumes): int
    {
        try {
            $report = $volumes->inspect(
                strtoupper((string) $this->argument('symbol')),
                strtoupper((string) $this->option('timeframe')),
                $this->date($this->option('from')),
                $this->date($this->option('to')),
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $report['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }

    private function date(?string $value): ?\DateTimeImmutable
    {
        return $value ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : null;
    }
}
