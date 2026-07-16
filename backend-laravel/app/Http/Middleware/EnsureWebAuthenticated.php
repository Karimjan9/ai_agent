<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureWebAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('testing') && ! config('services.access_control.enforce_in_tests', false)) {
            return $next($request);
        }
        if (! Auth::check()) return redirect()->guest(route('login'));
        abort_unless((bool) $request->user()?->is_active, 403, 'Account is disabled.');
        return $next($request);
    }
}
