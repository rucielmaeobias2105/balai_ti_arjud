<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin Flow 1 — the staff sign-in page, after its cleanup.
 *
 * Four things were removed and one was fixed. All five are user-visible on a
 * screen somebody looks at several times a day, so each is asserted rather than
 * assumed:
 *
 *   - the "Staff Portal" pill, which labelled a page whose URL, tab title and
 *     brand panel already said it;
 *   - "Sign in with your staff credentials.", the same fact a third time;
 *   - the "OR" rule and the "Back to the website" link, which only existed to
 *     separate the submit from a second way out; and
 *   - the Forgot password? placeholder, which was an inert `<span>` reading as a
 *     broken link.
 *
 * The fix was the submit's icon: it was a bare "↪" text glyph, and the markup
 * that replaced it named a Font Awesome class with no family (`fa-right-to-bracket`
 * alone), so the glyph resolved to no font and drew nothing. It is now a full
 * `fa-solid fa-right-to-bracket`, and the assertions below cover both halves —
 * dropping either one brings the empty icon straight back.
 */
class AdminLoginPageTest extends TestCase
{
    use RefreshDatabase;

    private function login(): string
    {
        return $this->get(route('login'))->assertOk()->getContent();
    }

    /* ------------------------------------------------------------------ */
    /* 1. What is still there                                              */
    /* ------------------------------------------------------------------ */

    public function test_the_form_is_still_a_working_login(): void
    {
        $html = $this->login();

        // /login and /admin/login render the shared login form (`auth.login`).
        $this->assertStringContainsString('Welcome Back', $html);
        $this->assertStringContainsString('name="login"', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString('name="remember"', $html);
        $this->assertMatchesRegularExpression(
            '/<form method="POST" action="'.preg_quote(route('login'), '/').'"/',
            $html,
        );

        // Admin credentials route to the admin dashboard.
        $admin = $this->makeAdmin(['username' => 'staff', 'password' => 'the-password']);

        $this->post(route('login.store'), [
            'login' => 'staff',
            'password' => 'the-password',
        ])->assertRedirect(route('admin.dashboard'));
    }

    /* ------------------------------------------------------------------ */
    /* 2. What came off                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * The three labels for one fact are gone.
     *
     * Scoped to the form card rather than the document: the customer footer
     * carries a "Staff Portal" link, and that is a different thing on a different
     * page — in fact the only remaining way in for a customer who needs the staff
     * login, so removing it would be the opposite of this requirement.
     */
    public function test_the_staff_portal_label_is_gone_from_the_card(): void
    {
        $html = $this->login();

        $card = $this->formCard($html);

        $this->assertStringNotContainsString('Staff Portal', $card);
        $this->assertStringNotContainsString('Sign in with your staff credentials', $card);

        // The pill's own wrapper class is the tell: it rendered nothing else, so
        // if the badge style were still used it would be a label with no text.
        $this->assertStringNotContainsString('admin-auth-badge', $card);
    }

    public function test_the_back_to_the_website_link_and_its_separator_are_gone(): void
    {
        $html = $this->login();

        $card = $this->formCard($html);

        $this->assertStringNotContainsString('Back to the website', $card);

        // `admin-auth-or` is the "OR" rule between the submit and the back link.
        // With the back link gone it would be a line drawn across nothing.
        $this->assertStringNotContainsString('admin-auth-or', $card);

        // …and the link's own class, for the same reason.
        $this->assertStringNotContainsString('admin-auth-back', $card);
    }

    /**
     * "Forgot password?" is now a link that goes somewhere.
     *
     * It used to be a `<span aria-disabled="true">` — styled to look like a link,
     * with a comment explaining it was deliberately inert. This is the whole of
     * Admin Flow 9's entry point, so an inert control here was the visible symptom
     * of the feature being missing.
     */
    public function test_the_staff_reset_link_is_no_longer_on_the_login(): void
    {
        $html = $this->login();

        $this->assertStringNotContainsString(route('admin.password.request'), $html);
        $this->assertStringNotContainsString('Staff member', $html);
        $this->assertStringNotContainsString('aria-disabled="true"', $html);
        $this->assertStringNotContainsString('cursor-not-allowed', $html);
    }

    public function test_the_submit_carries_a_font_awesome_icon(): void
    {
        $html = $this->login();

        $this->assertMatchesRegularExpression(
            '/<button type="submit" class="btn-primary w-full">\s*Log In\s*<\/button>/',
            $html,
        );
    }

    /* ------------------------------------------------------------------ */
    /* 4. The password field                                                */
    /* ------------------------------------------------------------------ */

    /** The login form's password field has a working eye toggle. */
    public function test_the_password_field_has_an_eye_toggle(): void
    {
        $html = $this->login();

        $this->assertStringContainsString('data-password-toggle-for="password"', $html);
        $this->assertStringContainsString('data-password-toggle-icon', $html);

        // Both marks are in the markup; CSS decides which is visible.
        $this->assertStringContainsString('data-password-eye-open', $html);
        $this->assertStringContainsString('data-password-eye-closed', $html);

        /*
         * The field's own leading lock icon.
         *
         * Asserted structurally rather than by the icon's component name, because
         * the name does not survive into the rendered page — Blade resolves
         * `heroicon-o-lock-closed` to an inline `<svg>`, and the string being
         * searched for is not there. The `pl-10` on the input is the reliable
         * signal that the icon slot is in use, since the component only applies
         * it when an icon is passed.
         */
        $this->assertMatchesRegularExpression(
            '/name="password"[^>]*class="input pl-10/',
            $html,
            'The password field should reserve room for a leading icon.',
        );

        // And the toggle sits inside the field rather than after it, which is why
        // the input carries `pr-12` for the button.
        $this->assertMatchesRegularExpression('/name="password"[^>]*pr-12/', $html);
    }

    /* ------------------------------------------------------------------ */
    /* 5. What the cleanup did not touch                                   */
    /* ------------------------------------------------------------------ */

    /**
     * The brand panel and the tab title.
     *
     * The staff portal keeps its own headings and features in
     * `AdminSessionController::create()`; only the card above the form was
     * trimmed. Asserted so a future pass cannot "tidy" those away as well.
     */
    public function test_the_brand_panel_is_untouched(): void
    {
        $html = $this->login();

        $this->assertStringContainsString('Balai ti Arjud', $html);
        $this->assertStringContainsString('Book Appointments', $html);
        $this->assertStringContainsString('<title>LOG IN | Balai ti Arjud</title>', $html);
    }

    /** The page stays public, and stays behind `admin.guest`. */
    public function test_it_is_public_and_closed_to_signed_in_admins(): void
    {
        $this->get(route('login'))->assertOk();

        $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('login'))
            ->assertRedirect(route('admin.dashboard'));
    }

    /** The part of the document the form card occupies. */
    private function formCard(string $html): string
    {
        $start = strpos($html, 'admin-auth-card');
        $end = strrpos($html, '</main>');

        $this->assertNotFalse($start, 'The auth card should be in the page.');
        $this->assertNotFalse($end, 'The page should have a main region.');

        return substr($html, $start, $end - $start);
    }
}