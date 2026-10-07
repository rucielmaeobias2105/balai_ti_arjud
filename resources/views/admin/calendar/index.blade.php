@extends('layouts.admin')

@section('title', 'Calendar')
@section('heading', 'Calendar & Blocked Slots')

@section('content')
    {{--
        The whole visible month is rendered into the page as one map keyed by date,
        and the dialog reads from it. A month grid is about 42 cells and only the
        blocked ones carry anything, so the payload is small — and having the grid,
        the dialog and the summary below all read the same numbers on the same
        render is what stops a day looking free in one and blocked in another.

        Two different kinds of "closed" are painted differently, because they are
        different facts:

          - a day greyed with a dot   = an admin entered a block for that date;
          - a day greyed with a slash = the salon has no opening hours for that
                                       weekday, from Salon Settings.

        An admin closing the 25th and an admin who has always shut on Sundays are
        not the same decision, and a grid that painted them the same would make the
        first look permanent and the second look like a one-off.
    --}}
    <div
        x-data="blockedSlotCalendar()"
        class="grid gap-6 lg:grid-cols-3"
    >
        {{-- ============================ Month grid ============================ --}}
        <div class="lg:col-span-2">
            {{--
                The month name is the card title rather than free-floating markup
                between two links: `x-ui.card` only renders its header when it has a
                title or a subtitle, so prev/next placed in the actions slot with no
                title would have nowhere to live and would not be emitted at all.
            --}}
            <x-ui.card :title="$month->format('F Y')">
                <x-slot:actions>
                    <a
                        href="{{ route('admin.calendar.index', ['month' => $previousMonth->format('Y-m')]) }}"
                        class="btn-ghost px-2 py-1 text-xs"
                        rel="prev"
                        aria-label="Previous month"
                    ><span aria-hidden="true">&larr;</span> Prev</a>

                    <a
                        href="{{ route('admin.calendar.index', ['month' => $nextMonth->format('Y-m')]) }}"
                        class="btn-ghost px-2 py-1 text-xs"
                        rel="next"
                        aria-label="Next month"
                    >Next <span aria-hidden="true">&rarr;</span></a>
                </x-slot:actions>

                {{-- Monday-first, to match `startOfWeek()` in the controller. --}}
                <div class="grid grid-cols-7 gap-1 text-center text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                    @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                        <div class="pb-1">{{ $weekday }}</div>
                    @endforeach
                </div>

                <div class="grid grid-cols-7 gap-1">
                    @foreach ($days as $day)
                        @php
                            /*
                                Read from the client map when the dialog or the grid
                                has already been given the day's blocks, falling back
                                to the server-rendered set. Both come from the same
                                rows, so the two paths cannot disagree about what is
                                blocked — only about how fresh they are.
                            */
                            $entry = $blocksByDate->get($day['date']);
                            $wholeDayBlocked = $entry?->contains(fn ($b) => $b->isWholeDay()) ?? false;
                            $rangeCount = $entry?->reject(fn ($b) => $b->isWholeDay())->count() ?? 0;
                            $isPast = $day['date'] < $today;
                        @endphp

                        <button
                            type="button"
                            x-on:click="openDate(@js($day['date']), @js($day['label']))"
                            @disabled($isPast)
                            @class([
                                'group relative flex aspect-square flex-col items-center justify-center rounded-card border text-sm transition-colors',
                                'border-line/70 bg-white hover:border-primary/40 hover:bg-linen/50' => ! $isPast,
                                'border-dashed border-line/60 bg-linen/30' => ! $day['inMonth'] && ! $isPast,
                                'border-dashed border-line/40 bg-linen/40 text-ink-muted' => $isPast,
                                'border-primary/40 bg-linen/60' => $day['date'] === $today,
                            ])
                            aria-label="{{ $day['label'] }}{{ $wholeDayBlocked ? ' — blocked' : ($rangeCount ? ' — '.$rangeCount.' blocked period(s)' : '') }}"
                        >
                            {{-- The date number, at full strength in every state.

                                 This was `text-ink-muted/50` on the button, `/60` on
                                 this span, with a blanket `opacity-40` over
                                 out-of-month cells. Stacked, those are three
                                 separate fades of one already-muted grey
                                 (`--color-text-muted` is #806A5D, about 5.4:1 on
                                 white), which lands the digits near 2:1 - the
                                 washed-out look that made this page the hard one
                                 to read.

                                 De-emphasising a cell is a job for its border and
                                 background, which can carry that without costing
                                 the number a single point of contrast. So an
                                 out-of-month day is dashed and paler behind, a
                                 past day is dashed and paler still, and the digit
                                 itself is `text-primary` (#7A241B, ~8.9:1) or full
                                 `text-ink-muted` - never a fraction of either.
                            --}}
                            <span @class([
                                'font-medium',
                                'text-primary' => $day['inMonth'],
                                'text-ink-muted' => ! $day['inMonth'],
                            ])>{{ $day['day'] }}</span>

                            {{-- A block somebody entered: solid dot. --}}
                            <span
                                x-show="isWholeDayBlocked(@js($day['date'])) || rangesFor(@js($day['date'])).length > 0"
                                x-cloak
                                class="mt-0.5 flex items-center gap-0.5"
                            >
                                <span
                                    x-show="isWholeDayBlocked(@js($day['date']))"
                                    class="h-1.5 w-1.5 rounded-full bg-status-cancelled"
                                    title="Blocked all day"
                                ></span>

                                {{-- Partial blocks: count, so "one window" and "three
                                     windows" do not look the same. --}}
                                <span
                                    x-show="! isWholeDayBlocked(@js($day['date'])) && rangesFor(@js($day['date'])).length > 0"
                                    class="rounded-full bg-status-low-stock/20 px-1 text-[9px] font-bold leading-4 text-status-low-stock"
                                    x-text="rangesFor(@js($day['date'])).length"
                                    title="Blocked periods"
                                ></span>
                            </span>

                            {{-- A weekday with no opening hours: struck through,
                                 not dotted, because it is the salon's standing
                                 schedule rather than a closure. --}}
                            @if ($day['closedWeekly'] && ! $wholeDayBlocked)
                                <span class="mt-0.5 text-[9px] font-semibold uppercase text-ink-muted">Closed</span>
                            @endif
                        </button>
                    @endforeach
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-line/70 pt-4 text-[11px] text-ink-muted">
                    <span class="flex items-center gap-1.5">
                        <span class="h-2 w-2 rounded-full bg-status-cancelled"></span> Blocked all day
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="rounded-full bg-status-low-stock/20 px-1.5 font-bold text-status-low-stock">2</span> Blocked periods
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="font-semibold uppercase">Closed</span> No opening hours that weekday
                    </span>
                </div>

                {{-- What a block can actually affect, so an admin closing a date
                     outside the window knows it changes nothing yet.

                     The window itself is configuration rather than a record and has
                     no screen, so there is nothing to link to here. It used to point
                     at the Salon Settings form — a dead link from the moment that
                     screen was removed, which is the kind of thing that survives
                     because nobody clicks every link on every page. --}}

            </x-ui.card>
        </div>

        {{-- ============================ Summary ============================ --}}
        <div>
            <x-ui.card title="Upcoming Blocks" class="h-full">
                <x-slot:actions>
                    <span class="text-xs text-ink-muted">{{ $summary->count() }}</span>
                </x-slot:actions>

                @if ($summary->isEmpty())
                    <p class="py-8 text-center text-sm text-ink-muted">
                        Nothing blocked. Every open day is bookable.
                    </p>
                @else
                    <ul class="divide-y divide-line/60">
                        @foreach ($summary as $block)
                            <li class="flex items-start gap-3 py-2.5 first:pt-0 last:pb-0">
                                <span @class([
                                    'mt-0.5 flex h-8 w-8 shrink-0 flex-col items-center justify-center rounded-lg text-[9px] font-bold uppercase leading-tight',
                                    'bg-status-cancelled/10 text-status-cancelled' => $block->isWholeDay(),
                                    'bg-status-low-stock/15 text-status-low-stock' => ! $block->isWholeDay(),
                                ])>
                                    <span>{{ $block->date->format('M') }}</span>
                                    <span class="text-xs">{{ $block->date->format('j') }}</span>
                                </span>

                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-primary">{{ $block->date->format('D, M j Y') }}</p>
                                    <p class="text-xs text-ink">{{ $block->rangeLabel() }}</p>

                                    @if ($block->reason)
                                        <p class="mt-0.5 truncate text-xs text-ink-muted">{{ $block->reason }}</p>
                                    @endif
                                </div>

                                <x-ui.icon-action
                                    label="Remove block on {{ $block->date->format('M j, Y') }}"
                                    icon="heroicon-o-trash"
                                    tone="danger"
                                    :action="route('admin.calendar.destroy', $block).'?month='.$month->format('Y-m')"
                                    confirm-event="confirm-remove-block"
                                    :confirm="'“'.$block->rangeLabel().'” on '.$block->date->format('M j, Y')"
                                />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        {{-- ================================= Confirmation ================================

             One dialog for every block on the page, rather than the browser's own
             prompt on each row's form. The sentence is composed here from a verb,
             the block's own name and its consequence, so a list of twenty blocks
             reads correctly for every one of them instead of twenty dialogs that
             happen to say the same thing.

             `Cancel` rather than the component's default `No`: the question here is
             phrased as a sentence, so a bare "No" is answering a question nobody
             asked. --}}
        <x-ui.confirm-dialog
            title="Remove this block?"
            event="confirm-remove-block"
            verb="Remove"
            subject="block"
            warning="That time becomes bookable again."
            confirm-label="Yes, remove it"
            cancel-label="Cancel"
        />

        {{-- ============================ Per-day dialog ============================ --}}
        <x-ui.dialog title="Blocked Times" tone="red" icon="heroicon-o-calendar" title-id="blocked-slot-title">
            <p class="text-sm text-ink-muted">
                <span class="font-semibold text-ink" x-text="label"></span>
            </p>

            {{-- What is already blocked on this date, each with its own edit and
                 remove. A date can hold several ranges, so this is a list rather
                 than a pair of fields showing one of them. --}}
            <div class="mt-4">
                <p class="label">Already blocked</p>

                <p x-show="rangesFor(selected).length === 0 && ! isWholeDayBlocked(selected)" class="text-sm text-ink-muted" x-cloak>
                    Nothing blocked on this date.
                </p>

                <p
                    x-show="isWholeDayBlocked(selected)"
                    x-cloak
                    class="mb-2 flex items-center gap-2 rounded-card bg-status-cancelled/10 px-3 py-2 text-sm font-medium text-status-cancelled"
                >
                    <span class="h-2 w-2 rounded-full bg-status-cancelled"></span>
                    Closed all day
                </p>

                <ul class="space-y-1.5">
                    <template x-for="range in rangesFor(selected)" :key="range.id">
                        <li class="flex items-center gap-2 rounded-card border border-line/70 bg-white px-3 py-2">
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-primary" x-text="range.label"></span>
                                <span class="block truncate text-xs text-ink-muted" x-text="range.reason || 'No reason given'"></span>
                            </span>

                            <button
                                type="button"
                                class="btn-ghost px-2 py-1 text-[11px]"
                                x-on:click="editRange(range)"
                            >Edit</button>

                            <form method="POST" action="" x-bind:action="removeUrl(range.id)">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                                <button type="submit" class="btn-ghost px-2 py-1 text-[11px] text-status-cancelled">Remove</button>
                            </form>
                        </li>
                    </template>
                </ul>
            </div>

            {{-- One form, two modes. Whole-day and window are the same row in the
                 table with different columns filled, so they are the same form with
                 a checkbox that swaps which inputs matter — rather than two forms
                 and two submit buttons to choose between. --}}
            <form method="POST" x-bind:action="formAction" class="mt-5 border-t border-line/70 pt-5">
                @csrf
                <input type="hidden" name="date" x-bind:value="selected">
                <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">

                {{-- Carries the id being edited, or absent when adding. --}}
                <template x-if="editingId !== null">
                    <input type="hidden" name="block_id" x-bind:value="editingId">
                </template>

                <x-ui.form.switch
                    name="whole_day"
                    label="Closed all day"
                    hint="Untick to block only part of the day."
                    wrapperClass="mb-4"
                    x-model="wholeDay"
                />

                <div class="grid gap-4 sm:grid-cols-2" x-show="! wholeDay">
                    <x-ui.form.input
                        type="time"
                        name="start_time"
                        label="From"
                        step="900"
                        wrapperClass="w-full"
                        x-model="startTime"
                    />

                    <x-ui.form.input
                        type="time"
                        name="end_time"
                        label="To"
                        step="900"
                        wrapperClass="w-full"
                        x-model="endTime"
                    />
                </div>



                <x-ui.form.input
                    name="reason"
                    label="Reason (optional)"
                    placeholder="Staff meeting, public holiday…"
                    wrapperClass="mt-4"
                    x-model="reason"
                />

                <div class="mt-5 flex flex-wrap items-center justify-end gap-2">
                    <button
                        type="button"
                        class="btn-ghost"
                        x-show="editingId !== null"
                        x-cloak
                        x-on:click="reset()"
                    >Cancel edit</button>

                    <button type="submit" class="btn-primary" x-bind:class="editingId === null ? 'btn-primary' : 'btn-info'">
                        <span x-show="editingId === null">Add block</span>
                        <span x-show="editingId !== null" x-cloak>Save changes</span>
                    </button>
                </div>
            </form>
        </x-ui.dialog>
    </div>
@endsection

@push('scripts')
    @php
        $calendarConfig = [
            'blocks' => $blockedPayload,
            'storeUrl' => route('admin.calendar.store'),
            'updateUrlTemplate' => route('admin.calendar.update', ['block' => '__BLOCK__']),
            'destroyUrlTemplate' => route('admin.calendar.destroy', ['block' => '__BLOCK__']),
            'month' => $month->format('Y-m'),
        ];
    @endphp

    {{-- `JSON_UNESCAPED_SLASHES`: without it every URL in the payload reads
         `http:\/\/localhost\/admin\/calendar\/blocks`, which is valid JSON and
         valid JavaScript but unreadable in the DOM inspector and unmatchable by
         any test asserting on a route. `JSON_HEX_TAG` is what keeps `</script>`
         out of the element, and it is still here. --}}
    <script type="application/json" id="bta-calendar-config">{!! json_encode($calendarConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!}</script>

    <script>
        /**
         * The month grid and its per-day dialog.
         *
         * One state object: which date is open, and the range currently being typed
         * or edited. Everything the grid paints comes out of the server's block map,
         * so the grid and the dialog cannot show different things for the same day.
         *
         * Adding and editing share a form. `editingId` decides whether the submit
         * adds a row or patches the one being edited, and the button says which —
         * because "Save changes" on an action that quietly added a second block is
         * how a day ends up blocked twice.
         */
        document.addEventListener('alpine:init', () => {
            Alpine.data('blockedSlotCalendar', () => ({
                blocks: {},
                storeUrl: '',
                updateUrlTemplate: '',
                destroyUrlTemplate: '',
                month: '',

                selected: '',
                label: '',
                wholeDay: false,
                startTime: '',
                endTime: '',
                reason: '',
                editingId: null,

                /*
                 * `open` and `close()` are the two names `x-ui.dialog` reads off
                 * whatever scope encloses it, which is why the dialog component owns
                 * no state of its own. Both are declared here so the component's
                 * backdrop click and Escape key work.
                 */
                open: false,

                init() {
                    const island = document.getElementById('bta-calendar-config');

                    if (! island) return;

                    const config = JSON.parse(island.textContent || '{}');

                    this.blocks = config.blocks || {};
                    this.storeUrl = config.storeUrl || '';
                    this.updateUrlTemplate = config.updateUrlTemplate || '';
                    this.destroyUrlTemplate = config.destroyUrlTemplate || '';
                    this.month = config.month || '';
                },

                entry(date) {
                    return this.blocks[date] || { wholeDay: false, reason: null, ranges: [] };
                },

                rangesFor(date) {
                    return this.entry(date).ranges || [];
                },

                isWholeDayBlocked(date) {
                    return Boolean(this.entry(date).wholeDay);
                },

                openDate(date, label) {
                    this.selected = date;
                    this.label = label;
                    this.wholeDay = this.isWholeDayBlocked(date);
                    this.reason = this.entry(date).reason || '';

                    // Pre-fill from the first existing range so editing the only
                    // block on a date does not start from two empty time inputs.
                    const first = this.rangesFor(date)[0];

                    this.startTime = first ? first.start : '';
                    this.endTime = first ? first.end : '';
                    this.editingId = null;

                    this.open = true;
                },

                close() {
                    this.open = false;
                    this.editingId = null;
                },

                editRange(range) {
                    this.editingId = range.id;
                    this.wholeDay = false;
                    this.startTime = range.start || '';
                    this.endTime = range.end || '';
                    this.reason = range.reason || '';
                },

                reset() {
                    this.editingId = null;
                    this.startTime = '';
                    this.endTime = '';
                },

                /**
                 * Submit target for the one form in the dialog.
                 *
                 * A plain REST route per row would need the id in the URL, which a
                 * form whose action changes while it is on screen cannot express
                 * without JavaScript rewriting it. Rewriting `action` on the form is
                 * the smallest version of that, and it degrades to "adds a new block"
                 * if the script never runs — the same as the form behaving normally.
                 */
                get formAction() {
                    return this.editingId === null
                        ? this.storeUrl
                        : this.updateUrlTemplate.replace('__BLOCK__', this.editingId);
                },

                removeUrl(id) {
                    return this.destroyUrlTemplate.replace('__BLOCK__', id) + '?month=' + this.month;
                },
            }));
        });
    </script>
@endpush