<?php

namespace App\Console\Commands;

use App\Services\MarketData\MarketTrainingDataService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AttestIndependentProviderExport extends Command
{
    protected $signature = 'market-data:attest-independent-export
        {source : CSV exported directly from an independent provider}
        {--provider= : Provider identity recorded in the immutable manifest}
        {--symbol=XAUUSD}
        {--timeframe=H1}';

    protected $description = 'Validate and seal an external pre-2026 provider CSV for temporal holdout research';

    public function handle(MarketTrainingDataService $training): int
    {
        $source = (string) $this->argument('source');
        $provider = strtolower(trim((string) $this->option('provider')));
        $symbol = strtoupper(trim((string) $this->option('symbol')));
        $timeframe = strtoupper(trim((string) $this->option('timeframe')));
        $canonicalProvider = strtolower((string) config('services.market_data.canonical_provider', 'twelve'));

        if ($provider === '' || $provider === $canonicalProvider) {
            return $this->failCommand('Independent export provider majburiy va canonical providerdan farqli bo\'lishi kerak.');
        }
        if (! is_file($source)) return $this->failCommand('Independent provider CSV topilmadi: '.$source);

        $sourceHash = hash_file('sha256', $source);
        if (! is_string($sourceHash) || $sourceHash === '') return $this->failCommand('CSV hash hisoblanmadi.');

        try {
            $summary = $this->inspect($source, $training->trainingCutoff());
        } catch (\RuntimeException $exception) {
            return $this->failCommand($exception->getMessage());
        }

        $directory = storage_path('app/lab-datasets/independent-exports');
        File::ensureDirectoryExists($directory);
        $safeProvider = preg_replace('/[^a-z0-9_-]+/i', '-', $provider) ?: 'provider';
        $target = $directory."/{$symbol}_{$timeframe}_{$safeProvider}_".substr($sourceHash, 0, 16).'.csv';
        $manifestPath = $target.'.manifest.json';

        if (! is_file($target) && ! copy($source, $target)) {
            return $this->failCommand('Independent provider export storagega ko\'chirilmadi.');
        }
        if (hash_file('sha256', $target) !== $sourceHash) {
            return $this->failCommand('Independent provider export copy hash mos emas.');
        }

        $manifest = [
            'protocol' => 'independent_provider_export_attestation_v1',
            'provider' => $provider,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'sha256' => $sourceHash,
            'row_count' => $summary['row_count'],
            'first_candle_at' => $summary['first']->toIso8601String(),
            'last_candle_at' => $summary['last']->toIso8601String(),
            'training_end_exclusive' => $training->trainingCutoff()->toIso8601String(),
            'independent_from_canonical' => true,
            'source_kind' => 'direct_external_provider_export',
            'attested_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ];
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->line(json_encode([
            'status' => 'attested',
            'path' => $target,
            'manifest_path' => $manifestPath,
            'provider' => $provider,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'row_count' => $summary['row_count'],
            'first_candle_at' => $summary['first']->toIso8601String(),
            'last_candle_at' => $summary['last']->toIso8601String(),
            'promotion_evidence' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /** @return array{row_count: int, first: CarbonImmutable, last: CarbonImmutable} */
    private function inspect(string $path, CarbonImmutable $cutoff): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new \RuntimeException('Independent provider CSV ochilmadi.');
        $header = fgetcsv($handle);
        if (! is_array($header) || count($header) < 5) {
            fclose($handle);
            throw new \RuntimeException('Independent provider CSV OHLC headerini o\'z ichiga olishi kerak.');
        }

        $count = 0;
        $first = null;
        $previous = null;
        try {
            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null] || $row === []) continue;
                if (count($row) < 5) throw new \RuntimeException('Independent provider CSV da to\'liq bo\'lmagan OHLC qator bor.');
                $time = CarbonImmutable::parse((string) $row[0], 'UTC')->utc();
                if ($time->greaterThanOrEqualTo($cutoff)) {
                    throw new \RuntimeException('Independent provider export 2026-01-01 yoki keyingi candle qabul qilmaydi.');
                }
                if ($previous !== null && $time->lessThanOrEqualTo($previous)) {
                    throw new \RuntimeException('Independent provider export chronological va unique bo\'lishi kerak.');
                }
                foreach (array_slice($row, 1, 4) as $value) {
                    if (! is_numeric($value)) throw new \RuntimeException('Independent provider CSV yaroqsiz OHLC qiymatga ega.');
                }
                $first ??= $time;
                $previous = $time;
                $count++;
            }
        } finally {
            fclose($handle);
        }
        if ($count === 0 || ! $first instanceof CarbonImmutable || ! $previous instanceof CarbonImmutable) {
            throw new \RuntimeException('Independent provider export bo\'sh.');
        }

        return ['row_count' => $count, 'first' => $first, 'last' => $previous];
    }

    private function failCommand(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
