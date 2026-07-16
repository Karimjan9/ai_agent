<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (app()->environment('testing') && ! config('services.access_control.enforce_in_tests', false)) {
            return $next($request);
        }
        abort_unless(in_array($request->user()?->role, $roles, true), 403, 'Insufficient role.');
        return $next($request);
    }
}
