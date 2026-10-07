<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBlockedSlotRequest;
use App\Models\BlockedSlot;
use App\Services\BookingAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Calendar & Blocked Slots — the dates the salon is not taking bookings on.
 *
 * A month grid with the days marked, a dialog per day for adding and removing
 * ranges, and a summary list of what is coming up.
 *
 * Every block for the visible month is rendered into the page as one JSON map
 * rather than fetched per click. A month grid is about 42 days; the payload is a
 * few hundred bytes per blocked day, almost always empty, and having the dialog
 * answer "which ranges exist on this date" without a round trip is what lets the
 * grid, the dialog and the summary all be reading the same numbers on the same
 * render. A screen that re-fetched on every click could show a day as clear in
 * the grid and blocked in the dialog.
 */
class CalendarController extends Controller
{
    /** Rows in the "upcoming blocks" list below the grid. */
    private const SUMMARY_LIMIT = 25;

    /**
     * The month grid.
     *
     * `?month=YYYY-MM` moves between months. Paging is by URL rather than by
     * Alpine alone so the grid survives a refresh, and so a month can be linked
     * to from the summary list.
     *
     * Leading and trailing days from the neighbouring months are shown, because a
     * month grid that started on the 1st and stopped on the 31st could not show
     * that the salon is open on the Saturday either side of a blocked week. They
     * are rendered, clickable, and belong to the month they fall in.
     */
    public function index(Request $request): View
    {
        $month = $this->monthFrom($request->query('month'));

        $gridStart = $month->copy()->startOfMonth()->startOfWeek();
        $gridEnd = $month->copy()->endOfMonth()->endOfWeek();

        $blocks = BlockedSlot::query()
            ->whereBetween('date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        // Grouped once, server-side, rather than filtered in the browser on every
        // click. Keyed by date so both the grid and the dialog read one lookup.
        $byDate = $blocks->groupBy(fn (BlockedSlot $slot) => $slot->date->toDateString());

        $availability = BookingAvailability::make();

        return view('admin.calendar.index', [
            'month' => $month,
            'gridStart' => $gridStart,
            'days' => $this->days($gridStart, $gridEnd, $availability->settings()),
            'blocksByDate' => $byDate,
            'blockedPayload' => $this->payload($byDate),
            'previousMonth' => $month->copy()->subMonth(),
            'nextMonth' => $month->copy()->addMonth(),
            'settings' => $availability->settings(),
            'today' => today()->toDateString(),
            // What the customer form will actually accept, printed under the grid.
            // An admin closing a day outside this window has changed nothing a
            // customer can reach yet, and should be able to see that before saving.
            'firstBookable' => $availability->firstBookableDate()->toDateString(),
            'lastBookable' => $availability->lastBookableDate()->toDateString(),
            'firstBookableLabel' => $availability->firstBookableDate()->format('M j, Y'),
            'lastBookableLabel' => $availability->lastBookableDate()->format('M j, Y'),
            'summary' => $this->summary(),
        ]);
    }

    /**
     * Add one or more ranges to a date.
     *
     * All rows in one transaction. A submission of two ranges where the second
     * would overlap nothing and store fine, but the first collided with an
     * existing block, must not leave the day half-blocked after the dialog
     * reported a failure.
     */
    public function store(StoreBlockedSlotRequest $request): RedirectResponse
    {
        // The request has already resolved a ticked "closed all day" into a single
        // row with no times, so the controller does not branch on which form the
        // admin filled in — it saves whatever rows it is handed.
        $ranges = $request->ranges();

        DB::transaction(function () use ($ranges, $request) {
            foreach ($ranges as $range) {
                BlockedSlot::create([
                    'date' => $range['date'],
                    'start_time' => $range['start_time'],
                    'end_time' => $range['end_time'],
                    'reason' => $range['reason'],
                    'created_by' => $request->user()?->id,
                ]);
            }
        });

        $date = Carbon::parse($request->blockedDate());

        return redirect()
            ->route('admin.calendar.index', ['month' => $request->query('month')])
            ->with('status', $this->addedMessage($date, count($ranges)));
    }

    /**
     * Reshape one range.
     *
     * The date is not editable here. A block is a fact about one date, and moving
     * it is a delete plus an add — which keeps the status history of the day
     * honest and means the "which ranges exist on the 12th" question never has to
     * cope with a row that claims to be about two dates.
     */
    public function update(StoreBlockedSlotRequest $request, BlockedSlot $block): RedirectResponse
    {
        $wholeDay = $request->isWholeDay();
        $range = $request->rangeFor();

        $block->update([
            'start_time' => $wholeDay ? null : $range['start_time'],
            'end_time' => $wholeDay ? null : $range['end_time'],
            'reason' => $request->cleanReasonFor(),
        ]);

        return redirect()
            ->route('admin.calendar.index', ['month' => $request->query('month')])
            ->with('status', 'Block updated for '.$block->date->format('M j, Y').'.');
    }

    public function destroy(Request $request, BlockedSlot $block): RedirectResponse
    {
        $date = $block->date->toDateString();

        $block->delete();

        return redirect()
            ->route('admin.calendar.index', ['month' => $request->query('month')])
            ->with('status', 'Block removed from '.Carbon::parse($date)->format('M j, Y').'.');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * One entry per cell in the grid, Monday-first.
     *
     * @param  \App\Models\SalonSetting  $settings
     * @return array<int, array<string, mixed>>
     */
    protected function days(Carbon $from, Carbon $to, $settings): array
    {
        $days = [];

        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $days[] = [
                'date' => $cursor->toDateString(),
                'day' => $cursor->day,
                'inMonth' => $cursor->month === $from->month,
                // Marked greyed, and for a different reason than a block. The salon
                // being shut on a Sunday is a recurring rule from Salon Settings, not
                // a closure somebody entered, so the grid says which of the two it
                // is rather than leaving both looking like the same free/busy pair.
                'closedWeekly' => ! $settings->isOpenOn($cursor),
                'label' => $cursor->format('D j M Y'),
            ];
        }

        return $days;
    }

    /**
     * Every block in range, shaped for the client.
     *
     * `wholeDay` and `ranges` are separate because they are separate questions:
     * whether the date can be picked at all, and which of its times have gone.
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, BlockedSlot>>  $byDate
     * @return array<string, array{wholeDay: bool, reason: ?string, ranges: array<int, array<string, mixed>>}>
     */
    protected function payload($byDate): array
    {
        return $byDate->map(function ($slots) {
            $wholeDays = $slots->filter(fn (BlockedSlot $slot) => $slot->isWholeDay());
            $ranges = $slots->reject(fn (BlockedSlot $slot) => $slot->isWholeDay());

            return [
                'wholeDay' => $wholeDays->isNotEmpty(),
                // One reason for the whole day. Any of them, since two whole-day
                // blocks on one date would be the same closure entered twice.
                'reason' => $wholeDays->first()?->reason,
                'ranges' => $ranges->map(fn (BlockedSlot $slot) => [
                    'id' => $slot->id,
                    'start' => $slot->start_time,
                    'end' => $slot->end_time,
                    'label' => $slot->rangeLabel(),
                    'reason' => $slot->reason,
                ])->values()->all(),
            ];
        })->all();
    }

    /**
     * Upcoming blocks, whole days and windows alike.
     *
     * Today onwards only: a closure in the past is not something an admin can act
     * on, and a list of them pushes next month's actual closures off the bottom.
     *
     * @return \Illuminate\Support\Collection<int, BlockedSlot>
     */
    protected function summary()
    {
        return BlockedSlot::query()
            ->whereDate('date', '>=', today())
            ->orderBy('date')
            ->orderByRaw('start_time IS NULL DESC')
            ->orderBy('start_time')
            ->limit(self::SUMMARY_LIMIT)
            ->get();
    }

    /**
     * The month being viewed, from `?month=YYYY-MM`.
     *
     * Falls back to the current month on anything unparseable, so a hand-edited or
     * stale bookmark lands on a real calendar rather than a 500.
     */
    protected function monthFrom(mixed $value): Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}$/', $value)) {
            return today()->copy()->startOfMonth();
        }

        try {
            return Carbon::createFromFormat('Y-m', $value)->startOfMonth();
        } catch (\Throwable) {
            return today()->copy()->startOfMonth();
        }
    }

    protected function addedMessage(Carbon $date, int $count): string
    {
        $when = $date->format('M j, Y');

        if ($count === 1) {
            return "1 block added for {$when}.";
        }

        return "{$count} blocks added for {$when}.";
    }
}