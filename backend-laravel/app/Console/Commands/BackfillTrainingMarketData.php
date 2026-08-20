<?php

namespace App\Console\Commands;

use App\Models\MarketSymbol;
use App\Services\MarketData\DukascopyMarketDataProvider;
use App\Services\MarketData\MarketTrainingDataService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class BackfillTrainingMarketData extends Command
{
    protected $signature = 'market-data:backfill-training
                            {--symbol=XAUUSD}
                            {--timeframe=H1}
                            {--from= : UTC inclusive start; defaults to ten years ago}
                            {--to= : UTC exclusive end; defaults to the last closed boundary}
                            {--chunk-days=31}
                            {--max-chunks=1 : Number of chunks per invocation; 0 means all}
                            {--cursor= : Explicitly resume from this UTC boundary}
                            {--dataset=foundation_10y}
                            {--provider=dukascopy}
                            {--transport=jetta}';

    protected $description = 'Resume a bounded XAUUSD H1/M15 training archive backfill without touching canonical candles';

    public function handle(
        MarketTrainingDataService $training,
        DukascopyMarketDataProvider $provider,
    ): int {
        $symbol = strtoupper((string) $this->option('symbol'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $sourceProvider = (string) $this->option('provider');
        if (! in_array($timeframe, ['H1', 'M15'], true)) {
            $this->error("Unsupported training timeframe: {$timeframe}");

            return self::INVALID;
        }
        if ($sourceProvider === '') {
            $this->error('Training provider bo\'sh bo\'lishi mumkin emas.');

            return self::INVALID;
        }

        $marketSymbol = MarketSymbol::query()
            ->where('symbol', $symbol)
            ->where('is_active', true)
            ->first();
        if (! $marketSymbol) {
            $this->error("Active symbol topilmadi: {$symbol}");

            return self::FAILURE;
        }

        // H1 and M15 are independent training streams. Keep a lock per
        // timeframe so a slow M15 archive request cannot starve H1, while
        // retries for the same stream still remain single-writer.
        $lockPath = storage_path("app/market-training-backfill-{$timeframe}.lock");
        $lock = fopen($lockPath, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }
            $this->line("{$symbol} {$timeframe}: boshqa training backfill ishlayapti; bu tick o'tkazib yuborildi.");

            return self::SUCCESS;
        }

        try {
            try {
            $requestedFrom = $this->parseBoundary($this->option('from'));
            $requestedTo = $this->parseBoundary($this->option('to'));
            $targetFrom = $requestedFrom ?? CarbonImmutable::now('UTC')->subYears(10)->startOfDay();
            $trainingCutoff = $training->trainingCutoff();
            $targetTo = $requestedTo ?? $this->lastClosedBoundary($timeframe);
            if ($targetTo->greaterThan($trainingCutoff)) {
                $targetTo = $trainingCutoff;
            }
            if ($targetFrom->greaterThanOrEqualTo($trainingCutoff)) {
                throw new \InvalidArgumentException('Training archive 2026-01-01 dan keyin boshlanishi mumkin emas.');
            }
            if ($targetFrom->greaterThanOrEqualTo($targetTo)) {
                throw new \InvalidArgumentException('Training range bo\'sh bo\'lishi mumkin emas.');
            }

            $archive = $training->ensureArchive(
                (string) $this->option('dataset'),
                $sourceProvider,
                $symbol,
                $timeframe,
                $targetFrom,
                $targetTo,
            );
            $targetTo = CarbonImmutable::instance($archive->target_to)->utc();
            $explicitCursor = $this->parseBoundary($this->option('cursor'));
            if ($explicitCursor) {
                if ($explicitCursor->greaterThanOrEqualTo($trainingCutoff)) {
                    $archive->update([
                        'target_to' => $trainingCutoff,
                        'backfill_cursor_at' => $trainingCutoff,
                        'status' => 'complete',
                        'last_error' => null,
                    ]);
                    $this->info("{$symbol} {$timeframe}: pre-2026 training archive complete.");

                    return self::SUCCESS;
                }
                if ($requestedTo) {
                    $targetTo = $requestedTo;
                    if ($targetTo->greaterThan($trainingCutoff)) {
                        $targetTo = $trainingCutoff;
                    }
                }
                if ($explicitCursor->greaterThanOrEqualTo($targetTo)) {
                    throw new \InvalidArgumentException('--cursor --to dan oldin bo\'lishi kerak.');
                }
                $archive->update([
                    'target_to' => $targetTo,
                    'backfill_cursor_at' => $explicitCursor,
                    'status' => 'partial',
                    'last_error' => null,
                ]);
                $archive->refresh();
            }
            $cursor = $explicitCursor
                ?? ($archive->backfill_cursor_at
                    ? CarbonImmutable::instance($archive->backfill_cursor_at)->utc()
                    : $targetFrom);
            $targetTo = CarbonImmutable::instance($archive->target_to)->utc();
            if ($cursor->greaterThanOrEqualTo($targetTo)) {
                $archive->update(['status' => 'complete', 'backfill_cursor_at' => $targetTo]);
                $this->info("{$symbol} {$timeframe}: training archive already complete.");

                return self::SUCCESS;
            }

            $maxChunks = max(0, (int) $this->option('max-chunks'));
            $chunkDays = max(1, min(366, (int) $this->option('chunk-days')));
            $processedChunks = 0;
            $previousTransport = config('services.dukascopy.transport');
            config()->set('services.dukascopy.transport', strtolower((string) $this->option('transport')));

            try {
                while ($cursor->lessThan($targetTo) && ($maxChunks === 0 || $processedChunks < $maxChunks)) {
                    $chunkFrom = $cursor;
                    $chunkTo = $cursor->addDays($chunkDays);
                    if ($chunkTo->greaterThan($targetTo)) {
                        $chunkTo = $targetTo;
                    }

                    $archive->update([
                        'status' => 'backfilling',
                        'last_attempt_at' => now(),
                        'last_chunk_from' => $cursor,
                        'last_chunk_to' => $chunkTo,
                        'last_error' => null,
                    ]);

                    try {
                        $rows = $provider->fetchCandles(
                            symbol: $symbol,
                            providerSymbol: $marketSymbol->provider_symbol ?? $marketSymbol->symbol,
                            timeframe: $timeframe,
                            limit: 10000,
                            from: $cursor,
                            to: $chunkTo,
                        );
                        if ($rows === []) {
                            throw new \RuntimeException('Dukascopy training archive bo\'sh response qaytardi; cursor advance qilinmadi.');
                        }
                        $saved = $training->upsertCandles(
                            $archive->dataset_key,
                            $archive->provider,
                            $archive->symbol,
                            $archive->timeframe,
                            $rows,
                        );
                    } catch (Throwable $exception) {
                        $archive->increment('failed_chunks');
                        $archive->update([
                            'status' => 'blocked',
                            'last_error' => $exception->getMessage(),
                            'last_attempt_at' => now(),
                        ]);
                        throw $exception;
                    }

                    $cursor = $chunkTo;
                    $processedChunks++;
                    $archive->increment('completed_chunks');
                    $archive->update([
                        'status' => $cursor->greaterThanOrEqualTo($targetTo) ? 'complete' : 'partial',
                        'backfill_cursor_at' => $cursor,
                        'last_success_at' => now(),
                        'last_error' => null,
                        'metrics' => array_merge($archive->fresh()->metrics ?? [], [
                            'last_chunk_rows' => count($rows),
                            'last_chunk_saved' => $saved,
                            'timezone' => 'UTC',
                            'price_side' => 'BID',
                            'source_role' => 'foundation_training_only',
                        ]),
                    ]);
                    $coverage = $training->refreshCoverage($archive->fresh());

                    $this->line(sprintf(
                        '%s %s: %d rows %s -> %s; coverage=%d; cursor=%s',
                        $symbol,
                        $timeframe,
                        $saved,
                        $chunkFrom->format('Y-m-d H:i'),
                        $chunkTo->format('Y-m-d H:i'),
                        $coverage['row_count'],
                        $cursor->format('Y-m-d H:i'),
                    ));
                }
            } finally {
                config()->set('services.dukascopy.transport', $previousTransport);
            }
            } catch (Throwable $exception) {
                $this->error('Training backfill stopped: '.$exception->getMessage());

                return self::FAILURE;
            }

            $archive = $archive->fresh();
            $this->info(sprintf(
                'Training backfill checkpoint: status=%s cursor=%s rows=%d',
                $archive->status,
                $archive->backfill_cursor_at?->utc()->toIso8601String() ?? 'none',
                $archive->row_count,
            ));

            return self::SUCCESS;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function parseBoundary(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC')->utc();
    }

    private function lastClosedBoundary(string $timeframe): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');

        return $timeframe === 'M15'
            ? $now->setTime($now->hour, intdiv($now->minute, 15) * 15, 0)
            : $now->startOfHour();
    }
}
