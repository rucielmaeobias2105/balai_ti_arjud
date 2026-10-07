<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
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
 * Customer Flow 2 — Login.
 *
 * The field is labelled "Username or Email" per the mockup and matches either
 * column. Attempts are rate limited per identifier + IP.
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

        $user = User::query()
            ->where('email', $login)
            ->orWhere('username', $login)
            ->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            RateLimiter::hit($this->throttleKey($request), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'login' => 'These credentials do not match our records.',
            ]);
        }

        if (! $user->is_active) {
            RateLimiter::hit($this->throttleKey($request), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'login' => 'Your account has been deactivated. Please contact us.',
            ]);
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

        /*
         * Always the home page, and the remembered destination is discarded.
         *
         * This used to be `IntendedUrl::pull($request, 'customer', route('home'))`,
         * which sent a customer who had been bounced off a guarded page back to
         * that page. In practice the page they were bounced off most often was
         * My Appointments — it is in the customer navigation, so it is where
         * people click, and it is guarded — so signing in appeared to dump
         * everybody on the appointments list whether they wanted it or not.
         *
         * The home page is the only landing that is right for both cases: the
         * customer who came to sign in, and the customer who was interrupted
         * halfway to something. The cost is that a customer sent to the login
         * screen by clicking "Book Appointment" now arrives at the home page
         * rather than back at the booking form, and has to click through again.
         * That is the trade this makes, deliberately, and it is why the
         * remembered URL is cleared rather than left to expire.
         *
         * The admin login is untouched: staff signing in after being bounced off
         * a panel page still go back to it, which is the case the mechanism was
         * built for and has no equivalent complaint attached to it.
         */
        IntendedUrl::forget($request, 'customer');

        return redirect()->route('home');
    }

    /**
     * Sign out.
     *
     * Three things happen here, and each covers a different way a signed-out
     * browser could still be showing a customer's page:
     *
     *   1. the guard is logged out and the session invalidated, so the *next*
     *      request for a protected page redirects to the login screen;
     *   2. this response is marked uncacheable, so the browser does not keep the
     *      logout redirect around either; and
     *   3. `sessionSync` in `resources/js/app.js` announces `guest` from the
     *      login page this redirects to, which is what takes any *other* tab
     *      still showing a customer page to the login screen.
     *
     * The `PreventAuthenticatedCaching` middleware covers (2) for every
     * authenticated response; it is repeated here because `/logout` is behind
     * `auth` on the way in but is not itself an authenticated page — it is the
     * boundary between the two states, so its own response must not be a
     * candidate for the Back button either.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->uncacheable(redirect()->route('home'));
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
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(LoginRequest $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'login' => "Too many login attempts. Please try again in {$seconds} seconds.",
        ]);
    }

    private function throttleKey(LoginRequest $request): string
    {
        return Str::transliterate(Str::lower($request->string('login')->toString()).'|'.$request->ip());
    }
}
