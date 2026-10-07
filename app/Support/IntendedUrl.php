<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Where a login should send the browser afterwards — kept per guard.
 *
 * Laravel remembers where a guest was trying to go, so a login can put them back
 * there. It stores it in one session key, `url.intended`, written by whichever
 * guard's `auth` middleware happened to bounce the request:
 *
 *   - a signed-out customer asking for `/appointments` puts `url.intended` =
 *     `/appointments`; then
 *   - `redirect()->intended(route('admin.dashboard'))` in the admin login reads
 *     that same key and sends the staff member to the *customer* appointments page.
 *
 * The customer and admin guards share one session cookie (see config/auth.php),
 * so one session holds one `url.intended` and the two logins keep stealing each
 * other's. The symptom is an admin who signs in correctly and lands on
 * `/appointments`, which then bounces to `/login` because the customer guard is
 * not signed in — so the login appears to do nothing at all.
 *
 * The fix is to stop sharing the key. Each guard remembers its own destination
 * under a scoped key, and each login only ever reads its own:
 *
 *   - App\Http\Middleware\RememberIntendedUrl writes it, on the authenticated
 *     route groups, before `auth` has a chance to redirect; and
 *   - `pull()` below reads and clears it, falling back to the guard's own home.
 *
 * The framework's unscoped `url.intended` is left alone rather than fought over.
 * Nothing in this application reads it any more — both logins go through `pull()`
 * — and it is still written by `Authenticate` because that is what the framework
 * does; there is no per-guard seam to stop it at.
 */
class IntendedUrl
{
    /**
     * Session keys are `bta.intended.<scope>`.
     *
     * Deliberately not nested under `url.intended`. Laravel stores its own key as
     * a plain string at that path, and `Arr::set()` on `url.intended.customer`
     * would replace that string with an array — so the framework's
     * `redirect()->guest()`, which runs *after* this middleware, silently
     * destroys the scoped key on every guest request. A sibling top-level prefix
     * cannot be clobbered that way.
     */
    public const SESSION_KEY = 'bta.intended.';

    /**
     * The guard names a scope signs in on.
     *
     * Scopes are named for the *area* rather than the guard because that is what
     * a caller reasons about: `intended.url:customer` reads correctly on a route
     * group, where `intended.url:web` would not.
     */
    public static function guardFor(string $scope): string
    {
        return $scope === 'admin' ? 'admin' : 'web';
    }

    /**
     * Note where this guard's user was trying to go.
     *
     * Called before the guard's `auth` middleware runs, so it happens on the way
     * to the redirect rather than after it.
     */
    public static function remember(Request $request, string $scope): void
    {
        $request->session()->put(static::SESSION_KEY.$scope, $request->fullUrl());
    }

    /**
     * Read and clear this guard's remembered destination.
     *
     * Cleared on read, so a URL is only ever "intended" once: without that, a
     * later login would be sent to a page from an earlier visit that has since
     * been abandoned.
     *
     * The remembered value is only honoured when it is a URL on this application.
     * It is written from `$request->fullUrl()`, so it cannot be off-site as things
     * stand — but it is a session value, and a remembered destination is exactly
     * the kind of thing that becomes an open redirect the moment anything else can
     * write to the session.
     */
    public static function pull(Request $request, string $scope, string $fallback): string
    {
        $intended = $request->session()->pull(static::SESSION_KEY.$scope);

        if (! is_string($intended) || $intended === '') {
            return $fallback;
        }

        return str_starts_with($intended, $request->getSchemeAndHttpHost())
            ? $intended
            : $fallback;
    }

    /**
     * Throw a remembered destination away without reading it.
     *
     * For the guard whose login no longer honours one. The key is still
     * cleared, deliberately: leaving it in the session would keep a stale URL
     * sitting there for the rest of the visit, and would mean the behaviour came
     * back the moment anything started reading this key again.
     *
     * @see \App\Http\Controllers\Auth\AuthenticatedSessionController for why the
     *       customer login lands on the home page rather than where the guest was
     *       going.
     */
    public static function forget(Request $request, string $scope): void
    {
        $request->session()->forget(static::SESSION_KEY.$scope);
    }
}
