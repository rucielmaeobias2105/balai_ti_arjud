<?php

namespace Tests\Feature;

use App\Models\SalonSetting;
use App\Models\ServiceCategory;
use App\Services\BookingAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The booking date rules and the toast surface.
 *
 * Two of the date rules are the same rule reached two ways — the date input's
 * `min` and the server's rejection — so both are asserted. A change that only
 * moves one leaves a date bookable in the UI but not in the database, or the
 * reverse.
 *
 * The blocked-date cases went with the Calendar & Blocked Dates feature and have
 * come back in a different shape: whole-day closures now disable a date, and a
 * partial closure greys individual times out of the dropdown. `BlockedSlotsTest`
 * covers those, including the server-side refusal behind the greyed-out option.
 *
 * The category-colour tests stay. The colour a category resolves to is still a
 * property of the category row and the category screen still uses it, even though
 * no admin grid paints chips with it any more.
 */
class BookingRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeSalonSettings();
    }

    /* ------------------------------------------------------------------ */
    /* 1. The schedule: every day, 09:00–17:00                            */
    /* ------------------------------------------------------------------ */

    /**
     * The salon books to one window, seven days a week.
     *
     * It used to vary: Monday–Thursday to 18:00, Friday to 19:00, Saturday from
     * 08:00 to 19:00, Sunday to 17:00. That is gone, so this pins the flat
     * schedule — and pins it on the *stored* settings rather than on
     * `defaultHours()` alone, because that is what the running app reads and the
     * two can drift: the default only applies to a fresh install, while an
     * existing `salon_settings` row keeps whatever hours it was seeded with.
     */
    public function test_the_salon_is_open_every_day_from_nine_to_five(): void
    {
        // Read back rather than compared against `defaultHours()`: this suite's
        // `makeSalonSettings()` fixture is what the running app reads, and the
        // default only applies to a fresh install. The two drifting is exactly the
        // bug this assertion is for.
        $hours = SalonSetting::current()->operating_hours;

        $this->assertSame(
            array_keys(SalonSetting::dayNames()),
            array_keys($hours),
            'Every day of the week should have an entry, so none reads as closed.',
        );

        foreach (SalonSetting::dayNames() as $day => $label) {
            $this->assertSame(['09:00', '18:00'], $hours[$day] ?? null, "{$label} hours.");
        }
    }

    /**
     * The same window every day means the same slots every day.
     *
     * This is the assertion that caught the old per-day variation: a customer
     * could book a slot that existed on two days and not on five others.
     *
     * The fixture steps in whole hours, so the last slot is 17:00 rather than the
     * half-hourly 16:30 a 30-minute interval would produce. Asserted against the
     * fixture's own interval rather than a literal, so changing it does not mean
     * rewriting the expectation.
     *
     * The grid is then compared slot-for-slot against the opening window minus
     * the lunch break, rather than by counting. A count had to know how many
     * starts the interval produced *and* how many of them lunch took — one on an
     * hourly grid, two on a half-hourly one — so it broke whenever either changed.
     * Listing them is both exact and independent of both.
     */
    public function test_every_day_offers_the_same_slots_up_to_closing(): void
    {
        $settings = SalonSetting::current();

        $reference = $settings->slotsFor(today()->addDay());
        $hours = $settings->hoursFor(today()->addDay());
        $step = max(15, (int) $settings->slot_interval_minutes);

        $this->assertNotEmpty($reference);
        $this->assertSame($hours[0], $reference[0], 'The first slot is when the salon opens.');
        // The loop is `while ($start->lt($end))`, so the last slot is the closing
        // time minus one step. Adding the step back gives the closing time,
        // which is what proves no slot is offered past closing.
        $this->assertSame($hours[1], Carbon::parse(end($reference))->addMinutes($step)->format('H:i'));

        // The whole opening window, less the lunch break, and nothing else.
        $day = today()->addDay()->format('Y-m-d');
        $cursor = Carbon::createFromFormat('Y-m-d H:i', $day.' '.$hours[0]);
        $close = Carbon::createFromFormat('Y-m-d H:i', $day.' '.$hours[1]);
        $window = [];

        while ($cursor->lt($close)) {
            $window[] = $cursor->format('H:i');
            $cursor->addMinutes($step);
        }

        $this->assertSame(
            array_values(array_filter($window, fn ($slot) => ! $settings->isTimeDuringBreak($slot))),
            $reference,
            'The offered slots should be the whole opening window bar the lunch break.',
        );

        foreach (SalonSetting::dayNames() as $day) {
            $this->assertSame(
                $reference,
                $settings->slotsFor(today()->next($day)),
                'Every open day should offer the same slot list.',
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* 2. Minimum notice, horizon and the server's gate                   */
    /* ------------------------------------------------------------------ */

    public function test_today_and_the_past_are_not_bookable(): void
    {
        $availability = BookingAvailability::make();

        $this->assertFalse($availability->isDateAvailable(today()->toDateString()));
        $this->assertFalse($availability->isDateAvailable(today()->subDay()->toDateString()));
        $this->assertFalse($availability->isDateAvailable(today()->subWeek()->toDateString()));
    }

    /**
     * The wording is asserted, not just the refusal.
     *
     * It is shown to the customer under the date field, as an inline error and as
     * a toast, so a reworded constant is a user-visible change rather than a
     * refactor.
     */
    public function test_the_minimum_notice_message_is_the_specified_wording(): void
    {
        $this->assertSame(
            'Appointments must be booked at least one day in advance.',
            BookingAvailability::MINIMUM_NOTICE_MESSAGE,
        );

        $this->assertContains(
            BookingAvailability::MINIMUM_NOTICE_MESSAGE,
            BookingAvailability::make()->dateProblems(today()->toDateString()),
        );
    }

    public function test_tomorrow_is_the_first_bookable_date(): void
    {
        $availability = BookingAvailability::make();

        $this->assertSame(today()->addDay()->toDateString(), $availability->firstBookableDate()->toDateString());
        $this->assertTrue($availability->isDateAvailable($availability->firstBookableDate()->toDateString()));
    }

    /**
     * The booking window the picker is given, so the calendar cannot offer a
     * date the server would refuse.
     *
     * This used to be asserted against the native date input's own `min`/`max`
     * attributes. The picker draws its own day cells now, so there is no
     * browser picker left to bound — the window reaches the panel through the
     * config node instead, and `dayState()` is what acts on it. Same rule, new
     * carrier.
     */
    public function test_the_booking_picker_is_bounded_by_the_booking_window(): void
    {
        // Signed in, because `/book` is behind the customer guard — an
        // unauthenticated request is redirected to the login screen and asserts
        // nothing about the picker.
        $html = $this->actingAs($this->makeUser())
            ->get(route('appointments.create'))
            ->assertOk()
            ->getContent();

        preg_match('/<script type="application\/json" id="bta-booking-config">(.*?)<\/script>/s', $html, $matches);

        $this->assertNotEmpty($matches, 'The booking page must ship its config node.');

        $config = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);

        $this->assertSame(today()->addDay()->toDateString(), $config['minDate']);
        $this->assertSame(BookingAvailability::make()->lastBookableDate()->toDateString(), $config['maxDate']);
    }

    public function test_booking_for_today_is_rejected_by_the_server(): void
    {
        $this->actingAs($this->makeUser())
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->payload($this->makeService(), today()->toDateString()))
            ->assertSessionHasErrors('preferred_date');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_booking_for_tomorrow_succeeds(): void
    {
        $tomorrow = today()->addDay();

        $this->actingAs($this->makeUser())
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->payload($this->makeService(), $tomorrow->toDateString()))
            // With `?view=ID`, which is where a booking lands: the dialog for the
            // one just made opens on the list rather than the customer having to
            // go and find it.
            ->assertRedirect(route('appointments.index', ['view' => 1]));

        $this->assertDatabaseCount('appointments', 1);

        // Read the model rather than assertDatabaseHas: the column is a DATE that
        // SQLite round-trips as "Y-m-d 00:00:00", so a bare date string never
        // matches the stored value.
        $appointment = \App\Models\Appointment::sole();

        $this->assertSame($tomorrow->toDateString(), $appointment->preferred_date->toDateString());
        $this->assertSame('pending', $appointment->status->value);
    }

    /**
     * A new booking is Pending, which is what keeps it off the salon's work
     * queue until an admin accepts it.
     */
    public function test_a_new_booking_is_pending(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();

        $this->actingAs($user)->post(
            route('appointments.store'),
            $this->payload($service, today()->addDay()->toDateString()),
        );

        $this->assertSame('pending', \App\Models\Appointment::sole()->status->value);
    }

    public function test_rescheduling_to_today_is_rejected(): void
    {
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user, null, [
            'preferred_date' => today()->addDays(3),
            'status' => 'confirmed',
        ]);

        $this->actingAs($user)
            ->from(route('appointments.reschedule', $appointment))
            ->patch(route('appointments.reschedule.update', $appointment), [
                'preferred_date' => today()->toDateString(),
                'preferred_time' => '10:00',
            ])
            ->assertSessionHasErrors('preferred_date');
    }

    /**
     * The horizon is a rule too, and it is enforced on both sides of the wire.
     *
     * A date past `booking_lead_days` is refused by the server, and the booking
     * form's `max` is the same value — so the picker cannot offer a day the server
     * will reject.
     */
    public function test_a_date_past_the_booking_horizon_is_refused(): void
    {
        $settings = SalonSetting::current();
        $settings->update(['booking_lead_days' => 30]);

        $tooFar = Carbon::parse(today()->addDays(31));

        $this->actingAs($this->makeUser())
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->payload($this->makeService(), $tooFar->toDateString()))
            ->assertSessionHasErrors('preferred_date');

        $this->assertDatabaseCount('appointments', 0);
    }

    /**
     * A closed weekday is refused, and says why.
     *
     * This is what replaced the demo blocked dates the seeder used to create: a
     * day the salon is closed is still a day no one can book, and the customer is
     * still told why rather than just losing their selection.
     */
    public function test_a_closed_weekday_is_refused_and_says_so(): void
    {
        $settings = SalonSetting::current();
        $settings->update(['operating_hours' => ['monday' => ['09:00', '17:00']]]);

        $sunday = Carbon::parse(today()->next(Carbon::SUNDAY));

        $problems = BookingAvailability::make()->dateProblems($sunday->toDateString());

        $this->assertContains('We are closed on Sundays.', $problems);

        $this->actingAs($this->makeUser())
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->payload($this->makeService(), $sunday->toDateString()))
            ->assertSessionHasErrors('preferred_date');
    }

    /**
     * The slots endpoint reports the same reasons the server would.
     *
     * This is what the date picker's hint is built from, so it has to agree with
     * what a submit would do — a hint built from a different rule is how a
     * customer picks a date that then fails.
     */
    public function test_the_slot_lookup_endpoint_explains_why_a_date_is_refused(): void
    {
        $payload = $this->getJson(route('appointments.slots', [
            'date' => today()->toDateString(),
        ]))->assertOk()->json();

        $this->assertFalse($payload['open']);
        $this->assertContains(BookingAvailability::MINIMUM_NOTICE_MESSAGE, $payload['problems']);
        $this->assertSame([], $payload['slots'], 'A refused date offers no slots at all.');
    }

    /**
     * The blocked-date map in that response is now permanently empty.
     *
     * Asserted so the shape stays: the booking form still reads the key, and a
     * client that expects a non-empty object is not going to be surprised by this
     * endpoint in a way the form does not already handle.
     */
    public function test_the_slot_lookup_still_returns_an_empty_blocked_dates_map(): void
    {
        $payload = $this->getJson(route('appointments.slots', [
            'date' => today()->addDay()->toDateString(),
        ]))->assertOk()->json();

        $this->assertArrayHasKey('blockedDates', $payload);
        $this->assertSame([], (array) $payload['blockedDates']);
    }

    /** A closed weekday comes back with no slots, rather than slots that fail on submit. */
    public function test_a_closed_weekday_offers_no_slots(): void
    {
        $settings = SalonSetting::current();
        $settings->update(['operating_hours' => ['monday' => ['09:00', '17:00']]]);

        $sunday = Carbon::parse(today()->next(Carbon::SUNDAY));

        $this->assertSame([], BookingAvailability::make()->availableSlots($sunday->toDateString()));
    }

    /* ------------------------------------------------------------------ */
    /* 3. Toasts                                                           */
    /* ------------------------------------------------------------------ */

    public function test_the_toast_container_is_mounted_on_both_sides(): void
    {
        $admin = $this->makeAdmin();

        $this->get('/')->assertOk()->assertSee('data-toast-container', false);
        $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->assertSee('data-toast-container', false);
    }

    public function test_booking_for_today_raises_an_amber_toast(): void
    {
        $this->actingAs($this->makeUser())
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->payload($this->makeService(), today()->toDateString()))
            ->assertSessionHas('toast', [
                'type' => 'warning',
                'message' => BookingAvailability::MINIMUM_NOTICE_MESSAGE,
            ]);
    }

    public function test_accepting_and_declining_raise_toasts(): void
    {
        $admin = $this->makeAdmin();

        $accepted = $this->makeAppointment(null, null, ['status' => 'pending']);
        $this->actingAs($admin, 'admin')
            ->patch(route('admin.appointments.status', $accepted), ['status' => 'confirmed'])
            ->assertSessionHas('toast', ['type' => 'success', 'message' => 'Appointment accepted.']);

        $declined = $this->makeAppointment(null, null, ['status' => 'pending']);
        $this->actingAs($admin, 'admin')
            ->patch(route('admin.appointments.status', $declined), ['status' => 'declined'])
            ->assertSessionHas('toast', ['type' => 'warning', 'message' => 'Appointment declined.']);
    }

    /**
     * The spec asks for a fade in, a ~4 second stay, then a dismissal.
     *
     * Asserted on the three mechanics rather than one string: a single timer
     * in the component covers the server flash, and the JS default covers a
     * toast raised without a page load.
     *
     * The live default is asserted as the rendered pair of durations rather than
     * as the literal `detail.duration || 4000` this used to look for. The
     * fallback is now chosen by tone — a success is 4s, an error is 8s, because
     * a validation message arrives as the page reloads and a toast that expires
     * while the person is still finding the offending field reads as the form
     * accepting the input. Asserting the old literal pinned an implementation
     * detail that the change deliberately generalised, so the longer error stay
     * was real but untested.
     */
    public function test_a_toast_lasts_about_four_seconds(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // The fallback follows the tone — 4s for everything but an error, 8s for
        // an error — and an explicit duration still wins over both.
        $this->assertStringContainsString("type === 'error' ? 8000 : 4000", $html, 'Live toast default by tone.');
        $this->assertStringContainsString('detail.duration || fallback', $html, 'Explicit duration wins.');

        $this->assertStringContainsString("classList.add('opacity-0')", $html, 'Fades out before removal.');
        $this->assertStringContainsString('setTimeout(() => dismiss(wrap)', $html, 'Auto-dismiss.');
        $this->assertStringContainsString('duration-300', $html, 'Fade transition.');
    }

    /**
     * The server-side flash carries its own timer, so a toast raised by a
     * redirect disappears without any JavaScript having to run — and it fades
     * on the same schedule as a live one, so a redirect does not feel cheaper
     * than an in-page action.
     */
    public function test_a_flashed_toast_dismisses_itself(): void
    {
        $html = $this->actingAs($this->makeAdmin(), 'admin')
            ->withSession(['toast' => ['type' => 'success', 'message' => 'Appointment accepted.']])
            ->get('/admin')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Appointment accepted.', $html);
        $this->assertStringContainsString('$el.classList.add(\'opacity-0\')', $html, 'Fades before it goes.');
        $this->assertStringContainsString('setTimeout(() => $el.remove(), 300)', $html);
        $this->assertStringContainsString('}, 4000);', $html, 'Four seconds on screen.');
    }

    /** A raised toast is appended without a request, outside any dialog. */
    public function test_a_toast_can_be_raised_without_a_request(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('window.btaToast', $html);
        $this->assertStringContainsString("x-on:bta:toast.window", $html);
    }

    /* ------------------------------------------------------------------ */
    /* 4. Category colours                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * A category's colour still comes from the database.
     *
     * These used to assert against the admin calendar's chips, because that grid
     * was the only place a category's colour was painted. The grid went with the
     * Calendar & Blocked Dates feature, so the assertion is now on the model: an
     * admin changing a colour on the category screen changes what
     * `categoryColor()`, `tint` and `shade` resolve to, which is what any screen
     * that paints a chip would read.
     *
     * `makeService` already created the category row via the model's saving hook,
     * so this is a colour change rather than an insert.
     */
    public function test_chip_colours_still_come_from_the_database(): void
    {
        $service = $this->makeService(['name' => 'Gelish Manicure', 'category' => 'Manicure & Pedicure']);

        $category = ServiceCategory::where('name', 'Manicure & Pedicure')->sole();
        $category->update(['color' => '#123456']);

        $this->assertSame('#123456', $category->fresh()->color);
        $this->assertSame('#123456', $service->fresh()->categoryColor());

        // A chip paints a tint of the stored colour, and its label a darkened
        // shade of it — not the hex itself, which is what the old calendar chips
        // derived on the fly. Asserted here because `shade()` is the model's
        // arithmetic and nothing on screen currently exercises it.
        $this->assertSame('rgba(18, 52, 86, 0.16)', $category->tint);
        $this->assertSame($category->darken(0.22), $category->shade);
        $this->assertSame('#0E2943', $category->shade, '18/52/86 darkened 22% toward black.');
    }

    public function test_a_new_category_takes_the_next_unused_palette_colour(): void
    {
        $used = ['#E11D48', '#EC4899'];

        $this->assertSame('#8B5CF6', ServiceCategory::nextColorFromPalette($used));
    }

    public function test_the_palette_maps_the_salons_own_wording_to_its_colour(): void
    {
        // The price list says "Spa Services" and "Bleaching Services"; the
        // catalogue says "Spa Services" and "Bleaching". Both must land on the
        // colour the list gives them rather than consuming a palette slot.
        $this->assertSame('#8B5CF6', ServiceCategory::paletteColorFor('Spa Services'));
        $this->assertSame('#8B5CF6', ServiceCategory::paletteColorFor('Spa'));
        $this->assertSame('#64748B', ServiceCategory::paletteColorFor('Bleaching'));
        $this->assertSame('#EA580C', ServiceCategory::paletteColorFor('Hair Care'));
        $this->assertSame('#0D9488', ServiceCategory::paletteColorFor('Glutathione Push OR Drip'));
        $this->assertSame('#0D9488', ServiceCategory::paletteColorFor('Glutathione Push/Drip'));
        $this->assertNull(ServiceCategory::paletteColorFor('Something Off The List'));
    }

    /**
     * A service whose category row has gone still resolves to *a* colour.
     *
     * Worth keeping after the grid went: it is the branch that stops a
     * soft-deleted or never-registered category from resolving to nothing at all,
     * and it would otherwise only surface on whichever screen next paints a chip.
     *
     * The category row is removed rather than never created, because creating a
     * service always makes its category row — that is what `Service::booted()`
     * does — so "no row" is only reachable by taking one away.
     */
    public function test_a_service_with_no_category_row_still_resolves_to_a_colour(): void
    {
        $service = $this->makeService(['name' => 'Walk In Facial', 'category' => 'Retired Category']);

        ServiceCategory::where('name', 'Retired Category')->delete();

        $this->assertNull($service->fresh()->serviceCategory, 'The category row should be gone.');
        $this->assertMatchesRegularExpression(
            '/^#[0-9A-F]{6}$/i',
            $service->categoryColor(),
            'A service with no category row must still resolve to a hex colour.',
        );
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    protected function payload(\App\Models\Service $service, string $date): array
    {
        return [
            'services' => [[
                'service_id' => $service->id,
                'service_variant_id' => null,
                'quantity' => 1,
            ]],
            'customer_name' => 'Liza Mercado',
            'customer_phone' => '09171234567',
            'preferred_date' => $date,
            'preferred_time' => '10:00',
            // Step 4 asks a first-timer what they had done before, and these
            // tests post as one. `BookingRulesTest` is about date rules, so the
            // answer is a fixed string rather than anything meaningful.
            'last_services_availed_note' => 'Glow Manicure',
            'down_payment_reference' => 'GCASH-123',
            'agree_terms' => '1',
        ];
    }
}