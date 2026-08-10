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
        $critical = $checks->where('status', 'critical')->count();
        $warning = $checks->where('status', 'warning')->count();

        $this->info("System health checked: {$checks->count()} services, {$critical} critical, {$warning} warning.");

        if ($critical > 0 && Cache::add('alerts:system-health-critical', true, now()->addHour())) {
            $details = $checks->where('status', 'critical')->map(fn ($check) => "{$check->service_label}: {$check->message}")->implode("\n");
            $telegram->send("[CRITICAL] NeuroTrader health\n\n{$details}");
        }

        return $this->option('strict') && $critical > 0 ? self::FAILURE : self::SUCCESS;
    }
}
