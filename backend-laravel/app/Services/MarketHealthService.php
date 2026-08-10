<?php

namespace App\Services;

use App\Models\Candle;
use App\Models\MarketProviderHealth;
use App\Models\MarketHealthSample;
use App\Models\ServiceHealthCheck;
use App\Models\Symbol;
use App\Models\SystemEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

class MarketHealthService
{
    public function __construct(
        private readonly TelegramAlertService $telegram,
        private readonly SystemLogService $logs,
        private readonly PhaseTwoFoundationService $foundation,
    ) {
    }

    public function check(bool $allowRecovery = false): Collection
    {
        if (! Schema::hasTable('market_provider_health')) {
            return collect();
        }

        // The health monitor must inspect the provider that actually wrote the
        // candles. Keeping the historical MT5 setting as an override preserves
        // MT5 deployments while allowing the default Dukascopy pipeline to be
        // monitored correctly.
        $provider = (string) config('services.mt5.provider', config('services.market_data.provider', 'mt5'));
        $symbols = $this->configuredSymbols();
        $timeframes = $this->configuredTimeframes();

        return collect($symbols)
            ->flatMap(fn (string $symbol): Collection => collect($timeframes)
                ->map(fn (string $timeframe): MarketProviderHealth => $this->checkOne($provider, $symbol, $timeframe, $allowRecovery)))
            ->values();
    }

    private function checkOne(string $provider, string $symbol, string $timeframe, bool $allowRecovery): MarketProviderHealth
    {
        $latest = $this->latestCandle($symbol, $timeframe, $provider);
        $lostAfter = (int) config('services.mt5.feed_lost_after_seconds', 1200);
        $staleAfter = (int) config('services.mt5.feed_stale_after_seconds', 900);
        $age = $latest ? max(0, (int) $latest->time->diffInSeconds(now())) : PHP_INT_MAX;
        $status = $this->statusForAge($age, $staleAfter, $lostAfter);
        $previous = MarketProviderHealth::query()
            ->where('provider', $provider)
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->first();

        $health = MarketProviderHealth::updateOrCreate(
            ['provider' => $provider, 'symbol' => $symbol, 'timeframe' => $timeframe],
            [
                'last_candle_at' => $latest?->time,
                'last_seen_at' => $latest?->created_at,
                'status' => $status,
                'age_seconds' => $latest ? $age : $lostAfter + 1,
                'stale_after_seconds' => $staleAfter,
                'lost_after_seconds' => $lostAfter,
                'alert_sent' => $status === 'lost' ? (bool) ($previous?->alert_sent ?? false) : false,
                'alert_sent_at' => $status === 'lost' ? $previous?->alert_sent_at : null,
                'auto_recovery_attempted' => $status === 'lost' ? (bool) ($previous?->auto_recovery_attempted ?? false) : false,
                'auto_recovery_attempted_at' => $status === 'lost' ? $previous?->auto_recovery_attempted_at : null,
                'message' => $latest ? "Last {$symbol} {$timeframe} candle age {$age}s." : "No {$symbol} {$timeframe} candle found.",
                'metrics' => [
                    'latest_candle_id' => $latest?->id,
                    'latest_candle_time' => $latest?->time?->toDateTimeString(),
                ],
            ],
        );

        if (Schema::hasTable('market_health_samples')) {
            MarketHealthSample::create([
                'provider' => $provider,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'status' => $status,
                'age_seconds' => $latest ? $age : $lostAfter + 1,
                'candle_time' => $latest?->time,
                'sampled_at' => now(),
            ]);
        }

        $this->syncServiceHealth($health);
        $this->writeStateEvents($previous, $health);

        if ($health->status === 'lost') {
            $this->alertLostFeed($health);

            if ($allowRecovery || config('services.mt5.auto_recovery_enabled')) {
                $this->attemptRecovery($health);
            }
        }

        return $health->fresh();
    }

    private function latestCandle(string $symbol, string $timeframe, string $provider): ?Candle
    {
        $symbolRow = Symbol::query()->where('code', $symbol)->first();

        if (! $symbolRow) {
            return null;
        }

        $query = Candle::query()
            ->where('symbol_id', $symbolRow->id)
            ->where('timeframe', $timeframe);

        if (Schema::hasColumn('candles', 'provider')) {
            // A fallback feed is useful for research recovery, but it must
            // never make the configured provider look healthy. Monitoring the
            // exact writer also prevents legacy NULL-provider rows from
            // masking a canonical outage.
            $query->where('provider', $provider);
        }

        return $query->latest('time')->first();
    }

    private function statusForAge(int $age, int $staleAfter, int $lostAfter): string
    {
        if ($age === PHP_INT_MAX) {
            return 'lost';
        }

        if ($age > $lostAfter) {
            return 'lost';
        }

        if ($age > $staleAfter) {
            return 'stale';
        }

        return 'ok';
    }

    private function syncServiceHealth(MarketProviderHealth $health): void
    {
        if (! Schema::hasTable('service_health_checks')) {
            return;
        }

        ServiceHealthCheck::updateOrCreate(
            ['service_key' => "market_feed:{$health->provider}:{$health->symbol}:{$health->timeframe}"],
            [
                'service_label' => "Market Feed {$health->symbol} {$health->timeframe}",
                'status' => $health->status === 'lost' ? 'critical' : ($health->status === 'stale' ? 'warning' : 'ok'),
                'health_score' => $health->status === 'ok' ? 100 : ($health->status === 'stale' ? 60 : 0),
                'last_ok_at' => $health->status === 'ok' ? now() : null,
                'last_checked_at' => now(),
                'stale_after_seconds' => $health->stale_after_seconds,
                'message' => $health->message,
                'metrics' => $health->metrics,
            ],
        );
    }

    private function writeStateEvents(?MarketProviderHealth $previous, MarketProviderHealth $health): void
    {
        if ($previous?->status === $health->status) {
            return;
        }

        $eventType = match ($health->status) {
            'ok' => 'provider_recovered',
            'stale' => 'provider_stale',
            default => 'provider_lost',
        };

        $this->foundation->recordEvent([
            'event_type' => $eventType,
            'source_type' => MarketProviderHealth::class,
            'source_id' => $health->id,
            'symbol' => $health->symbol,
            'timeframe' => $health->timeframe,
            'severity' => $health->status === 'lost' ? 'critical' : ($health->status === 'stale' ? 'warning' : 'info'),
            'summary' => "{$health->provider} {$health->symbol} {$health->timeframe} feed status: {$health->status}.",
            'payload' => [
                'previous_status' => $previous?->status,
                'age_seconds' => $health->age_seconds,
            ],
        ]);

        $this->logs->write(
            $eventType,
            "{$health->provider} {$health->symbol} {$health->timeframe} feed status changed to {$health->status}.",
            ['previous_status' => $previous?->status, 'age_seconds' => $health->age_seconds],
            $health->status === 'lost' ? 'critical' : 'info',
            'market_feed',
            $eventType,
            $health->status,
            MarketProviderHealth::class,
            $health->id,
        );
    }

    private function alertLostFeed(MarketProviderHealth $health): void
    {
        if ($health->alert_sent) {
            return;
        }

        $minutes = round($health->age_seconds / 60, 1);
        $message = "[ALERT] {$health->provider} Feed Lost\n\nSymbol: {$health->symbol}\nTimeframe: {$health->timeframe}\nLast candle: ".($health->last_candle_at?->toDateTimeString() ?? 'never')."\nAge: {$minutes} minutes ago.";
        $sent = $this->telegram->send($message);

        $health->update([
            'alert_sent' => true,
            'alert_sent_at' => $sent ? now() : null,
        ]);

        $this->logs->write(
            'telegram_alert',
            $sent ? 'Telegram feed lost alert sent.' : 'Telegram alert skipped or failed.',
            ['symbol' => $health->symbol, 'timeframe' => $health->timeframe, 'sent' => $sent],
            $sent ? 'warning' : 'error',
            'telegram',
            'send_feed_lost_alert',
            $sent ? 'sent' : 'failed',
            MarketProviderHealth::class,
            $health->id,
        );
    }

    private function attemptRecovery(MarketProviderHealth $health): void
    {
        if ($health->auto_recovery_attempted) {
            return;
        }

        $script = (string) config('services.mt5.restart_script');

        if ($script === '') {
            $this->logs->write('auto_recovery_skipped', 'MT5 restart script is not configured.', ['symbol' => $health->symbol, 'timeframe' => $health->timeframe], 'warning', 'mt5', 'restart', 'skipped', MarketProviderHealth::class, $health->id);
            return;
        }

        $command = str_ends_with($script, '.sh') ? ['bash', $script] : [$script];
        $result = Process::timeout((int) config('services.mt5.restart_timeout_seconds', 60))->run($command);

        $health->update([
            'auto_recovery_attempted' => true,
            'auto_recovery_attempted_at' => now(),
        ]);

        $this->logs->write(
            'auto_recovery',
            $result->successful() ? 'MT5 recovery script executed.' : 'MT5 recovery script failed.',
            [
                'script' => $script,
                'command' => $command,
                'exit_code' => $result->exitCode(),
                'output' => $result->output(),
                'error_output' => $result->errorOutput(),
            ],
            $result->successful() ? 'warning' : 'error',
            'mt5',
            'restart',
            $result->successful() ? 'executed' : 'failed',
            MarketProviderHealth::class,
            $health->id,
        );
    }

    private function configuredSymbols(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('services.mt5.symbols', 'XAUUSD,EURUSD')))));
    }

    private function configuredTimeframes(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('services.mt5.timeframes', 'M15,H1')))));
    }
}
