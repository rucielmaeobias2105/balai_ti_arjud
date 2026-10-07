<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Single-row operating configuration for the salon.
 *
 * **Backend-only: there is no admin screen for this.** It was seeded once by
 * `AdminSeeder` for as long as the model existed, briefly grew an edit screen, and
 * that screen was removed again — the values below are configuration, not records,
 * so they are changed in the seeder or a migration and deployed like any other
 * code. The row and every column are kept, because all of this is read on the
 * booking path:
 *
 *   - `operating_hours` → which weekdays are open, and the window on each
 *     (`isOpenOn()`, `hoursFor()`, `slotsFor()`, `isTimeWithinHours()`);
 *   - `slot_interval_minutes` → how long one appointment slot lasts;
 *   - `booking_lead_days` → how far ahead bookings open;
 *   - `minimum_notice_hours` → how much notice a booking needs; and
 *   - `down_payment_required` / `down_payment_percentage` → the deposit
 *     `BookingService` records against a booking.
 *
 * `SalonSetting::current()` is the accessor every one of those goes through, which
 * is why the row is a singleton: a second one would make "the salon's hours" a
 * coin toss on the booking form.
 *
 * One-off closures are **not** here. They are records with rows an admin adds and
 * removes, so they live in `blocked_slots` and have a Calendar screen.
 */
class SalonSetting extends Model
{
    protected $fillable = [
        'name',
        'address',
        'phone',
        'email',
        'operating_hours',
        'slot_interval_minutes',
        'booking_lead_days',
        'minimum_notice_hours',
        'down_payment_required',
        'down_payment_percentage',
    ];

    protected function casts(): array
    {
        return [
            'operating_hours' => 'array',
            'slot_interval_minutes' => 'integer',
            'booking_lead_days' => 'integer',
            'minimum_notice_hours' => 'integer',
            'down_payment_required' => 'boolean',
            'down_payment_percentage' => 'integer',
        ];
    }

    public static function current(): self
    {
        $settings = static::first();

        if ($settings) {
            return $settings;
        }

        // Create with every default explicit: column-level DB defaults are not
        // reflected on the in-memory model, and a booking_lead_days of 0 would
        // make every date look unbookable.
        //
        // `down_payment_required` is false: the salon takes no deposit for a web
        // booking, and the form no longer asks for a GCash reference. Left true it
        // would make `BookingService` compute a deposit amount for a booking that
        // can never have paid one.
        return static::create([
            'name' => 'Balai ti Arjud — Glow & Co. Beauty Lounge',
            'operating_hours' => self::defaultHours(),
            'slot_interval_minutes' => 30,
            'booking_lead_days' => 60,
            'minimum_notice_hours' => 24,
            'down_payment_required' => false,
            'down_payment_percentage' => 50,
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function defaultHours(): array
    {
        // Closed every Monday; open Tuesday to Sunday, 9am to 5pm — one window, no
        // per-day variation.
        //
        // Previously Monday–Thursday 09:00–18:00, Friday to 19:00, Saturday from
        // 08:00 to 19:00 and Sunday to 17:00, so a customer could book a slot that
        // existed on two days and not on five others. The salon books to a single
        // schedule now, which is also why the days are listed out rather than
        // generated: `operating_hours` treats a missing day as closed, and a
        // generated list would quietly depend on that rule to mean "open".
        //
        // Monday's absence from this map is the whole mechanism for a standing
        // weekly closure. Nothing in the admin panel has to block it, and an admin
        // cannot un-block it by accident: `hoursFor()` returns null for a day that
        // is not listed, `slotsFor()` then returns no slots at all, and the
        // booking rules refuse the date outright. The customer calendar greys
        // Mondays from the same map, so the grid and the server cannot disagree
        // about which weekdays are shut.
        return [
            'tuesday' => ['09:00', '17:00'],
            'wednesday' => ['09:00', '17:00'],
            'thursday' => ['09:00', '17:00'],
            'friday' => ['09:00', '17:00'],
            'saturday' => ['09:00', '17:00'],
            'sunday' => ['09:00', '17:00'],
        ];
    }

    /** @return array<string, string> */
    public static function dayNames(): array
    {
        return [
            'monday' => 'Monday',
            'tuesday' => 'Tuesday',
            'wednesday' => 'Wednesday',
            'thursday' => 'Thursday',
            'friday' => 'Friday',
            'saturday' => 'Saturday',
            'sunday' => 'Sunday',
        ];
    }

    /** Hours for a given date, or null when the salon is closed that day. */
    public function hoursFor(Carbon|string $date): ?array
    {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);

        return $this->operating_hours[strtolower($day->dayName)] ?? null;
    }

    /**
     * "09:00" → "09:00 AM", "17:00" → "05:00 PM", for display only.
     *
     * `operating_hours` is stored and compared as 24-hour "HH:MM" because
     * `isTimeWithinHours` and the slot maths both parse it as such — switching the
     * stored format to "5:00 PM" would break every availability rule behind the
     * booking form. So the 12-hour form belongs at the edge, in the view, and
     * this is the one place it is derived.
     *
     * The hour is zero-padded on purpose: "09:00 AM" lines its digits up with
     * "05:00 PM" in a column, which "9:00 AM" next to "5:00 PM" does not.
     */
    public static function formatTimeForDisplay(?string $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        [$hour, $minute] = array_pad(explode(':', $time, 2), 2, '00');

        $hour = (int) $hour;
        $suffix = $hour >= 12 ? 'PM' : 'AM';

        // 12-hour wrap, with 12 and 0 both landing on 12 rather than 0.
        $hour12 = $hour % 12 === 0 ? 12 : $hour % 12;

        return sprintf('%02d:%02d %s', $hour12, (int) $minute, $suffix);
    }

    public function isOpenOn(Carbon|string $date): bool
    {
        return $this->hoursFor($date) !== null;
    }

    /**
     * The salon's standing lunch break: 12:00 to 13:00, every open day.
     *
     * On a 30-minute grid over 09:00–17:00 that is exactly two slots — 12:00
     * and 12:30 — and they were being offered as bookable because nothing in the
     * grid knew they should not be. The salon does not take appointments over
     * lunch, so those two entries were a promise the front desk could not keep.
     *
     * Expressed as a window rather than as two literal times, because the grid
     * is generated: a salon that later moves to 15- or 45-minute slots should
     * not need a second edit here, and a window reads as the reason the slot is
     * missing rather than as an arbitrary exclusion.
     *
     * A constant rather than a column, for the same reason Monday's closure is a
     * constant: there is no admin screen for salon settings, so a column would be
     * a value nobody could change without a deploy. This is the one place to edit.
     */
    public const BREAK_START = '12:00';

    public const BREAK_END = '13:00';

    /**
     * Does this start time fall inside the lunch break?
     *
     * Half-open — `>= 12:00` and `< 13:00` — so the break covers the slots that
     * *start* inside it and 13:00 itself is bookable again. A break that ended
     * inclusively would also refuse 13:00, which is the first slot after lunch.
     *
     * Normalised first, so a time arriving as `12:00:00` or `1:00 PM` is judged
     * the same as `12:00`. The string comparison that follows is safe because
     * both sides are then zero-padded 24-hour "HH:MM", which is the format
     * `operating_hours` is stored in and the format `slotsFor()` produces.
     */
    public function isTimeDuringBreak(string $time): bool
    {
        try {
            $normalised = Carbon::parse($time)->format('H:i');
        } catch (\Throwable) {
            // Unparseable is not "during the break"; the caller's own validation
            // is what reports an unusable time, not this predicate.
            return false;
        }

        return $normalised >= self::BREAK_START && $normalised < self::BREAK_END;
    }

    /**
     * Bookable slot times ("HH:MM") for a given date.
     *
     * The lunch break is removed here rather than in the dropdown, so every
     * consumer of the grid agrees: the time select, `BookingAvailability`'s
     * blocked-slot sweep (which walks the same grid), and anything else that
     * asks the salon when it is free. Filtering it in the view would leave the
     * grid and the rules disagreeing about which times exist.
     */
    public function slotsFor(Carbon|string $date): array
    {
        $hours = $this->hoursFor($date);

        if ($hours === null) {
            return [];
        }

        $day = $date instanceof Carbon ? $date->format('Y-m-d') : Carbon::parse($date)->format('Y-m-d');

        $start = Carbon::createFromFormat('Y-m-d H:i', $day.' '.$hours[0]);
        $end = Carbon::createFromFormat('Y-m-d H:i', $day.' '.$hours[1]);
        $step = max(15, (int) $this->slot_interval_minutes);

        $slots = [];

        while ($start->lt($end)) {
            $slot = $start->format('H:i');

            if (! $this->isTimeDuringBreak($slot)) {
                $slots[] = $slot;
            }

            $start->addMinutes($step);
        }

        return $slots;
    }

    public function isTimeWithinHours(string $date, string $time): bool
    {
        $hours = $this->hoursFor($date);

        if ($hours === null) {
            return false;
        }

        return $time >= $hours[0] && $time <= $hours[1];
    }

    public function expectedDownPaymentFor(float $total): float
    {
        return round($total * ((int) $this->down_payment_percentage / 100), 2);
    }
}
