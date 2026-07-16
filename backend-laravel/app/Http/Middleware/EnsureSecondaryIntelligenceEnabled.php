<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSecondaryIntelligenceEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('testing') && ! config('services.access_control.enforce_in_tests', false)) {
            return $next($request);
        }
        abort_unless(
            (bool) config('services.secondary_intelligence.enabled', false),
            503,
            'Secondary intelligence modules are frozen until P0 evidence gates pass.',
        );

        return $next($request);
    }
}
