<?php

namespace Tests\Feature;

use App\Support\IntendedUrl;
use App\Support\SessionIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * One active role per browser.
 *
 * The scenario this exists for: signed in as a CUSTOMER in Tab A, open Tab B and
 * sign in as ADMIN — Tab A must stop behaving like a customer page, rather than
 * sitting there showing a dashboard with working links until it happens to
 * navigate.
 *
 * Two halves, and this file pins both:
 *
 *   - the server, where a login logs the other guard out, so the browser never
 *     holds two active roles at all; and
 *   - the client, where each page states its identity in a `bta-session` meta tag
 *     and `sessionSync` in `resources/js/app.js` reconciles the other tabs.
 *
 * The client half cannot be exercised by PHPUnit — there is no browser here and
 * no `localStorage`. What is asserted below is its contract: the identity a page
 * publishes, where it sends a tab when the browser disagrees, and that the two
 * guards really are exclusive.
 *
 * The guard-exclusivity tests go through the real login forms rather than
 * `actingAs()`. `actingAs` injects a user straight into the guard without
 * touching the session, so the mutual logout under test would never run — the
 * test would pass for the wrong reason, or fail for one.
 */
class SingleActiveRoleTest extends TestCase
{
    use RefreshDatabase;

    private function unescaped(string $html): string
    {
        return str_replace('\\', '', str_replace('\u0022', '"', $html));
    }

    private function loginAsAdmin($admin): void
    {
        $this->post(route('login.store'), [
            'username' => $admin->username,
            'password' => 'password',
        ])->assertRedirect();
    }

    private function loginAsCustomer($user): void
    {
        $this->post(route('login.store'), [
            'login' => $user->email,
            'password' => 'password',
        ])->assertRedirect();
    }

    /* ------------------------------------------------------------------ */
    /* 1. The server: the guards are exclusive                              */
    /* ------------------------------------------------------------------ */

    /**
     * Signing in as a customer signs the admin out.
     *
     * The two guards share a session cookie, so without this a single browser
     * could hold both at once: the staff panel reachable from a customer
     * session, and the panel's Log Out button leaving the customer signed in.
     */
    public function test_signing_in_as_a_customer_signs_out_the_admin(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser();

        $this->loginAsAdmin($admin);
        $this->assertTrue(auth('admin')->check());

        $this->loginAsCustomer($user);

        $this->assertTrue(auth()->check(), 'The customer should be signed in.');
        $this->assertFalse(auth('admin')->check(), 'The admin guard should have been logged out.');
    }

    public function test_signing_in_as_an_admin_signs_out_the_customer(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser();

        $this->loginAsCustomer($user);
        $this->assertTrue(auth()->check());

        $this->loginAsAdmin($admin);

        $this->assertTrue(auth('admin')->check(), 'The admin should be signed in.');
        $this->assertFalse(auth()->check(), 'The customer guard should have been logged out.');
    }

    /**
     * A panel is unreachable once the other role has taken over, and it says
     * where to go instead.
     *
     * This is what a Tab A ends up doing when it finally navigates — and,
     * with `sessionSync` in place, it is also what the tab redirects to without
     * navigating at all.
     */
    public function test_the_customer_pages_are_closed_once_an_admin_takes_over(): void
    {
        $admin = $this->makeAdmin();

        $this->loginAsAdmin($admin);

        $this->get(route('appointments.index'))->assertRedirect(route('login'));
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
    }

    public function test_the_admin_pages_are_closed_once_a_customer_takes_over(): void
    {
        $user = $this->makeUser();

        $this->loginAsCustomer($user);

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    /* ------------------------------------------------------------------ */
    /* 1b. One guard must not inherit the other's destination               */
    /* ------------------------------------------------------------------ */

    /**
     * Signing in as an admin lands on the panel, even after a guest has been
     * turned away from a customer page.
     *
     * This is the bug that made an admin login look broken. Both guards share one
     * session cookie, so the customer `auth` middleware and the admin login were
     * reading and writing the same `url.intended`. A tab that had been bounced off
     * `/dashboard` — which is what happens as soon as a stale identity drags it
     * there — left `/dashboard` behind, and the admin login dutifully sent the
     * staff member to the customer dashboard. That page belongs to the customer
     * guard, so it redirected straight back to `/login`: a successful sign-in
     * that appeared to do nothing, on a page that could only ever fail.
     *
     * @see \App\Support\IntendedUrl
     */
    public function test_an_admin_login_is_not_sent_to_a_customer_page_a_guest_asked_for(): void
    {
        $admin = $this->makeAdmin();

        // A guest asking for the customer dashboard: remembered, then refused.
        $this->get(route('appointments.index'))->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'username' => $admin->username,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));
    }

    /** The same in the other direction, because the cookie is shared both ways. */
    public function test_a_customer_login_is_not_sent_to_the_admin_panel(): void
    {
        $user = $this->makeUser();

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'login' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('home'));
    }

    /**
     * A customer sign-in honours the customer guard's remembered destination.
     *
     * The destination is scoped, so the admin bounce in the test below cannot
     * move it; and a plain visit to /login still falls back to the home page.
     */
    public function test_a_customer_sign_in_is_returned_to_the_page_it_asked_for(): void
    {
        $user = $this->makeUser();

        $this->get(route('appointments.index'))->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'login' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('appointments.index'));
    }

    /** …and the same bounce does not take the admin to the customer's pages. */
    public function test_a_customer_bounce_does_not_move_the_admin_login(): void
    {
        $admin = $this->makeAdmin();

        $this->get(route('appointments.index'))->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'username' => $admin->username,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));
    }

    /**
     * The admin login still honours a remembered destination.
     *
     * The customer side stopped doing this on purpose; the admin side did not,
     * because a staff member bounced off a panel page and put back on that page
     * is the case the mechanism was built for.
     */
    public function test_an_admin_is_still_returned_to_the_panel_page_it_asked_for(): void
    {
        $admin = $this->makeAdmin();

        $this->get(route('admin.appointments.index'))->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'username' => $admin->username,
            'password' => 'password',
        ])->assertRedirect(route('admin.appointments.index'));
    }

    /**
     * A customer bounce is read once and leaves nothing behind.
     *
     * The customer login clears the remembered key rather than leaving it to
     * expire, so a stale URL cannot sit in the session for the rest of the
     * visit. A later, deliberately bare /login therefore falls back to home.
     */
    public function test_a_customer_bounce_leaves_nothing_behind_for_the_next_login(): void
    {
        $user = $this->makeUser();

        $this->get(route('appointments.index'))->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'login' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('appointments.index'));

        $this->assertNull(session(IntendedUrl::SESSION_KEY.'customer'));

        $this->post(route('logout'));

        $this->post(route('login.store'), [
            'login' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('home'));
    }

    /** The whole reported sequence, in order, with the admin login at the end. */
    public function test_the_reported_customer_tab_to_admin_tab_sequence(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser();

        // Tab A signs in as a customer.
        $this->loginAsCustomer($user);
        $this->get(route('appointments.index'))->assertOk();

        // Tab B opens and finds that same customer session.
        $this->get(route('appointments.index'))->assertOk();

        // Tab B signs out, which leaves the browser a guest.
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->get(route('appointments.index'))->assertRedirect(route('login'));

        // Tab B signs in as an admin — and gets the panel, not the customer
        // dashboard that the guest above was bounced off.
        $this->post(route('login.store'), [
            'username' => $admin->username,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->get(route('admin.dashboard'))->assertOk();
        $this->assertTrue(auth('admin')->check());
        $this->assertFalse(auth()->check());
    }

    /* ------------------------------------------------------------------ */
    /* 2. The client contract: what a page publishes                        */
    /* ------------------------------------------------------------------ */

    /** A customer page says `web:12`; an admin page says `admin:3`. */
    public function test_a_page_publishes_the_identity_it_was_rendered_for(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser();

        $this->loginAsCustomer($user);
        $customer = $this->get(route('appointments.index'))->assertOk()->getContent();
        $this->assertStringContainsString('name="bta-session" content="web:'.$user->id.'"', $customer);

        $this->loginAsAdmin($admin);
        $panel = $this->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('name="bta-session" content="admin:'.$admin->id.'"', $panel);
    }

    public function test_a_signed_out_page_publishes_guest(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('name="bta-session" content="guest"', $html);
    }

    /**
     * Every layout tells `sessionSync` where to send a tab that is now stale.
     *
     * Read from the document rather than hardcoded in the script, so the routing
     * stays in routes/web.php. All three layouts carry the set, because a tab
     * can be sitting on any of them — and a login screen in particular is exactly
     * where somebody lands after the browser's identity changed underneath them.
     */
    public function test_every_layout_publishes_the_destinations_a_stale_tab_needs(): void
    {
        $expected = [
            'bta-home-admin' => route('admin.dashboard'),
            'bta-home-web' => route('home'),
            'bta-login-admin' => route('login'),
            'bta-login-web' => route('login'),
            'bta-session-status' => route('session-status'),
        ];

        $pages = [
            route('home') => $this->get(route('home'))->assertOk()->getContent(),
            route('login') => $this->get(route('login'))->assertOk()->getContent(),
            route('admin.dashboard') => $this->actingAs($this->makeAdmin(), 'admin')
                ->get(route('admin.dashboard'))->assertOk()->getContent(),
        ];

        foreach ($pages as $path => $html) {
            foreach ($expected as $name => $value) {
                $this->assertStringContainsString(
                    'name="'.$name.'" content="'.$value.'"',
                    $html,
                    "{$path} should publish {$name}.",
                );
            }
        }
    }

    /**
     * The sync module is bundled, and it is a plain module rather than an
     * Alpine component.
     *
     * It has to be plain: it has to be listening before anything can change the
     * session, and it owns a `localStorage` key and a `storage` listener that no
     * other component should be able to unbind.
     */
    public function test_the_sync_module_is_bundled_and_standalone(): void
    {
        /*
         * Asserted on the source rather than on the rendered tag: `@vite`
         * resolves to a content-hashed asset name (`app-<hash>.js`), so the
         * literal "app.js" never appears in the HTML and an assertion on it would
         * only be testing the build. What matters is that the module is part of
         * the bundle every layout loads, and that it is standalone.
         */
        foreach (['customer', 'admin', 'auth'] as $layout) {
            $source = (string) file_get_contents(resource_path('views/layouts/'.$layout.'.blade.php'));

            $this->assertStringContainsString(
                "resources/js/app.js",
                $source,
                "The {$layout} layout should load the bundle the sync module ships in.",
            );
        }

        $bundle = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("STORAGE_KEY = 'bta:session'", $bundle);
        $this->assertStringContainsString("addEventListener('storage'", $bundle);

        // The tag names, rather than the selector syntax used to read them: the
        // module reads them through one helper, and pinning the query would be
        // testing that helper rather than the contract.
        $this->assertStringContainsString("'bta-session'", $bundle);

        // Not an Alpine component: it has to be listening before anything can
        // change the session, and it owns a `localStorage` key and a `storage`
        // listener that no other component should be able to unbind.
        $this->assertStringNotContainsString("Alpine.data('sessionSync'", $bundle);
    }

    /**
     * The module announces on load and never navigates on load.
     *
     * This is the reload loop, pinned. The earlier module read the stored value
     * first and, when it disagreed with the page, treated the stored value as the
     * newer truth — navigating to it and returning before writing its own value
     * back. A stale value then survived every page load and dragged the tab to
     * the same URL each time, which is the flicker: a tab that had just logged
     * out was pulled straight back into the page it had left.
     *
     * There is no browser here to observe the loop, so this asserts the two
     * properties that make it impossible — the page's own identity is published
     * unconditionally, and `start()` holds no navigation of its own.
     */
    public function test_the_sync_module_announces_on_load_and_never_navigates_from_it(): void
    {
        $bundle = (string) file_get_contents(resource_path('js/app.js'));

        $start = strstr($bundle, 'function start()');
        $this->assertNotFalse($start, 'The module should have a start function.');

        // The part of `start()` that runs on load, up to the point where it only
        // starts listening. Navigating from a listener is the whole point of the
        // module; navigating during load is what closed the loop.
        $body = substr($start, 0, strpos($start, "addEventListener('storage'"));

        $this->assertStringContainsString(
            'announce(mine)',
            $body,
            'The page must publish its own identity every time it loads.',
        );
        $this->assertStringNotContainsString(
            'goTo(',
            $body,
            'Loading a page must never navigate: that is what closed the loop.',
        );
    }

    /**
     * A tab spends one redirect per identity, and only on another tab's news.
     *
     * The remaining loop vectors were all "navigate to something that leads back
     * here": an unrecognised stored value falling through to `location.href`, and
     * the bfcache probe comparing a bare guard against `admin:3` and so
     * disagreeing with itself on every Back press.
     */
    public function test_the_sync_module_cannot_spend_the_same_redirect_twice(): void
    {
        $bundle = (string) file_get_contents(resource_path('js/app.js'));

        // The per-tab budget. `sessionStorage`, so a sibling tab cannot spend it.
        $this->assertStringContainsString('bta:session-sync', $bundle);
        $this->assertStringContainsString('window.sessionStorage', $bundle);
        $this->assertStringContainsString('attempts.read() === announced', $bundle);

        // An unrecognised value is repaired, never followed — following it used
        // to mean reloading the current URL, which reloads it again, forever.
        $this->assertStringContainsString('isActionable', $bundle);
        $this->assertStringNotContainsString(
            'window.location.href',
            $bundle,
            'A redirect target must never be the page we are already on.',
        );

        // The probe answers with a guard and no id, so it has to be compared on
        // the guard alone.
        $this->assertStringContainsString('guardOnly', $bundle);
    }

    /* ------------------------------------------------------------------ */
    /* 3. The identity key                                                  */
    /* ------------------------------------------------------------------ */

    public function test_the_identity_key_distinguishes_guards_accounts_and_signed_out(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser();

        $this->loginAsCustomer($user);
        $this->assertSame('web:'.$user->id, SessionIdentity::key());
        $this->assertSame(['guard' => 'web', 'id' => $user->id, 'name' => $user->full_name], SessionIdentity::current());

        $this->loginAsAdmin($admin);
        $this->assertSame('admin:'.$admin->id, SessionIdentity::key());

        Auth::guard('admin')->logout();
        Auth::logout();

        $this->assertSame('guest', SessionIdentity::key());
        $this->assertNull(SessionIdentity::current());
    }

    /**
     * The admin identity wins when both guards somehow hold a session.
     *
     * Only reachable with a session cookie minted before the mutual logout, or by
     * two tabs signing in at the same moment. The ordering is written down so the
     * answer is a decision rather than an accident: the page renders as the more
     * privileged of the two, never the less.
     */
    public function test_the_admin_identity_wins_if_both_guards_hold_a_session(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser();

        $this->loginAsCustomer($user);

        // Put the admin back without letting the login handler clear the customer,
        // which is exactly the state this rule exists to describe.
        Auth::guard('admin')->login($admin);

        $this->assertTrue(auth()->check());
        $this->assertTrue(auth('admin')->check());

        $this->assertSame('admin:'.$admin->id, SessionIdentity::key());
    }
}
