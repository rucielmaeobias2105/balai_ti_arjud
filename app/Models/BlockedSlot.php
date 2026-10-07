<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A window the salon is not taking bookings in.
 *
 * One row is one range on one date. Three shapes, distinguished by the times:
 *
 *   - both times null    → the whole day is closed;
 *   - both times set     → that window only, the rest of the day still books;
 *   - several rows a day → 9–10am *and* 2–4pm closed, the rest open.
 *
 * `end_time` is exclusive. A 2:00–4:00 block refuses a start at 2:00, 2:30, 3:00
 * or 3:30 and leaves 4:00 bookable, because a block is about when a treatment may
 * *begin* — 4:00 is not part of a 2-to-4 window. A range whose two ends are equal
 * blocks exactly that one slot, so "block the 2:00pm appointment" is expressible
 * without having to invent a 2:01 end.
 *
 * All of that is asked through `blocksTime()` and `isWholeDay()` rather than by
 * comparing strings at the call site, so the booking form, the calendar grid and
 * the admin summary cannot each come away with their own idea of what a range
 * means.
 */
class BlockedSlot extends Model
{
    protected $fillable = [
        'date',
        'start_time',
        'end_time',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /** The admin who recorded the block, if that account still exists. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /**
     * True when this row closes the entire day rather than a window.
     *
     * A row with only one of the two times set is not a whole-day block and is not
     * a usable window either — the admin form refuses to save it — but this asks
     * the question that matters for availability, which is whether the day is shut.
     */
    public function isWholeDay(): bool
    {
        return $this->start_time === null && $this->end_time === null;
    }

    /**
     * Does this row stop a treatment starting at `$time` ("HH:MM")?
     *
     * A whole-day row blocks every time. A window blocks starts from `start_time`
     * up to but not including `end_time`, and a zero-width window
     * (`start_time === end_time`) blocks that one time exactly.
     */
    public function blocksTime(string $time): bool
    {
        if ($this->isWholeDay()) {
            return true;
        }

        // A half-set range cannot say anything useful, so it blocks nothing. It
        // should not exist — `StoreBlockedSlotRequest` refuses it — and this is
        // the backstop that keeps a hand-edited row from closing a whole day.
        if ($this->start_time === null || $this->end_time === null) {
            return false;
        }

        if ($this->start_time === $this->end_time) {
            return $time === $this->start_time;
        }

        return $time >= $this->start_time && $time < $this->end_time;
    }

    /** "Closed all day" or "09:00 AM – 10:00 AM", for the admin list. */
    public function rangeLabel(): string
    {
        if ($this->isWholeDay()) {
            return 'Closed all day';
        }

        return trim(
            (SalonSetting::formatTimeForDisplay($this->start_time) ?? '')
            .' – '
            .(SalonSetting::formatTimeForDisplay($this->end_time) ?? '')
        );
    }

    /** Sort key so a day's rows read in clock order, whole-day ones first. */
    public function scopeForDate(Builder $query, Carbon|string $date): Builder
    {
        return $query->whereDate('date', $date instanceof Carbon ? $date->toDateString() : $date);
    }

    /** Rows that close the entire day — the ones that disable a date outright. */
    public function scopeWholeDays(Builder $query): Builder
    {
        return $query->whereNull('start_time')->whereNull('end_time');
    }

    /** Rows that close part of a day, which grey out individual time slots. */
    public function scopeRanges(Builder $query): Builder
    {
        return $query->whereNotNull('start_time')->whereNotNull('end_time');
    }

    /**
     * Every whole day closed in `$from`…`to`, keyed by date.
     *
     * One query for the lot rather than a lookup per day, because the booking
     * form's calendar asks this for a whole month at a time.
     *
     * @return array<string, BlockedSlot>
     */
    public static function wholeDaysBetween(Carbon|string $from, Carbon|string $to): array
    {
        return static::query()
            ->wholeDays()
            ->whereBetween('date', [
                $from instanceof Carbon ? $from->toDateString() : $from,
                $to instanceof Carbon ? $to->toDateString() : $to,
            ])
            ->get()
            ->keyBy(fn (self $slot) => $slot->date->toDateString())
            ->all();
    }

    /**
     * Time ranges blocked on one date.
     *
     * Only the partial rows. A whole-day block is not a time range, and letting
     * one in here would have `BookingAvailability` filter every slot — correct, but
     * by accident and at the cost of loading a row it did not need.
     *
     * @return array<int, BlockedSlot>
     */
    public static function rangesOn(Carbon|string $date): array
    {
        return static::query()
            ->ranges()
            ->forDate($date)
            ->orderBy('start_time')
            ->get()
            ->all();
    }

    /**
     * Normalise an incoming time to "HH:MM:SS" for the `time` columns.
     *
     * The admin form posts `type="time"`, which yields "HH:MM" or "HH:MM:SS"
     * depending on the browser, and a hand-built request can send anything. Parsing
     * through Carbon rejects the lot on one path instead of storing a string the
     * availability check would later compare lexicographically and get wrong.
     */
    protected function normaliseTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::createFromFormat('H:i', substr((string) $value, 0, 5))->format('H:i:s');
    }

    public function setStartTimeAttribute(mixed $value): void
    {
        $this->attributes['start_time'] = $this->normaliseTime($value);
    }

    public function setEndTimeAttribute(mixed $value): void
    {
        $this->attributes['end_time'] = $this->normaliseTime($value);
    }

    /**
     * Read the stored "HH:MM:SS" back as "HH:MM".
     *
     * Every comparison in `blocksTime()` and every label in the admin list works in
     * "HH:MM", which is also what `operating_hours` stores, so normalising on the
     * way out means a blocked range and an opening time can be compared directly.
     */
    protected function timeAsHhmm(?string $value): ?string
    {
        return $value === null ? null : substr($value, 0, 5);
    }

    public function getStartTimeAttribute(mixed $value): ?string
    {
        return $this->timeAsHhmm(is_string($value) ? $value : null);
    }

    public function getEndTimeAttribute(mixed $value): ?string
    {
        return $this->timeAsHhmm(is_string($value) ? $value : null);
    }
}