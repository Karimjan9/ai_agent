<?php

namespace App\Services\MarketData;

use App\Models\FrozenPaperWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Owns the one-time chronological split between research/training and the
 * six-month paper window. The boundary is persisted and the paper rows are
 * copied to a hash-verified CSV, so it cannot silently move as new data lands.
 */
class FrozenPaperWindowService
{
    public function __construct(private MarketTrainingDataService $training) {}

    public function freeze(
        string $dataset,
        string $provider,
        string $symbol,
        string $timeframe,
        CarbonImmutable $paperEndsAt,
        int $months = 6,
    ): FrozenPaperWindow {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $months = max(1, $months);
        $paperEndsAt = $this->closedBoundary($paperEndsAt, $timeframe);
        $identity = compact('dataset', 'provider', 'symbol', 'timeframe');
        $identity['dataset_key'] = $identity['dataset'];
        unset($identity['dataset']);

        $existing = FrozenPaperWindow::query()->where($identity)->first();
        if ($existing) {
            $this->assertSnapshotIntact($existing);

            return $existing;
        }

        $base = $this->training->query($dataset, $provider, $symbol, $timeframe);
        $first = (clone $base)->orderBy('time')->value('time');
        if (! $first) {
            throw new RuntimeException('Frozen paper window yaratilmadi: training archive bo\'sh.');
        }
        $trainingStartsAt = CarbonImmutable::parse((string) $first, 'UTC')->utc();
        $latest = (clone $base)->orderByDesc('time')->value('time');
        $latestAvailableEnd = $this->nextBoundary(CarbonImmutable::parse((string) $latest, 'UTC')->utc(), $timeframe);
        // A static window must end at a candle we actually possess. When the
        // archive tail is behind wall-clock time, seal its last closed candle
        // instead of claiming several missing days as paper evidence.
        if ($latestAvailableEnd->lessThan($paperEndsAt)) {
            $paperEndsAt = $latestAvailableEnd;
        }
        $paperStartsAt = $paperEndsAt->subMonthsNoOverflow($months);
        if ($trainingStartsAt->greaterThanOrEqualTo($paperStartsAt)) {
            throw new RuntimeException('Frozen paper window uchun 6 oylik oldingi training tarixi yetarli emas.');
        }

        $rows = (clone $base)
            ->where('time', '>=', $paperStartsAt)
            ->where('time', '<', $paperEndsAt)
            ->orderBy('time')
            ->get();
        if ($rows->isEmpty()) {
            throw new RuntimeException('Frozen paper window uchun archive ichida candle topilmadi.');
        }
        $directory = storage_path('app/frozen-paper-windows');
        File::ensureDirectoryExists($directory);
        $stamp = $paperEndsAt->format('Ymd_His');
        $path = $directory."/{$symbol}_{$timeframe}_{$stamp}_{$months}m.csv";
        $temporary = tempnam($directory, ".{$symbol}_{$timeframe}_paper_");
        if ($temporary === false) {
            throw new RuntimeException('Frozen paper window temporary fayli yaratilmadi.');
        }

        try {
            $handle = fopen($temporary, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Frozen paper window temporary fayli ochilmadi.');
            }
            fputcsv($handle, ['time', 'open', 'high', 'low', 'close', 'volume']);
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->time->copy()->utc()->format('Y-m-d H:i:s'),
                    (float) $row->open, (float) $row->high, (float) $row->low,
                    (float) $row->close, (float) $row->volume,
                ]);
            }
            fclose($handle);
            if (! copy($temporary, $path)) {
                throw new RuntimeException('Frozen paper window publish qilinmadi.');
            }
        } finally {
            File::delete($temporary);
        }

        $hash = hash_file('sha256', $path);
        if (! is_string($hash)) {
            throw new RuntimeException('Frozen paper window hash hisoblanmadi.');
        }

        return FrozenPaperWindow::query()->create([
            ...$identity,
            'training_starts_at' => $trainingStartsAt,
            'training_ends_at' => $paperStartsAt,
            'paper_starts_at' => $paperStartsAt,
            'paper_ends_at' => $paperEndsAt,
            'months' => $months,
            'snapshot_path' => $path,
            'snapshot_sha256' => $hash,
            'row_count' => $rows->count(),
            'frozen_at' => now()->utc(),
        ]);
    }

    public function active(string $dataset, string $provider, string $symbol, string $timeframe): ?FrozenPaperWindow
    {
        return FrozenPaperWindow::query()->where([
            'dataset_key' => $dataset,
            'provider' => $provider,
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
        ])->first();
    }

    public function trainingEnd(string $dataset, string $provider, string $symbol, string $timeframe): ?CarbonImmutable
    {
        $window = $this->active($dataset, $provider, $symbol, $timeframe);
        if (! $window) {
            return null;
        }
        $this->assertSnapshotIntact($window);

        return CarbonImmutable::instance($window->training_ends_at)->utc();
    }

    public function snapshot(FrozenPaperWindow $window): string
    {
        $this->assertSnapshotIntact($window);

        return $window->snapshot_path;
    }

    private function assertSnapshotIntact(FrozenPaperWindow $window): void
    {
        $actual = is_file($window->snapshot_path) ? hash_file('sha256', $window->snapshot_path) : false;
        if (! is_string($actual) || ! hash_equals($window->snapshot_sha256, $actual)) {
            throw new RuntimeException('Frozen paper window snapshot buzilgan yoki o\'chirilgan; paper/holdout bloklandi.');
        }
    }

    private function closedBoundary(CarbonImmutable $at, string $timeframe): CarbonImmutable
    {
        $at = $at->utc();

        return $timeframe === 'M15'
            ? $at->setTime($at->hour, intdiv($at->minute, 15) * 15, 0)
            : $at->startOfHour();
    }

    private function nextBoundary(CarbonImmutable $at, string $timeframe): CarbonImmutable
    {
        return $timeframe === 'M15' ? $at->addMinutes(15) : $at->addHour();
    }
}
