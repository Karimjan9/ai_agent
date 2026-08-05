<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateInternalApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('testing') && ! config('services.access_control.enforce_in_tests', false)) {
            return $next($request);
        }
        $expected = (string) config('services.internal_api.token');
        abort_if($expected === '', 503, 'Internal API token is not configured.');
        $provided = (string) ($request->header('X-Internal-Token') ?: $request->bearerToken());
        abort_unless(hash_equals($expected, $provided), 401, 'Invalid internal API token.');
        return $next($request);
    }
}
   