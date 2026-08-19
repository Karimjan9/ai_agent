<?php

namespace App\Console\Commands;

use App\Services\MarketData\FrozenPaperWindowService;
use App\Services\MarketData\MarketTrainingDataService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class FreezePaperWindow extends Command
{
    protected $signature = 'market-data:freeze-paper-window
        {symbol=XAUUSD}
        {--timeframe=H1}
        {--dataset=foundation_10y}
        {--provider=dukascopy}
        {--months=6}
        {--as-of= : UTC boundary; defaults to the current closed boundary}';

    protected $description = 'Create the one-time immutable six-month paper window and lock the training cutoff';

    public function handle(FrozenPaperWindowService $windows): int
    {
        try {
            $window = $windows->freeze(
                (string) $this->option('dataset'),
                (string) $this->option('provider'),
                strtoupper((string) $this->argument('symbol')),
                strtoupper((string) $this->option('timeframe')),
                $this->asOf(),
                (int) $this->option('months'),
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode([
            'status' => 'frozen',
            'symbol' => $window->symbol,
            'timeframe' => $window->timeframe,
            'training_range' => [
                'from' => $window->training_starts_at?->utc()->toIso8601String(),
                'to_exclusive' => $window->training_ends_at?->utc()->toIso8601String(),
            ],
            'paper_range' => [
                'from' => $window->paper_starts_at?->utc()->toIso8601String(),
                'to_exclusive' => $window->paper_ends_at?->utc()->toIso8601String(),
            ],
            'snapshot_path' => $window->snapshot_path,
            'snapshot_sha256' => $window->snapshot_sha256,
            'row_count' => $window->row_count,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function asOf(): CarbonImmutable
    {
        $raw = trim((string) $this->option('as-of'));

        return $raw === '' ? CarbonImmutable::now('UTC') : CarbonImmutable::parse($raw, 'UTC')->utc();
    }
}
