<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Admin;
use App\Models\User;
use App\Support\IntendedUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Unified Login Entry Point (Customer & Admin).
 *
 * `/login` is the only login form and this is its only authentication handler.
 * `/admin/login` is a legacy redirect to it. The role is decided only after
 * credentials are verified:
 *   - Admin account (`admins` table) -> `admin` guard -> `admin.dashboard`
 *   - Customer account (`users` table) -> `web` guard -> `home`
 *
 * Invalid or inactive credentials return a single generic "Invalid credentials"
 * error without leaking whether an account belongs to an admin or customer.
 */
class AuthenticatedSessionController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $this->ensureIsNotRateLimited($request);

        $login = $request->string('login')->toString();
        $password = $request->string('password')->toString();

        $admin = Admin::query()
            ->whereRaw('LOWER(username) = ?', [$login])
            ->orWhereRaw('LOWER(email) = ?', [$login])
            ->first();

        if ($admin && Hash::check($password, $admin->password)) {
            if (! $admin->is_active) {
                RateLimiter::hit($this->throttleKey($request), self::DECAY_SECONDS);

                throw ValidationException::withMessages($this->errorMessages($request, 'Invalid credentials.'));
            }

            RateLimiter::clear($this->throttleKey($request));

            // Maintain session isolation: one active role per browser.
            Auth::guard('web')->logout();

            Auth::guard('admin')->login($admin, $request->boolean('remember'));
            $request->session()->regenerate();

            $admin->forceFill(['last_login_at' => now()])->saveQuietly();

            IntendedUrl::forget($request, 'customer');

            return redirect()->to(IntendedUrl::pull($request, 'admin', route('admin.dashboard')));
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$login])
            ->orWhereRaw('LOWER(username) = ?', [$login])
            ->first();

        if (! $user || ! Hash::check($password, $user->password) || ! $user->is_active) {
            RateLimiter::hit($this->throttleKey($request), self::DECAY_SECONDS);

            throw ValidationException::withMessages($this->errorMessages($request, 'Invalid credentials.'));
        }

        RateLimiter::clear($this->throttleKey($request));

        // One account at a time in this browser.
        //
        // The customer and admin guards share a session cookie, so signing in
        // here while signed in there left both guards authenticated at once —
        // the panel was reachable from a customer session and vice versa, and
        // the "Log Out" button in one panel left the other still signed in.
        // Logging the other guard out is what makes "one active role per
        // browser" true on the server; `sessionSync` in `resources/js/app.js`
        // is what makes the other tabs in this browser notice.
        Auth::guard('admin')->logout();

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        // Both roles now honour the destination their own guard remembered. A
        // customer bounced off /appointments is returned there; one who simply
        // opened /login still lands on home as the fallback.
        return redirect()->to(IntendedUrl::pull($request, 'customer', route('home')));
    }

    /**
     * Sign out (shared across customer and admin roles; always redirects to /login).
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->uncacheable(redirect()->route('login'));
    }

    /**
     * Stamp no-store headers onto a response the browser must not keep.
     *
     * @see \App\Http\Middleware\PreventAuthenticatedCaching for why these four
     *      headers rather than just `Cache-Control: no-store`.
     */
    protected function uncacheable(RedirectResponse $response): RedirectResponse
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', 'Wed, 11 Jan 1984 05:00:00 GMT');

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function errorMessages(LoginRequest $request, string $message): array
    {
        $messages = ['login' => $message];

        if ($request->has('username')) {
            $messages['username'] = $message;
        }

        return $messages;
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(LoginRequest $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages(
            $this->errorMessages($request, "Too many login attempts. Please try again in {$seconds} seconds.")
        );
    }

    private function throttleKey(LoginRequest $request): string
    {
        return Str::transliterate(Str::lower($request->string('login')->toString()).'|'.$request->ip());
    }
}
