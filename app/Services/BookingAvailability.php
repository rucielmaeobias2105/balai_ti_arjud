<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\BlockedSlot;
use App\Models\SalonSetting;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for "can this appointment be booked at that time?".
 *
 * Used by:
 *  - StoreBookingRequest / RescheduleRequest (server-side validation)
 *  - AppointmentController + RescheduleAppointmentController (slot dropdown)
 *  - the booking form's date picker and its blocked-day calendar
 *
 * Five rules:
 *
 *   - far enough ahead to be planned, and no further ahead than the horizon;
 *   - not a day the salon is closed, per its operating hours;
 *   - not a day an admin has blocked outright;
 *   - inside operating hours, outside the standing lunch break, and not inside a
 *     blocked window; and
 *   - not a slot another booking already holds.
 *
 * The lunch break is a sixth fact rather than a sixth rule: it is kept out of the
 * grid in `SalonSetting::slotsFor()`, so the times on offer never include it, and
 * it is re-checked here because this method is the server's own gate and does not
 * otherwise consult the grid. See `lunchBreakMessage()`.
 *
 * Every one of the first four reads `salon_settings` or `blocked_slots` live, so
 * an admin changing the opening hours, the slot length or a closure changes what
 * this class answers on the next request, with no cache to expire and nothing to
 * deploy.
 *
 * The whole-day block and the partial block are separate rules on purpose. A whole
 * day is refused at the *date*, so it can be greyed out in the picker before the
 * customer reaches the time step; a partial block leaves the date bookable and
 * removes individual times from the dropdown. A single combined rule could only do
 * one of those, and would either hide a partly-open day or refuse the whole of it.
 *
 * Everything here is still enforced server-side as well as on the date input, and
 * the customer form reads this class for its slot dropdown, so a blocked day comes
 * back with no slots rather than with slots that fail on submit.
 */
class BookingAvailability
{
    /** Shown when a date is inside the minimum-notice window. */
    public const MINIMUM_NOTICE_MESSAGE = 'Appointments must be booked at least one day in advance.';

    /** Shown when an admin has closed the whole day. Also a red toast. */
    public const UNAVAILABLE_DATE_MESSAGE = 'This date is not available. Please choose another date.';

    /** Shown when the chosen time falls in a blocked window. Also a red toast. */
    public const UNAVAILABLE_TIME_MESSAGE = 'This time is not available. Please choose another time.';

    /**
     * The lunch-break refusal, in the 12-hour form customers read.
     *
     * A method rather than a constant because it is built from
     * `SalonSetting::BREAK_START` / `BREAK_END` through the same display
     * formatter the rest of the booking form uses. Written out as a literal it
     * would be a second place describing the same window, and re-tuning the
     * break would leave the two disagreeing — the message saying 12:00–13:00
     * while the grid quietly moved.
     *
     * Also a red toast; see `toastFor()`. It reads as a refusal rather than as
     * a correction because the customer did nothing wrong — the hour simply is
     * not one the salon takes bookings in — but it is still a time they asked
     * for and did not get, and amber is reserved here for the minimum-notice
     * rule, which is the one case that is not the customer's choice at all.
     */
    public static function lunchBreakMessage(): string
    {
        $from = SalonSetting::formatTimeForDisplay(SalonSetting::BREAK_START);
        $to = SalonSetting::formatTimeForDisplay(SalonSetting::BREAK_END);

        return "We are closed for lunch between {$from} and {$to} — please choose another time.";
    }

    /**
     * The messages a blocked date or time produces.
     *
     * Read by `StoreBookingRequest::failedValidation()` to decide whether the
     * customer's warning should be amber or red. Listed here rather than matched
     * by string fragment in the request class, so the two cannot drift apart when
     * the wording changes.
     *
     * @var array<int, string>
     */
    public const UNAVAILABLE_MESSAGES = [
        self::UNAVAILABLE_DATE_MESSAGE,
        self::UNAVAILABLE_TIME_MESSAGE,
    ];

    public function __construct(protected SalonSetting $settings) {}

    public static function make(): self
    {
        return new self(SalonSetting::current());
    }

    public function settings(): SalonSetting
    {
        return $this->settings;
    }

    /**
     * First bookable date.
     *
     * The salon's configured minimum notice, resolved to the start of a calendar
     * day. Truncating to midnight is what makes the rule read as "a day" rather
     * than "an instant" — a booking is a date and a time, so the first date that
     * clears the notice is the day it lands on, not the moment it does.
     *
     * At the default 24 hours this is identical to the `today()->addDay()` it
     * replaced: 24 hours from any moment is always on the next calendar day.
     */
    public function firstBookableDate(): Carbon
    {
        return now()
            ->addHours(max(0, (int) $this->settings->minimum_notice_hours))
            ->startOfDay();
    }

    /** Last bookable date, bounded by the configured booking lead time. */
    public function lastBookableDate(): Carbon
    {
        return today()->addDays((int) $this->settings->booking_lead_days);
    }

    /**
     * All reasons a date is unavailable.
     *
     * Takes no `$serviceId`: neither notice, horizon, opening day, hours nor block
     * depends on which service is in the basket, so a parameter that did nothing
     * was removed rather than left in place.
     *
     * @return array<int, string>
     */
    public function dateProblems(string $date): array
    {
        $problems = [];
        $day = Carbon::parse($date);

        // One rule covers today and the past: both fail the minimum notice.
        if ($day->lt($this->firstBookableDate())) {
            $problems[] = $this->minimumNoticeMessage();
        }

        if ($day->gt($this->lastBookableDate())) {
            $problems[] = 'Bookings only open '.(int) $this->settings->booking_lead_days.' days in advance.';
        }

        if (! $this->settings->isOpenOn($day)) {
            $problems[] = 'We are closed on '.$day->format('l').'s.';
        }

        // Last, so a blocked day reports its block only when nothing else about it
        // was already wrong. A Sunday the salon is always closed and has also
        // blocked reads as the ordinary weekly closure, which is the more useful
        // of the two things to tell somebody.
        if ($this->isDateBlocked($date)) {
            $problems[] = self::UNAVAILABLE_DATE_MESSAGE;
        }

        return $problems;
    }

    /**
     * The minimum-notice wording, quoted from the setting rather than fixed.
     *
     * The message used to be a constant reading "at least one day in advance",
     * which became a lie the moment the salon set the notice to anything else.
     * The number is the configured one now — and at the default 24 hours it
     * produces that exact sentence, so the wording customers already know is
     * unchanged until they change a setting.
     *
     * Small numbers are spelled out rather than written as digits because "at
     * least 1 day" reads like a placeholder and "at least one day" reads like
     * something a salon decided.
     */
    public function minimumNoticeMessage(): string
    {
        $hours = (int) $this->settings->minimum_notice_hours;

        if ($hours <= 0) {
            return 'Appointments can be booked for any upcoming date.';
        }

        if ($hours < 24) {
            return 'Appointments must be booked at least '.$this->count($hours).' hour'.($hours === 1 ? '' : 's').' in advance.';
        }

        $days = intdiv($hours, 24);

        return 'Appointments must be booked at least '.$this->count($days).' day'.($days === 1 ? '' : 's').' in advance.';
    }

    /**
     * "one", "two" … up to seven, then the digits.
     *
     * Only the first week is spelled out, because past that a salon is stating a
     * policy in weeks rather than counting small change.
     */
    protected function count(int $value): string
    {
        $words = [
            1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four',
            5 => 'five', 6 => 'six', 7 => 'seven',
        ];

        return $words[$value] ?? (string) $value;
    }

    /**
     * The toast a set of validation messages should raise, or null for none.
     *
     * Both the booking form and the reschedule form refuse the same rules, and both
     * need to say so in the same colour — so the decision is asked here rather than
     * duplicated as a pair of `foreach` loops over string constants that would drift
     * the first time one of the messages was reworded.
     *
     * Red for a block. The customer picked something the screen showed as
     * available, so from where they are sitting the site offered it and then took
     * it away; amber would read as a correction on their side. Amber for the
     * minimum-notice rule, which is not a mistake at all — the date simply is not
     * one the salon takes bookings for, and the picker already showed it that way.
     *
     * @param  array<int, string>  $messages
     * @return array{type: string, message: string}|null
     */
    public static function toastFor(array $messages): ?array
    {
        foreach (self::UNAVAILABLE_MESSAGES as $unavailable) {
            if (in_array($unavailable, $messages, true)) {
                return ['type' => 'error', 'message' => $unavailable];
            }
        }

        // The lunch break is the same kind of refusal — a time that was asked
        // for and is not on offer — so it raises the same red toast. Compared by
        // value rather than kept in `UNAVAILABLE_MESSAGES`, because that array is
        // a const and this message is built from the break's own constants.
        $lunch = static::lunchBreakMessage();

        if (in_array($lunch, $messages, true)) {
            return ['type' => 'error', 'message' => $lunch];
        }

        /*
         * Matched against both the configured wording and the 24-hour default, so a
         * submission refused before the setting was ever saved still gets its toast
         * rather than silently getting none.
         */
        foreach (array_unique([self::make()->minimumNoticeMessage(), self::MINIMUM_NOTICE_MESSAGE]) as $watched) {
            if (in_array($watched, $messages, true)) {
                return ['type' => 'warning', 'message' => $watched];
            }
        }

        return null;
    }

    /** Has an admin closed this whole date? */
    public function isDateBlocked(string $date): bool
    {
        return isset($this->blockedDayMap()[$date]);
    }

    /**
     * Partial blocks on one date, as "HH:MM" start times keyed by the slot.
     *
     * @return array<int, BlockedSlot>
     */
    public function blockedRanges(string $date): array
    {
        return BlockedSlot::rangesOn($date);
    }

    /**
     * Every whole-day block in `$from`…`to`, keyed by date.
     *
     * Memoised per instance because the booking form asks about a whole month at
     * once and `dateProblems()` would otherwise re-query for each day it checked.
     *
     * @return array<string, BlockedSlot>
     */
    protected function blockedDayMap(): array
    {
        if ($this->blockedDays !== null) {
            return $this->blockedDays;
        }

        $from = $this->firstBookableDate();
        $to = $this->lastBookableDate();

        return $this->blockedDays = BlockedSlot::wholeDaysBetween($from, $to);
    }

    /** @var array<string, BlockedSlot>|null */
    protected ?array $blockedDays = null;

    public function isDateAvailable(string $date): bool
    {
        return $this->dateProblems($date) === [];
    }

    /**
     * Bookable times on a date, excluding slots already taken, slots inside an
     * admin's blocked window, and slots too close to now.
     *
     * @return array<int, string>
     */
    public function availableSlots(string $date, ?int $ignoreAppointmentId = null): array
    {
        if (! $this->isDateAvailable($date)) {
            return [];
        }

        $slots = $this->settings->slotsFor($date);

        if ($slots === []) {
            return [];
        }

        $taken = $this->takenTimes($date, $ignoreAppointmentId);
        $blocked = $this->blockedTimes($date);

        // A slot is unavailable once the current time is within an hour of it.
        $now = now();

        return array_values(array_filter($slots, function (string $slot) use ($taken, $blocked, $date, $now) {
            if (in_array($slot, $taken, true)) {
                return false;
            }

            if (in_array($slot, $blocked, true)) {
                return false;
            }

            // The date has to be part of the parse: `Carbon::parse('09:00')` alone
            // resolves to *today* at 09:00, which for any bookable date but today
            // is in the past and would filter out every morning slot on the grid.
            $slotAt = Carbon::parse($date.' '.$slot);

            return $slotAt->gt($now->copy()->addHour());
        }));
    }

    /**
     * Slot start times an admin has blocked on a date.
     *
     * The salon's own slot grid walked first, then each range asked whether it
     * covers that slot. Walking the grid rather than the ranges is what keeps
     * "blocked" and "bookable" describing the same set of times — a block of
     * 02:00–04:00 on a 30-minute grid has to remove four dropdown entries, and
     * deriving that from the grid means it cannot come out as three.
     *
     * @return array<int, string>
     */
    public function blockedTimes(string $date): array
    {
        $ranges = $this->blockedRanges($date);

        if ($ranges === []) {
            return [];
        }

        return array_values(array_filter(
            $this->settings->slotsFor($date),
            fn (string $slot) => $this->isTimeBlocked($date, $slot, $ranges),
        ));
    }

    /**
     * Is this start time inside one of the given ranges?
     *
     * @param  array<int, BlockedSlot>  $ranges
     */
    public function isTimeBlocked(string $date, string $time, ?array $ranges = null): bool
    {
        $ranges ??= $this->blockedRanges($date);

        foreach ($ranges as $range) {
            if ($range->blocksTime($time)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Times already committed on a date.
     *
     * Pending and accepted both hold their slot, otherwise a customer could
     * pick a time another customer has already been given and the second
     * booking would fail at confirmation instead of at selection.
     *
     * @return array<int, string>
     */
    public function takenTimes(string $date, ?int $ignoreAppointmentId = null): array
    {
        return Appointment::query()
            ->active()
            ->whereDate('preferred_date', $date)
            ->whereIn('status', [
                AppointmentStatus::Pending->value,
                AppointmentStatus::Confirmed->value,
                AppointmentStatus::InProgress->value,
            ])
            ->when($ignoreAppointmentId, fn ($q) => $q->whereKeyNot($ignoreAppointmentId))
            ->pluck('preferred_time')
            ->map(fn ($time) => Carbon::parse($time)->format('H:i'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function timeProblems(string $date, string $time, ?int $ignoreAppointmentId = null): array
    {
        $problems = $this->dateProblems($date);

        if ($problems !== []) {
            return $problems;
        }

        if (! $this->settings->isTimeWithinHours($date, $time)) {
            $hours = $this->settings->hoursFor($date);
            $problems[] = "That time is outside our operating hours ({$hours[0]}–{$hours[1]}).";
        }

        /*
         * The lunch break is refused here as well as being kept out of the grid.
         *
         * `slotsFor()` already omits 12:00 and 12:30, so the dropdown cannot
         * offer them and this check is unreachable through the form. It is here
         * because this method is the server's own gate, and it never consulted
         * the grid: it checks operating hours, admin blocks, taken slots and
         * minimum notice, and nothing else. A request that posted `12:00` by hand
         * would otherwise sail through every one of those and write a booking the
         * salon cannot serve — the exact booking the grid was changed to prevent.
         *
         * Checked before the "slot has just been taken" rule for the same reason
         * an admin block is: this is a closure, not a race, and telling somebody
         * to "pick another time" for a lunch hour they were never offered sends
         * them looking for one that does not exist.
         */
        if ($this->settings->isTimeDuringBreak($time)) {
            $problems[] = static::lunchBreakMessage();
        }

        /*
         * Checked before the "slot has just been taken" rule below, because a slot
         * an admin blocked is not a race — nothing is going to release it — and
         * telling somebody to "pick another time" when the whole window is closed
         * sends them looking for one that does not exist.
         */
        if ($this->isTimeBlocked($date, Carbon::parse($time)->format('H:i'))) {
            $problems[] = self::UNAVAILABLE_TIME_MESSAGE;
        }

        if (in_array(Carbon::parse($time)->format('H:i'), $this->takenTimes($date, $ignoreAppointmentId), true)) {
            $problems[] = 'That slot has just been taken. Please pick another time.';
        }

        if (Carbon::parse($date.' '.$time)->lte(now()->addHour())) {
            $problems[] = 'Please book at least an hour in advance.';
        }

        return $problems;
    }

    }