<?php

namespace App\Services\MarketData;

use App\Models\Candle;
use App\Models\FrozenPaperWindow;
use App\Models\Symbol;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Owns the one-time chronological split between research/training and the
 * paper windows. The boundary is persisted and the paper rows are copied to
 * a hash-verified CSV, so it cannot silently move as new data lands.
 */
class FrozenPaperWindowService
{
    public const CANONICAL_WINDOW_KEY = 'paper_2026';

    public function __construct(private MarketTrainingDataService $training) {}

    public function freeze(
        string $dataset,
        string $provider,
        string $symbol,
        string $timeframe,
        CarbonImmutable $paperEndsAt,
        int $months = 12,
        ?CarbonImmutable $paperStartsAt = null,
        string $windowKey = self::CANONICAL_WINDOW_KEY,
    ): FrozenPaperWindow {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $months = 12;
        $windowKey = trim($windowKey) !== '' ? trim($windowKey) : self::CANONICAL_WINDOW_KEY;
        $paperEndsAt = $this->closedBoundary($paperEndsAt, $timeframe);
        $trainingCutoff = $this->training->trainingCutoff();
        $paperYearEnd = $trainingCutoff->addYear();
        if ($paperStartsAt !== null
            && ! $this->closedBoundary($paperStartsAt, $timeframe)->equalTo($trainingCutoff)) {
            throw new RuntimeException('2026 to\'liq paper-only: paper start faqat 2026-01-01 bo\'lishi mumkin.');
        }
        $paperStartsAt = $trainingCutoff;
        if ($windowKey !== self::CANONICAL_WINDOW_KEY) {
            throw new RuntimeException('2026 paper authority faqat canonical paper_2026 window key orqali yaratiladi.');
        }
        if ($paperEndsAt->greaterThan($paperYearEnd)) {
            $paperEndsAt = $paperYearEnd;
        }
        if ($paperEndsAt->lessThanOrEqualTo($paperStartsAt)) {
            throw new RuntimeException('2026 paper window uchun yopilgan candle hali mavjud emas.');
        }
        $identity = [
            'dataset_key' => $dataset,
            'provider' => $provider,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'window_key' => $windowKey,
        ];

        $existing = FrozenPaperWindow::query()->where($identity)->first();
        if ($existing) {
            $this->assertTemporalAuthority($existing);
            $this->assertSnapshotIntact($existing);

            return $existing;
        }

        // Training archive is strictly pre-2026. Paper rows come from the
        // canonical market stream and are never copied into the training table.
        $trainingBase = $this->training->query($dataset, $provider, $symbol, $timeframe);
        $symbolId = Symbol::query()->where('code', $symbol)->value('id');
        if (! $symbolId) {
            throw new RuntimeException("Paper window symbol topilmadi: {$symbol}");
        }
        $paperBase = Candle::query()
            ->where('symbol_id', $symbolId)
            ->where('timeframe', $timeframe)
            ->where('time', '>=', $this->training->trainingCutoff());
        $first = (clone $trainingBase)->orderBy('time')->value('time');
        if (! $first) {
            throw new RuntimeException('Frozen paper window yaratilmadi: training archive bo\'sh.');
        }
        $trainingStartsAt = CarbonImmutable::parse((string) $first, 'UTC')->utc();
        $latest = (clone $paperBase)->orderByDesc('time')->value('time');
        if (! $latest) {
            throw new RuntimeException('Frozen paper window yaratilmadi: canonical paper stream bo\'sh.');
        }
        $latestAvailableEnd = $this->nextBoundary(CarbonImmutable::parse((string) $latest, 'UTC')->utc(), $timeframe);
        // A static window must end at a candle we actually possess. When the
        // archive tail is behind wall-clock time, seal its last closed candle
        // instead of claiming several missing days as paper evidence.
        if ($latestAvailableEnd->lessThan($paperEndsAt)) {
            $paperEndsAt = $latestAvailableEnd;
        }
        if ($trainingStartsAt->greaterThanOrEqualTo($paperStartsAt)) {
            throw new RuntimeException('Frozen paper window uchun pre-2026 training tarixi yetarli emas.');
        }

        $rows = (clone $paperBase)
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
        $safeWindowKey = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $windowKey) ?: 'paper';
        $path = $directory."/{$symbol}_{$timeframe}_{$safeWindowKey}_{$stamp}.csv";
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
            'training_ends_at' => $trainingCutoff,
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
            'window_key' => self::CANONICAL_WINDOW_KEY,
        ])->where('training_ends_at', $this->training->trainingCutoff())
            ->where('paper_starts_at', $this->training->trainingCutoff())
            ->orderByDesc('paper_ends_at')
            ->first();
    }

    public function trainingEnd(string $dataset, string $provider, string $symbol, string $timeframe): ?CarbonImmutable
    {
        $window = $this->active($dataset, $provider, $symbol, $timeframe);
        if (! $window) {
            return null;
        }
        $this->assertTemporalAuthority($window);
        $this->assertSnapshotIntact($window);

        return CarbonImmutable::instance($window->training_ends_at)->utc();
    }

    public function snapshot(FrozenPaperWindow $window): string
    {
        $this->assertTemporalAuthority($window);
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

    private function assertTemporalAuthority(FrozenPaperWindow $window): void
    {
        $cutoff = $this->training->trainingCutoff();
        $yearEnd = $cutoff->addYear();
        $trainingEnd = CarbonImmutable::instance($window->training_ends_at)->utc();
        $paperStart = CarbonImmutable::instance($window->paper_starts_at)->utc();
        $paperEnd = CarbonImmutable::instance($window->paper_ends_at)->utc();
        if ((string) $window->window_key !== self::CANONICAL_WINDOW_KEY
            || ! $trainingEnd->equalTo($cutoff)
            || ! $paperStart->equalTo($cutoff)
            || $paperEnd->lessThanOrEqualTo($cutoff)
            || $paperEnd->greaterThan($yearEnd)) {
            throw new RuntimeException('Frozen paper window 2026 paper-only temporal authority contractiga mos emas.');
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
