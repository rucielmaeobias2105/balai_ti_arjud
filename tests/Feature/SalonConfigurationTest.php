<?php

namespace Tests\Feature;

use App\Models\SalonSetting;
use App\Services\BookingAvailability;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `salon_settings` is backend-only configuration, and everything on the booking
 * path still reads it.
 *
 * This suite used to test an admin edit screen for these values. That screen is
 * gone — the values are configuration, not records, so they change in the seeder
 * or a migration and deploy like any other code. The row and every column stayed,
 * because all of it is read on the booking path:
 *
 *   - `operating_hours` → which weekdays are open, and the window on each;
 *   - `slot_interval_minutes` → how long one appointment slot lasts;
 *   - `booking_lead_days` → how far ahead bookings open;
 *   - `minimum_notice_hours` → how much notice a booking needs; and
 *   - `down_payment_percentage` → the deposit recorded against a booking.
 *
 * So the coverage that mattered is kept and re-aimed. Each test writes a value the
 * way an operator now would — directly on the row — and then asserts the *effect*,
 * because "the column holds 45" is worth nothing on its own and "45 produces
 * quarter-hour slots" is the thing that would break.
 *
 * The removal itself is asserted in `SalonSettingsRemovalTest`.
 */
class SalonConfigurationTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ */
    /* The singleton                                                        */
    /* ------------------------------------------------------------------ */

    public function test_the_configuration_is_created_with_a_usable_default(): void
    {
        $settings = SalonSetting::current();

        $this->assertNotEmpty($settings->operating_hours, 'The salon has to open somewhere.');

        // Not "today" and not "tomorrow": the salon is shut every Monday, so both
        // of those depend on which day the suite happens to run and this test
        // would fail one day in seven. `bookableDate()` asks the settings which
        // days are actually open, which is the claim being made here anyway.
        $this->assertTrue($settings->isOpenOn($this->bookableDate()));
        $this->assertNotSame([], $settings->slotsFor($this->bookableDate()));

        // And the standing weekly closure is in the default itself, not left to be
        // blocked by hand: no Monday, so no slot on a Monday.
        $this->assertArrayNotHasKey('monday', $settings->operating_hours);
        $this->assertSame([], $settings->slotsFor($this->nextWeekday('monday')));

        // Every default is explicit. Column-level DB defaults are not reflected on
        // the in-memory model, and a `booking_lead_days` of 0 would make every date
        // look unbookable.
        $this->assertSame(30, $settings->slot_interval_minutes);
        $this->assertSame(24, $settings->minimum_notice_hours);
        $this->assertSame(50, $settings->down_payment_percentage);
        $this->assertFalse($settings->down_payment_required, 'The salon takes no deposit for a web booking.');
    }

    public function test_current_returns_the_same_row_every_time(): void
    {
        $first = SalonSetting::current();

        SalonSetting::current()->update(['slot_interval_minutes' => 15]);

        // Every booking rule resolves "the salon's hours" through this. A second row
        // would make it a coin toss on the booking form.
        $this->assertSame(1, SalonSetting::count());
        $this->assertSame($first->id, SalonSetting::current()->id);
        $this->assertSame(15, SalonSetting::current()->slot_interval_minutes);
    }

    /* ------------------------------------------------------------------ */
    /* Operating hours drive availability                                   */
    /* ------------------------------------------------------------------ */

    public function test_the_slot_interval_decides_the_starts_the_booking_form_offers(): void
    {
        $date = $this->bookableDate();

        SalonSetting::current()->update([
            'operating_hours' => $this->hours('09:00', '18:00'),
            'slot_interval_minutes' => 60,
        ]);

        $this->assertContains('10:00', BookingAvailability::make()->availableSlots($date));
        $this->assertNotContains('10:30', BookingAvailability::make()->availableSlots($date));

        SalonSetting::current()->update(['slot_interval_minutes' => 30]);

        $slots = BookingAvailability::make()->availableSlots($date);

        $this->assertContains('10:30', $slots, 'A 30-minute interval should offer half-hour starts.');
        $this->assertNotContains('10:15', $slots, '15 minutes was not what was set.');
    }

    /**
     * The shipped default grid: 09:00 to 17:00, Tuesday to Sunday.
     *
     * The other tests here set their own hours, so nothing was pinning down what
     * a customer actually gets on a normal Tuesday. This one reads the default
     * row with no overrides, because that is what a fresh install serves.
     *
     * The boundary worth stating is 17:00. It is the closing time, not a bookable
     * start — a 30-minute slot beginning at 17:00 would run past close — so the
     * last offered start is 16:30 and the exclusive comparison is correct rather
     * than off by one.
     *
     * And the count is 14, not 16. The grid used to run unbroken from 09:00 to
     * 16:30; the salon now takes a lunch break from 12:00, which on a 30-minute
     * grid removes exactly two starts, 12:00 and 12:30. They are checked by name
     * below as well as by count, because a count alone cannot tell "lunchtime is
     * missing" from "some other slot is missing".
     */
    public function test_the_default_grid_runs_from_09_00_to_16_30_on_tuesday_to_sunday(): void
    {
        $settings = SalonSetting::current();

        $this->assertSame(30, $settings->slot_interval_minutes, 'The default interval is 30 minutes.');

        // Derived from the stored week rather than assumed, so the assertion is
        // about the row a fresh install serves and not about a constant.
        $days = array_keys(SalonSetting::dayNames());

        $this->assertSame(
            ['monday'],
            array_values(array_filter(
                $days,
                fn ($day) => ! $settings->isOpenOn($this->nextWeekday($day)),
            )),
            'The default week is closed on Monday and open on every other day.',
        );

        foreach ($days as $day) {
            $slots = BookingAvailability::make()->availableSlots($this->nextWeekday($day)->toDateString());

            if ($day === 'monday') {
                $this->assertSame([], $slots, 'Monday should offer nothing.');

                continue;
            }

            $this->assertSame('09:00', $slots[0] ?? null, "{$day} should open at 09:00.");
            $this->assertSame('16:30', end($slots), "{$day} should offer 16:30 as its last start.");
            $this->assertNotContains('17:00', $slots, "{$day} should not offer a start at closing time.");
            $this->assertNotContains('08:30', $slots, "{$day} should not offer a start before opening.");

            // Lunchtime is the one gap in the day, on every open day.
            $this->assertNotContains('12:00', $slots, "{$day} should not offer a start over lunch.");
            $this->assertNotContains('12:30', $slots, "{$day} should not offer a start over lunch.");

            // …with the slots either side of it still offered, which is what makes
            // this a break rather than a truncated afternoon.
            $this->assertContains('11:30', $slots, "{$day} should still offer the slot before lunch.");
            $this->assertContains('13:00', $slots, "{$day} should offer the slot lunch ends at.");

            // 09:00–16:30 inclusive at 30 minutes is 16 starts, less the two
            // lunchtime ones.
            $this->assertCount(14, $slots, "{$day} should offer a start every 30 minutes, bar lunch.");
        }
    }

    public function test_per_day_hours_produce_a_different_slot_grid_on_each_day(): void
    {
        $hours = $this->hours('09:00', '18:00');

        // A salon that opens early on Saturday and stays open late on Friday.
        $hours['friday'] = ['09:00', '19:00'];
        $hours['saturday'] = ['08:00', '14:00'];

        SalonSetting::current()->update(['operating_hours' => $hours]);

        $settings = SalonSetting::current();
        $friday = $this->nextWeekday('friday');
        $saturday = $this->nextWeekday('saturday');

        $this->assertSame(['09:00', '19:00'], $settings->hoursFor('friday'));
        $this->assertSame(['08:00', '14:00'], $settings->hoursFor('saturday'));

        $this->assertContains('18:00', BookingAvailability::make()->availableSlots($friday->toDateString()));
        $this->assertContains('08:00', BookingAvailability::make()->availableSlots($saturday->toDateString()));
        $this->assertNotContains(
            '18:00',
            BookingAvailability::make()->availableSlots($saturday->toDateString()),
            'Saturday closes at 14:00, so an 18:00 start should not exist.',
        );
    }

    public function test_a_weekday_with_no_hours_is_closed_every_week(): void
    {
        // The recurring weekly closure pattern: a day with no entry in the map. No
        // separate `closed_days` column, because two pieces of state both meaning
        // "we are shut" is how a day ends up open in one place and shut in another.
        $hours = $this->hours('09:00', '17:00');
        unset($hours['sunday']);

        SalonSetting::current()->update(['operating_hours' => $hours]);

        $settings = SalonSetting::current();

        $this->assertArrayNotHasKey('sunday', $settings->operating_hours);

        // Three Sundays a fortnight apart, to prove it is a weekly pattern and not
        // one date being singled out.
        $sunday = today()->next(Carbon::SUNDAY);

        foreach ([1, 2, 3] as $weeks) {
            $date = $sunday->copy()->addWeeks($weeks);

            $this->assertFalse($settings->isOpenOn($date), 'Sunday '.$date->toDateString().' should be closed.');

            $this->assertContains(
                'We are closed on Sundays.',
                BookingAvailability::make()->dateProblems($date->toDateString()),
            );
        }
    }

    public function test_a_closed_weekday_is_refused_on_submit(): void
    {
        $hours = $this->hours('09:00', '17:00');
        unset($hours['sunday']);

        SalonSetting::current()->update(['operating_hours' => $hours]);

        $sunday = today()->next(Carbon::SUNDAY);

        $this->actingAs($this->makeUser())
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), [
                'customer_name' => 'Ana Reyes',
                'customer_phone' => '09171234567',
                'preferred_date' => $sunday->toDateString(),
                'preferred_time' => '10:00',
                'services' => [['service_id' => $this->makeService()->id, 'quantity' => 1]],
                'last_services_availed_note' => 'Glow Manicure',
                'agree_terms' => '1',
            ])
            ->assertSessionHasErrors('preferred_date');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_hours_are_still_published_to_customers(): void
    {
        // Configuration or not, the contact page prints the week. A customer asking
        // when the salon opens has to be told from the same row the booking form
        // validates against, or the two can disagree.
        $this->assertStringContainsString('Operating Hours', $this->get('/contact')->assertOk()->getContent());

        $this->assertStringContainsString('SalonSetting::dayNames()', file_get_contents(
            resource_path('views/contact.blade.php')
        ));
    }

    /* ------------------------------------------------------------------ */
    /* The booking window                                                   */
    /* ------------------------------------------------------------------ */

    public function test_the_booking_window_moves_the_last_bookable_date(): void
    {
        SalonSetting::current()->update([
            'operating_hours' => $this->hours('09:00', '18:00'),
            'booking_lead_days' => 14,
        ]);

        $availability = BookingAvailability::make();

        $this->assertSame(today()->addDays(14)->toDateString(), $availability->lastBookableDate()->toDateString());
        $this->assertContains(
            'Bookings only open 14 days in advance.',
            $availability->dateProblems(today()->addDays(20)->toDateString()),
        );
    }

    public function test_the_minimum_notice_moves_the_first_bookable_date(): void
    {
        $settings = $this->makeSalonSettings();

        $this->assertSame(
            today()->addDay()->toDateString(),
            BookingAvailability::make()->firstBookableDate()->toDateString(),
            '24 hours of notice should mean tomorrow.',
        );

        $settings->update(['minimum_notice_hours' => 72]);

        $availability = BookingAvailability::make();

        $this->assertSame(
            today()->addDays(3)->toDateString(),
            $availability->firstBookableDate()->toDateString(),
            '72 hours of notice should mean three days out.',
        );

        // Spelled out rather than "3 days", so the wording a customer reads does
        // not change shape when the number does.
        $this->assertContains(
            'Appointments must be booked at least three days in advance.',
            $availability->dateProblems(today()->addDay()->toDateString()),
        );
    }

    /* ------------------------------------------------------------------ */
    /* The down payment is still calculated                                 */
    /* ------------------------------------------------------------------ */

    public function test_the_down_payment_percentage_is_used_by_a_real_booking(): void
    {
        $service = $this->makeService(['price' => 1000, 'base_price' => 1000]);

        /*
         * The date is resolved *before* the down payment is configured.
         *
         * `bookableDate()` calls `makeSalonSettings()`, which writes a known-good
         * window and so resets `down_payment_required` to false on the way past.
         * Setting the deposit first and asking for a date second wiped the very
         * thing under test, and the assertion below failed with 0.0 against a
         * service priced at 1000 — which looked like a bug in `BookingService` and
         * was a bug in the test.
         */
        $date = $this->bookableDate();

        SalonSetting::current()->update([
            'down_payment_required' => true,
            'down_payment_percentage' => 25,
        ]);

        $user = $this->makeUser();

        $this->actingAs($user)
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->payload($service, $date))
            ->assertSessionHasNoErrors();

        $appointment = $user->appointments()->sole();

        $this->assertSame(250.0, (float) $appointment->down_payment_amount, 'A quarter of 1000.');
        $this->assertSame(
            \App\Enums\DownPaymentStatus::NotRequired,
            $appointment->down_payment_status,
            'No reference was collected, so nothing is pending verification.',
        );
    }

    public function test_the_down_payment_is_left_alone_when_it_is_not_required(): void
    {
        $service = $this->makeService(['price' => 1000, 'base_price' => 1000]);

        SalonSetting::current()->update([
            'down_payment_required' => false,
            'down_payment_percentage' => 25,
        ]);

        $user = $this->makeUser();

        $this->actingAs($user)
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->payload($service, $this->bookableDate()))
            ->assertSessionHasNoErrors();

        $appointment = $user->appointments()->sole();

        $this->assertNull($appointment->down_payment_amount);
        $this->assertSame(\App\Enums\DownPaymentStatus::NotRequired, $appointment->down_payment_status);
    }

    public function test_expected_down_payment_rounds_to_two_places(): void
    {
        $settings = SalonSetting::current();

        $settings->update(['down_payment_percentage' => 33]);

        $this->assertSame(330.0, $settings->expectedDownPaymentFor(1000.0));

        // A third of a peso has to land on a real peso figure, not a float tail.
        $settings->update(['down_payment_percentage' => 100]);
        $this->assertSame(99.99, $settings->expectedDownPaymentFor(99.99));
    }

    /* ------------------------------------------------------------------ */

    /**
     * A seven-day week at one window.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function hours(string $from, string $to): array
    {
        $hours = [];

        foreach (array_keys(SalonSetting::dayNames()) as $day) {
            $hours[$day] = [$from, $to];
        }

        return $hours;
    }

    /** The next occurrence of a weekday, far enough out to be bookable. */
    private function nextWeekday(string $weekday, int $offset = 3): Carbon
    {
        $date = today()->addDays($offset);

        while (strtolower($date->dayName) !== $weekday) {
            $date->addDay();
        }

        return $date;
    }

    /**
     * A complete, valid booking payload.
     *
     * @return array<string, mixed>
     */
    private function payload($service, string $date): array
    {
        return [
            'customer_name' => 'Ana Reyes',
            'customer_phone' => '09171234567',
            'preferred_date' => $date,
            'preferred_time' => '10:00',
            'services' => [['service_id' => $service->id, 'quantity' => 1]],
            'last_services_availed_note' => 'Glow Manicure',
            'agree_terms' => '1',
        ];
    }
}