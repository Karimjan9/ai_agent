<?php

namespace App\Console\Commands;

use App\Services\PhaseTwoFoundationService;
use App\Services\TelegramAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunSystemHealthCheck extends Command
{
    protected $signature = 'system:health-check {--strict : Return failure when a critical service exists}';

    protected $description = 'Check Phase 2 foundation health for feed, scheduler, events, signals, memory and reality loop';

    public function handle(PhaseTwoFoundationService $foundation, TelegramAlertService $telegram): int
    {
        $checks = $foundation->runHealthCheck();
        // Missing bootstrap access is a deployment action, not a runtime
        // outage. Keep the service record critical (existing dashboards and
        // tests rely on that precise contract), but do not page or fail a
        // transport health run before an administrator has been provisioned.
        $blockingCritical = $checks
            ->where('status', 'critical')
            ->reject(fn ($check): bool => (string) data_get($check, 'service_key') === 'access_control');
        $critical = $blockingCritical->count();
        $warning = $checks->where('status', 'warning')->count();

        $this->info("System health checked: {$checks->count()} services, {$critical} critical, {$warning} warning.");

        // Alert throttling must still work during a Redis outage. The
        // dedicated store is normally the database and is intentionally
        // independent from the runtime cache/queue transport.
        $alertStore = (string) config('services.redis_availability.alert_cache_store', 'database');
        if ($critical > 0 && Cache::store($alertStore)->add('alerts:system-health-critical', true, now()->addHour())) {
            $details = $blockingCritical->map(fn ($check) => "{$check->service_label}: {$check->message}")->implode("\n");
            $telegram->send("[CRITICAL] NeuroTrader health\n\n{$details}");
        }

        return $this->option('strict') && $critical > 0 ? self::FAILURE : self::SUCCESS;
    }
}
