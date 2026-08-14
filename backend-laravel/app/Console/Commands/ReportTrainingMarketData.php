<?php

namespace App\Console\Commands;

use App\Models\MarketTrainingArchive;
use Illuminate\Console\Command;

class ReportTrainingMarketData extends Command
{
    protected $signature = 'market-data:training-coverage
                            {--symbol=XAUUSD}
                            {--timeframe=}
                            {--dataset=foundation_10y}
                            {--provider=dukascopy}
                            {--json}';

    protected $description = 'Show resumable agent training archive coverage and checkpoint state';

    public function handle(): int
    {
        $query = MarketTrainingArchive::query()
            ->where('dataset_key', (string) $this->option('dataset'))
            ->where('provider', (string) $this->option('provider'))
            ->where('symbol', strtoupper((string) $this->option('symbol')))
            ->orderBy('timeframe');
        if ($this->option('timeframe')) {
            $query->where('timeframe', strtoupper((string) $this->option('timeframe')));
        }

        $archives = $query->get()->map(static fn (MarketTrainingArchive $archive): array => [
            'dataset_key' => $archive->dataset_key,
            'provider' => $archive->provider,
            'symbol' => $archive->symbol,
            'timeframe' => $archive->timeframe,
            'status' => $archive->status,
            'target_from' => $archive->target_from?->utc()->toIso8601String(),
            'target_to' => $archive->target_to?->utc()->toIso8601String(),
            'backfill_cursor_at' => $archive->backfill_cursor_at?->utc()->toIso8601String(),
            'row_count' => (int) $archive->row_count,
            'first_candle_at' => $archive->first_candle_at?->utc()->toIso8601String(),
            'last_candle_at' => $archive->last_candle_at?->utc()->toIso8601String(),
            'completed_chunks' => (int) $archive->completed_chunks,
            'failed_chunks' => (int) $archive->failed_chunks,
            'last_error' => $archive->last_error,
        ])->values();

        if ($this->option('json')) {
            $this->line($archives->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($archives->isEmpty()) {
            $this->warn('Training archive topilmadi.');

            return self::SUCCESS;
        }

        foreach ($archives as $archive) {
            $this->line(sprintf(
                '%s %s: %s rows=%d cursor=%s range=%s -> %s',
                $archive['symbol'],
                $archive['timeframe'],
                strtoupper((string) $archive['status']),
                $archive['row_count'],
                $archive['backfill_cursor_at'] ?? 'none',
                $archive['first_candle_at'] ?? 'none',
                $archive['last_candle_at'] ?? 'none',
            ));
            if ($archive['last_error']) {
                $this->warn('  '.$archive['last_error']);
            }
        }

        return self::SUCCESS;
    }
}
