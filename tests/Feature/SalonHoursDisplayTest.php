<?php

namespace Tests\Feature;

use App\Models\SalonSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How opening hours are *written out*, as opposed to how they are stored.
 *
 * The distinction matters: `operating_hours` is kept as 24-hour "HH:MM" because
 * `isTimeWithinHours` and the slot maths both parse it that way, and the
 * availability rules behind the booking form depend on it. Only the rendering is
 * 12-hour. These tests hold both halves at once, so neither can be changed to
 * "fix" the other.
 */
class SalonHoursDisplayTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ */
    /* 1. The formatter                                                    */
    /* ------------------------------------------------------------------ */

    public function test_times_are_shown_twelve_hour_with_the_hour_padded(): void
    {
        $this->assertSame('09:00 AM', SalonSetting::formatTimeForDisplay('09:00'));
        $this->assertSame('05:00 PM', SalonSetting::formatTimeForDisplay('17:00'));
    }

    /**
     * The wrap is where a naive `% 12` goes wrong: midnight and noon both have to
     * land on 12, and 13:00 is 01:00 PM rather than 01:00 AM.
     */
    public function test_the_twelve_hour_wrap_is_correct_at_the_boundaries(): void
    {
        $this->assertSame('12:00 AM', SalonSetting::formatTimeForDisplay('00:00'));
        $this->assertSame('12:30 AM', SalonSetting::formatTimeForDisplay('00:30'));
        $this->assertSame('11:59 PM', SalonSetting::formatTimeForDisplay('23:59'));
        $this->assertSame('12:00 PM', SalonSetting::formatTimeForDisplay('12:00'));
        $this->assertSame('01:00 PM', SalonSetting::formatTimeForDisplay('13:00'));
        $this->assertSame('11:45 AM', SalonSetting::formatTimeForDisplay('11:45'));
    }

    /** A closed day has no times, so the formatter must not invent any. */
    public function test_a_missing_time_formats_to_nothing(): void
    {
        $this->assertNull(SalonSetting::formatTimeForDisplay(null));
        $this->assertNull(SalonSetting::formatTimeForDisplay(''));
    }

    /* ------------------------------------------------------------------ */
    /* 2. The contact page                                                */
    /* ------------------------------------------------------------------ */

    public function test_the_contact_page_shows_the_uniform_schedule_in_twelve_hour_time(): void
    {
        SalonSetting::current()->update(['operating_hours' => SalonSetting::defaultHours()]);

        $html = $this->get('/contact')->assertOk()->getContent();

        // Monday is shut every week, so the schedule opens on Tuesday. The card
        // renders the first and last OPEN day, so its label follows the hours
        // rather than staying "Monday - Sunday" over a Tuesday-to-Sunday week.
        $this->assertStringContainsString('Tuesday – Sunday', $html);
        $this->assertStringNotContainsString('Monday – Sunday', $html);
        $this->assertStringContainsString('09:00 AM – 05:00 PM', $html);

        // And none of the 24-hour form the page used to print.
        $this->assertStringNotContainsString('09:00 – 17:00', $html);
        $this->assertStringNotContainsString('17:00', $html);
    }

    /**
     * The per-day branch of the same card — used when the days do *not* share one
     * window — has to format the same way, or the two renderings disagree.
     */
    public function test_the_per_day_branch_formats_the_same_way(): void
    {
        SalonSetting::current()->update([
            'operating_hours' => [
                'monday' => ['09:00', '17:00'],
                'tuesday' => ['10:00', '16:00'],
                // Wednesday to Sunday closed.
            ],
        ]);

        $html = $this->get('/contact')->assertOk()->getContent();

        $this->assertStringContainsString('09:00 AM – 05:00 PM', $html);
        $this->assertStringContainsString('10:00 AM – 04:00 PM', $html);
        $this->assertStringContainsString('Closed', $html);
    }

    /* ------------------------------------------------------------------ */
    /* 3. Storage is untouched by the rendering                            */
    /* ------------------------------------------------------------------ */

    /**
     * The single most important test here: showing 12-hour times must not become
     * *storing* 12-hour times. "5:00 PM" in `operating_hours` would make every
     * availability comparison fail and empty the slot list.
     */
    public function test_the_stored_hours_are_still_twenty_four_hour(): void
    {
        $settings = SalonSetting::current();
        $settings->update(['operating_hours' => SalonSetting::defaultHours()]);

        $this->get('/contact')->assertOk();

        $stored = $settings->fresh()->operating_hours;

        foreach ($stored as $day => $pair) {
            $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $pair[0], "{$day} start is not HH:MM.");
            $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $pair[1], "{$day} end is not HH:MM.");
        }

        // Checked against a day the salon is actually open. Monday is no longer
        // stored at all, so asserting its window here would read an undefined
        // index rather than test anything about 24-hour storage.
        $this->assertSame(['09:00', '17:00'], $stored['tuesday']);
        $this->assertArrayNotHasKey('monday', $stored);
    }
}