<?php

namespace Tests\Feature;

use App\Support\Nav;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer Dashboard is gone.
 *
 * It duplicated the landing page's own "Book an Appointment" call to action
 * behind a second URL. A customer signing in now lands on `home`.
 *
 * The risk in a removal like this is not the deletion — it is the leftovers. A
 * `route('dashboard')` surviving in a redirect, a layout meta tag or a nav map
 * throws at render time and takes a page down with it; one surviving in a nav
 * item is worse, because it renders a link that 404s. Both are invisible to a
 * test that only checks the happy path, so they are asserted here directly.
 */
class CustomerDashboardRemovalTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ */
    /* The route, controller and view are gone                             */
    /* ------------------------------------------------------------------ */

    public function test_the_dashboard_route_no_longer_exists(): void
    {
        $this->get('/dashboard')->assertNotFound();
    }

    public function test_a_signed_in_customer_asking_for_it_gets_a_404_too(): void
    {
        $this->actingAs($this->makeUser())->get('/dashboard')->assertNotFound();
    }

    public function test_the_controller_and_view_are_deleted(): void
    {
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Customer/DashboardController.php'));
        $this->assertFileDoesNotExist(resource_path('views/customer/dashboard.blade.php'));
    }

    /* ------------------------------------------------------------------ */
    /* Every login lands on the home page                                   */
    /* ------------------------------------------------------------------ */

    public function test_signing_in_lands_on_the_home_page(): void
    {
        $user = $this->makeUser();

        $this->post(route('login.store'), [
            'login' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('home'));

        $this->get(route('home'))->assertOk();
    }

    /**
     * Registering lands on the home page, and actually registers.
     *
     * This used to post no `terms` and no password that satisfied the password
     * rules, and still passed: a rejected POST redirects too, and `back()` with
     * no referer falls back to `/` — which is the home page. So it asserted its
     * own subject without ever creating an account. `terms` and a real password
     * are supplied here, and the assertions prove the redirect came from a
     * registration rather than from a refusal.
     */
    public function test_registering_lands_on_the_home_page(): void
    {
        $this->post(route('register'), [
            'first_name' => 'New',
            'last_name' => 'Customer',
            'email' => 'new.customer@example.test',
            'username' => 'newcustomer',
            'contact_number' => '09171234567',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms' => '1',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'new.customer@example.test']);

        $this->get(route('home'))->assertOk();
    }

    public function test_a_signed_in_customer_is_redirected_off_the_login_screen_to_home(): void
    {
        $this->actingAs($this->makeUser())->get(route('login'))->assertRedirect(route('home'));
    }

    /* ------------------------------------------------------------------ */
    /* Nothing links at it                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * No `route('dashboard')` survives anywhere in the shipped views.
     *
     * Checked as source text across the whole view tree rather than by rendering
     * every page: a leftover call throws when that page is next rendered, which
     * could be months later and on a screen nobody opened today.
     */
    public function test_no_view_still_references_the_removed_route(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (str_contains($source, "route('dashboard')")) {
                $offenders[] = $this->relative($file);
            }
        }

        $this->assertSame([], $offenders, 'These views still call route(\'dashboard\'):');
    }

    /** And nothing links at the literal path either. */
    public function test_no_view_still_hardcodes_the_dashboard_url(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (str_contains($source, "'/dashboard'") || str_contains($source, '"/dashboard"')) {
                $offenders[] = $this->relative($file);
            }
        }

        $this->assertSame([], $offenders, 'These views still hardcode /dashboard:');
    }

    /** The customer nav map has no entry that could light up a removed item. */
    public function test_the_customer_nav_map_has_no_dashboard_key(): void
    {
        $map = (new \ReflectionClass(Nav::class))->getConstant('CUSTOMER');

        $this->assertIsArray($map);
        $this->assertArrayNotHasKey('dashboard', $map);
        $this->assertArrayHasKey('home', $map, 'Home is what a customer lands on now.');
    }

    /** The mobile nav rendered for a signed-in customer offers no Dashboard row. */
    public function test_the_mobile_nav_has_no_dashboard_row(): void
    {
        $html = $this->actingAs($this->makeUser())
            ->get(route('home'))
            ->assertOk()
            ->getContent();

        $mobile = $html;

        if (($start = strpos($html, 'x-show="mobileOpen"')) !== false) {
            $mobile = substr($html, $start);
        }

        $this->assertStringNotContainsString('/dashboard', $mobile);
        $this->assertStringNotContainsString('Dashboard', $mobile);
    }

    /* ------------------------------------------------------------------ */
    /* The session-sync contract now points at the home page               */
    /* ------------------------------------------------------------------ */

    /**
     * `bta-home-web` is where a stale customer tab is sent.
     *
     * It matters more than it looks: the meta tag is what `sessionSync` in
     * resources/js/app.js reads, and if it still named a removed route every
     * cross-tab redirect would send a customer to a 404.
     */
    public function test_the_layouts_send_a_stale_customer_tab_to_the_home_page(): void
    {
        foreach ([route('home'), route('login'), route('login')] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            $this->assertStringContainsString(
                'name="bta-home-web" content="'.route('home').'"',
                $html,
                "{$path} should send a stale customer tab to the home page.",
            );
        }
    }

    /* ------------------------------------------------------------------ */

    /** @return array<int, string> */
    private function bladeFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(resource_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
