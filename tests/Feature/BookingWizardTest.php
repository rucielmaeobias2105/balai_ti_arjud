<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Support\BookingSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * The Book Appointment page after it became one wizard card.
 *
 * What is pinned here is the shape of the thing and the four steps that changed
 * their behaviour:
 *
 *   - the four steps live inside one `bta-card`, not four cards, and are moved
 *     between by a stepper rather than by a long scroll;
 *   - step 1 groups services by category and shows four per category before a
 *     "See More", the same way the Browse Services cards do;
 *   - step 3's only input is a free-text box rather than a dropdown of fourteen
 *     predefined answers, and what the customer typed is stored as typed;
 *   - step 4 asks a first-timer and informs a returning customer, and the server
 *     requires it only of the first;
 *   - the agreement is the one gate this form has to enforce itself, because the
 *     form is `novalidate`, and it sits on step 4 rather than being a step.
 *
 * It was seven steps and is four. The merges are asserted by their consequence
 * rather than by counting — a joined pair produces one heading carrying both
 * questions, and the retired headings are asserted absent — because a test that
 * only counted panels would still pass with the panels split back apart.
 *
 * The submission rules that were already here are covered by
 * `BookingCreationTest` and `BookingRulesTest`; this file is about the page.
 */
class BookingWizardTest extends TestCase
{
    use RefreshDatabase;

    private function html(?\App\Models\User $user = null): string
    {
        // `/book` is behind `auth.customer`, so there is no guest version of this
        // page to assert on. A signed-up customer with no completed appointments
        // is the first-timer case, and that is what a first-timer always is.
        return $this->actingAs($user ?: $this->makeUser())
            ->get(route('appointments.create'))
            ->assertOk()
            ->getContent();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'services' => [['service_id' => $this->makeService(['price' => 500])->id, 'quantity' => 1]],
            'customer_name' => 'Ana Reyes',
            'customer_phone' => '09171234567',
            'preferred_date' => $this->bookableDate(),
            'preferred_time' => '10:00',
            'last_services_availed_note' => 'Glow Manicure',
            'agree_terms' => '1',
        ], $overrides);
    }

    /**
     * Post a booking as a customer.
     *
     * `/book` is behind `auth.customer`, so an unauthenticated post would be a
     * redirect to the login page and every assertion below would be measuring
     * that instead of the booking. Posting as a fresh account by default also
     * makes the first-timer case the default, which is the one most of these
     * tests are about.
     */
    private function book(array $overrides = [], ?\App\Models\User $user = null)
    {
        return $this->actingAs($user ?: $this->makeUser())
            ->post(route('appointments.store'), $this->payload($overrides));
    }

    /**
     * Post a booking and return the page the customer is actually put back on.
     *
     * `store()` answers a refused booking with `back()`, so where the customer
     * lands is the page they were on — and in a test that is `/` unless the form
     * has been fetched first. Loading the form is also what a customer does, so
     * this is the real flow rather than a workaround for the referrer, and it is
     * what makes the assertions below about a page rather than about a redirect.
     */
    private function bookAndFollow(array $overrides = [], ?\App\Models\User $user = null): string
    {
        $this->actingAs($user ?: $this->makeUser())
            ->get(route('appointments.create'))
            ->assertOk();

        return $this->followingRedirects()
            ->post(route('appointments.store'), $this->payload($overrides))
            ->getContent();
    }

    /* ------------------------------------------------------------------ */
    /* 1. One card, not seven                                              */
    /* ------------------------------------------------------------------ */

    /**
     * The four steps are sections of one card.
     *
     * Asserted as a single `bta-card` wrapping all four headings, and as the
     * absence of the `x-ui.card`s the steps used to be. Counting the cards is the
     * part that matters: a single card around everything, with four headings in
     * it, is the shape this is about, and a regression that put one step back in
     * its own card would still contain both headings.
     *
     * It was seven steps and is four. The three merges are asserted by their
     * consequence rather than by counting: two cards joined means one heading
     * carrying both questions, and the terms agreement is no longer a heading at
     * all because it is no longer a step.
     */
    public function test_all_four_steps_live_in_one_card(): void
    {
        $this->makeService();

        $html = $this->html();

        foreach ([
            '1. Selected Service',
            '2. Your Details &amp; Preferred Date &amp; Time',
            '3. Preferences &amp; Health Notes',
            '4. Last Service(s) Availed, Technician &amp; Special Request',
        ] as $heading) {
            $this->assertStringContainsString($heading, $html);
        }

        // The merged-away headings must not survive as anything: they would mean a
        // step that still exists somewhere the counter cannot reach.
        foreach ([
            '2. Your Details<',
            '3. Preferred Date &amp; Time',
            '5. Last Service(s) Availed<',
            '6. Choose Technician &amp; Special Request',
            '7. Terms &amp; Conditions',
        ] as $deadHeading) {
            $this->assertStringNotContainsString($deadHeading, $html);
        }

        // One card shell holds the four steps, and one other holds the Booking
        // Summary. That second one is deliberate â€” the summary is not a step, and
        // it has to stay visible while the steps change â€” so the count that
        // matters is "two", not "one".
        //
        // Matched on the class alone rather than on the whole attribute list:
        // the steps' card carries an id and a `scroll-mt-20` for `revealStep()`,
        // and pinning the exact attribute string would fail on that instead of
        // on the thing this is actually about.
        $this->assertSame(
            2,
            substr_count($html, 'class="bta-card'),
            'The four steps must share one card shell; only the summary keeps its own.'
        );

        // The steps' card is the one `revealStep()` scrolls, so it has to still
        // be findable by that id after the classes above moved.
        $this->assertStringContainsString('id="booking-step-card"', $html);

        // And no step is left in an `x-ui.card` of its own. This is what would
        // fail first if one step were put back into its own box.
        $this->assertStringNotContainsString('x-ui.card', $html);
    }

    /**
     * The steps are moved by Back and Continue, and the header only counts.
     *
     * A row of clickable step pills used to sit under the counter. It is gone: it
     * restated the counter, and on a phone it wrapped into two ragged rows that
     * read as a menu rather than as progress. What has to survive is the counter
     * and the two buttons.
     */
    public function test_the_steps_are_moved_by_back_and_continue(): void
    {
        $this->makeService();

        $html = $this->html();

        // Four panels, each shown on its own step â€” and no fifth.
        for ($step = 1; $step <= 4; $step++) {
            $this->assertStringContainsString('x-show="step === '.$step.'"', $html);
        }

        for ($step = 5; $step <= 7; $step++) {
            $this->assertStringNotContainsString('x-show="step === '.$step.'"', $html);
        }

        // The counter stays, is driven by the step list rather than a literal, and
        // is announced when it changes.
        $this->assertStringContainsString('Step <span x-text="step">1</span> of 4', $html);
        $this->assertStringNotContainsString('of 7', $html);

        $this->assertStringContainsString('aria-live="polite"', $html);

        $this->assertStringContainsString('x-on:click="next()"', $html);
        $this->assertStringContainsString('x-on:click="back()"', $html);

        // No step pills: no list, no jump buttons, no per-chip state.
        $this->assertStringNotContainsString('aria-label="Booking steps"', $html);
        $this->assertStringNotContainsString('x-on:click="goTo(', $html);

        // And the count of steps is one number, sent from PHP rather than typed
        // into the markup as well, so the counter and the panels cannot disagree.
        // It was 7 and is 4; the merges shortened the form and this is the one
        // place the new length is written down as a number.
        $this->assertStringContainsString('"stepCount":4', $html);
    }

    /**
     * Nothing stands between the customer and submitting the form.
     *
     * This is about the SUBMIT button, and deliberately not about Continue: the
     * two were once lumped together here, and they are different. Continue is
     * gated per step (see the next test). Confirm is not gated on anything at
     * all, because `StoreBookingRequest` is what refuses a booking and it does
     * so with a full list of problems rather than a dead button.
     */
    public function test_nothing_blocks_the_form_in_the_browser(): void
    {
        $this->makeService();

        $html = $this->html();

        /*
         * The form does have a `submit` listener — the duplicate-submission
         * guard — and it is deliberately the *only* thing on it. It sets `submitting`
         * so the two Confirm buttons grey out, and it does not call
         * preventDefault, so it cannot refuse a submission the server would have
         * accepted. Asserted on both halves: the presence of the flag, and the
         * absence of any interception, because a guard that stopped here would be
         * exactly the thing this test exists to prevent.
         */
        $this->assertStringContainsString('x-on:submit="submitting = true"', $html);
        $this->assertStringNotContainsString('x-on:submit.prevent', $html);
        $this->assertStringNotContainsString('$event.preventDefault()', $html);

        // The submit button is never disabled for an empty basket — only once the
        // form has actually been sent.
        $this->assertStringNotContainsString('x-bind:disabled="selectedIds.length === 0"', $html);
        $this->assertStringContainsString('x-bind:disabled="submitting"', $html);
    }

    /**
     * A step has to be finished before the next one opens.
     *
     * Continue used to be unconditional, on the reasoning that one refusal at the
     * end beats a running commentary. The consequence was that a customer could
     * walk three cards forward and only then be told they had never given a name —
     * the error belonged to a screen they had already left.
     *
     * The gate is `missingOnCurrentStep()`, and it is asserted as one list rather
     * than as four separate booleans because that is how it is built: the button's
     * `disabled` state and the note underneath both read the same array, so they
     * cannot end up disagreeing about why the button is dead.
     *
     * What is deliberately NOT gated is asserted too, at the end. Demanding a
     * special request, or a health note from someone who has never been to a
     * salon, is a chore dressed up as validation — and the server does not
     * require them either.
     */
    public function test_continue_is_gated_on_the_current_step_being_complete(): void
    {
        $this->makeService();

        $html = $this->html();

        // The button, and the reason it is disabled.
        $this->assertStringContainsString('x-bind:disabled="! canContinue()"', $html);
        $this->assertStringContainsString('missingOnCurrentStep()', $html);
        $this->assertStringContainsString("canContinue() {\n                    return this.missingOnCurrentStep().length === 0;", $html);

        // `next()` refuses in its own body too, not only via the attribute: this
        // is the path a keyboard Enter takes, arriving without ever touching the
        // button the attribute is on.
        $this->assertMatchesRegularExpression(
            '/next\(\)\s*\{[^}]*if \(! this\.canContinue\(\)\) return;/',
            $html,
            'next() must not advance off an incomplete step.',
        );

        // Each required field, by the message that names it.
        $this->assertStringContainsString('Choose at least one service', $html);
        $this->assertStringContainsString('Enter your full name', $html);
        $this->assertStringContainsString('Enter a phone number we can reach you on', $html);
        $this->assertStringContainsString('Choose a preferred date', $html);
        $this->assertStringContainsString('Choose a preferred time', $html);
        $this->assertStringContainsString('Agree to the booking terms to continue', $html);

        // The phone is held to the shape the server validates, so "invalid" means
        // the same thing on the button as it does on submit.
        $this->assertStringContainsString('/^[0-9+\-\s()]{7,32}$/', $html);

        // Reactive bindings. A getter that read `el.value` instead would look
        // correct and sit there stale until something unrelated forced a render.
        foreach (['customerName', 'customerPhone', 'agreeTerms', 'lastServicesNote'] as $bound) {
            $this->assertStringContainsString('x-model="'.$bound.'"', $html);
        }

        // The last-services answer is required only of a first-time customer, and
        // the gate uses the same predicate the validation rule does rather than
        // asking a returning customer for something they were never shown.
        $this->assertStringContainsString('requiresLastServices', $html);
        $this->assertStringContainsString('Tell us what you have had done elsewhere', $html);

        // Nothing optional is gated. Scoped to the gate's own body on purpose: both
        // field names appear elsewhere in the form, so searching the whole page
        // would either pass vacuously or fail for the wrong reason.
        $this->assertSame(
            1,
            preg_match('/missingOnCurrentStep\(\)\s*\{(.*?)\n\s{16}\},/s', $html, $gate),
            'Could not find the gate to inspect.',
        );

        $this->assertStringNotContainsString('allergies_other', $gate[1], 'The health note is optional and must not be gated.');
        $this->assertStringNotContainsString('special_request', $gate[1], 'A special request is optional and must not be gated.');
        $this->assertMatchesRegularExpression(
            '/get lastServicesAnswered\(\)\s*\{\s*if \(! this\.config\.requiresLastServices\) return true;/',
            $html,
            'A returning customer must count as having answered.',
        );
    }

    /**
     * An empty, ungated form is still refused by the server.
     *
     * The point of removing the client gate: it is only safe because the server
     * is the real one. If this ever passes, a booking can be saved with nothing
     * in it.
     */
    public function test_an_empty_booking_is_still_refused_by_the_server(): void
    {
        $this->makeSalonSettings();

        // No services at all.
        $this->book(['services' => []])
            ->assertSessionHasErrors('services');

        $this->assertDatabaseCount('appointments', 0);
    }

    /**
     * A booking without a name, a phone number or the agreement is refused.
     *
     * The minimum the server keeps: the three fields without which a booking
     * cannot be acted on, plus a service. Each asserted on its own, because a
     * payload that omits all three at once would pass even if only one of them
     * were actually checked.
     */
    public function test_the_three_essentials_are_each_required(): void
    {
        $this->makeSalonSettings();

        $this->book(['customer_name' => ''])->assertSessionHasErrors('customer_name');
        $this->book(['customer_phone' => ''])->assertSessionHasErrors('customer_phone');
        $this->book(['agree_terms' => null])->assertSessionHasErrors('agree_terms');

        $this->assertDatabaseCount('appointments', 0);
    }

    /**
     * A booking with only the essentials is accepted.
     *
     * The flip side of the rule above: the server checks what a booking cannot
     * exist without, and nothing more. A first-timer's step-5 answer is one of
     * those; a preference, a special request and a technician are not.
     */
    public function test_only_the_essentials_are_required(): void
    {
        Notification::fake();

        $this->makeSalonSettings();
        $service = $this->makeService(['price' => 500]);

        $this->book([
            'services' => [['service_id' => $service->id, 'quantity' => 1]],
            // No preference, no special request, no technician.
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('appointments', 1);
    }

    /* ------------------------------------------------------------------ */
    /* 2. Step 1 â€” four per category, then See More                        */
    /* ------------------------------------------------------------------ */

    /**
     * Four services per category, then a toggle.
     *
     * The Browse Services page shows four rows per category card and hides the
     * rest behind "See All". This step does the same thing, so a customer who has
     * read the price list meets the same shape here instead of a flat grid of
     * thirty checkboxes.
     */
    public function test_step_one_shows_four_services_per_category_before_a_toggle(): void
    {
        $this->makeCategory('Manicure & Pedicure', 9);
        $this->makeCategory('Threading', 2);

        $html = $this->html();

        // The long category gets the disclosure, with the hidden count in its
        // label so the button says what it is going to show.
        $this->assertStringContainsString('See More (5 more)', $html);
        $this->assertStringContainsString('See Less', $html);

        // The short one does not: a toggle that reveals nothing is worse than no
        // toggle.
        $this->assertSame(1, substr_count($html, 'See More (5 more)'));

        // Both categories are labelled and counted.
        $this->assertStringContainsString('Manicure &amp; Pedicure', $html);
        $this->assertStringContainsString('9 services', $html);
        $this->assertStringContainsString('2 services', $html);
    }

    /**
     * A category of exactly four gets no disclosure.
     *
     * The boundary, because `> 4` and `>= 4` both pass the nine-service case and
     * only one of them is right.
     */
    public function test_a_category_of_exactly_four_gets_no_toggle(): void
    {
        $this->makeCategory('Threading', 4);

        $html = $this->html();

        $this->assertStringNotContainsString('See More', $html);
        $this->assertStringNotContainsString('See Less', $html);
    }

    /**
     * An opened category stays open.
     *
     * This is the whole point of step 1's disclosure state. It used to live in a
     * per-category Alpine scope holding a bare boolean, which is right for the
     * Browse Services cards â€” where a category card is the whole page â€” and wrong
     * here, because this list re-renders around it: ticking a service redraws the
     * basket in the summary, and every step change flips panel visibility. Either
     * one re-initialises nested scopes, the boolean goes back to false, and a
     * category the customer had opened silently collapsed.
     *
     * So the state is on the form's own scope, keyed by category.
     */
    public function test_an_opened_category_is_keyed_on_the_form_not_a_local_scope(): void
    {
        $this->makeCategory('Threading', 7);

        $html = $this->html();

        // Keyed state on the parent form, and the read/write pair that uses it.
        $this->assertStringContainsString('expandedCategories: {}', $html);
        $this->assertStringContainsString('isCategoryExpanded(key)', $html);
        $this->assertStringContainsString('toggleCategory(key)', $html);

        // The rows and the button both ask that key, not a bare `expanded`.
        $this->assertStringContainsString('x-show="isCategoryExpanded(\'threading\')"', $html);
        $this->assertStringContainsString('x-on:click="toggleCategory(\'threading\')"', $html);

        // And the old per-category scope is gone â€” it was the thing that reset.
        $this->assertStringNotContainsString('x-data="serviceCategory()"', $html);
        $this->assertStringNotContainsString('x-show="expanded"', $html);
    }

    /**
     * Two categories, two independent keys.
     *
     * A single shared boolean would pass the test above and still be wrong:
     * opening Manicure would close Threading. Asserted on the two slugs being two
     * distinct keys in the markup.
     */
    public function test_each_category_has_its_own_key(): void
    {
        $this->makeCategory('Manicure & Pedicure', 6);
        $this->makeCategory('Threading', 7);

        $html = $this->html();

        $this->assertStringContainsString('isCategoryExpanded(\'manicure-pedicure\')', $html);
        $this->assertStringContainsString('isCategoryExpanded(\'threading\')', $html);
    }

    /* ------------------------------------------------------------------ */
    /* 3. Step 3 â€” free text                                               */
    /* ------------------------------------------------------------------ */

    /**
     * The primary input is one free-text box, not a dropdown and not a grid.
     *
     * A closed list of fourteen predefined answers is the wrong shape for what
     * people actually arrive with: "allergic to nickel, and my scalp reacts to
     * strong fragrance" is one sentence and no list of chips was ever going to
     * hold it. So the `<select>` is gone and there is a textarea.
     *
     * Both previous shapes are asserted absent, because either would satisfy a
     * weaker test than this one: the checkbox grid by `allergy_0`, the dropdown
     * by its own `name`.
     */
    public function test_step_three_is_a_free_text_box_of_preferences(): void
    {
        $this->makeService();

        $html = $this->html();

        // No dropdown. Asserted on the field's own name rather than on one of the
        // allergy words, because "latex" would still appear in the placeholder of
        // a free-text box and a word assertion would pass either way.
        $this->assertDoesNotMatchRegularExpression(
            '/<select[^>]*name="allergy_preference"/',
            $html,
            'Step 3 must not be a dropdown of predefined answers.'
        );
        $this->assertStringNotContainsString('allergy_preference', $html);

        // And no grid: `allergy_0` is the only name the eight checkbox row could
        // have produced.
        $this->assertStringNotContainsString('allergy_0', $html);
        $this->assertStringNotContainsString('select all that apply', $html);

        // The one control that remains.
        $this->assertStringContainsString('name="allergies_other"', $html);
        $this->assertMatchesRegularExpression(
            '/<textarea[^>]*name="allergies_other"/',
            $html,
            'Step 3 must be a textarea, so an answer in sentences fits on one line of code.'
        );
    }

    /**
     * What the customer typed reaches the column as typed.
     *
     * The sentence is stored verbatim, with nothing prepended and nothing folded
     * in, because there is no longer a dropdown answer for it to be joined to.
     */
    public function test_the_note_is_stored_as_typed(): void
    {
        Notification::fake();

        $this->makeSalonSettings();
        $service = $this->makeService(['price' => 500]);

        $this->book([
            'services' => [['service_id' => $service->id, 'quantity' => 1]],
            'allergies_other' => 'Allergic to nickel, and my scalp reacts to strong fragrance',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'Allergic to nickel, and my scalp reacts to strong fragrance',
            Appointment::first()->allergies,
        );
    }

    /** An untouched box writes nothing: a blank is not a claim of no sensitivities. */
    public function test_an_empty_note_writes_nothing(): void
    {
        Notification::fake();

        $this->makeSalonSettings();
        $service = $this->makeService(['price' => 500]);

        $this->book([
            'services' => [['service_id' => $service->id, 'quantity' => 1]],
            'allergies_other' => '',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Appointment::first()->allergies);
    }

    /** It stays optional. Forcing a health declaration to buy a manicure is not the salon's call. */
    public function test_the_note_is_never_required(): void
    {
        $this->makeSalonSettings();
        $service = $this->makeService(['price' => 500]);

        $this->book([
            'services' => [['service_id' => $service->id, 'quantity' => 1]],
            'allergies_other' => null,
        ])->assertSessionHasNoErrors();
    }

    /** The free-text box is bounded, like every other free-text field on the form. */
    public function test_an_over_long_note_is_refused(): void
    {
        $this->makeSalonSettings();
        $service = $this->makeService(['price' => 500]);

        $this->book([
            'services' => [['service_id' => $service->id, 'quantity' => 1]],
            'allergies_other' => str_repeat('a', 2001),
        ])->assertSessionHasErrors('allergies_other');

        $this->assertDatabaseCount('appointments', 0);
    }

    /** The retired dropdown's backing value is no longer a field, so posting it is simply ignored. */
    public function test_a_retired_dropdown_value_is_ignored(): void
    {
        Notification::fake();

        $this->makeSalonSettings();
        $service = $this->makeService(['price' => 500]);

        // A cached page or a replayed request can still carry it. It must not be
        // validated as a field any more, and it must not reach the column.
        $this->book([
            'services' => [['service_id' => $service->id, 'quantity' => 1]],
            'allergy_preference' => 'weak_or_brittle_nails',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Appointment::first()->allergies);
    }

    /* ------------------------------------------------------------------ */
    /* 4. Step 4 â€” asked of a first-timer only                             */
    /* ------------------------------------------------------------------ */

    /** A first-timer is asked, and the server requires the answer. */
    public function test_a_first_timer_must_say_what_they_had_before(): void
    {
        $this->makeSalonSettings();

        // `null` rather than omitted: the field is present but empty, which is
        // what the browser sends for a textarea nobody typed in.
        $this->book(['last_services_availed_note' => null])
            ->assertSessionHasErrors('last_services_availed');

        $this->assertDatabaseCount('appointments', 0);
    }

    /**
     * A signed-up customer with no history is a first-timer.
     *
     * `/book` is behind `auth.customer` â€” guests browse the catalogue but must
     * register before booking â€” so "has a history" is the only thing that
     * separates the two cases here, and an account with no completed appointment
     * is the new one.
     */
    public function test_a_customer_with_no_history_is_a_first_timer(): void
    {
        $this->makeSalonSettings();
        $user = $this->makeUser();
        $service = $this->makeService(['price' => 500]);

        $payload = $this->payload(['services' => [['service_id' => $service->id, 'quantity' => 1]]]);
        unset($payload['last_services_availed_note']);

        $this->actingAs($user)
            ->post(route('appointments.store'), $payload)
            ->assertSessionHasErrors('last_services_availed');

        $this->assertStringContainsString(
            'first visit with us',
            $this->html($user),
            'A customer with no history must be asked, in those words.'
        );
    }

    /**
     * A returning customer is not asked, and the answer is filled in for them.
     *
     * The booking still carries the field â€” it is just not the customer who
     * supplies it. Asserted on the stored column, because "the form did not ask"
     * is worth much less than "the salon can still see it".
     */
    public function test_a_repeat_customer_is_not_asked_and_is_still_documented(): void
    {
        Notification::fake();

        $this->makeSalonSettings();
        $user = $this->makeUser();

        $this->makeAppointment($user, $this->makeService([
            'name' => 'Gloss Manicure',
        ]), ['status' => AppointmentStatus::Completed]);

        $service = $this->makeService(['price' => 500]);

        $payload = $this->payload(['services' => [['service_id' => $service->id, 'quantity' => 1]]]);
        unset($payload['last_services_availed_note']);

        $this->actingAs($user)
            ->post(route('appointments.store'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'Gloss Manicure',
            Appointment::latest('id')->first()->last_services_availed,
            'The booking is documented from the customer\'s own record.'
        );
    }

    /** An answer the customer gave deliberately is never overwritten by the history. */
    public function test_a_typed_answer_beats_the_history(): void
    {
        Notification::fake();

        $this->makeSalonSettings();
        $user = $this->makeUser();

        $this->makeAppointment($user, $this->makeService([
            'name' => 'Gloss Manicure',
        ]), ['status' => AppointmentStatus::Completed]);

        $service = $this->makeService(['price' => 500]);

        $this->book([
            'services' => [['service_id' => $service->id, 'quantity' => 1]],
            'last_services_availed_note' => 'Actually, Colour & Highlights last month',
        ], $user)->assertSessionHasNoErrors();

        $this->assertSame(
            'Actually, Colour & Highlights last month',
            Appointment::latest('id')->first()->last_services_availed
        );
    }

    /**
     * A booking that was never completed is not a history.
     *
     * A pending booking says the customer intends to come and a cancelled one
     * says they decided not to. Neither is something that was done to their hair
     * and nails, so neither answers "what have you had done before" â€” and a
     * customer in that position is still asked.
     */
    public function test_an_unfinished_booking_is_not_a_history(): void
    {
        $this->makeSalonSettings();
        $user = $this->makeUser();

        $this->makeAppointment($user, $this->makeService(), ['status' => AppointmentStatus::Pending]);
        $this->makeAppointment($user, $this->makeService(), ['status' => AppointmentStatus::Cancelled]);

        $html = $this->html($user);

        $this->assertStringNotContainsString('Welcome back', $html);

        $service = $this->makeService(['price' => 500]);

        $payload = $this->payload(['services' => [['service_id' => $service->id, 'quantity' => 1]]]);
        unset($payload['last_services_availed_note']);

        $this->actingAs($user)
            ->post(route('appointments.store'), $payload)
            ->assertSessionHasErrors('last_services_availed');
    }

    /**
 * Every field sits on the step it belongs to, after the merges.
 *
 * Joining two panels is the easy half; putting every field from both halves on
 * the joined card is the half that can quietly go wrong, and nothing else here
 * would catch it. A field left behind on a panel that no longer exists is not a
 * missing heading and not a wrong step count — it is a form that silently stops
 * asking for something.
 *
 * So this slices the rendered page into its four panels and checks membership
 * directly, rather than trusting the headings.
 */
public function test_every_field_sits_on_the_step_it_belongs_to(): void
{
    $this->makeService();

    // A technician, so the dropdown branch renders rather than the "we will
    // assign one for you" fallback.
    \App\Models\Technician::factory()->create(['name' => 'Maria Santos']);

    $panels = $this->stepPanels($this->html());

    $this->assertSame([1, 2, 3, 4], array_keys($panels), 'The form must still be four panels.');

    // Step 1 keeps the service picker to itself. The rows are an
    // `x-salon.service-choice` component, so what identifies them is the binding
    // the component writes — `toggle()` and the shared `selectedIds` state.
    $this->assertStringContainsString('x-on:change="toggle(', $panels[1]);
    $this->assertStringContainsString('x-model="selectedIds"', $panels[1]);
    $this->assertStringNotContainsString('customer_name', $panels[1]);

    // Step 2 is the merge of the old steps 2 and 3: who you are, and when.
    foreach (['customer_name', 'customer_phone', 'preferred_date', 'preferred_time'] as $field) {
        $this->assertStringContainsString(
            'name="'.$field.'"',
            $panels[2],
            "The {$field} field must be on step 2, with the rest of the booking's own details.",
        );
    }

    // And the date picker itself is on that same panel, not floating outside it.
    $this->assertStringContainsString('datePickerAnchor', $panels[2]);

    // Step 3 is the free-text box, and only the free-text box.
    $this->assertStringContainsString('name="allergies_other"', $panels[3]);
    $this->assertStringNotContainsString('allergy_preference', $panels[3]);

    // Step 4 is the merge of the old steps 5, 6 and 7.
    foreach (['last_services_availed_note', 'technician_id', 'special_request', 'agree_terms'] as $field) {
        $this->assertStringContainsString(
            'name="'.$field.'"',
            $panels[4],
            "The {$field} field must be on the last step.",
        );
    }

    // And nothing spilled onto a step that should not carry it.
    $this->assertStringNotContainsString('preferred_time', $panels[3]);
    $this->assertStringNotContainsString('allergies_other', $panels[4]);
    $this->assertStringNotContainsString('agree_terms', $panels[3]);
}

/**
 * The rendered page split into its four step panels, keyed by step number.
 *
 * Sliced on the panels' own opening tags rather than on the headings, so a
 * heading that was renamed or merged cannot make a field look misplaced. The
 * window runs from the first panel to the navigation footer, which is the only
 * marker after the last panel and is a real element rather than a Blade comment
 * — Blade comments do not survive into the HTML.
 *
 * @return array<int, string>
 */
private function stepPanels(string $html): array
{
    $from = strpos($html, '<section x-show="step === 1"');
    // On the tag alone, not on its class list. The marker used to be
    // `<footer class="flex items-center justify-between`, which quietly made
    // this helper a test of the footer's Tailwind: restyling the row — as
    // stacking it on a phone did — broke a test about which step a field is on.
    $to = strpos($html, '<footer');

    $this->assertNotFalse($from, 'The wizard card should open with its first step panel.');
    $this->assertNotFalse($to, 'The wizard card should close with its navigation footer.');

    $body = substr($html, $from, $to - $from);

    preg_match_all('/<section x-show="step === (\d)"/', $body, $matches, PREG_OFFSET_CAPTURE);

    $panels = [];

    foreach ($matches[1] as $index => $step) {
        $panels[(int) $step[0]] = substr(
            $body,
            $matches[0][$index][1],
            $index + 1 < count($matches[1])
                ? $matches[1][$index + 1][1] - $matches[0][$index][1]
                : strlen($body),
        );
    }

    ksort($panels);

    return $panels;
}

/* ------------------------------------------------------------------ */
    /* 5. The gate, and where it lives                                     */
    /* ------------------------------------------------------------------ */

    /**
     * The agreement is required, and the server is the gate.
     *
     * The browser is not: the form is `novalidate`, and the client-side
     * `guardSubmit` that used to compensate is gone. So `accepted` in
     * `StoreBookingRequest` is now the only thing refusing a booking with no
     * agreement, which is why this asserts the checkbox is present and that a
     * payload without it is refused.
     */
    public function test_the_terms_checkbox_is_required_and_the_server_refuses_without_it(): void
    {
        $this->makeService();

        $html = $this->html();

        $this->assertStringContainsString('name="agree_terms"', $html);
        $this->assertStringContainsString('required', $html);
        $this->assertStringContainsString('I agree to the', $html);

        // Nothing in the browser stands in front of it any more. The form's
        // `submit` listener is the duplicate-submission guard, which cannot refuse
        // anything â€” it sets a flag and lets the request go.
        $this->assertStringNotContainsString('x-on:submit.prevent', $html);
        $this->assertStringNotContainsString('$event.preventDefault()', $html);

        $this->book(['agree_terms' => null])
            ->assertSessionHasErrors('agree_terms');

        $this->assertDatabaseCount('appointments', 0);
    }

    /**
     * The agreement starts unticked.
     *
     * `x-ui.form.checkbox` seeds its state from `old($name, (bool) $value))`, so
     * the `value="1"` this control used to carry pre-ticked the box on a fresh
     * form. The server never noticed, because a checked box posts `1` and
     * `accepted` is satisfied — which is exactly why it went unnoticed: consent
     * to the cancellation and rescheduling policies was being given on an
     * admin's behalf, and the request looked valid.
     *
     * Dropping the prop is the fix, and the two halves need separate assertions.
     * A fresh form must render unchecked, but a rejected submission that did
     * carry the agreement must come back with it still ticked, or the customer
     * loses their consent by answering one unrelated field wrong.
     */
    public function test_the_terms_checkbox_starts_unticked_but_survives_a_rejected_submission(): void
    {
        $this->makeSalonSettings();
        $this->makeService();

        // Fresh form: present, unchecked, and still posting `1` when ticked.
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="agree_terms"[^>]*>/',
            $html = $this->html(),
            'The agreement checkbox should render.',
            );

        preg_match('/<input[^>]*name="agree_terms"[^>]*>/', $html, $match);

        $this->assertStringNotContainsString(
            'checked',
            $match[0],
            'The agreement must not be pre-ticked on a fresh form.',
        );
        $this->assertStringContainsString(
            'value="1"',
            $match[0],
            'Un-ticking is the default; ticking must still post 1.',
        );

        // Rejected for an unrelated reason, with the agreement given.
        $this->book(['customer_phone' => 'not-a-phone'])
            ->assertSessionHasErrors('customer_phone')
            ->assertSessionHasInput('agree_terms', '1');

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="agree_terms"[^>]*checked[^>]*>/',
            $this->followingRedirects()->post(route('appointments.store'), $this->payload([
                'customer_phone' => 'not-a-phone',
            ]))->getContent(),
            'A rejected submission should come back with the agreement still ticked.',
        );
    }

    /**
     * The agreement is a field on the last step, not a step of its own.
     *
     * It used to be step 7: a card whose entire content was one checkbox. It is
     * now the last thing on step 4, above the Confirm Booking button in the
     * footer, so reading order and intent agree — agree, then book.
     *
     * Two things have to hold. The control is still on the last panel, and the
     * heading it used to live under is gone — because a heading is what made it
     * look like a step, and that is the thing being removed.
     */
    public function test_the_agreement_is_on_the_last_step_and_not_a_step_of_its_own(): void
    {
        $this->makeSalonSettings();
        $this->makeService();

        \App\Models\TermsAndCondition::create([
            'category' => 'booking',
            'content' => '<p>These terms govern every appointment made with the salon.</p>',
            'is_published' => true,
            'published_at' => now(),
        ]);

        $html = $this->html();

        // The checkbox and its three links survive — the policies are still
        // readable, one click away, through the shared Terms dialog.
        $this->assertStringContainsString('name="agree_terms"', $html);
        $this->assertStringContainsString('terms-modal', $html);

        // It is on the last panel, and the last panel is step 4.
        $this->assertMatchesRegularExpression(
            '/x-show="step === 4".*name="agree_terms"/s',
            $html,
            'The agreement must sit inside the final step, not after it.'
        );

        // And it is not a step: no panel claims it, and the heading is gone.
        $this->assertStringNotContainsString('7. Terms &amp; Conditions', $html);
        $this->assertStringNotContainsString('x-show="step === 7"', $html);

        // The metadata block that used to fill that card is gone too.
        $this->assertStringNotContainsString('Booking Terms (v1)', $html);
        $this->assertStringNotContainsString('Last updated', $html);
        $this->assertStringNotContainsString('Read the full', $html);
        $this->assertStringNotContainsString('termsLead', $html);
    }

    /**
     * One Confirm Booking, in one place.
     *
     * The last step used to carry two: one in the navigation footer and one in
     * the summary sidebar, both `type="submit"`, both saying the same thing. Two
     * buttons for one irreversible action on one screen, and on a phone they sat
     * at opposite ends of the page with nothing tying them together — which read
     * as two different bookings.
     *
     * The summary keeps it, because that is where the total it commits to and the
     * "By confirming you agree…" line already are.
     */
    public function test_the_last_step_offers_exactly_one_confirm_booking(): void
    {
        $this->makeSalonSettings();
        $this->makeService();

        $html = $this->html();

        // Scoped to the booking form: the customer navigation has its own
        // `<form>`s around the logout links, and counting the whole document
        // would be counting those.
        $form = $this->bookingForm($html);

        $this->assertSame(
            1,
            substr_count($form, 'type="submit"'),
            'The booking form should have exactly one submit control.',
        );

        $this->assertSame(
            1,
            substr_count($form, '>Confirm Booking<'),
            'There should be exactly one Confirm Booking action.',
        );

        // It is the one in the summary, next to the total and the disclaimer.
        $summary = strpos($form, 'By confirming you agree to our booking terms.');

        $this->assertNotFalse($summary, 'The summary disclaimer should still be there.');

        $button = strpos($form, '>Confirm Booking<');

        $this->assertGreaterThan(
            0,
            $button,
            'Confirm Booking should still exist.',
        );

        // Both sit inside the same summary panel, a short walk apart.
        $this->assertLessThan(
            1500,
            abs($summary - $button),
            'Confirm Booking should be the action inside the summary panel, beside its total.',
        );
    }

    /**
     * The footer still moves between steps, and still explains a disabled
     * Continue — it just no longer submits.
     */
    public function test_the_navigation_row_moves_between_steps_and_does_not_submit(): void
    {
        $this->makeSalonSettings();
        $this->makeService();

        $html = $this->html();

        $this->assertStringContainsString('x-on:click="next()"', $html);
        $this->assertStringContainsString('x-on:click="back()"', $html);

        // The gate and its explanation survive the restyle.
        $this->assertStringContainsString('id="step-gate-hint"', $html);
        $this->assertStringContainsString("missingOnCurrentStep().join(' · ')", $html);

        // …and the last step points at the one button rather than repeating it.
        $this->assertStringContainsString('Confirm your booking from the Booking Summary.', $html);
    }

    /**
     * The navigation row reflows instead of overflowing on a phone.
     *
     * It used to be a single `justify-between` row holding two buttons and a
     * 16rem hint — about 340px, which does not fit a 360px phone once the card's
     * padding is counted, and does not fit a 320px one at all. The hint is where
     * the reason the Continue button is disabled lives, so it is the part that
     * must not be the part that falls off the right edge.
     */
    public function test_the_navigation_row_stacks_on_a_phone(): void
    {
        $this->makeSalonSettings();
        $this->makeService();

        $form = $this->bookingForm($this->html());

        $this->assertMatchesRegularExpression(
            '/<footer class="flex flex-col[^"]*sm:flex-row/',
            $form,
            'The footer should stack on a phone and sit in a row from `sm` up.',
        );

        // The hint is capped from `sm` up only, so it may use the full width of a
        // stacked row instead of 16rem of it.
        //
        // The element is sliced out first rather than matched across attributes,
        // because `x-show` on this hint contains a `>` (`length > 0`) and so any
        // `[^>]*` walk between attributes stops in the wrong place.
        $hint = $this->elementWithId($form, 'step-gate-hint');

        $this->assertStringContainsString(
            'sm:max-w-[16rem]',
            $hint,
            'The gate hint should only be width-capped once there is room beside it.',
        );
        $this->assertStringNotContainsString(
            '"max-w-[16rem]',
            $hint,
            'The hint should not be capped at 16rem on a phone, where that is the whole row.',
        );
    }

    /**
     * The `<form>` that posts the booking, and nothing outside it.
     *
     * The customer navigation wraps its own forms around the logout links, so a
     * count taken over the whole document counts those too. Sliced on the store
     * route — which only this form posts to — and closed at the matching
     * `</form>`, which is the first one after it.
     */
    private function bookingForm(string $html): string
    {
        $from = strpos($html, route('appointments.store'));

        $this->assertNotFalse($from, 'The page should post the booking somewhere.');

        $to = strpos($html, '</form>', $from);

        $this->assertNotFalse($to, 'The booking form should be closed.');

        return substr($html, $from, $to - $from);
    }

    /**
     * The whole element carrying this id, from its opening `<` to its `</p>`.
     *
     * Returned whole rather than as a slice up to the id because `class` comes
     * after `id` in this markup, and because the tag cannot be delimited on `>`
     * — `x-show` here contains one (`length > 0`).
     */
    private function elementWithId(string $html, string $id): string
    {
        $at = strpos($html, 'id="'.$id.'"');

        $this->assertNotFalse($at, 'Expected an element with id "'.$id.'".');

        $start = strrpos(substr($html, 0, $at), '<');
        $end = strpos($html, '</p>', $at);

        $this->assertNotFalse($end, 'Expected that element to be a <p>.');

        return substr($html, $start, $end - $start);
    }

    /* ------------------------------------------------------------------ */
    /* 6. Where a refused submission lands                                */
    /* ------------------------------------------------------------------ */

    /**
     * Refused for the agreement, the form comes back on the step with the agreement.
     *
     * This is the bug, in the customer's words. "You must agree to the Terms and
     * Conditions to book." was arriving in the toast on a page showing the service
     * list — step 1, three Continue clicks away from the box the sentence was
     * about. The message was right and the screen was wrong, and the screen was
     * wrong because a fresh render of a server-rendered page opens on whatever the
     * wizard's hardcoded default is, which has always been 1.
     *
     * Asserted as the number the server sends rather than as the toast, which was
     * never the thing that was broken.
     */
    public function test_a_booking_refused_for_the_agreement_comes_back_on_the_last_step(): void
    {
        $this->makeSalonSettings();
        $this->makeService();

        // Everything answered except the one box.
        $this->book(['agree_terms' => null])->assertSessionHasErrors('agree_terms');

        $html = $this->bookAndFollow(['agree_terms' => null]);

        $this->assertStringContainsString(
            '"startStep":4',
            $html,
            'A refusal about the agreement must open the panel holding the agreement.',
        );

        // The number sent is only worth anything if something reads it. Asserted on
        // the read rather than on the outcome, because `step: 1` in the component's
        // own properties is still there — it is written into the HTML for every
        // visitor — and a config key nothing reads is the shape of the bug this
        // test exists to prevent. Read through `goTo()`, which is also what clamps
        // it, so a step count that disagreed could not park the wizard on a panel
        // that does not exist.
        $this->assertMatchesRegularExpression(
            '/this\.goTo\(\s*this\.config\.startStep/',
            $html,
            'The wizard must take its opening step from the server, not from its own default.',
        );
    }

    /**
     * Changing step puts the new step in view, and only when it is not already.
     *
     * Continue sits at the FOOT of the card, so it is pressed at the bottom of a
     * long step, and the steps swap with `x-show` — which changes the document
     * height and leaves the scroll offset exactly where it was. The customer is
     * then looking at whatever now occupies that offset: the middle of a form, or
     * past the end of one. Clicking Continue then reads as a button that did
     * nothing.
     *
     * The "only when it is not already" half matters as much as the scroll. The
     * request was to move on without being dragged back, and a card that
     * already straddles the top of the viewport would be scrolled up and out of
     * the way by an unconditional scrollIntoView — undoing the step change the
     * customer just asked for.
     */
    public function test_changing_step_reveals_the_new_step_without_dragging_the_page_back(): void
    {
        $html = $this->html();

        // The card that gets revealed, and the id `revealStep()` looks for.
        $this->assertStringContainsString('id="booking-step-card"', $html);
        $this->assertStringContainsString('scroll-mt-20', $html);

        // Scrolling to `block: start` under a `sticky top-0` navbar would hide the
        // step counter, which is the thing the scroll is meant to show.
        $this->assertMatchesRegularExpression(
            '/id="booking-step-card"[^>]*class="[^"]*scroll-mt-/',
            $html,
            'The steps card needs a scroll offset for the sticky customer navbar.',
        );

        // A step change that did not move must not scroll — that is what stops
        // `goTo()` from yanking the page on a clamped or already-current step.
        $this->assertStringContainsString('const moved = next !== this.step;', $html);
        $this->assertStringContainsString('if (moved) this.revealStep();', $html);

        // The "not already in view" guard: top on screen AND most of the card
        // below it means the customer is looking at the card already.
        $this->assertStringContainsString(
            'if (top >= 0 && height > window.innerHeight * 0.5) return;',
            $html,
            'revealStep() must not scroll a card the customer is already looking at.',
        );

        // `$nextTick`, because `x-show` has not swapped the panels when `goTo`
        // returns and the card is still the old step's height.
        $this->assertMatchesRegularExpression(
            '/revealStep\(\)\s*\{\s*this\.\$nextTick/',
            $html,
            'revealStep() must measure the card after the step panels have swapped.',
        );

        // And it honours a stated preference for less motion.
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $html);
    }

    /**
     * A fresh form opens on step 1, as it always has.
     *
     * The other half of the fix, and the one that would be easy to break while
     * fixing the first: a clean error bag must send 1 rather than "the last step
     * that has ever had an error" or anything else sticky. A customer arriving at
     * `/book` for the first time is shown the service list.
     */
    public function test_an_unsubmitted_form_still_opens_on_the_first_step(): void
    {
        $this->makeService();

        $this->assertStringContainsString('"startStep":1', $this->html());

        $this->assertSame(
            1,
            BookingSteps::startStep(new ViewErrorBag()),
            'An empty error bag must be step 1, not a default that could drift.',
        );
    }

    /**
     * The refusal opens the first thing that has to be put right.
     *
     * Earliest, not last and not first-in-the-toast-by-accident: a customer who
     * sent no name at all is sent to step 2, and a customer who sent a name but no
     * agreement is sent to step 4. Both are asserted through the real request, so
     * the ordering comes from what `StoreBookingRequest` actually refuses on
     * rather than from a list written out here.
     *
     * A step with no error of its own is not a candidate. The health note is the
     * one field on step 3, it is optional, and refusing a booking for it can only
     * happen over a length — which is asserted here so the map's claim to cover
     * step 3 is a tested one rather than a hopeful one.
     */
    public function test_the_refusal_opens_the_earliest_step_with_something_to_fix(): void
    {
        $this->makeSalonSettings();
        $this->makeService();

        // Both ends of the wizard at once, and the answer is the earlier one.
        $this->assertStringContainsString('"startStep":2', $this->bookAndFollow([
            'customer_name' => '',
            'agree_terms' => null,
        ]));

        // Nothing wrong on step 1, wrong only on the last: the last one.
        $this->assertStringContainsString('"startStep":4', $this->bookAndFollow([
            'agree_terms' => null,
            'last_services_availed_note' => null,
        ]));

        // Step 3's one field, refused for length.
        $this->assertStringContainsString('"startStep":3', $this->bookAndFollow([
            'allergies_other' => str_repeat('a', 2001),
        ]));

        // An empty basket is step 1's own problem, and stays there.
        $this->assertStringContainsString('"startStep":1', $this->bookAndFollow([
            'services' => [],
        ]));
    }

    /**
     * A nested error is claimed by the step that owns the field, not dropped.
     *
     * `services.0.service_id` and `services.0.quantity` are how the basket's rules
     * report themselves, and an error key nobody claims would send the customer to
     * step 1 for no stated reason — the one outcome this feature must never
     * produce. Prefix matching is what keeps that from being a silent no-op, and
     * `special_request_notes` is the boundary that stops it matching too much.
     */
    public function test_a_nested_error_key_is_claimed_by_its_own_step(): void
    {
        $this->assertSame(1, BookingSteps::stepForField('services'));
        $this->assertSame(1, BookingSteps::stepForField('services.0.service_id'));
        $this->assertSame(1, BookingSteps::stepForField('services.9.quantity'));
        $this->assertSame(4, BookingSteps::stepForField('special_request'));
        $this->assertNull(BookingSteps::stepForField('special_request_notes'));
        $this->assertNull(BookingSteps::stepForField('_token'));
    }

    /**
     * Every field the form puts on a step is claimed by that step, in the map the
     * refusal is computed from.
     *
     * `test_every_field_sits_on_the_step_it_belongs_to` reads the rendered page.
     * This reads the other list — `BookingSteps::STEP_FIELDS` — and checks it
     * against the same page, because the two are exactly what can drift: a field
     * moved to another panel fixes the first test's rendering and leaves the
     * refusal pointing at a step that no longer holds it. Neither test can catch
     * that on its own.
     *
     * The only tolerated absence is `services`, which is not a server-rendered
     * field at all: the basket rows are Alpine checkboxes and the `services[…]`
     * inputs are written by the component at runtime. It is claimed here because a
     * refusal on it has to land on the panel that owns the picker.
     */
    public function test_the_step_map_agrees_with_the_form_it_describes(): void
    {
        $this->makeService();

        // A technician, so the dropdown branch renders rather than the "we will
        // assign one for you" fallback.
        \App\Models\Technician::factory()->create(['name' => 'Maria Santos']);

        $panels = $this->stepPanels($this->html());

        foreach (BookingSteps::STEP_FIELDS as $step => $fields) {
            foreach ($fields as $field) {
                if ($field === 'services') {
                    continue;
                }

                $this->assertStringContainsString(
                    'name="'.$field.'"',
                    $panels[$step],
                    "The map says {$field} is answered on step {$step}, so step {$step} has to hold it.",
                );
            }
        }
    }

    /**
     * Nothing the customer can fill in is missing from the map.
     *
     * The other direction, and the one that matters most: a field added to the form
     * and not added to `STEP_FIELDS` is a field the server can refuse and the
     * refusal cannot place. The customer gets the message in the toast, lands on a
     * step that has nothing to do with it, and is back to the bug this whole thing
     * was for — so this walks the rendered inputs rather than trusting the class
     * to have kept up.
     *
     * The two exceptions are not form fields. `_token` is the CSRF token, and
     * `idempotency_key` is validated `nullable` with no rule that can fail, so
     * neither can produce an error to place.
     *
     * Scanned inside the booking form rather than across the page, because the page
     * also carries the layout's own forms — the logout button is one — and the
     * `<meta name="viewport">` in its `<head>` would otherwise read as a field the
     * booking form forgot to place.
     */
    public function test_no_field_the_customer_fills_in_is_missing_from_the_step_map(): void
    {
        $this->makeService();

        \App\Models\Technician::factory()->create(['name' => 'Maria Santos']);

        $html = $this->html();

        $anchor = strpos($html, 'x-data="bookingForm"');
        $this->assertNotFalse($anchor, 'The booking form mounts the wizard.');

        $this->assertSame(
            1,
            preg_match('/<form\b.*?<\/form>/s', substr($html, strrpos(substr($html, 0, $anchor), '<form')), $form),
            'Could not find the booking form itself.'
        );

        preg_match_all('/name="([a-z_]+)"/', $form[0], $found);

        $inputs = array_values(array_unique(array_diff($found[1], ['_token', 'idempotency_key'])));

        $this->assertNotEmpty($inputs, 'The form should render inputs to check.');

        foreach ($inputs as $field) {
            $this->assertNotNull(
                BookingSteps::stepForField($field),
                "The form asks for {$field}, so the refusal has to know which step it is on.",
            );
        }

        // And the gate, which words each omission from its own hand, is only
        // trustworthy if it covers the same fields. The agreement is the one this
        // feature is about, and it is the one whose gate used to read a string
        // instead of the number `stepCount` was parsed into.
        $this->assertStringNotContainsString(
            'this.step === this.config.stepCount',
            $html,
            'The last step must be compared against the parsed number, not the raw config value.',
        );
    }

    /* ------------------------------------------------------------------ */

    /** N services in one named category. */
    private function makeCategory(string $category, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->makeService([
                'name' => $category.' Service '.$i,
                'category' => $category,
            ]);
        }
    }
}
