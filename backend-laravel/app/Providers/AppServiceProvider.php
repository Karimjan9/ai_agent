<?php

namespace App\Providers;

use App\Models\LabAgent;
use App\Observers\LabAgentObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole() && in_array($_SERVER['argv'][1] ?? '', ['queue:work', 'queue:listen'], true)) {
            $this->app->instance('research.worker_boot_source_hash', app(\App\Services\LabImmutableEvidenceService::class)->codeHash());
        }
        LabAgent::observe(LabAgentObserver::class);
    }
}
