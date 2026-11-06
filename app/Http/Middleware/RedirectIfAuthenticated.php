<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        // The two guards share one session cookie, so this check has to look at
        // both. Only testing `web` let a signed-in admin open the login form at
        // /login even though they were already authenticated on the admin guard.
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        if (Auth::guard('web')->check()) {
            return redirect()->route('home');
        }

        return $next($request);
    }
}
