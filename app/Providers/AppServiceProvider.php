<?php

namespace App\Providers;

use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
     *
     * Every entry in AdminRole::abilities() becomes an `admin.<ability>` Gate.
     * One definition then drives both halves of enforcement: the
     * `admin.role:*` middleware on the admin routes and the `@can` directives
     * in the views, so a button is never shown to a role the server would
     * reject anyway.
     */
    public function boot(): void
    {
        foreach (AdminRole::abilities() as $ability) {
            // The parameter is untyped and defaults to null on purpose. Gate
            // passes the *default* guard's user as the first argument, and the
            // default guard here is the customer `web` guard — so as soon as
            // somebody is signed in as a customer, that argument is a User, and
            // a `?Admin` hint would fatal with a TypeError before this body
            // ever ran. Keeping it untyped means Gate can still evaluate the
            // gate for a guest, and the decision reads the guard that actually
            // owns the panel.
            Gate::define('admin.'.$ability, function ($admin = null) use ($ability): bool {
                $admin = Auth::guard('admin')->user();

                return $admin instanceof Admin && $admin->role->can($ability);
            });
        }

        $this->registerBookingRateLimiter();
    }

    /**
     * Rate limit for `POST /book`.
     *
     * Every other write route in the app is throttled — contact at 12/min, login at
     * 10/min, password reset at 6/min — and the booking form was the one that was
     * not. It is the most expensive write in the product: it validates a basket,
     * opens a transaction, writes an appointment and its service lines, decrements
     * linked stock and sends a notification. Being able to fire it as fast as the
     * network allows is not something to leave open.
     *
     * Twelve a minute rather than one. A stricter limit reads as "you are doing
     * something wrong" to a customer who submitted once, pressed Back, and
     * submitted again — and the duplicate this is really guarding against is
     * handled properly by the idempotency key, which identifies the *same*
     * attempt. The limiter's job is the different one: stopping a script or a
     * stuck retry loop from hammering the endpoint. Twelve a minute is roughly one
     * every five seconds sustained, which no human booking a treatment produces and
     * a loop cannot sustain quietly.
     *
     * Keyed by account, falling back to the session for an unauthenticated caller,
     * so the limit is per person rather than per IP — a shared office connection
     * should not have two staff on it rate-limited against each other, and one
     * customer behind a rotating IP should not get a fresh budget on every request.
     */
    protected function registerBookingRateLimiter(): void
    {
        RateLimiter::for('booking', function (Request $request) {
            $key = $request->user()?->getAuthIdentifier()
                ?? $request->session()->getId();

            return Limit::perMinute(12)->by('booking:'.$key);
        });
    }
}
