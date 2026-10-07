{{--
    The date field, as a dropdown calendar.

    WHAT THIS REPLACED

    The booking form used to put a full-month availability grid permanently on the
    page under the date and time fields — always visible, never collapsed, taking
    roughly a third of the step down. It was there because a *native* date input
    renders the browser's own picker, and nothing can be done to that picker: a
    date the salon has closed cannot be greyed out in it, so the information had
    to live somewhere else. The cost was a wall of calendar on a form where the
    customer came to choose a date and a time.

    This component removes that wall. The field is a single input; clicking it
    opens a small floating month panel anchored to it, which closes on selection,
    on Escape, or on a click anywhere else — the dropdown-calendar pattern.

    IT IS A BLADE PART, NOT A NATIVE CONTROL, ON PURPOSE

    The whole reason the grid existed is that the browser will not show blocked
    days. The only way to strike a date through in red is to draw the day
    buttons, so the picker has to be ours. The input beside it is therefore
    `readonly` and holds no value the form submits.

    WHAT ACTUALLY SUBMITS

    A hidden input carries the ISO date. The visible input is a display surface
    only — it shows the chosen day written out ("Sat, 12 Jun 2026") because a
    raw `2026-06-12` in a field a customer is told is a date reads as a string
    they are being asked to understand. Both are written by the same `date` the
    rest of the booking form already uses, so the panel, the summary column and
    the submitted field cannot drift apart.

    WHY THE PANEL IS `fixed` AND NOT `absolute`

    An absolutely-positioned panel would be clipped by the wizard card's
    `overflow-hidden` — the card clips its header and footer background to its
    rounded corners — and it would hang off the right edge of a phone. Sizing it
    from the input's `getBoundingClientRect()` and placing it against the
    viewport fixes both, and lets it flip above the field when there is no room
    below. `positionDatePicker()` in the booking form does that arithmetic.

    THE ALPHABETICAL DAY CELLS

    Blocked days are struck through in red — a real `line-through` on the date
    number, with `decoration-status-cancelled` setting the line's colour
    independently of the digit's. That separation is the whole trick: a
    text-decoration defaults to `currentColor`, so a bare `line-through` would
    paint the digit red too and a closed day would read as an error rather than
    as a shut date. The digit stays muted and legible; only the line is red.

    `dayClasses()` in the booking form owns both classes, so the legend under the
    grid can be struck through the same way and the key cannot drift from the
    calendar it explains.

    A blocked day is NOT a disabled button here. It is live, and clicking it
    raises the refusal toast — the customer has made an attempt and is owed an
    answer, not a button that silently does nothing. Only days outside the
    booking window are truly inert.

    THE SCOPE THIS COMPONENT DEPENDS ON

    It carries no `x-data` of its own. It is a view onto the booking form's
    state — `date`, `minDate`, `maxDate`, `blockedDates`, `calendarDays`,
    `dayClasses()`, `pickDate()`, `shiftMonth()` — so it must be rendered inside
    an element carrying `x-data="bookingForm"`. Rendering it anywhere else would
    silently render an inert field, which is why the contract is stated here
    rather than left to be discovered.
--}}
@props([
    'name' => 'preferred_date',
    'label' => 'Preferred Date',
    'id' => null,
    'value' => null,
    'required' => false,
    'hint' => null,
    'wrapperClass' => '',
])

@php
    $id = $id ?: ($name . '_picker');
    $hasError = $name && $errors->has($name);
    $describedBy = collect([
        $hint ? $id . '-hint' : null,
        $hasError ? $id . '-error' : null,
    ])->filter()->join(' ');

    /*
     * The display text, written server-side as well as in the picker.
     *
     * `x-bind:value` takes over the moment Alpine starts, and a field that shows
     * one date and then swaps to a differently-formatted copy of the same date is
     * a visible flicker on every render. Same format string as
     * `datePickerLabel` in the booking form, so the handover is invisible.
     */
    $rawDate = old($name, $value);
    $displayDate = '';

    if ($rawDate) {
        try {
            $displayDate = \Illuminate\Support\Carbon::parse($rawDate)->format('D, j M Y');
        } catch (\Throwable) {
            // A value the server could not parse is shown verbatim rather than
            // blanked; `StoreBookingRequest` is what decides whether it is valid.
            $displayDate = (string) $rawDate;
        }
    }
@endphp

<div
    {{ $attributes->merge(['class' => $wrapperClass !== '' ? $wrapperClass : null]) }}
    x-on:keydown.escape.window="closeDatePicker()"
    x-on:resize.window="datePickerOpen && positionDatePicker()"
    x-on:scroll.capture.window="datePickerOpen && positionDatePicker()"
>
    <label for="{{ $id }}" class="label">
        {{ $label }}
        @if ($required)<span class="text-status-cancelled">*</span>@endif
    </label>

    {{-- The value the form actually submits.

         `x-bind:value` keeps it in step with the panel; the server-rendered value
         is what a rejected submit comes back with, so a validation error never
         silently wipes the date the customer had already chosen. --}}
    <input type="hidden" name="{{ $name }}" value="{{ $rawDate }}" x-bind:value="date">

    <div class="relative" x-ref="datePickerAnchor">
        <input
            type="text"
            id="{{ $id }}"
            readonly
            autocomplete="off"
            placeholder="Choose a date"
            class="input cursor-pointer pr-11 {{ $hasError ? 'input-error ' : '' }}"
            value="{{ $displayDate }}"
            x-bind:value="datePickerLabel"
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if ($hasError) aria-invalid="true" @endif
            aria-haspopup="dialog"
            aria-controls="{{ $id }}-panel"
            x-bind:aria-expanded="datePickerOpen ? 'true' : 'false'"
            x-on:click="toggleDatePicker()"
            x-on:keydown.enter.space.prevent="toggleDatePicker()"
        >

        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3.5 text-ink-muted">
            <x-heroicon-o-calendar-days class="h-4 w-4" />
        </span>

        {{-- The panel.

             `datePickerReady` gates the fade rather than the display, because the
             panel has to be laid out before it can be measured — and the panel's
             own size is what tells it where to sit. It is set in the same tick
             the position is written, so it never appears at the wrong place for a
             frame. --}}
        <div
            id="{{ $id }}-panel"
            x-ref="datePickerPanel"
            x-show="datePickerOpen"
            x-cloak
            x-bind:style="datePickerStyle"
            x-bind:class="datePickerReady ? 'opacity-100' : 'opacity-0'"
            x-on:click.outside="closeDatePicker()"
            role="dialog"
            aria-label="Choose a booking date"
            {{-- `max-w-[calc(100vw-2rem)]` alongside the fixed width.

                 The panel is 19rem (304px) because that is seven comfortable day
                 cells plus padding. On a 360px phone that fits; on a 320px one it
                 did not, and a calendar with its last two columns off the right
                 edge of the screen is worse than a narrower one. The cap lets the
                 width give way rather than overflow, and `datePickerStyle` has
                 already positioned the panel by the time the cap applies. --}}
            class="fixed z-[70] w-[19rem] max-w-[calc(100vw-2rem)] rounded-card border border-line bg-cream p-3 shadow-card-hover transition-opacity duration-100 motion-reduce:transition-none"
        >
            <div class="flex items-center justify-between gap-1">
                <button
                    type="button"
                    class="btn-ghost h-7 w-7 shrink-0 p-0"
                    x-on:click="shiftMonth(-1)"
                    x-bind:disabled="! canShiftBack()"
                    aria-label="Previous month"
                >&larr;</button>

                <span
                    class="min-w-[7rem] flex-1 text-center text-sm font-semibold text-primary"
                    x-text="calendarLabel"
                    aria-live="polite"
                ></span>

                <button
                    type="button"
                    class="btn-ghost h-7 w-7 shrink-0 p-0"
                    x-on:click="shiftMonth(1)"
                    x-bind:disabled="! canShiftForward()"
                    aria-label="Next month"
                >&rarr;</button>
            </div>

            <div
                class="mt-2.5 grid grid-cols-7 gap-1 text-center text-[10px] font-semibold uppercase tracking-wider text-ink-muted"
                aria-hidden="true"
            >
                @foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $weekdayInitial)
                    <div>{{ $weekdayInitial }}</div>
                @endforeach
            </div>

            <div class="mt-1 grid grid-cols-7 gap-1">
                <template x-for="day in calendarDays" :key="day.date">
                    <button
                        type="button"
                        class="relative flex h-8 items-center justify-center rounded-lg border text-xs transition-colors"
                        :class="dayClasses(day)"
                        x-on:click="pickDate(day)"
                        x-bind:disabled="day.state === 'outside'"
                        x-bind:aria-label="day.label"
                        x-bind:title="day.title || ''"
                    >
                        {{-- The date number. A blocked day is struck through it by
                             `dayClasses()`, which puts `line-through` and
                             `decoration-status-cancelled` on this button — so the line
                             is drawn through the digits themselves, at whatever width
                             the digits are, and the digit stays readable underneath. --}}
                        <span x-text="day.day"></span>
                    </button>
                </template>
            </div>

            <div class="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-line/60 pt-2 text-[10px] text-ink-muted">
                <span class="flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded border border-primary/40 bg-linen"></span>
                    Available
                </span>

                {{-- The strike, drawn the way it is drawn on the day itself — a red line
                     straight through the digits — so the key and the calendar cannot
                     look like two different things. --}}
                <span class="flex items-center gap-1.5">
                    <span class="flex h-3.5 w-5 items-center justify-center rounded border border-line/50 bg-linen/40 text-[9px] font-semibold leading-none text-ink-muted line-through decoration-status-cancelled">13</span>
                    Closed or fully booked
                </span>

                <span class="flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded border border-line/40 bg-linen/30"></span>
                    Outside booking window
                </span>
            </div>

            <template x-if="blockedDateList.length > 0">
                <p class="mt-1.5 text-[10px] leading-snug text-ink-muted">
                    <span class="font-semibold text-ink" x-text="blockedDateList.length"></span>
                    <span x-text="blockedDateList.length === 1 ? 'date is' : 'dates are'"></span>
                    fully booked out:
                    <span x-text="blockedDateList.slice(0, 3).join(', ')"></span><span x-show="blockedDateList.length > 3" x-text="' and ' + (blockedDateList.length - 3) + ' more'"></span>.
                </p>
            </template>
        </div>
    </div>

    @if ($hint)
        <p id="{{ $id }}-hint" class="input-hint">{{ $hint }}</p>
    @endif

    @error($name)
        <p id="{{ $id }}-error" class="input-error-text">
            <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10A8 8 0 11 2 10a8 8 0 0116 0zm-9-4a1 1 0 112 0v4a1 1 0 11-2 0V6zm1 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
            {{ $message }}
        </p>
    @enderror
</div>