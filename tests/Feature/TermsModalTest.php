<?php

namespace Tests\Feature;

use App\Enums\TermsCategory;
use App\Models\TermsAndCondition;
use App\Support\TermsRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Tests\TestCase;

/**
 * Terms & Conditions as a dialog, on both sides of the app.
 *
 * The pattern being copied is MCA Café's "General Terms & Conditions" modal: a
 * header bar with an icon, a title and a close X, a numbered list in the body,
 * and one Close button at the bottom right. What is *not* copied is its
 * Bootstrap 5 implementation — this project is Tailwind and Alpine — so the
 * assertions are about the arrangement and the words, never about a class name
 * that MCA happens to use.
 *
 * The behaviour worth protecting is not really the dialog's looks. It is:
 *
 *   1. every place a customer is asked to agree to something opens the dialog
 *      rather than navigating away from the form they are filling in;
 *   2. the dialog degrades to the standalone page without JavaScript, so the
 *      terms are readable either way; and
 *   3. the list is numbered structurally, so it is numbered even when the admin
 *      never typed a number.
 */
class TermsModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishBookingTerms();
    }

    /* ------------------------------------------------------------------ */
    /* The dialog itself                                                   */
    /* ------------------------------------------------------------------ */

    public function test_every_customer_page_carries_the_dialog_and_its_content(): void
    {
        $customer = $this->makeUser();

        foreach (['/', $this->bookingUrl(), route('appointments.index')] as $url) {
            $html = $this->actingAs($customer)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('termsModal(', $html, "The dialog is missing on {$url}.");
            $this->assertStringContainsString('terms-modal.window', $html);

            // The handler must call a *method*. `open` is the boolean flag, not a
            // function, so `open($event.detail)` throws a TypeError the moment a
            // trigger is clicked and the dialog silently never appears — on every
            // page in the app, including both policy links. Asserting only that
            // the event name is present (as this test used to) cannot catch that.
            $this->assertMatchesRegularExpression(
                '/x-on:terms-modal\.window="show\(\$event\.detail\)"/',
                $html,
                'The terms-modal handler must call show($event.detail).',
            );

            // The live copy travels with the page, so the dialog opens with the
            // terms already in it rather than a spinner.
            $this->assertStringContainsString('An appointment is', $html, "The terms are missing on {$url}.");
        }
    }

    /**
 * The admin dashboard carries no terms dialog.
     *
     * It used to, for the "Preview" link on the Terms screen. That link is gone,
     * and nothing else in the panel opens the dialog — so the dashboard was
     * paying for it: the component reads all three published policies and renders
     * each through `TermsRenderer`, on every admin page, for markup no control
     * on those pages can reach.
     *
     * The customer and auth layouts still mount it, and there the triggers are
     * real; `test_every_customer_surface_that_links_the_terms_has_the_dialog`
     * covers those.
     */
    public function test_the_admin_panel_does_not_carry_an_unreachable_dialog(): void
    {
        $html = $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('termsModal(', $html);
        $this->assertStringNotContainsString('id="terms-modal-title"', $html);
    }

    public function test_the_dialog_has_a_header_a_body_and_one_close_button(): void
    {
        $html = $this->actingAs($this->makeUser())->get(route('home'))->assertOk()->getContent();

        // Header: the title is Alpine-bound, so the *contract* is what is
        // asserted — the heading, the icon, and the X that closes it.
        $this->assertStringContainsString('id="terms-modal-title"', $html);
        $this->assertStringContainsString('x-text="title"', $html);
        $this->assertStringContainsString('aria-label="Close"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);

        // The numbered list body.
        $this->assertStringContainsString('x-html="current?.html"', $html);
        $this->assertStringContainsString('terms-list', $html);

        // One Close button in the footer, which is the specified arrangement.
        $this->assertSame(
            1,
            preg_match('/<button[^>]*class="btn-primary"[^>]*x-on:click="close\(\)"[^>]*>Close</', $html),
            'The dialog must have exactly one footer Close button.',
        );
    }

    public function test_the_booking_dialog_is_titled_as_the_general_terms(): void
    {
        $html = $this->actingAs($this->makeUser())->get(route('home'))->assertOk()->getContent();

        $this->assertSame(
            'General Terms & Conditions',
            TermsCategory::Booking->modalTitle(),
            'The booking dialog is headed as the general terms, as MCA Café has it.',
        );

        // The dialog's config reaches the page through `@js()`, which hex-escapes
        // `&` and then wraps the whole payload in `JSON.parse('…')` — escaping
        // that backslash a second time on the way. Comparing with the backslashes
        // stripped from both sides asserts the title itself rather than how many
        // layers of escaping the encoder applied today.
        $encoded = json_encode(
            TermsCategory::Booking->modalTitle(),
            JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES,
        );

        $this->assertStringContainsString(
            str_replace('\\', '', trim($encoded, '"')),
            str_replace('\\', '', $html),
        );
    }

    /* ------------------------------------------------------------------ */
    /* The triggers                                                        */
    /* ------------------------------------------------------------------ */

    public function test_the_agreement_checkboxes_open_the_dialog_instead_of_leaving_the_page(): void
    {
        $html = $this->actingAs($this->makeUser())->get($this->bookingUrl())->assertOk()->getContent();

        // All three policies, all opening the dialog.
        foreach (['booking', 'cancellation', 'rescheduling'] as $category) {
            $this->assertStringContainsString(
                "\$dispatch('terms-modal', { category: '{$category}' })",
                $html,
                "The {$category} terms must open the dialog.",
            );
        }
    }

    public function test_registration_offers_the_terms_without_navigating_away(): void
    {
        $html = $this->get(route('register'))->assertOk()->getContent();

        $this->assertStringContainsString("\$dispatch('terms-modal', { category: 'booking' })", $html);

        // It was `target="_blank"` before, which opened a second tab from
        // mid-registration and lost the half-filled form behind it.
        $this->assertStringNotContainsString('target="_blank"', $html);
    }

    /**
     * The sign-in page offers the same two policies, the same way.
     *
     * It had no terms link at all: the only policies on that screen were
     * reachable by typing the URL. The dialog was already mounted there — it
     * comes from the shared auth layout — so all this adds is the triggers.
     */
    public function test_sign_in_offers_the_terms_without_navigating_away(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString("\$dispatch('terms-modal', { category: 'booking' })", $html);
        $this->assertStringContainsString("\$dispatch('terms-modal', { category: 'cancellation' })", $html);
    }

    /**
     * Every shared layout roots an Alpine tree, and this is the whole fix.
     *
     * `Alpine.start()` initialises the elements matching `[x-data]` and walks
     * each one's subtree. It does not walk from `<body>`. So a directive with
     * no `x-data` ancestor is not "not working yet" — it is never initialised,
     * silently, with no console error to find it by.
     *
     * The registration form's terms link was exactly that: `x-on:click.prevent`
     * inside a `<form>` that had no `x-data`, so `preventDefault` was never
     * bound, the click followed the real `href`, and a customer asking to read
     * the terms before signing up was sent to a full page instead of a dialog.
     *
     * Asserted against the layouts rather than against pages because the
     * invariant is layout-level: it holds for every screen that extends one,
     * including the ones that add no `x-data` of their own.
     */
    public function test_every_shared_layout_roots_an_alpine_tree(): void
    {
        foreach (['auth', 'customer', 'admin'] as $layout) {
            $source = (string) file_get_contents(
                resource_path("views/layouts/{$layout}.blade.php")
            );

            $this->assertMatchesRegularExpression(
                '/<body\b[^>]*\bx-data\b/s',
                $source,
                "layouts/{$layout}.blade.php must put x-data on the body. Alpine does not "
                    .'walk from the body on its own, so without it every directive '
                    .'outside some x-data subtree is silently never initialised.',
            );
        }
    }

    public function test_the_cancel_and_reschedule_pages_no_longer_print_the_policy_inline(): void
    {
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user, null, ['preferred_date' => today()->addDays(4)]);

        $cancel = $this->actingAs($user)
            ->get(route('appointments.cancel', $appointment))
            ->assertOk()
            ->getContent();

        // A way in, and the fact of a policy — but not the whole document on the
        // page next to the reason field.
        $this->assertStringContainsString("\$dispatch('terms-modal', { category: 'cancellation' })", $cancel);
        $this->assertStringNotContainsString(
            'We understand that plans change',
            $cancel,
            'The cancellation policy must not be inlined on the cancel page any more.',
        );

        $reschedule = $this->actingAs($user)
            ->get(route('appointments.reschedule', $appointment))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("\$dispatch('terms-modal', { category: 'rescheduling' })", $reschedule);
        $this->assertStringNotContainsString(
            'Need a different time?',
            $reschedule,
            'The rescheduling policy must not be inlined on the reschedule page any more.',
        );
    }

    /**
 * The Terms screen has no Preview trigger, and the panel mounts no dialog for
 * one.
 *
 * There used to be a "Preview" pill in each card's header, linking to the shared
 * dialog so an admin could see what a customer would get. It is gone: the page
 * is the editing surface, the textarea beside it holds the same text, and
 * `/terms/{category}` is the page a customer actually lands on.
 *
 * With the last trigger gone, mounting the dialog in the admin layout became
 * cost with no benefit — it reads all three published policies and renders them
 * on every admin page. So the panel no longer mounts it either, and this asserts
 * both halves: nothing dispatches the dialog, and it is not in the page.
 */
public function test_the_admin_terms_page_has_no_preview_trigger_and_mounts_no_dialog(): void
    {
        $html = $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.terms.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString("\$dispatch('terms-modal'", $html);
        $this->assertStringNotContainsString('>Preview<', $html);
        $this->assertStringNotContainsString('id="terms-modal-title"', $html);
    }

    /* ------------------------------------------------------------------ */
    /* The no-JavaScript floor                                             */
    /* ------------------------------------------------------------------ */

    public function test_every_trigger_still_hrefs_the_standalone_page(): void
    {
        $html = $this->actingAs($this->makeUser())->get($this->bookingUrl())->assertOk()->getContent();

        // The trigger is a real link that Alpine intercepts, so without the
        // bundle the browser follows it and the terms are still readable.
        $this->assertStringContainsString(
            'href="'.route('terms.show', 'cancellation').'"',
            $html,
            'A terms trigger must degrade to the standalone page.',
        );
    }

    public function test_the_standalone_page_still_serves_the_live_terms(): void
    {
        $html = $this->get(route('terms.show', 'booking'))->assertOk()->getContent();

        $this->assertStringContainsString('An appointment is', $html);
        $this->assertStringContainsString('terms-list', $html);
    }

    /**
     * The dialog and the standalone page must be the same document.
     *
     * Asserted on the term headings rather than by comparing the two renderings
     * byte for byte: the page emits raw HTML while the dialog's copy is
     * JSON-encoded into an Alpine config, so a byte comparison would be a test
     * of the escaping rather than of the content. The headings appearing in both
     * is the claim that actually matters.
     */
    public function test_the_dialog_and_the_page_render_the_same_document(): void
    {
        $modalHtml = $this->actingAs($this->makeUser())
            ->get(route('home'))
            ->assertOk()
            ->getContent();

        $pageHtml = $this->get(route('terms.show', 'booking'))->assertOk()->getContent();

        foreach (['Confirmation', 'Arrival'] as $heading) {
            $this->assertStringContainsString($heading, $pageHtml);
            $this->assertStringContainsString($heading, $modalHtml);
        }

        $this->assertStringContainsString('terms-list', $pageHtml);
        $this->assertStringContainsString('terms-list', $modalHtml);
    }

    /* ------------------------------------------------------------------ */
    /* Nothing published yet                                                */
    /* ------------------------------------------------------------------ */

    public function test_the_dialog_says_so_when_a_policy_is_unpublished(): void
    {
        // Retire every published policy.
        TermsAndCondition::query()->update(['is_published' => false]);

        $html = $this->actingAs($this->makeUser())->get(route('home'))->assertOk()->getContent();

        // The dialog is still mounted and still offers its explanation, rather
        // than opening an empty box with no list in it.
        $this->assertStringContainsString('has not been published yet', $html);
        $this->assertStringContainsString('termsModal(', $html);

        // And a trigger still leads there, so a customer who asks gets an answer
        // instead of a dead click.
        $booking = $this->actingAs($this->makeUser())->get($this->bookingUrl())->assertOk()->getContent();

        $this->assertStringContainsString("\$dispatch('terms-modal', { category: 'booking' })", $booking);
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    private function publishBookingTerms(string $content = null): TermsAndCondition
    {
        return TermsAndCondition::updateOrCreate(
            ['category' => 'booking'],
            [
                'content' => $content ?? <<<'HTML'
                    <h2>Booking Terms &amp; Conditions</h2>
                    <p>These terms govern every appointment made through the salon.</p>
                    <h3>1. Confirmation</h3>
                    <p>An appointment is <strong>not</strong> confirmed until you hear from us.</p>
                    <h3>2. Arrival</h3>
                    <p>Please arrive 10 minutes early.</p>
                    <hr><p><em>Updated after our 2024 rate review.</em></p>
                    HTML,
                'is_published' => true,
                'published_at' => now()->subWeek(),
            ],
        );
    }
}
