<?php

namespace App\Enums;

/**
 * The salon has exactly three kinds of visitor, and only one of them is an
 * admin: `admin` (this enum, the `admins` table), `customer` (the `users`
 * table, which carries no role column) and `guest` (nobody signed in).
 *
 * There is deliberately no manager or staff tier. The panel used to split into
 * Super Admin / Manager / Staff, but the salon runs it as one account, so the
 * extra tiers only added ways to lock a real user out of a screen they need.
 * `AdminRole` therefore keeps its name and its ability table — the `admin.role:*`
 * middleware and the `@can` directives still read from it, so authorisation
 * stays server-side — but there is a single case holding every ability.
 */
enum AdminRole: string
{
    case Admin = 'admin';

    public function label(): string
    {
        return 'Administrator';
    }

    /**
     * Every gated ability in the admin panel.
     *
     * The list is the single source of truth: AppServiceProvider turns each
     * entry into an `admin.<ability>` Gate, which the routes enforce through
     * the `can:` middleware and the views use through `@can`. A read-only
     * `*.view` twin exists wherever a role could otherwise see a screen but
     * not change it.
     *
     * @return array<int, string>
     */
    public static function abilities(): array
    {
        return [
            'dashboard.view',
            'appointments.manage',
            'catalog.view',
            'catalog.manage',
            'technicians.view',
            'technicians.manage',
            'inventory.view',
            'inventory.manage',
            'tags.manage',
            'users.view',
            'terms.view',
            'terms.manage',
            'reports.view',
            'promos.manage',
            'messages.manage',
            /*
             * One ability, for the calendar of one-off closures.
             *
             * No `*.view` twin, unlike the catalogue and the technician roster.
             * Those have one because they are lists with a natural read-only
             * reading — an admin can be shown the price list without being able to
             * change it. The calendar is not that: every action on it is an edit,
             * so a read-only version would be a screen an admin could look at and
             * not act on, which is the situation the split exists to avoid.
             *
             * There is deliberately no settings ability. The recurring schedule is
             * configuration in `salon_settings`, read by `BookingAvailability` on
             * every request; it has no admin screen, so there is nothing for an
             * ability to gate.
             */
            'calendar.manage',
        ];
    }

    /**
     * Abilities held by the role. With one role this is every ability, and it
     * stays a method rather than an inline `return self::abilities()` so a
     * future tier has an obvious place to land.
     *
     * @return array<int, string>
     */
    public static function abilitiesFor(self $role): array
    {
        return self::abilities();
    }

    public function can(string $ability): bool
    {
        return in_array($ability, self::abilitiesFor($this), true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
