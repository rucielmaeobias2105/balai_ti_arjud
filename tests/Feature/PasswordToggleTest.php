<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The password show/hide toggle, on every screen that has one.
 *
 * The requirement was that it works in four places: the unified login (which
 * replaced the separate admin login), the register form, and the password fields
 * on both profile screens. It worked in none of them, and for one reason — see `test_the_selector_matches_the_markup`
 * below, which is the test that matters here. The rest assert that each location
 * is actually wired, because "the helper works" and "this form has a toggle" are
 * different claims and only the second one is what a user experiences.
 *
 * What cannot be asserted without a browser is the click itself. So the
 * JavaScript is checked structurally instead: that it selects the attribute the
 * markup renders, that it flips `type`, and that it drives the icon swap through
 * the class the stylesheet keys off.
 */
class PasswordToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeSalonSettings();
    }

    private function source(): string
    {
        return (string) file_get_contents(resource_path('js/app.js'));
    }

    /* ------------------------------------------------------------------ */
    /* 1. The bug                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * The selector and the attribute are the same string.
     *
     * This is the whole fix. The script bound on `[data-password-toggle]`, while
     * the component has always rendered `data-password-toggle-for` — so
     * `querySelectorAll` matched nothing, no listener was ever attached, and the
     * eye did nothing anywhere in the app. Nothing about the click handler was
     * wrong; it was simply never reached.
     *
     * Asserted against both sides so a future rename has to change them together.
     */
    public function test_the_selector_matches_the_markup(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            "querySelectorAll('[data-password-toggle-for]')",
            $source,
            'The script must select the attribute the component renders.',
        );

        $this->assertStringNotContainsString(
            "querySelectorAll('[data-password-toggle]')",
            $source,
            'The bare form of the attribute is rendered by nothing, so selecting it binds nothing.',
        );

        $component = (string) file_get_contents(
            resource_path('views/components/ui/form/password.blade.php'),
        );

        $this->assertStringContainsString('data-password-toggle-for="{{ $id }}"', $component);
    }

    /** It reads the input's id off that same attribute. */
    public function test_it_resolves_the_input_from_the_attribute(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('document.getElementById(button.dataset.passwordToggleFor)', $source);
    }

    /** And it flips the input type. */
    public function test_it_toggles_the_input_type(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("input.type = revealed ? 'password' : 'text'", $source);
    }

    /* ------------------------------------------------------------------ */
    /* 2. Every location is wired                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, array{0: string}>
     */
    public static function locations(): array
    {
        return [
            'customer login' => ['/login'],
            'customer register' => ['/register'],
            'admin reset password' => ['/admin/password/reset/abc?email=admin@example.test'],
        ];
    }

    /**
     * @dataProvider locations
     */
    public function test_the_public_forms_have_a_working_toggle(string $path): void
    {
        $this->get($path)->assertOk()->assertSee('data-password-toggle-for=', false);
    }

    /**
     * The two "forgot password" pages are not in the list above, and that is
     * correct: they ask for an email address and have no password field, so there
     * is nothing to reveal. Asserted rather than left implicit, because a future
     * reader would otherwise assume they were missed.
     */
    public function test_the_request_pages_have_no_password_field_to_reveal(): void
    {
        foreach (['/password', '/admin/password'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('name="email"', false)
                ->assertDontSee('data-password-toggle-for=', false);
        }
    }

    /**
     * The customer's reset form, which cannot be reached by URL.
     *
     * It sits behind the session the four-step wizard builds, so it is rendered
     * directly rather than navigated to. Going through the wizard to reach it
     * would test the wizard, not the toggle.
     */
    public function test_the_customer_reset_form_has_a_working_toggle(): void
    {
        // Through the wizard, because the reset step asserts on session state
        // (`password_reset.verified`) that no URL can set. Steps 1 and 2 are
        // driven with real requests; the six-digit code comes from the service
        // rather than being read out of a faked notification, and is not the
        // thing under test here.
        $user = $this->makeUser(['email' => 'reset@example.test']);

        // Step 1 as a guest: the reset routes sit behind `guest`, so a signed-in
        // visitor is redirected straight to their dashboard rather than being
        // walked through the wizard.
        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.code'));

        $code = app(\App\Services\PasswordResetService::class)->issue($user)->code;

        // Still a guest: the whole wizard lives behind `guest`, because somebody
        // already signed in has no password to recover. Signing this user in here
        // would bounce both requests to their dashboard instead.
        $this->post(route('password.verify'), ['code' => $code])
            ->assertRedirect(route('password.reset'));

        $html = $this->get(route('password.reset'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-password-toggle-for="password"', $html);
        $this->assertStringContainsString('data-password-toggle-for="password_confirmation"', $html);
        $this->assertStringContainsString('data-password-toggle-icon', $html);
    }

    /**
     * The two profile screens are behind the guards, and each is checked
     * separately rather than through the data provider — the setup differs and a
     * data provider cannot express that without hiding which side failed.
     */
    public function test_the_admin_profile_has_a_working_toggle(): void
    {
        $html = $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.profile.edit'))
            ->assertOk()
            ->getContent();

        // Both the new password and its confirmation.
        $this->assertSame(2, substr_count($html, 'data-password-toggle-for='));
        $this->assertStringContainsString('data-password-toggle-for="password"', $html);
        $this->assertStringContainsString('data-password-toggle-for="password_confirmation"', $html);
    }

    public function test_the_customer_profile_has_a_working_toggle(): void
    {
        $html = $this->actingAs($this->makeUser())
            ->get(route('profile.edit'))
            ->assertOk()
            ->getContent();

        $this->assertSame(2, substr_count($html, 'data-password-toggle-for='));
        $this->assertStringContainsString('data-password-toggle-for="password"', $html);
        $this->assertStringContainsString('data-password-toggle-for="password_confirmation"', $html);
    }

    /* ------------------------------------------------------------------ */
    /* 3. The icon actually swaps                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Both marks are in the markup.
     *
     * The component renders the open eye and its slashed twin, and CSS decides
     * which is visible. The alternative — swapping markup from script — means
     * holding a template string in JavaScript next to Blade that draws the same
     * icon, and the two drifting apart is a matter of time.
     */
    public function test_both_eye_marks_are_rendered(): void
    {
        $component = (string) file_get_contents(
            resource_path('views/components/ui/form/password.blade.php'),
        );

        $this->assertStringContainsString('data-password-eye-open', $component);
        $this->assertStringContainsString('data-password-eye-closed', $component);

        // The slashed twin is derived from the caller's icon by name, so a caller
        // names one icon rather than two.
        $this->assertStringContainsString("'-slash'", $component);

        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('data-password-eye-open', $html);
        $this->assertStringContainsString('data-password-eye-closed', $html);
    }

    /** The stylesheet shows one and hides the other, keyed off the state class. */
    public function test_the_stylesheet_swaps_the_two_marks(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.admin-auth-eye-slash', $css);
        $this->assertStringContainsString('.admin-auth-eye-toggle.is-revealed .admin-auth-eye-slash', $css);
    }

    /** The script drives that class rather than the icons themselves. */
    public function test_the_script_toggles_a_state_class_not_the_icons(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            "button.classList.toggle('is-revealed', !revealed)",
            $source,
            'The icon variant is driven by a class, not by rewriting markup.',
        );
    }

    /**
     * `aria-pressed` is the accessible half.
     *
     * With the icon variant there is no visible label that changes, so this is
     * the only thing telling a screen reader the state moved — and it starts
     * `false` in the markup.
     */
    public function test_the_toggle_announces_its_state(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertStringContainsString("button.setAttribute('aria-pressed', String(!revealed))", $this->source());
    }

    /**
     * The text-label variant still works.
     *
     * It is the component's default, so it is what any `x-ui.form.password`
     * without `toggleIcon` renders. Swapping `textContent` is safe there
     * precisely because there is no SVG to destroy — the branch the icon variant
     * exists to avoid.
     *
     * Rendered from the component directly rather than from a page, because every
     * form in the app has since moved to the icon variant and there is no screen
     * left that exercises this branch. It is kept because it is the component's
     * default: dropping the branch would mean the next caller who omits
     * `toggleIcon` gets a button that silently does nothing.
     */
    public function test_the_text_variant_swaps_its_label(): void
    {
        $this->assertStringContainsString(
            "button.textContent = revealed ? 'Show' : 'Hide'",
            $this->source(),
        );

        $html = view('components.ui.form.password', ['name' => 'password', 'label' => 'Password'])
            ->with('errors', new \Illuminate\Support\ViewErrorBag)
            ->render();

        // Proved by the absence of the icon variant's attributes, rather than by
        // asserting on a class name that could change.
        $this->assertStringNotContainsString('data-password-toggle-icon', $html);
        $this->assertStringNotContainsString('data-password-eye-open', $html);

        // And it carries the label the script swaps.
        $this->assertMatchesRegularExpression(
            '/data-password-toggle-for="password"[^>]*>\s*Show\s*</',
            $html,
            'The default variant should render the "Show" label.',
        );
    }

    /* ------------------------------------------------------------------ */
    /* 4. Late-arriving markup                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Re-initialised after Alpine walks the tree.
     *
     * A field rendered inside an Alpine template is added to the document after
     * `DOMContentLoaded`, so a single pass on that event would leave it
     * unwired — which is how a toggle works on the login form and silently does
     * nothing on the profile screen.
     */
    public function test_it_rebinds_after_alpine_renders(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("document.addEventListener('DOMContentLoaded'", $source);
        $this->assertStringContainsString("document.addEventListener('alpine:initialized'", $source);
    }

    /** The bound marker stops a second pass from adding a duplicate listener. */
    public function test_binding_twice_is_a_no_op(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("if (button.dataset.passwordToggleBound === '1') return;", $source);
    }
}