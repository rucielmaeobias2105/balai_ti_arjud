<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Guards the admin role model.
 *
 * The salon has three kinds of visitor — admin, customer and guest — and only
 * one admin role, `admin`, holding every ability. There is deliberately no
 * manager or staff tier. `AdminRole::abilities()` still drives the
 * `admin.role:*` middleware on the routes and the `@can` directives in the
 * views, so these tests assert the server-side half and the page half together:
 * if a future change narrows a role again, the ability table and the screens an
 * admin can open have to move with it.
 */
class AdminRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeSalonSettings();
    }

    private function admin(): Admin
    {
        return Admin::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => AdminRole::Admin,
            'is_active' => true,
        ]);
    }

    public function test_admin_is_the_only_role_and_it_holds_every_ability(): void
    {
        $this->assertSame(
            [AdminRole::Admin],
            AdminRole::cases(),
            'The panel should expose exactly one admin role: no manager, no staff.',
        );

        $this->assertSame(['admin' => 'Administrator'], AdminRole::options());
        $this->assertSame(['admin'], AdminRole::values());

        foreach (AdminRole::abilities() as $ability) {
            $this->assertTrue(
                AdminRole::Admin->can($ability),
                'The admin role should hold the "'.$ability.'" ability.',
            );
        }
    }

    /**
     * Removed features take their abilities with them.
     *
     * `abilities()` is the single source the `admin.role:*` middleware and the
     * `@can` directives both read, so a leftover `admin.reviews.*` or
     * `admin.calendar.*` entry would mint a Gate that no route enforces and no
     * screen checks — invisible, but still a claim that the panel can moderate
     * reviews or block dates.
     */
    public function test_removed_features_leave_no_abilities_behind(): void
    {
        $abilities = AdminRole::abilities();

        foreach ([
            'admin.reviews.view',
            'admin.reviews.manage',
            'admin.calendar.view',
            'admin.calendar.manage',
        ] as $gone) {
            $this->assertNotContains($gone, $abilities, $gone.' should have been removed with the feature.');
        }
    }

    /**
     * Every screen the panel still has.
     *
     * Calendar is the newest of them, guarded by `admin.calendar.manage`;
     * `BlockedSlotsTest` asserts what it does and `SalonSettingsRemovalTest` covers
     * the recurring schedule that sits behind it. This loop only proves each screen
     * is reachable.
     */
    public function test_an_admin_reaches_every_admin_screen(): void
    {
        $admin = $this->admin();

        foreach (['/', 'appointments', 'catalog', 'services', 'technicians', 'inventory', 'tags', 'users', 'terms', 'reports', 'promos', 'messages'] as $path) {
            $this->actingAs($admin, 'admin')
                ->get('/admin/'.$path)
                ->assertOk();
        }
    }

    public function test_an_admin_can_edit_legal_text(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get('/admin/terms')->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin/terms/create')->assertOk();
    }

    /**
     * The panel can delete a customer account, and can do nothing else to one.
 *
     * The delete is the one addition, and it was decided rather than incidental:
 * an admin can remove an account, but it is an anonymise-and-soft-delete rather
 * than a cascade — see `App\Services\UserAnonymizer` and
     * `AdminDeleteUserTest`, which covers the behaviour in detail.
 *
 * Everything else this test used to assert still holds: there is no *status*
 * route, so a live account cannot be switched off from this screen and only
 * removed; and there is still no `users.manage` ability, so nothing about this
 * screen depends on a second tier.
 */
public function test_the_admin_role_can_delete_a_customer_record_and_nothing_else(): void
    {
        $admin = $this->admin();
        $user = $this->makeUser();

        // Viewing is unchanged.
        $this->actingAs($admin, 'admin')
            ->get(route('admin.users.show', $user))
            ->assertOk();

        // Deactivation still has no ability and no route: deleting is the only
        // write, so there is nothing to gate separately.
        foreach (['users.delete', 'users.manage', 'users.status'] as $ability) {
            $this->assertNotContains($ability, AdminRole::abilities());
            $this->assertFalse(AdminRole::Admin->can('admin.'.$ability));
        }

        $this->assertFalse(Route::has('admin.users.status'), 'There is no status route.');

        // The delete exists, and is gated on the same ability as the screen.
        $this->assertTrue(Route::has('admin.users.destroy'));

        foreach (['admin.users.index', 'admin.users.show', 'admin.users.destroy'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "{$name} should exist.");
            $this->assertContains(
                'admin.role:admin.users.view',
                $route->gatherMiddleware(),
                "{$name} should be gated on the users ability.",
            );
        }

        $this->assertTrue($user->fresh()->is_active);
        $this->assertNotSoftDeleted($user);
    }

    public function test_an_admin_reaches_every_write_route(): void
    {
        $admin = $this->admin();
        $service = $this->makeService();
        $user = $this->makeUser();

        $this->actingAs($admin, 'admin')->get('/admin/services/create')->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin/technicians/create')->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin/inventory/create')->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin/terms/create')->assertOk();

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.services.destroy', $service))
            ->assertRedirect();

        $this->assertSoftDeleted($service);
        $this->assertNotSoftDeleted($user);
    }

    public function test_an_admin_can_work_the_appointment_queue(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get('/admin/appointments')
            ->assertOk();
    }

    public function test_the_sidebar_shows_every_screen(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->getContent();

        // Narrow to the nav itself: the dashboard body links to some of these
        // URLs too, so asserting on the whole page would pass even if the
        // sidebar rendered nothing at all.
        preg_match('/<nav[^>]*aria-label="Admin">(.*?)<\/nav>/s', $html, $matches);
        $this->assertNotEmpty($matches, 'The admin sidebar nav should be present.');

        $nav = $matches[1];

        // Calendar is here. Salon Settings is not: the recurring schedule is
        // configuration in `salon_settings` rather than a record, and it has no
        // admin screen — see `SalonSettingsRemovalTest`, which also covers the
        // Calendar view's link to it having been a dead one.
        //
        // Messages is still absent: it was here for the sidebar badge, which was
        // the only place the panel surfaced unread contact enquiries once the
        // topbar bell went. Both the row and the badge are gone, so the screen is
        // reachable by URL and advertised nowhere —
        // `test_the_sidebar_offers_no_link_that_would_403` is what stops that
        // from turning into a dead end.
        foreach ([
            'Dashboard', 'Appointments', 'Services', 'Categories', 'Technicians', 'Promo',
            'Inventory', 'Registered Users', 'Terms &amp; Conditions', 'Reports', 'Calendar',
        ] as $label) {
            $this->assertStringContainsString($label, $nav);
        }

        $this->assertStringNotContainsString('Salon Settings', $nav);
        $this->assertStringNotContainsString('/admin/settings', $nav);
        $this->assertStringNotContainsString('Messages', $nav, 'Messages is off the admin nav.');

        // The Low-Stock Tags screen was dropped from the nav; the stock warning
        // it carried lives on the Inventory badge instead.
        $this->assertStringNotContainsString('Low-Stock Tags', $nav);

        // "Services & Items" was the combined catalogue overview; the three
        // catalogue screens each have their own entry now, so the overview link
        // would only be a fourth way into the same records.
        $this->assertStringNotContainsString('Services &amp; Items', $nav);
    }

    public function test_the_sidebar_offers_no_link_that_would_403(): void
    {
        $admin = $this->admin();
        $service = $this->makeService();
        $user = $this->makeUser();

        $services = $this->actingAs($admin, 'admin')->get('/admin/services')->assertOk()->getContent();
        $this->assertStringContainsString(route('admin.services.create'), $services);
        $this->assertStringContainsString(route('admin.services.edit', $service), $services);
        $this->assertStringContainsString(route('admin.services.destroy', $service), $services);

        $inventory = $this->actingAs($admin, 'admin')->get('/admin/inventory')->assertOk()->getContent();
        $this->assertStringContainsString(route('admin.inventory.create'), $inventory);

        $users = $this->actingAs($admin, 'admin')->get('/admin/users')->assertOk()->getContent();
        $this->assertStringContainsString(route('admin.users.show', $user), $users);
    }

    public function test_the_role_cannot_be_edited_from_the_profile_form(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($admin, 'admin')->get('/admin/profile')->assertOk()->getContent();
        $this->assertStringNotContainsString('name="role"', $html);
        $this->assertStringContainsString('Administrator', $html);

        // A hand-rolled POST is ignored rather than trusted.
        $this->actingAs($admin, 'admin')
            ->patch(route('admin.profile.update'), [
                'first_name' => 'Test',
                'last_name' => 'Admin',
                'email' => 'admin@example.test',
                'username' => 'admin',
                'role' => 'super_admin',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(AdminRole::Admin, $admin->fresh()->role);
    }

    public function test_a_customer_session_never_reaches_the_admin_panel(): void
    {
        $customer = $this->makeUser();

        $this->actingAs($customer)
            ->get('/admin')
            ->assertRedirect(route('admin.login'));
    }
}
