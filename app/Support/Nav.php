<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Resolves which nav item should render as "active" from the current route.
 * Keeps the active-state logic out of the Blade partials.
 *
 * The customer map has no `dashboard` entry: the customer Dashboard was removed,
 * and `home` is what a signed-in customer lands on now.
 */
class Nav
{
    /**
     * Customer nav keys, matched against the active route name prefix.
     *
     * @var array<string, string>
     */
    protected const CUSTOMER = [
        'services.index' => 'services',
        'services.refined' => 'refined',
        'appointments.*' => 'appointments',
        'notifications.*' => 'notifications',
        'profile.*' => 'profile',
        'contact.*' => 'contact',
        'about' => 'about',
        'promos.index' => 'promos',
        'home' => 'home',
    ];

    /**
     * Admin nav keys.
     *
     * @var array<string, string>
     */
    protected const ADMIN = [
        'admin.dashboard' => 'dashboard',
        // Before the `admin.appointments.*` wildcard, or the archive would light
        // up "Appointments" instead of its own row. Exact matches are checked
        // first by `match()`, but the wildcard is evaluated in declaration
        // order, so the specific key has to come first here too.
        'admin.appointments.archived' => 'archived-appointments',
        'admin.appointments.*' => 'appointments',
        'admin.catalog.*' => 'catalog',
        'admin.services.*' => 'services',
        'admin.categories.*' => 'categories',
        'admin.technicians.*' => 'technicians',
        'admin.inventory.*' => 'inventory',
        'admin.tags.*' => 'tags',
        'admin.users.*' => 'users',
        'admin.terms.*' => 'terms',
        'admin.reports.*' => 'reports',
        'admin.promos.*' => 'promos',
        'admin.messages.*' => 'messages',
        // The one-off closures an admin adds and removes. The recurring schedule
        // has no screen and so no nav key.
        'admin.calendar.*' => 'calendar',
    ];

    public static function customerCurrent(): ?string
    {
        return static::match(Route::current()?->getName(), self::CUSTOMER);
    }

    public static function adminCurrent(): ?string
    {
        return static::match(Route::current()?->getName(), self::ADMIN);
    }

    /**
     * @param  array<string, string>  $map
     */
    protected static function match(?string $routeName, array $map): ?string
    {
        if ($routeName === null) {
            return null;
        }

        // Exact matches win over wildcards.
        foreach ($map as $pattern => $key) {
            if ($pattern === $routeName) {
                return $key;
            }
        }

        foreach ($map as $pattern => $key) {
            if (str_ends_with($pattern, '.*') && str_starts_with($routeName, substr($pattern, 0, -1))) {
                return $key;
            }
        }

        return null;
    }
}
