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
            $bootHash = app(\App\Services\LabImmutableEvidenceService::class)->codeHash();
            $this->app->instance('research.worker_boot_source_hash', $bootHash);
            // Expose the existing boot binding for cold-rollout verification.
            // This observation grants no replay or scientific authority.
            \Illuminate\Support\Facades\Cache::put('runtime:research-worker-boot:'.getmypid(), [
                'protocol' => 'research_php_worker_boot_observation_v1',
                'pid' => getmypid(), 'boot_source_hash' => $bootHash,
                'observed_at' => gmdate('c'), 'scientific_evidence' => false,
                'promotion_evidence' => false,
            ], 86400);
        }
        LabAgent::observe(LabAgentObserver::class);
    }
}
