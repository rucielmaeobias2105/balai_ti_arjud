<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BlockedSlot;
use App\Models\ServiceCategory;
use App\Services\BookingAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Blocked Slots — time ranges the salon is not taking bookings in.
 *
 * Three kinds of thing have to hold, and they are asserted separately because
 * they can fail apart:
 *
 *   - the *shape* of a row: a whole day, a window, or several windows on one day,
 *     with `end_time` exclusive so a 2–4pm block leaves 4:00 bookable;
 *   - the *admin's* side: a month grid, a dialog that adds and removes ranges for
 *     one date, and an upcoming list below; and
 *   - the *customer's* side: a closed day cannot be picked at all, a blocked time
 *     is greyed rather than silently missing, and a stale page that posts a
 *     blocked slot is refused with the red message rather than accepted.
 *
 * The last group is the one that matters most. A block that only greys something
 * out in a dropdown is a suggestion; the refusal has to be on the server, or a
 * customer who left the page open before the block was saved books straight
 * through it.
 *
 * The old one-row-per-date `blocked_dates` table is deliberately still gone — see
 * `test_the_old_blocked_dates_table_is_still_gone` — because it could not express
 * "closed 2–4pm on the 15th" and reinstating it would have brought back a schema
 * whose every consumer had already been deleted.
 */
class BlockedSlotsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeSalonSettings();
    }

    /* ------------------------------------------------------------------ */
    /* 1. The shape of a row                                               */
    /* ------------------------------------------------------------------ */

    public function test_a_row_with_no_times_closes_the_whole_day(): void
    {
        $slot = BlockedSlot::create(['date' => today()->addDays(3)->toDateString()]);

        $this->assertTrue($slot->isWholeDay());
        $this->assertSame('Closed all day', $slot->rangeLabel());

        // And it refuses every time of day.
        foreach (['00:00', '09:00', '13:30', '23:59'] as $time) {
            $this->assertTrue($slot->blocksTime($time), "A whole-day block should refuse {$time}.");
        }
    }

    public function test_a_window_blocks_only_the_times_inside_it(): void
    {
        $slot = BlockedSlot::create([
            'date' => today()->addDays(3)->toDateString(),
            'start_time' => '14:00',
            'end_time' => '16:00',
        ]);

        $this->assertFalse($slot->isWholeDay());

        // Inside.
        $this->assertTrue($slot->blocksTime('14:00'));
        $this->assertTrue($slot->blocksTime('14:30'));
        $this->assertTrue($slot->blocksTime('15:00'));
        $this->assertTrue($slot->blocksTime('15:30'));

        // The end is exclusive: 4:00pm is not part of a 2–4pm window.
        $this->assertFalse($slot->blocksTime('16:00'), 'The end time should not itself be blocked.');

        // Outside.
        $this->assertFalse($slot->blocksTime('09:00'));
        $this->assertFalse($slot->blocksTime('13:30'));
        $this->assertFalse($slot->blocksTime('17:00'));

        $this->assertSame('02:00 PM – 04:00 PM', $slot->rangeLabel());
    }

    public function test_a_zero_width_window_blocks_exactly_one_slot(): void
    {
        $slot = BlockedSlot::create([
            'date' => today()->addDays(3)->toDateString(),
            'start_time' => '14:00',
            'end_time' => '14:00',
        ]);

        // Otherwise "block the 2pm appointment only" would need a 14:01 end, which
        // nobody thinks to type.
        $this->assertTrue($slot->blocksTime('14:00'));
        $this->assertFalse($slot->blocksTime('14:30'));
        $this->assertFalse($slot->blocksTime('13:30'));
    }

    public function test_several_ranges_can_share_one_date(): void
    {
        $date = today()->addDays(3)->toDateString();

        BlockedSlot::create(['date' => $date, 'start_time' => '09:00', 'end_time' => '10:00']);
        BlockedSlot::create(['date' => $date, 'start_time' => '14:00', 'end_time' => '16:00', 'reason' => 'Staff meeting']);
        BlockedSlot::create(['date' => $date, 'start_time' => '11:00', 'end_time' => '12:00']);

        $this->assertCount(3, BlockedSlot::forDate($date)->get());
        $this->assertCount(3, BlockedSlot::rangesOn($date));
        $this->assertCount(0, BlockedSlot::wholeDays()->forDate($date)->get());

        // Every one of those ranges has to take effect, which is the point of
        // allowing more than one row per day. The test salon books on the hour
        // (09:00–18:00, 60-minute slots), so each range takes out one or two of
        // those starts and nothing else.
        $blocked = BookingAvailability::make()->blockedTimes($date);

        $this->assertSame(['09:00', '11:00', '14:00', '15:00'], $blocked);

        // 10:00 is the exclusive end of the 9–10 block, 12:00 the exclusive end of
        // the 11–12 one, and 13:00 sits in the gap the admin left open.
        foreach (['10:00', '12:00', '13:00', '16:00'] as $time) {
            $this->assertNotContains($time, $blocked, "{$time} is outside every block.");
        }
    }

    public function test_a_half_set_range_blocks_nothing_rather_than_the_whole_day(): void
    {
        // Not reachable through the form — `StoreBlockedSlotRequest` refuses it —
        // but a hand-edited row must not close a day by accident.
        $slot = new BlockedSlot(['start_time' => '14:00', 'end_time' => null]);
        $slot->date = today()->addDays(3)->toDateString();

        $this->assertFalse($slot->isWholeDay());
        $this->assertFalse($slot->blocksTime('14:00'));
        $this->assertFalse($slot->blocksTime('09:00'));
    }

    public function test_the_old_blocked_dates_table_is_still_gone(): void
    {
        // The replacement is a new table, not a resurrection. The old one could only
        // say "closed that day", which is why it was dropped in the first place.
        $this->assertFalse(Schema::hasTable('blocked_dates'));
        $this->assertFalse(class_exists('App\\Models\\BlockedDate'));
        $this->assertTrue(Schema::hasTable('blocked_slots'));
    }

    /* ------------------------------------------------------------------ */
    /* 2. The admin screen                                                 */
    /* ------------------------------------------------------------------ */

    public function test_the_calendar_screen_renders_a_month_grid_and_a_summary(): void
    {
        $date = today()->addDays(3)->toDateString();

        BlockedSlot::create(['date' => $date, 'reason' => 'Public holiday']);
        BlockedSlot::create([
            'date' => today()->addDays(9)->toDateString(),
            'start_time' => '14:00',
            'end_time' => '16:00',
            'reason' => 'Staff meeting',
        ]);

        $html = $this->actingAs($this->makeAdmin(), 'admin')
            ->get('/admin/calendar')
            ->assertOk()
            ->getContent();

        // Month navigation, both directions.
        $this->assertStringContainsString(route('admin.calendar.index', ['month' => today()->copy()->subMonth()->format('Y-m')]), $html);
        $this->assertStringContainsString(route('admin.calendar.index', ['month' => today()->copy()->addMonth()->format('Y-m')]), $html);

        // The summary list below the grid, naming both blocks and their ranges.
        $this->assertStringContainsString('Upcoming Blocks', $html);
        $this->assertStringContainsString('Closed all day', $html);
        $this->assertStringContainsString('Public holiday', $html);
        $this->assertStringContainsString('02:00 PM – 04:00 PM', $html);
        $this->assertStringContainsString('Staff meeting', $html);

        // The dialog: add a range, or close the whole day.
        $this->assertStringContainsString(route('admin.calendar.store'), $html);
        $this->assertStringContainsString('name="whole_day"', $html);
        $this->assertStringContainsString('name="start_time"', $html);
        $this->assertStringContainsString('name="end_time"', $html);
        $this->assertStringContainsString('name="reason"', $html);
    }

    /**
     * The date numbers on the admin calendar are actually readable.
     *
     * This grid faded the digits three times over: `text-ink-muted/50` on the
     * button, `/60` on the number inside it, and a blanket `opacity-40` on
     * out-of-month cells. `--color-text-muted` is #806A5D — about 5.4:1 on white,
     * which is fine — but a third of that grey is about 2:1, and two of them
     * together is worse. Stacked, the numbers read as pale smudges and the page
     * became the hardest one in the admin to check a date against.
     *
     * The fix moves the de-emphasis off the text and onto the cell: an
     * out-of-month or past day is dashed and paler *behind* the number, and the
     * digit itself is a full-strength token. So the assertions are about the
     * absence of the stacked fades, not merely the presence of a dark class —
     * a page can contain `text-primary` somewhere and still render every date
     * illegible.
     */
    public function test_the_admin_calendar_dates_are_not_faded_out(): void
    {
        $html = $this->actingAs($this->makeAdmin(), 'admin')
            ->get('/admin/calendar')
            ->assertOk()
            ->getContent();

        // Full strength where the number is printed. Asserted on the rendered
        // class list, not on the `@class()` array: that array is PHP, evaluated
        // before the response exists, so its source never reaches the page.
        $this->assertStringContainsString('text-primary', $html, 'Dates in the month shown are printed in the primary ink.');

        // No fraction of an ink anywhere on the grid, and no blanket opacity over
        // the cells — that is what took the digits from "muted" to "invisible".
        $this->assertStringNotContainsString('text-ink-muted/60', $html, 'A faded fraction of a muted grey is not a readable date.');
        $this->assertStringNotContainsString('text-ink-muted/50', $html, 'Half-strength muted grey is not a readable date.');
        $this->assertStringNotContainsString('opacity-40', $html, 'An opacity over the cell fades the number with it.');

        // The distinction between an in-month day and an adjacent-month one is
        // still legible — it has just moved onto the cell instead of the text.
        $this->assertStringContainsString('border-dashed border-line/60 bg-linen/30', $html);
    }

    /**
     * The customer date grid carries no faded ink either.
     *
     * The same complaint applied to the booking calendar, where three states of
     * day share one grid: a chosen date, a date the salon has blocked, and a date
     * that is simply not being taken (Monday, or past the booking window). The
     * blocked state keeps its full-strength ink with a red strike through it, and
     * the two unavailable states were the ones faded to `/50` and `/70` — so the
     * days a customer most needs to check, "is Monday open?", were the least
     * legible on the page. The border and background dim them now instead.
     */
    public function test_the_customer_booking_calendar_dates_are_not_faded_out(): void
    {
        $html = $this->actingAs($this->makeUser())
            ->get(route('appointments.create'))
            ->assertOk()
            ->getContent();

        // Scoped to `dayClasses`, because the booking page also renders the
        // customer footer, which carries its own legitimately dimmed text. Asserting
        // on the whole document would fail for reasons that have nothing to do
        // with the calendar, and the next person to touch the footer would be
        // pushed to work out why their test went red.
        $from = strpos($html, 'dayClasses(day) {');
        $to = strpos($html, 'pickDate(day) {');

        $this->assertNotFalse($from, 'The wizard should carry a dayClasses() grid.');
        $this->assertNotFalse($to, 'dayClasses() should be followed by pickDate().');

        $grid = substr($html, $from, $to - $from);

        // No state of the grid reaches for a fraction of an ink.
        $this->assertDoesNotMatchRegularExpression(
            '#text-[a-z-]+/\d+#',
            $grid,
            'Every day on the customer grid should be printed at full ink strength.',
        );
        $this->assertStringNotContainsString(
            'opacity-40',
            $grid,
            'An opacity over the cell fades the number with it.',
        );

        // Still visually distinct, and still refusing the click: the states are
        // separated by the cell rather than by the contrast of the digit.
        $this->assertStringContainsString('cursor-not-allowed', $grid);
        $this->assertStringContainsString('border-line/40 bg-linen/30 text-ink-muted cursor-not-allowed', $grid);
        $this->assertStringContainsString('border-line/70 bg-white text-ink-muted hover:border-primary/40', $grid);

        // And a blocked day is still struck through, still in full-strength ink
        // with a red line: legible digit, red strike.
        $this->assertStringContainsString(
            'text-ink-muted cursor-not-allowed line-through decoration-status-cancelled',
            $grid,
        );

        // Monday lands in the unavailable state, so it is dimmed and unclickable
        // but never struck through — a standing closure is not an admin's
        // one-off block.
        $this->assertStringNotContainsString('line-through', substr(
            $grid,
            strpos($grid, "'outside'"),
            strpos($grid, '! day.inMonth') - strpos($grid, "'outside'"),
        ));
    }

    public function test_the_calendar_is_in_the_sidebar(): void
    {
        $html = $this->actingAs($this->makeAdmin(), 'admin')->get('/admin')->assertOk()->getContent();

        preg_match('/<nav[^>]*aria-label="Admin">(.*?)<\/nav>/s', $html, $matches);

        $this->assertNotEmpty($matches);
        $this->assertStringContainsString('Calendar', $matches[1]);
        $this->assertStringContainsString(route('admin.calendar.index'), $matches[1]);
    }

    public function test_an_admin_can_block_a_whole_day_from_the_calendar(): void
    {
        $date = today()->addDays(4)->toDateString();

        $this->actingAs($this->makeAdmin(), 'admin')
            ->from(route('admin.calendar.index'))
            ->post(route('admin.calendar.store'), [
                'date' => $date,
                'whole_day' => 1,
                'reason' => 'Public holiday',
            ])
            ->assertRedirect(route('admin.calendar.index'));

        $slot = BlockedSlot::sole();

        $this->assertSame($date, $slot->date->toDateString());
        $this->assertTrue($slot->isWholeDay());
        $this->assertSame('Public holiday', $slot->reason);
        $this->assertSame('Closed all day', $slot->rangeLabel());
    }

    public function test_an_admin_can_block_a_time_range_from_the_calendar(): void
    {
        $date = today()->addDays(4)->toDateString();

        $this->actingAs($this->makeAdmin(), 'admin')
            ->from(route('admin.calendar.index'))
            ->post(route('admin.calendar.store'), [
                'date' => $date,
                'start_time' => '14:00',
                'end_time' => '16:00',
                'reason' => 'Staff meeting',
            ])
            ->assertRedirect(route('admin.calendar.index'));

        $slot = BlockedSlot::sole();

        $this->assertFalse($slot->isWholeDay());
        $this->assertSame('14:00', $slot->start_time);
        $this->assertSame('16:00', $slot->end_time);
    }

    public function test_a_range_is_attributed_to_the_admin_who_added_it(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.calendar.store'), [
                'date' => today()->addDays(4)->toDateString(),
                'whole_day' => 1,
            ]);

        $this->assertSame($admin->id, BlockedSlot::sole()->created_by);
    }

    public function test_an_admin_can_edit_and_remove_one_range(): void
    {
        $slot = BlockedSlot::create([
            'date' => today()->addDays(4)->toDateString(),
            'start_time' => '14:00',
            'end_time' => '16:00',
            'reason' => 'Staff meeting',
        ]);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->patch(route('admin.calendar.update', $slot), [
                'date' => $slot->date->toDateString(),
                'start_time' => '09:00',
                'end_time' => '11:00',
                'reason' => 'Training',
            ])
            ->assertRedirect();

        $slot->refresh();

        $this->assertSame('09:00', $slot->start_time);
        $this->assertSame('11:00', $slot->end_time);
        $this->assertSame('Training', $slot->reason);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->delete(route('admin.calendar.destroy', $slot))
            ->assertRedirect();

        $this->assertDatabaseCount('blocked_slots', 0);
    }

    public function test_editing_one_range_can_turn_it_into_a_whole_day(): void
    {
        $slot = BlockedSlot::create([
            'date' => today()->addDays(4)->toDateString(),
            'start_time' => '14:00',
            'end_time' => '16:00',
        ]);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->patch(route('admin.calendar.update', $slot), [
                'date' => $slot->date->toDateString(),
                'whole_day' => 1,
            ]);

        $slot->refresh();

        $this->assertTrue($slot->isWholeDay());
        $this->assertNull($slot->start_time);
    }

    public function test_a_window_that_ends_before_it_starts_is_refused(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->from(route('admin.calendar.index'))
            ->post(route('admin.calendar.store'), [
                'date' => today()->addDays(4)->toDateString(),
                'start_time' => '16:00',
                'end_time' => '14:00',
            ])
            ->assertSessionHasErrors('end_time');

        $this->assertDatabaseCount('blocked_slots', 0);
    }

    public function test_a_range_with_no_times_at_all_is_refused(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->from(route('admin.calendar.index'))
            ->post(route('admin.calendar.store'), [
                'date' => today()->addDays(4)->toDateString(),
            ])
            ->assertSessionHasErrors(['start_time', 'end_time']);

        $this->assertDatabaseCount('blocked_slots', 0);
    }

    public function test_a_past_date_cannot_be_blocked(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->from(route('admin.calendar.index'))
            ->post(route('admin.calendar.store'), [
                'date' => today()->subDay()->toDateString(),
                'whole_day' => 1,
            ])
            ->assertSessionHasErrors('date');

        $this->assertDatabaseCount('blocked_slots', 0);
    }

    public function test_a_customer_cannot_reach_the_calendar(): void
    {
        $this->get('/admin/calendar')->assertRedirect(route('admin.login'));

        $this->actingAs($this->makeUser())
            ->post(route('admin.calendar.store'), [
                'date' => today()->addDays(4)->toDateString(),
                'whole_day' => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('blocked_slots', 0);
    }

    /**
     * The save has to work with the default guard left where the app leaves it.
     *
     * `actingAs($admin, 'admin')` runs `Auth::shouldUse('admin')` as well as
     * signing the admin in, so in a test the default guard becomes the admin guard
     * too. That hides a request whose `authorize()` calls `$this->user()` with no
     * argument: that resolves against the default guard, which is `web` — the
     * customer guard — and is null for a real admin on the panel, so every block
     * would come back 403 in the browser while every test passed.
     */
    public function test_the_block_saves_with_the_default_guard_on_the_customer_guard(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'admin');

        Auth::shouldUse('web');

        $this->assertNull(auth()->user(), 'The default guard should be the empty customer guard here.');

        $this->post(route('admin.calendar.store'), [
            'date' => today()->addDays(4)->toDateString(),
            'whole_day' => 1,
        ])->assertRedirect(route('admin.calendar.index'));

        $this->assertTrue(BlockedSlot::sole()->isWholeDay());
    }

    /* ------------------------------------------------------------------ */
    /* 3. The customer's side                                              */
    /* ------------------------------------------------------------------ */

    public function test_a_fully_blocked_day_cannot_be_booked_at_all(): void
    {
        $date = $this->bookableDate();

        BlockedSlot::create(['date' => $date, 'reason' => 'Public holiday']);

        $availability = BookingAvailability::make();

        $this->assertTrue($availability->isDateBlocked($date));
        $this->assertFalse($availability->isDateAvailable($date));
        $this->assertSame([], $availability->availableSlots($date), 'A closed day should offer no slots at all.');
        $this->assertContains(
            BookingAvailability::UNAVAILABLE_DATE_MESSAGE,
            $availability->dateProblems($date),
        );
    }

    public function test_a_partially_blocked_day_still_books_its_free_times(): void
    {
        $date = $this->bookableDate();

        // The test helper opens 09:00–18:00 on 60-minute slots.
        //
        // The block runs to 13:00 rather than 12:00 so its end boundary can be
        // asserted on a slot that exists. It used to end at 12:00 and prove that
        // 12:00 was still bookable — but the salon takes a lunch break at 12:00
        // (see `SalonSetting::BREAK_START`), so that slot is now absent for a
        // reason that has nothing to do with the block, and asserting on it would
        // be asserting the break while claiming to test the block's edge.
        BlockedSlot::create([
            'date' => $date,
            'start_time' => '10:00',
            'end_time' => '13:00',
        ]);

        $availability = BookingAvailability::make();

        // The *date* is fine — which is what keeps it selectable in the picker.
        $this->assertFalse($availability->isDateBlocked($date));
        $this->assertTrue($availability->isDateAvailable($date));

        // 10:00 and 11:00 are gone; the rest of the day is untouched. 12:00 is not
        // listed because it is lunchtime, not because it was blocked.
        $this->assertSame(['10:00', '11:00'], $availability->blockedTimes($date));

        $slots = $availability->availableSlots($date);

        $this->assertNotContains('10:00', $slots);
        $this->assertNotContains('11:00', $slots);
        $this->assertContains('09:00', $slots);
        $this->assertContains('13:00', $slots, 'The end of a block is bookable again.');
    }

    public function test_the_slot_endpoint_reports_blocked_days_and_blocked_times(): void
    {
        $blockedDate = $this->bookableDate(4);
        $partialDate = $this->bookableDate(6);

        BlockedSlot::create(['date' => $blockedDate, 'reason' => 'Public holiday']);
        BlockedSlot::create(['date' => $partialDate, 'start_time' => '10:00', 'end_time' => '12:00']);

        $whole = $this->getJson('/book/slots?date='.$blockedDate)->assertOk()->json();

        $this->assertFalse($whole['open']);
        $this->assertTrue($whole['wholeDay']);
        $this->assertSame([], $whole['slots']);

        $partial = $this->getJson('/book/slots?date='.$partialDate)->assertOk()->json();

        $this->assertTrue($partial['open']);
        $this->assertFalse($partial['wholeDay']);
        $this->assertSame(['10:00', '11:00'], $partial['blockedSlots'], 'Blocked times come back separately so the dropdown can grey them.');
        $this->assertNotContains('10:00', $partial['slots']);

        // And the whole-day map, for the availability calendar to render from.
        $this->assertArrayHasKey($blockedDate, $partial['blockedDates']);
        $this->assertSame('Public holiday', $partial['blockedDates'][$blockedDate]);
        $this->assertArrayNotHasKey($partialDate, $partial['blockedDates'], 'A partial block does not close the date.');
    }

    public function test_booking_a_blocked_day_is_refused_with_the_red_message(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();
        $date = $this->bookableDate();

        BlockedSlot::create(['date' => $date, 'reason' => 'Public holiday']);

        $response = $this->actingAs($user)
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->bookingPayload($service, $date, '10:00'));

        $response->assertSessionHasErrors('preferred_date');
        $response->assertSessionHas('toast', fn (array $toast) => $toast['type'] === 'error'
            && $toast['message'] === BookingAvailability::UNAVAILABLE_DATE_MESSAGE);

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_booking_a_blocked_time_is_refused_with_the_red_message(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();
        $date = $this->bookableDate();

        BlockedSlot::create(['date' => $date, 'start_time' => '10:00', 'end_time' => '12:00']);

        $response = $this->actingAs($user)
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->bookingPayload($service, $date, '11:00'));

        $response->assertSessionHasErrors('preferred_time');
        $response->assertSessionHas('toast', fn (array $toast) => $toast['type'] === 'error'
            && $toast['message'] === BookingAvailability::UNAVAILABLE_TIME_MESSAGE);

        $this->assertDatabaseCount('appointments', 0);
    }

    /**
     * The stale-page case the red toast exists for.
     *
     * The customer has the booking form open from before the block was saved. Their
     * page still shows 11:00 as available, because it was. The submit has to be
     * refused — and refused loudly, since from where they are sitting the site
     * offered them that slot.
     */
    public function test_a_block_saved_after_the_page_was_loaded_still_refuses_the_booking(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();
        $date = $this->bookableDate();

        // The page renders while the slot is genuinely free.
        $this->actingAs($user)->get('/book')->assertOk()->assertSee('11:00', false);

        // Then an admin closes that window.
        BlockedSlot::create(['date' => $date, 'start_time' => '10:00', 'end_time' => '12:00']);

        $this->actingAs($user)
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->bookingPayload($service, $date, '11:00'))
            ->assertSessionHasErrors('preferred_time');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_rescheduling_onto_a_blocked_time_is_refused(): void
    {
        $user = $this->makeUser();

        $appointment = $this->makeAppointment($user, $this->makeService(), [
            'preferred_date' => today()->addDays(3),
            'preferred_time' => '10:00',
            'status' => 'confirmed',
        ]);

        $date = $this->bookableDate(5);

        BlockedSlot::create(['date' => $date, 'start_time' => '11:00', 'end_time' => '13:00']);

        $this->actingAs($user)
            ->from(route('appointments.reschedule', $appointment))
            ->patch(route('appointments.reschedule.update', $appointment), [
                'preferred_date' => $date,
                'preferred_time' => '12:00',
                'reason' => 'Later please.',
            ])
            // The reschedule dialog has one date field rather than a date and a
            // time, so every availability problem is reported against the date —
            // but the message still names the blocked time, and still arrives as a
            // red toast rather than a silent refusal.
            ->assertSessionHasErrors('preferred_date')
            ->assertSessionHas('toast', fn (array $toast) => $toast['type'] === 'error'
                && $toast['message'] === BookingAvailability::UNAVAILABLE_TIME_MESSAGE);

        $this->assertSame(
            today()->addDays(3)->toDateString(),
            $appointment->fresh()->preferred_date->toDateString(),
            'The appointment should not have moved.',
        );
    }

    public function test_a_block_does_not_affect_another_day(): void
    {
        $blocked = $this->bookableDate(3);
        $free = $this->bookableDate(4);

        BlockedSlot::create(['date' => $blocked, 'start_time' => '10:00', 'end_time' => '12:00']);

        $this->assertContains('10:00', BookingAvailability::make()->availableSlots($free));
        $this->assertTrue(BookingAvailability::make()->isDateAvailable($free));
    }

    public function test_the_booking_form_shows_the_blocked_days_and_the_greyed_times(): void
    {
        $date = $this->bookableDate();

        BlockedSlot::create(['date' => $this->bookableDate(4), 'reason' => 'Public holiday']);
        BlockedSlot::create(['date' => $date, 'start_time' => '10:00', 'end_time' => '12:00']);

        $config = $this->bookingConfig($this->renderBookingForm());

        $html = $this->lastBookingHtml;

        // The calendar panel, which is where a closed day gets struck out. It is
        // the picker itself now rather than a grid printed beside a native date
        // input, so the strike and the control are the same thing — and the
        // panel keys itself rather than relying on the grid's old heading.
        $this->assertStringContainsString('calendarDays', $html);
        $this->assertStringContainsString('dayState(', $html);
        $this->assertStringContainsString('Closed or fully booked', $html);
        $this->assertStringContainsString('Outside booking window', $html);

        // Blocked times rendered as disabled options rather than simply missing.
        $this->assertStringContainsString('blockedSlots', $html);
        $this->assertStringContainsString('disabled x-text="formatTime(slot)', $html);
        $this->assertStringContainsString('— unavailable', $html);

        // And the whole-day map is in the page's config, so the first render is
        // already correct.
        $this->assertArrayHasKey($this->bookableDate(4), $config['blockedDates']);
        $this->assertArrayNotHasKey($date, $config['blockedDates'], 'A range block does not close the date.');
    }

    /**
     * A blocked date is announced once, by the toast — not twice.
     *
     * Both refusals used to be written out in grey under the date and time fields
     * *and* raised as a red toast at the bottom-right, so every blocked date was
     * said in two registers, one of them the same weight as a hint. The inline
     * pair is gone; the toast is the only feedback for a blocked date.
     *
     * Asserted on the mechanism that carried them rather than on the sentences.
     * The date sentence is still in the page — it is the toast's message, wired
     * in as `blockedMessage` — so asserting its absence would be asserting that
     * the toast had been removed. What had to go is the element that displayed it
     * in place, and the one-time state that fed that element.
     */
    public function test_a_blocked_date_is_reported_by_the_toast_alone(): void
    {
        $html = $this->renderBookingForm();

        // No element renders a server-supplied reason under the date field any
        // more, so `dateBlockedReason` — the state that fed it — is gone too.
        $this->assertStringNotContainsString(
            'dateBlockedReason',
            $html,
            'Nothing may render the server\'s refusal inline any more.',
        );

        // The no-slots sentence had no other home, so it is gone outright.
        $this->assertStringNotContainsString(
            'No open slots on this date',
            $html,
            'The no-slots refusal must not also be written out under the field.',
        );

        // The toast is still there, and still the one that says it.
        $this->assertStringContainsString('window.btaToast', $html);
        $this->assertStringContainsString('blockedMessage', $html);

        // What survives under the time field is the count, which the toast cannot
        // say: how many times that day are gone.
        $this->assertStringContainsString('on this date', $html);
    }

    /* ------------------------------------------------------------------ */
    /* 5. How a blocked day is painted                                     */
    /* ------------------------------------------------------------------ */

    /**
 * The strike is a red `line-through` on the date number.
 *
 * Two properties, not one, and the pairing is the whole point: a text-decoration
 * defaults to `currentColor`, so a bare `line-through` would paint the digit red
 * along with the line and a closed day would read as an error rather than as a
 * shut date. `decoration-status-cancelled` sets the line's colour on its own, so
 * the strike is red and the digit stays muted and legible underneath.
 *
 * This is the reference treatment — the line is drawn straight through the
 * digits — rather than a bar positioned over them, which is what it used to be.
 */
    public function test_a_fully_blocked_day_is_struck_through_in_red(): void
    {
        $html = $this->renderBookingForm();

        // The strike is applied by state, so a partially blocked day cannot get one.
        $this->assertStringContainsString("day.state === 'blocked'", $html);
        $this->assertMatchesRegularExpression(
            "/day\.state === 'blocked'.*line-through decoration-status-cancelled/s",
            $html,
            'A blocked day must be struck through, with the line red and the digit not.'
        );

        // Both halves of that pairing. Asserted separately because dropping either
        // one produces a different, wrong picture rather than a broken page: a
        // missing `line-through` is no strike, and a missing decoration colour is a
        // strike in the digit's own colour.
        $this->assertStringContainsString('line-through', $html);
        $this->assertStringContainsString('decoration-status-cancelled', $html);

        // The legend is struck through the same way, so the key cannot describe a
        // different treatment to the one the calendar uses.
        $this->assertMatchesRegularExpression(
            '/line-through decoration-status-cancelled">13/',
            $html,
            'The legend must show the strike it is a legend for.'
        );

        // The old positioned bar is gone — it is what the real strike replaced.
        $this->assertStringNotContainsString('pointer-events-none absolute left-1/2 top-1/2', $html);
    }

    /**
     * A day closed WITHOUT a reason is still struck through.
     *
     * This is the test the strike was missing, and it exists because every other
     * assertion about the strike passed while the strike did not appear.
     *
     * The classes were all there — `day.state === 'blocked'` was in `dayClasses()`,
     * `line-through decoration-status-cancelled` was in the page and in the built
     * CSS. What was wrong was the thing that decides whether a day *reaches* that
     * branch. `blockedDates` maps a date to its reason, the reason is optional
     * (`StoreBlockedSlotRequest` calls it `nullable`, and the admin calendar lets a
     * whole day be closed with the reason left blank), so the overwhelmingly common
     * closure is `blockedDates[iso] === null`. `isWholeDayBlocked()` asked
     * `Boolean(this.blockedDates[iso])`, and `Boolean(null)` is `false`.
     *
     * So the chain broke in the middle: the day was in the map, but never reached
     * the branch that draws the line, and rendered as an ordinary open day. A
     * customer could only discover the closure by clicking the date and being
     * refused by the toast — which is why the toast demonstrably worked while the
     * calendar said the day was free. The two disagreed because the server asks
     * `isset()` about the model and the client was asking about the model's reason.
     *
     * Both halves are asserted, because either alone would pass while the bug was
     * still live: the payload test says the day reaches the page, and the source
     * test says the page asks about the day rather than about its reason.
     */
    public function test_a_day_closed_without_a_reason_is_still_struck_through(): void
    {
        $date = $this->bookableDate();

        // The state the admin form produces when the reason is left blank: a whole
        // day, no times, and a null reason rather than an empty string.
        BlockedSlot::create(['date' => $date, 'reason' => null]);

        $config = $this->bookingConfig($this->renderBookingForm());
        $html = $this->lastBookingHtml;

        $this->assertArrayHasKey(
            $date,
            $config['blockedDates'],
            'A reason-less closure must still reach the calendar as a closed day.',
        );

        // And it reaches it as a null value, which is the whole hazard: anything
        // testing this entry for truthiness reads the closure as an open day.
        $this->assertNull(
            $config['blockedDates'][$date],
            'This test is only meaningful while a blank reason stays null.',
        );

        // The lookup therefore has to be for the key's presence.
        $this->assertMatchesRegularExpression(
            '/isWholeDayBlocked\(iso\)\s*\{[^}]*hasOwnProperty\.call\(/',
            $html,
            'A closed day must be detected by key presence: a null reason is still a closure.',
        );

        // Named explicitly, because this exact mistake is invisible to every other
        // assertion about the strike.
        $this->assertStringNotContainsString(
            'Boolean(this.blockedDates[',
            $html,
            'Truthiness on a nullable reason silently un-blocks the day.',
        );
    }

    public function test_blocked_and_out_of_window_days_are_painted_differently(): void
    {
        $html = $this->renderBookingForm();

        // Three states, three looks. Blocked and "outside the window" used to share
        // one greyed box, which left the strike with nothing to be distinct from.
        $this->assertStringContainsString("day.state === 'blocked'", $html);
        $this->assertStringContainsString("day.state === 'outside'", $html);

        // And the legend explains all of them, including the strike.
        $this->assertStringContainsString('Closed or fully booked', $html);
        $this->assertStringContainsString('Outside booking window', $html);
        $this->assertStringContainsString('Chosen', $html);
    }

    public function test_a_partially_blocked_day_is_not_given_a_state_of_its_own(): void
    {
        $html = $this->renderBookingForm();

        // Stripped of comments first. The reasoning *for* having no `partial` state
        // is the most useful thing a future reader can find in that method, so a
        // bare substring search would match the explanation and fail the assertion
        // that the code it explains is absent.
        $code = $this->stripBladeAndJsComments($html);

        /*
         * A date with some times blocked and others free is bookable, so it has to
         * look like any other open day. A fourth state would be the natural way to
         * build this and the wrong one: it invites a marker, and any marker on the
         * whole date tells the customer more of it is shut than is true.
         */
        $this->assertStringNotContainsString('partial', $code);
    }

    public function test_the_closed_weekday_strike_comes_from_the_salons_own_hours(): void
    {
        // A weekday the salon never opens is struck through on the same terms as a
        // date an admin blocked, because to a customer they mean the same thing.
        $html = $this->renderBookingForm();

        $this->assertStringContainsString('closedWeekdays', $html);
        $this->assertStringContainsString('this.closedWeekdays.includes(day.getDay())', $html);
    }

    /**
     * Attempting a blocked date raises the house red toast, not only inline text.
     */
    public function test_landing_on_a_blocked_date_raises_a_red_toast(): void
    {
        $config = $this->bookingConfig($this->renderBookingForm());

        // The wording is the server's, so the toast, the inline hint and the refusal
        // on submit cannot become three different sentences.
        $this->assertSame(
            BookingAvailability::UNAVAILABLE_DATE_MESSAGE,
            $config['blockedMessage'] ?? null,
        );

        $html = $this->lastBookingHtml;

        // Raised through the established toast bus — bottom-right, red fill, icon,
        // auto-dismiss — rather than a bespoke second notification.
        $this->assertStringContainsString("window.btaToast.show(", $html);
        $this->assertStringContainsString("data.wholeDay", $html);

        // Guarded so one date cannot stack a toast per event, and re-armed when the
        // customer moves to a bookable date.
        $this->assertStringContainsString('lastBlockedToast', $html);
    }

    public function test_the_blocked_date_toast_is_not_raised_for_a_recurring_closed_weekday(): void
    {
        $html = $this->renderBookingForm();

        /*
         * Keyed on `wholeDay`, not on "the date was refused".
         *
         * A Sunday the salon is always closed is standing information the grid
         * already shows struck through. Tosting it would tell a customer something
         * they can see on the calendar is taking a slot away from them, which it is
         * not — and would fire on every Sunday they looked at.
         */
        $this->assertStringContainsString('data.wholeDay &&', $html);
    }

    /* ------------------------------------------------------------------ */
    /* 4. Booking still works, and the old rules still bite                */
    /* ------------------------------------------------------------------ */

    public function test_booking_still_works_with_no_blocks_at_all(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();
        $date = $this->bookableDate();

        $this->actingAs($user)
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->bookingPayload($service, $date, '10:00'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_a_closed_weekday_is_still_unbookable(): void
    {
        // The recurring closure and the one-off block are different mechanisms and
        // both have to hold. A salon that shuts every Sunday needs no entry in
        // `blocked_slots` for that.
        $this->makeSalonSettings()->update(['operating_hours' => ['monday' => ['09:00', '17:00']]]);

        $sunday = today()->next(Carbon::SUNDAY);

        $this->assertSame(
            ['We are closed on Sundays.'],
            BookingAvailability::make()->dateProblems($sunday->toDateString()),
        );

        $this->actingAs($this->makeUser())
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->bookingPayload($this->makeService(), $sunday->toDateString(), '10:00'))
            ->assertSessionHasErrors('preferred_date');

        $this->assertDatabaseCount('appointments', 0);
    }

    /**
     * Monday is shut, by default, without anyone filing a block for it.
     *
     * The salon closes every Monday. That is a standing weekly closure rather than
     * a one-off, so it cannot be done the way the 9th of October was: nobody is
     * going to file a `blocked_slots` row for every Monday for the next two years.
     * It is one absent key in `operating_hours`, and this is what stops that key
     * quietly coming back.
     *
     * Every surface is asserted, because the failure this guards against is them
     * drifting apart rather than any one of them being wrong. `hoursFor()`
     * returning null is the root; no slots follows from it; the server refusal
     * follows from that; and the customer calendar greys Mondays from the same
     * map published in the page config, so a customer is never shown a bookable
     * Monday that the server then refuses.
     */
    public function test_monday_is_closed_by_default_and_cannot_be_booked(): void
    {
        $settings = $this->makeSalonSettings();

        // The shipped default, not the shared fixture's "open every day" hours.
        // `makeSalonSettings()` opens all seven days at 09:00-18:00 so that date
        // maths in unrelated tests never has to think about closures; the claim
        // being made here is about what the salon actually ships with.
        $settings->update(['operating_hours' => \App\Models\SalonSetting::defaultHours()]);

        // The root: no hours for Monday at all.
        $this->assertArrayNotHasKey('monday', $settings->operating_hours);
        $this->assertNull($settings->hoursFor(today()->next(Carbon::MONDAY)));
        $this->assertFalse($settings->isOpenOn(today()->next(Carbon::MONDAY)));

        // The default itself reads a Monday as closed, not merely the stored row.
        $this->assertArrayNotHasKey('monday', \App\Models\SalonSetting::defaultHours());

        // No slots to pick on a Monday, which is what empties the time dropdown.
        $this->assertSame([], $settings->slotsFor(today()->next(Carbon::MONDAY)));

        // The server refuses it, with the same message every closed weekday gets.
        $monday = today()->next(Carbon::MONDAY);

        $this->assertSame(
            ['We are closed on Mondays.'],
            BookingAvailability::make()->dateProblems($monday->toDateString()),
        );

        $this->actingAs($this->makeUser())
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->bookingPayload($this->makeService(), $monday->toDateString(), '10:00'))
            ->assertSessionHasErrors('preferred_date');

        $this->assertDatabaseCount('appointments', 0);

        // And the customer's calendar is told in the page itself, so a Monday is
        // greyed before it is clicked rather than after the refusal.
        $config = $this->bookingConfig($this->renderBookingForm());

        $this->assertArrayNotHasKey('monday', $config['hours']);
        $this->assertArrayHasKey('tuesday', $config['hours']);

        // A six-day week, not a closed salon: Tuesday through Sunday all still
        // open. Sunday in particular, since where the open run ends was the
        // ambiguous half of the brief.
        foreach ([Carbon::TUESDAY, Carbon::WEDNESDAY, Carbon::THURSDAY, Carbon::FRIDAY, Carbon::SATURDAY, Carbon::SUNDAY] as $openDay) {
            $date = today()->next($openDay);

            $this->assertTrue($settings->isOpenOn($date), $date->dayName.' should be open.');
            $this->assertNotSame([], $settings->slotsFor($date), $date->dayName.' should have slots.');
        }
    }

    public function test_rescheduling_onto_a_free_time_still_works(): void
    {
        $user = $this->makeUser();

        $appointment = $this->makeAppointment($user, $this->makeService(), [
            'preferred_date' => today()->addDays(3),
            'preferred_time' => '10:00',
            'status' => 'confirmed',
        ]);

        $newDate = $this->bookableDate(5);

        $this->actingAs($user)
            ->from(route('appointments.reschedule', $appointment))
            ->patch(route('appointments.reschedule.update', $appointment), [
                'preferred_date' => $newDate,
                'preferred_time' => '11:00',
                'reason' => 'Work ran late.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($newDate, $appointment->fresh()->preferred_date->toDateString());
    }

    public function test_operating_hours_are_still_published_to_customers(): void
    {
        $html = $this->get('/contact')->assertOk()->getContent();

        $this->assertStringContainsString('Operating Hours', $html);
    }

    public function test_service_categories_keep_their_colour_and_palette(): void
    {
        $this->makeService(['category' => 'Manicure & Pedicure']);

        $category = ServiceCategory::where('name', 'Manicure & Pedicure')->sole();

        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $category->color);
        $this->assertMatchesRegularExpression('/^rgba\(\d+, \d+, \d+, 0\.16\)$/', $category->tint);
    }

    /* ------------------------------------------------------------------ */

    /** Set by `renderBookingForm()`, so a test can assert on the markup and its config island. */
    private string $lastBookingHtml = '';

    /**
     * The booking form as a signed-in customer sees it, kept for the assertions that
     * need both the markup and the decoded config island.
     */
    private function renderBookingForm(): string
    {
        return $this->lastBookingHtml = $this->actingAs($this->makeUser())
            ->get('/book')
            ->assertOk()
            ->getContent();
    }

    /**
     * Remove Blade `{{-- --}}`, `/* *\/` and line comments from a page.
     *
     * Not a parser. The only goal is to stop an explanatory comment from failing an
     * assertion about code, and a regex over-eager enough to eat a string literal
     * would be a false negative rather than a false positive — the safer direction
     * for a check like this.
     */
    private function stripBladeAndJsComments(string $html): string
    {
        $html = preg_replace('/\{\{--.*?--\}\}/s', '', $html) ?? $html;
        $html = preg_replace('#/\*.*?\*/#s', '', $html);
        $html = preg_replace('#(^|\s)//[^\n]*#m', '$1', $html) ?? $html;

        return $html;
    }

    /**
     * The booking form's server-rendered config island, decoded.
     *
     * The page is 1,500 lines of Blade and mentions every service name in the
     * catalogue, in the summary and in the customer's own booking history — so
     * "is this service in the basket?" cannot be answered by looking for its name.
     * This is the JSON the Alpine component is handed, which is the one place the
     * answer lives.
     *
     * @return array<string, mixed>
     */
    private function bookingConfig(string $html): array
    {
        $this->assertMatchesRegularExpression(
            '/<script type="application\/json" id="bta-booking-config">(.*?)<\/script>/s',
            $html,
            'The booking form should render its config island.',
        );

        preg_match('/<script type="application\/json" id="bta-booking-config">(.*?)<\/script>/s', $html, $matches);

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true) ?? [];
    }

    /**
     * A complete, valid booking payload.
     *
     * @return array<string, mixed>
     */
    private function bookingPayload($service, string $date, string $time): array
    {
        return [
            'customer_name' => 'Ana Reyes',
            'customer_phone' => '09171234567',
            'preferred_date' => $date,
            'preferred_time' => $time,
            'services' => [['service_id' => $service->id, 'quantity' => 1]],
            'last_services_availed_note' => 'Glow Manicure',
            'agree_terms' => '1',
        ];
    }
}