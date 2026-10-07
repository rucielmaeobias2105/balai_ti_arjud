<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\SalonSetting;
use App\Services\BookingAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Salon Settings screen is gone, and only the screen is gone.
 *
 * This is the half of a removal that is easy to get wrong. The instinct is to
 * delete the feature: the route, the controller, the view, the request, the nav
 * row, the ability — and then, because the screen is gone and nothing references
 * it any more, the model and the table too.
 *
 * That last step would take the booking form with it. `salon_settings` is not a
 * record an admin maintains; it is the operating configuration every booking rule
 * reads — the weekdays the salon opens, the length of a slot, how far ahead
 * bookings open, how much notice they need, and the deposit recorded against them.
 * `SalonConfigurationTest` covers those reads still work. This file covers the
 * other half: that there is genuinely no screen left to find, by any of the ways
 * one could be reached.
 */
class SalonSettingsRemovalTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ */
    /* 1. The screen is unreachable                                        */
    /* ------------------------------------------------------------------ */

    /**
     * A 404 rather than a 500.
     *
     * A route left pointing at a deleted controller throws, which in development is
     * an error page and in production a stack trace naming internal paths. 404 is
     * the honest answer for a screen that is not there.
     */
    public function test_the_settings_page_is_a_clean_404(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->get('/admin/settings')
            ->assertNotFound();
    }

    /** The write path is gone too, not just the form. */
    public function test_the_settings_update_route_is_gone(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->put('/admin/settings', ['slot_interval_minutes' => 15])
            ->assertNotFound();
    }

    public function test_no_route_points_at_the_removed_controller(): void
    {
        $routes = collect(Route::getRoutes())->flatMap(fn ($route) => [
            $route->uri() => $route->getActionName(),
        ]);

        $offenders = $routes->filter(
            fn (string $action) => str_contains($action, 'SalonSettingController')
                || str_contains($action, 'UpdateSalonSettingsRequest')
        );

        $this->assertSame([], $offenders->all(), 'A route still reaches the removed controller.');
    }

    public function test_the_route_names_are_gone(): void
    {
        foreach (['admin.settings.edit', 'admin.settings.update'] as $name) {
            $this->assertFalse(
                Route::has($name),
                "Route [{$name}] should no longer exist.",
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* 2. Nothing advertises it                                            */
    /* ------------------------------------------------------------------ */

    /**
     * No sidebar row.
     *
     * The most visible way to fail this: a nav link to a 404 is a dead click an
     * admin meets on every page load.
     */
    public function test_the_sidebar_has_no_settings_link(): void
    {
        $html = $this->actingAs($this->makeAdmin(), 'admin')
            ->get('/admin')
            ->assertOk()
            ->getContent();

        preg_match('/<nav[^>]*aria-label="Admin">(.*?)<\/nav>/s', $html, $matches);

        $this->assertNotEmpty($matches, 'The admin sidebar nav should be present.');
        $this->assertStringNotContainsString('Salon Settings', $matches[1]);
        $this->assertStringNotContainsString('/admin/settings', $matches[1]);

        // And the whole page, not just the nav — a dead link elsewhere is just as
        // confusing as one in the sidebar.
        $this->assertStringNotContainsString('/admin/settings', $html);
    }

    public function test_the_nav_map_has_no_settings_key(): void
    {
        // `Nav::ADMIN` is a private const, so this goes through the only thing that
        // reads it. Nothing resolves to `settings` any more, which is the point.
        $this->assertNull(\App\Support\Nav::adminCurrent());
    }

    public function test_there_is_no_settings_ability(): void
    {
        // No screen means nothing to gate. Leaving the ability behind would keep a
        // name in the Gate table that no route and no view ever asks for.
        $this->assertNotContains('settings.manage', AdminRole::abilities());
        $this->assertContains('calendar.manage', AdminRole::abilities(), 'The calendar keeps its own.');
    }

    public function test_no_executable_code_still_references_the_screen(): void
    {
        /*
         * Comments are stripped first, deliberately.
         *
         * A removal leaves explanatory comments behind on purpose — "this was the
         * settings screen" is the most useful thing a future reader can find, and
         * `SalonSetting`'s own docblock explains why the row survives without one.
         * A grep that flagged those would push towards deleting the explanations
         * rather than the code.
         *
         * What has to be gone is the executable half: a route name, a controller
         * reference, a view call, a request class. Those are what turn a removed
         * screen into a 500.
         */
        $patterns = [
            'SalonSettingController',
            'UpdateSalonSettingsRequest',
            'admin.settings',
            'admin/settings',
            'salonHoursForm',
            'bta-salon-settings-config',
        ];

        $offenders = [];

        foreach (['app', 'resources/views', 'routes', 'database/seeders'] as $root) {
            foreach ($this->phpFiles(base_path($root)) as $file) {
                $code = $this->stripComments((string) file_get_contents($file));

                foreach ($patterns as $pattern) {
                    if (str_contains($code, $pattern)) {
                        $offenders[] = str_replace(base_path().'\\', '', $file).' → '.$pattern;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Still referencing the removed screen:\n".implode("\n", $offenders));
    }

    /* ------------------------------------------------------------------ */
    /* 3. What survives                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * The row and its columns are all still here.
     *
     * Named one by one, because "the table still exists" is not the same claim as
     * "every column the booking path reads still exists". A migration that dropped
     * `minimum_notice_hours` on the way past would leave a working table and a
     * booking form that could not decide what day it was on.
     */
    public function test_the_configuration_table_and_every_booking_column_survives(): void
    {
        $this->assertTrue(Schema::hasTable('salon_settings'));

        foreach ([
            'operating_hours',
            'slot_interval_minutes',
            'booking_lead_days',
            'minimum_notice_hours',
            'down_payment_required',
            'down_payment_percentage',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('salon_settings', $column),
                "salon_settings.{$column} is read on the booking path and must survive.",
            );
        }
    }

    /** The model, and the accessor every one of those reads goes through. */
    public function test_the_model_still_resolves_the_singleton(): void
    {
        $settings = SalonSetting::current();

        $this->assertInstanceOf(SalonSetting::class, $settings);

        /*
         * A named open day rather than `today()`.
         *
         * The salon closes every Monday, so asking "is it open today?" makes this
         * a test of the calendar rather than of the accessor: it passed on six
         * days a week and failed on the seventh, which says nothing about the
         * settings screen this file is about. Naming the day pins the assertion to
         * what it means — the row exists, and its hours are read off it.
         */
        $this->assertTrue($settings->isOpenOn('2026-10-06'), 'A Tuesday is inside the salon hours.');
        $this->assertSame(['09:00', '17:00'], $settings->hoursFor('2026-10-06'));

        // And a closed day still reads as closed, so the accessor is reading the
        // row rather than answering yes to anything.
        $this->assertFalse($settings->isOpenOn('2026-10-05'), 'A Monday is the standing closure.');
    }

    /**
     * The helpers that had no other caller went with the screen.
     *
     * `closedDays()` and `slotIntervalOptions()` existed to render the settings form
     * — the first to summarise "closed: Sunday" under it, the second to build its
     * slot-length dropdown. Both are gone rather than left as unreferenced public
     * methods, which is how a model slowly becomes a place nobody can reason about.
     */
    public function test_the_helpers_the_settings_form_needed_are_gone(): void
    {
        $this->assertFalse(method_exists(SalonSetting::class, 'closedDays'));
        $this->assertFalse(method_exists(SalonSetting::class, 'slotIntervalOptions'));

        // `dayNames()` stays: the contact page prints the week with it.
        $this->assertTrue(method_exists(SalonSetting::class, 'dayNames'));
    }

    /** The whole booking path still answers, with no screen in front of it. */
    public function test_availability_still_reads_the_configuration(): void
    {
        $this->makeSalonSettings();

        $availability = BookingAvailability::make();

        $this->assertNotSame([], $availability->availableSlots($this->bookableDate()));
        $this->assertTrue($availability->isDateAvailable($this->bookableDate()));
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, string>
     */
    private function phpFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Remove Blade `{{-- --}}`, block and line comments.
     *
     * Not a real parser: the goal is only to stop an explanatory comment from
     * failing an assertion about code, and an over-eager regex that ate a string
     * literal would be a false negative rather than a false positive — the safer
     * direction for a check like this.
     */
    private function stripComments(string $code): string
    {
        $code = preg_replace('/\{\{--.*?--\}\}/s', '', $code) ?? $code;
        $code = preg_replace('#/\*.*?\*/#s', '', $code);
        $code = preg_replace('#(^|\s)//[^\n]*#m', '$1', $code) ?? $code;

        return $code;
    }
}