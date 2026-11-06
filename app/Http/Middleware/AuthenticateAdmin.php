<?php

namespace App\Http\Middleware;

use App\Support\IntendedUrl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the whole admin panel. Uses the dedicated `admin` guard so an
 * authenticated customer can never reach these routes.
 */
class AuthenticateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin) {
            // Note where the guest was heading before turning them away, under
            // this guard's own key — see App\Support\IntendedUrl for why the
            // destination has to be scoped rather than shared with the customer
            // guard, which uses the same session cookie.
            IntendedUrl::remember($request, 'admin');

            return redirect()->guest(route('login'));
        }

        if (! $admin->is_active) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'This admin account has been deactivated.']);
        }

        return $next($request);
    }
}
