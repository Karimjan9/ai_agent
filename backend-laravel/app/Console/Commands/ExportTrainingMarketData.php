<?php

namespace App\Console\Commands;

use App\Services\MarketData\MarketTrainingDataService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class ExportTrainingMarketData extends Command
{
    protected $signature = 'market-data:export-training
                            {symbol=XAUUSD}
                            {--timeframe=H1}
                            {--dataset=foundation_10y}
                            {--provider=dukascopy}
                            {--from=}
                            {--to=}
                            {--path=}';

    protected $description = 'Stream an isolated training archive to an agent-ready CSV with a manifest';

    public function handle(MarketTrainingDataService $training): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $dataset = (string) $this->option('dataset');
        $provider = (string) $this->option('provider');
        $from = $this->parseBoundary($this->option('from'));
        $to = $this->parseBoundary($this->option('to'));
        $path = (string) ($this->option('path') ?: storage_path("app/lab-datasets/training/{$symbol}_{$timeframe}_{$dataset}.csv"));
        $directory = dirname($path);
        File::ensureDirectoryExists($directory);
        $temporaryPath = tempnam($directory, '.training-export-');
        if ($temporaryPath === false) {
            $this->error('Training export temporary file yaratilmadi.');

            return self::FAILURE;
        }

        try {
            $handle = fopen($temporaryPath, 'wb');
            if ($handle === false) {
                throw new RuntimeException("Training export ochilmadi: {$temporaryPath}");
            }
            $written = 0;
            $first = null;
            $last = null;
            try {
                fputcsv($handle, ['time', 'open', 'high', 'low', 'close', 'volume']);
                foreach ($training->query($dataset, $provider, $symbol, $timeframe)
                    ->when($from, fn ($query) => $query->where('time', '>=', $from))
                    ->when($to, fn ($query) => $query->where('time', '<', $to))
                    ->orderBy('time')
                    ->cursor() as $candle) {
                    $time = $candle->time->copy()->utc();
                    fputcsv($handle, [
                        $time->format('Y-m-d H:i:s'),
                        $candle->open,
                        $candle->high,
                        $candle->low,
                        $candle->close,
                        $candle->volume,
                    ]);
                    $first ??= $time;
                    $last = $time;
                    $written++;
                }
            } finally {
                fclose($handle);
            }
            if ($written === 0) {
                throw new RuntimeException('Export uchun training candle topilmadi.');
            }
            if (! copy($temporaryPath, $path)) {
                throw new RuntimeException("Training export publish qilinmadi: {$path}");
            }
            $manifest = [
                'protocol' => 'agent_training_dataset_v1',
                'dataset_key' => $dataset,
                'provider' => $provider,
                'source_role' => 'foundation_training_only',
                'promotion_evidence' => false,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'row_count' => $written,
                'first_candle_at' => $first?->toIso8601String(),
                'last_candle_at' => $last?->toIso8601String(),
                'from' => $from?->toIso8601String(),
                'to_exclusive' => $to?->toIso8601String(),
                'sha256' => hash_file('sha256', $path),
                'generated_at' => now()->utc()->toIso8601String(),
            ];
            File::put($path.'.manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
            $this->info("{$path} ({$written} candles)");
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            File::delete($temporaryPath);
        }

        return self::SUCCESS;
    }

    private function parseBoundary(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC')->utc();
    }
}
