<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Signing out must actually end the session in the browser, not just in the
 * database.
 *
 * The failure this guards against is specific and was real: press Back after a
 * logout and the browser redraws the authenticated page it had cached, with a
 * signed-in navbar and working links, until something happens to re-request it.
 * The server side of the fix is `PreventAuthenticatedCaching`; the client side is
 * `sessionSync`'s `pageshow` handler, which asks `/session-status` whether this
 * browser still holds a session.
 *
 * Both halves are asserted here. The headers prove nothing is stored for the Back
 * button to redraw, and the probe proves that a page restored from the cache has
 * something to ask.
 */
class LogoutCacheControlTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ */
    /* 1. Nothing authenticated is ever stored                             */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, array{0: string}>
     */
    public static function protectedCustomerPages(): array
    {
        return [
            'appointments' => ['/appointments'],
            'book' => ['/book'],
            'notifications' => ['/notifications'],
            'profile' => ['/profile'],
        ];
    }

    /** @dataProvider protectedCustomerPages */
    public function test_customer_pages_are_marked_uncacheable(string $path): void
    {
        $url = $path === '/book' ? $this->bookingUrl() : $path;
        $response = $this->actingAs($this->makeUser())->get($url)->assertOk();

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control'),
            "{$path} must not be stored by the browser.",
        );
    }

    /** @dataProvider protectedCustomerPages */
    public function test_customer_pages_send_the_pragma_and_expires_backstops(string $path): void
    {
        $url = $path === '/book' ? $this->bookingUrl() : $path;
        $response = $this->actingAs($this->makeUser())->get($url)->assertOk();

        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('Wed, 11 Jan 1984 05:00:00 GMT', $response->headers->get('Expires'));
    }

    public function test_admin_pages_are_marked_uncacheable(): void
    {
        $admin = $this->makeAdmin();

        foreach (['/admin', '/admin/appointments', '/admin/users', '/admin/reports'] as $path) {
            $response = $this->actingAs($admin, 'admin')->get($path)->assertOk();

            $this->assertStringContainsString(
                'no-store',
                $response->headers->get('Cache-Control'),
                "{$path} must not be stored by the browser.",
            );
        }
    }

    /**
     * Public pages are deliberately left cacheable.
     *
     * A marketing page is meant to be stored; telling the browser not to costs
     * bandwidth and buys nothing, because there is nothing behind it to protect.
     */
    public function test_public_pages_are_left_cacheable(): void
    {
        $response = $this->get('/')->assertOk();

        $this->assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /* ------------------------------------------------------------------ */
    /* 2. The logout response itself is not a Back-button candidate         */
    /* ------------------------------------------------------------------ */

    public function test_the_customer_logout_response_is_not_cacheable(): void
    {
        $response = $this->actingAs($this->makeUser())
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_the_admin_logout_response_is_not_cacheable(): void
    {
        $response = $this->actingAs($this->makeAdmin(), 'admin')
            ->post('/admin/logout')
            ->assertRedirect(route('login'));

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    /* ------------------------------------------------------------------ */
    /* 3. The session really is gone                                       */
    /* ------------------------------------------------------------------ */

    public function test_a_protected_page_refuses_a_session_that_has_been_logged_out(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get('/appointments')->assertOk();

        $this->post('/logout');

        // Same test client, same cookie jar — so this is the Back button's
        // request, not a clean browser.
        $this->get('/appointments')->assertRedirect(route('login'));
    }

    public function test_the_admin_panel_refuses_a_session_that_has_been_logged_out(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')->get('/admin')->assertOk();

        $this->post('/admin/logout');

        $this->get('/admin')->assertRedirect(route('login'));
    }

    /* ------------------------------------------------------------------ */
    /* 4. The probe a bfcache-restored page asks                           */
    /* ------------------------------------------------------------------ */

    public function test_the_probe_reports_a_signed_in_customer(): void
    {
        $this->actingAs($this->makeUser())
            ->getJson('/session-status')
            ->assertOk()
            ->assertJson(['authenticated' => true, 'guard' => 'web']);
    }

    public function test_the_probe_reports_a_signed_in_admin(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->getJson('/session-status')
            ->assertOk()
            ->assertJson(['authenticated' => true, 'guard' => 'admin']);
    }

    public function test_the_probe_reports_a_signed_out_browser(): void
    {
        $this->getJson('/session-status')
            ->assertOk()
            ->assertJson(['authenticated' => false, 'guard' => 'guest']);
    }

    /**
     * The probe is asked *after* a logout, which is the whole point.
     *
     * It has to be reachable without a session — a browser that has just been
     * logged out is exactly the browser that needs the answer — so it sits
     * outside the authenticated middleware groups and cannot redirect.
     */
    public function test_the_probe_answers_after_a_logout_rather_than_redirecting(): void
    {
        $this->actingAs($this->makeUser())->post('/logout');

        $this->getJson('/session-status')
            ->assertOk()
            ->assertJson(['authenticated' => false, 'guard' => 'guest']);
    }

    /**
     * A cached "yes" here is the one answer that would defeat the whole
     * mechanism, so the probe is marked uncacheable as well.
     */
    public function test_the_probe_is_not_cacheable(): void
    {
        $response = $this->getJson('/session-status')->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    /**
     * It reports which guard, and nothing else.
     *
     * No id, no name, no email: the client only needs to know whether to send the
     * tab to a login screen, which is what lets this route sit outside the
     * authenticated groups at all.
     */
    public function test_the_probe_leaks_nothing_about_the_account(): void
    {
        $user = $this->makeUser();

        $body = $this->actingAs($user)
            ->getJson('/session-status')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($user->email, $body);
        $this->assertStringNotContainsString($user->full_name, $body);
        $this->assertStringNotContainsString((string) $user->id, $body);
    }

    /* ------------------------------------------------------------------ */
    /* 5. The client half is actually wired up                             */
    /* ------------------------------------------------------------------ */

    public function test_the_client_asks_the_probe_when_a_page_comes_back_from_the_cache(): void
    {
        $source = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString("'pageshow'", $source, 'A bfcache restore has to be detected.');
        $this->assertStringContainsString('event.persisted', $source, '…by the flag that marks a cache restore.');
        $this->assertStringContainsString('/session-status', $source, '…and answered by asking the server.');
    }
}