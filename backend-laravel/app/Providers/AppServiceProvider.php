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
        LabAgent::observe(LabAgentObserver::class);
    }
}
