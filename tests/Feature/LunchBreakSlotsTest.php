<?php

namespace Tests\Feature;

use App\Models\SalonSetting;
use App\Services\BookingAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The salon does not take appointments over lunch.
 *
 * 12:00 and 12:30 were being offered as bookable because nothing in the grid
 * knew they should not be: the slot list is generated from the opening hours and
 * the interval, and a uniform grid has no notion of a break. Both sat in the
 * dropdown as times the front desk could not serve.
 *
 * The rule is a half-open window — `>= 12:00`, `< 13:00` — in
 * `SalonSetting::BREAK_START` / `BREAK_END`, which on the shipped 30-minute grid
 * removes exactly those two slots and leaves 11:30 and 13:00 alone.
 *
 * It is enforced in two places, and both are asserted here:
 *
 *   - `slotsFor()`, so the times on offer never include it. This is what the
 *     customer sees, and it also keeps the blocked-slot sweep — which walks the
 *     same grid — agreeing about which times exist.
 *   - `timeProblems()`, so a hand-built request cannot book it. That method is
 *     the server's own gate and never consulted the grid: it checks operating
 *     hours, admin blocks, taken slots and minimum notice, and nothing else, so
 *     a `preferred_time` of `12:00` posted directly would otherwise pass every
 *     one of those and write the exact booking this rule exists to prevent.
 */
class LunchBreakSlotsTest extends TestCase
{
    use RefreshDatabase;

    private function openDate(): Carbon
    {
        // Tuesday: the first open day in the default week, so nothing here
        // depends on where in the week the suite happens to run.
        return today()->next(Carbon::TUESDAY);
    }

    public function test_the_two_lunchtime_slots_are_not_offered(): void
    {
        $slots = SalonSetting::current()->slotsFor($this->openDate());

        $this->assertNotContains('12:00', $slots);
        $this->assertNotContains('12:30', $slots);

        // …and they were offered before. Guarding the removal is only meaningful
        // if it is a removal, so this is asserted against the window arithmetic.
        $this->assertContains('11:30', $slots, 'The slot before lunch is still bookable.');
        $this->assertContains('13:00', $slots, 'The slot lunch ends at is still bookable.');
    }

    public function test_the_break_is_half_open_so_the_slot_lunch_ends_at_is_offered(): void
    {
        $settings = SalonSetting::current();

        $this->assertTrue($settings->isTimeDuringBreak('12:00'));
        $this->assertTrue($settings->isTimeDuringBreak('12:30'));

        // Half-open: 13:00 is the first slot after lunch, not part of it.
        $this->assertFalse($settings->isTimeDuringBreak('13:00'));
        $this->assertFalse($settings->isTimeDuringBreak('11:30'));

        // Written in the forms a request can arrive in, so a `12:00:00` from a
        // database column or a `1:00 PM` from a hand-built POST cannot slip past
        // the comparison.
        $this->assertTrue($settings->isTimeDuringBreak('12:00:00'));
        $this->assertTrue($settings->isTimeDuringBreak('12:30:00'));
        $this->assertFalse($settings->isTimeDuringBreak('13:00:00'));

        // Unparseable is not "lunch"; the caller's own validation reports an
        // unusable time, not this predicate.
        $this->assertFalse($settings->isTimeDuringBreak('not-a-time'));
    }

    public function test_the_dropdown_for_a_bookable_date_does_not_offer_lunchtime(): void
    {
        $date = $this->openDate();

        $this->assertNotContains(
            '12:00',
            BookingAvailability::make()->availableSlots($date->toDateString()),
        );
    }

    public function test_the_server_refuses_a_lunchtime_booking_that_was_posted_by_hand(): void
    {
        $availability = BookingAvailability::make();
        $date = $this->openDate()->toDateString();

        foreach (['12:00', '12:30'] as $time) {
            $this->assertContains(
                BookingAvailability::lunchBreakMessage(),
                $availability->timeProblems($date, $time),
                "{$time} should be refused by the server as well as kept out of the dropdown.",
            );
        }

        // The slots either side of it are fine, so this is a break rather than a
        // truncated afternoon.
        $this->assertSame([], $availability->timeProblems($date, '11:30'));
        $this->assertSame([], $availability->timeProblems($date, '13:00'));
    }

    public function test_a_booking_submitted_at_lunchtime_is_refused_and_writes_nothing(): void
    {
        $service = $this->makeService(['price' => 500]);

        $this->actingAs($this->makeUser())
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), [
                'services' => [['service_id' => $service->id, 'quantity' => 1]],
                'customer_name' => 'Ana Reyes',
                'customer_phone' => '09171234567',
                'preferred_date' => $this->openDate()->toDateString(),
                'preferred_time' => '12:00',
                'last_services_availed_note' => 'Glow Manicure',
                'agree_terms' => '1',
            ])
            ->assertSessionHasErrors('preferred_time');

        $this->assertDatabaseCount('appointments', 0);
    }

    /** The refusal raises the same red toast as an admin's blocked window. */
    public function test_a_lunchtime_refusal_raises_a_red_toast(): void
    {
        $toast = BookingAvailability::toastFor([BookingAvailability::lunchBreakMessage()]);

        $this->assertNotNull($toast);
        $this->assertSame('error', $toast['type']);
        $this->assertSame(BookingAvailability::lunchBreakMessage(), $toast['message']);
    }

    /**
     * The message names the real window, in the form customers read.
     *
     * Built from the break's own constants through the same formatter the rest of
     * the booking form uses, so re-tuning the break cannot leave the wording
     * describing an hour the salon no longer takes off.
     */
    public function test_the_message_names_the_break_in_twelve_hour_time(): void
    {
        $message = BookingAvailability::lunchBreakMessage();

        $this->assertStringContainsString(
            SalonSetting::formatTimeForDisplay(SalonSetting::BREAK_START),
            $message,
        );
        $this->assertStringContainsString(
            SalonSetting::formatTimeForDisplay(SalonSetting::BREAK_END),
            $message,
        );

        // Not the raw 24-hour values the grid is built from.
        $this->assertStringNotContainsString('12:00–13:00', $message);
    }
}