@extends('layouts.customer')

@section('title', 'My Appointments')

@section('content')
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
        {{-- Title and the New Booking button only. The eyebrow and the
             subtitle used to sit here and both were describing the list rather
             than titling it. --}}
        <x-ui.page-header title="My Appointments">
            <x-slot:actions>
                <a href="{{ route('appointments.create') }}" class="btn-primary btn-sm">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    New Booking
                </a>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Status filter — action links only, no input fields --}}
        <div class="mb-6 flex flex-wrap gap-2">
            @foreach ($statusOptions as $value => $label)
                @php $active = $status === $value; @endphp
                <a href="{{ route('appointments.index', ['status' => $value === 'all' ? null : $value]) }}"
                   class="rounded-pill px-4 py-2 text-sm font-medium transition {{ $active ? 'bg-primary text-cream shadow-card' : 'bg-cream text-ink hover:bg-linen' }}">
                    {{ $label }}
                    @if ($value !== 'all' && ($counts[$value] ?? 0) > 0)
                        <span class="ml-1 opacity-70">{{ $counts[$value] }}</span>
                    @endif
                </a>
            @endforeach
        </div>

        @if ($appointments->isEmpty())
            <x-ui.empty
                title="No appointments here"
                description="You don't have any {{ $status === 'all' ? '' : strtolower($status) }} appointments right now."
            >
                <x-slot:action>
                    <a href="{{ route('appointments.create') }}" class="btn-primary">Book an Appointment</a>
                </x-slot:action>
            </x-ui.empty>
        @else
            {{-- A table rather than a stack of cards, mirroring the reference
                 Order History: one row per booking, so the ref, the date and the
                 amount line up across rows and can be scanned.

                 `overflow-x-auto` rather than letting the columns squeeze. Ten
                 columns cannot fit a phone, and a table that reflows is no longer
                 a table — so below `sm` it scrolls sideways inside this box and
                 the page itself does not. --}}
            <div class="bta-card overflow-hidden" x-data="appointmentBulk()">
                @if ($deletableCount > 0)
                    {{-- Select-all and the bulk button, the same arrangement the
                         notifications list uses. Only rendered when the page holds
                         at least one deletable row, so a list of live bookings
                         never shows a control that could not do anything. --}}
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-primary/10 px-4 py-3">
                        <label class="flex cursor-pointer items-center gap-2.5 text-sm text-ink-muted">
                            <input
                                type="checkbox"
                                class="checkbox"
                                x-bind:checked="allSelected"
                                x-bind:indeterminate="someSelected && ! allSelected"
                                x-on:change="toggleAll($event.target.checked)"
                                aria-label="Select every deletable appointment on this page"
                            >
                            Select all on this page
                        </label>

                        {{-- `x-cloak` holds it hidden until Alpine has taken
                             over, otherwise it flashes for a moment with an
                             empty count on first paint. --}}
                        <div class="flex items-center gap-3" x-cloak x-show="selected.length > 0">
                            <span class="text-xs text-ink-muted">
                                <span x-text="selected.length"></span>
                                selected
                            </span>

                            <button
                                type="button"
                                class="btn-danger btn-sm"
                                x-on:click="$dispatch('confirm-appointment-delete', { count: selected.length, ids: selected })"
                            >
                                <x-heroicon-o-trash class="h-4 w-4" />
                                Delete selected
                            </button>
                        </div>
                    </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="bta-table bta-table-dark min-w-[70rem]">
                        <thead>
                            <tr>
                                @if ($deletableCount > 0)
                                    <th class="w-12"><span class="sr-only">Select</span></th>
                                @endif
                                <th class="w-12">#</th>
                                    <th>Service(s)</th>
                                    <th>Date &amp; Time</th>
                                <th class="text-right">Amount</th>
                                <th>Staff</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($appointments as $appointment)
                                @php
                                    $row = $viewRows[$appointment->id] ?? [];
                                    $canDelete = $appointment->canBeDeletedByCustomer();
                                @endphp

                                <tr>
                                    @if ($deletableCount > 0)
                                        <td>
                                            {{-- Only a settled booking carries a
                                                 box. A pending or confirmed
                                                 appointment is still live, so
                                                 there is nothing to tick — and
                                                 `canBeDeletedByCustomer()` is
                                                 the same gate the routes enforce,
                                                 so the two cannot disagree. --}}
                                            @if ($canDelete)
                                                <label class="flex cursor-pointer items-center">
                                                    <input
                                                        type="checkbox"
                                                        class="checkbox"
                                                        name="ids[]"
                                                        value="{{ $appointment->id }}"
                                                        x-model="selected"
                                                        aria-label="Select appointment {{ $appointment->reference_number }}"
                                                    >
                                                </label>
                                            @endif
                                        </td>
                                    @endif

                                    <td class="text-ink-muted">{{ $appointments->firstItem() + $loop->index }}</td>

                                    <td class="font-medium text-primary">{{ $appointment->service_names }}</td>

                                    {{--
                                        The Ref # column is gone. The reference is
                                        still generated and still shown inside the
                                        Appointment Details dialog, which is where
                                        a customer looks when they want to quote it
                                        to the salon; in the table it was a second
                                        copy of a number nobody reads at a glance,
                                        in a monospace font that gave the row the
                                        look of a receipt.

                                        The service-name cell does what the column
                                        used to do for opening the dialog.
                                    --}}
                                    <td class="whitespace-nowrap text-ink">{{ $appointment->date_time_label }}</td>

                                    <td class="whitespace-nowrap text-right font-semibold text-primary">
                                        ₱{{ number_format((float) $appointment->total_amount, 2) }}
                                    </td>

                                    <td class="text-ink">{{ $appointment->technicianLabel() }}</td>

                                    <td>
                                        <x-ui.badge :status="$appointment->status->badge()" :label="$appointment->status->label()" />
                                    </td>

                                    <td>
                                        {{-- View, Cancel, Reschedule, Delete and Book Again
                                             are the same square icon in the same
                                             neutral/danger/informational set.
                                             Cancel and Reschedule are gated on
                                             `isCustomerActionable()` (Pending and
                                             Confirmed); Delete is gated on the
                                             booking being settled; Book Again only
                                             on Completed. A live booking therefore
                                             shows view, cancel and reschedule; a
                                             finished one shows view, book again and
                                             delete — never both sets at once,
                                             which is what keeps the cell from
                                             growing a fifth square. --}}
                                        <div class="flex items-center justify-center gap-1.5">
                                            <button
                                                type="button"
                                                class="icon-action icon-action-secondary"
                                                title="View {{ $appointment->reference_number }}"
                                                aria-label="View {{ $appointment->reference_number }}"
                                                x-on:click="$dispatch('view-appointment', @js($appointment->id))"
                                            >
                                                <i class="fas fa-eye" aria-hidden="true"></i>
                                            </button>

                                            {{-- Repeat a finished visit. A plain link
                                                 rather than a dialog: the destination
                                                 is the ordinary booking form with
                                                 the basket pre-filled, and the
                                                 customer still has to pick a date,
                                                 a time and confirm. Nothing is
                                                 submitted on the way there. --}}
                                            @if ($appointment->status === \App\Enums\AppointmentStatus::Completed)
                                                <a
                                                    class="icon-action icon-action-primary"
                                                    href="{{ route('appointments.book-again', $appointment) }}"
                                                    title="Book {{ $appointment->reference_number }} again"
                                                    aria-label="Book {{ $appointment->reference_number }} again"
                                                >
                                                    <i class="fas fa-repeat" aria-hidden="true"></i>
                                                </a>
                                            @endif

                                            @if ($appointment->canBeCancelled())
                                                <button
                                                    type="button"
                                                    class="icon-action icon-action-danger"
                                                    title="Cancel {{ $appointment->reference_number }}"
                                                    aria-label="Cancel {{ $appointment->reference_number }}"
                                                    x-on:click="$dispatch('cancel-appointment', @js([
                                                        'reference' => $appointment->reference_number,
                                                        'date' => $appointment->preferred_date->format('M j, Y'),
                                                        'time' => $appointment->getTimeLabelAttribute(),
                                                        'total' => number_format((float) $appointment->total_amount, 2),
                                                        'services' => array_column($row['services'] ?? [], 'name'),
                                                        'action' => route('appointments.cancel.update', $appointment),
                                                    ]))"
                                                >
                                                    <i class="fas fa-times-circle" aria-hidden="true"></i>
                                                </button>
                                            @endif

                                            @if ($appointment->canBeRescheduled())
                                                <button
                                                    type="button"
                                                    class="icon-action icon-action-info"
                                                    title="Reschedule {{ $appointment->reference_number }}"
                                                    aria-label="Reschedule {{ $appointment->reference_number }}"
                                                    x-on:click="$dispatch('reschedule-appointment', @js([
                                                        'reference' => $appointment->reference_number,
                                                        'currentDate' => $appointment->preferred_date->toDateString(),
                                                        'currentDateLabel' => $appointment->preferred_date->format('M j, Y'),
                                                        'currentTime' => $appointment->getTimeLabelAttribute(),
                                                        'total' => number_format((float) $appointment->total_amount, 2),
                                                        'services' => array_column($row['services'] ?? [], 'name'),
                                                        'action' => route('appointments.reschedule.update', $appointment),
                                                        'date' => old('preferred_date'),
                                                    ]))"
                                                >
                                                    <i class="fas fa-calendar" aria-hidden="true"></i>
                                                </button>
                                            @endif

                                            @if ($canDelete)
                                                <button
                                                    type="button"
                                                    class="icon-action icon-action-danger"
                                                    title="Delete {{ $appointment->reference_number }}"
                                                    aria-label="Delete {{ $appointment->reference_number }}"
                                                    x-on:click="$dispatch('confirm-appointment-delete', @js([
                                                        'count' => 1,
                                                        'action' => route('appointments.destroy', $appointment),
                                                    ]))"
                                                >
                                                    <i class="fas fa-trash" aria-hidden="true"></i>
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-8">{{ $appointments->links() }}</div>
        @endif
    </div>
@endsection

@push('modals')
    {{--
        The three row actions open dialogs rather than navigating. All three are
        rendered once, below the table, and the row buttons only dispatch to them
        — a dialog per row would repeat the whole form, its CSRF token and its
        `_method` spoof once per booking.

        Rendered into `@stack('modals')`, which the customer layout places below
        `</main>`, so they are outside the table's `overflow-x-auto` and cannot be
        clipped by it.
    --}}

    {{-- One confirmation for both delete entry points, the same arrangement the
         notifications list uses: the bulk button passes `ids`, the per-row
         trash passes `action`, and this single form posts whichever it was given.
         Rendered at `@stack('modals')` so it sits outside the table's scrolling
         box and outside the `appointmentBulk` scope — which is why it listens on
         the window and takes the count from the event. --}}
    <x-ui.confirm-dialog
        title="Delete appointments"
        event="confirm-appointment-delete"
        :action="route('appointments.destroy-many')"
        subject="appointment"
        warning="This cannot be undone."
    />

    {{-- View: the Appointment Details dialog, fed a map of payloads keyed by id
         so opening it costs no request — the same arrangement the admin
         Appointments dialog uses.

         Layout is the reference "Order Details" modal: a two-column body with
         Appointment Information on the left and Payment Summary on the right,
         then the booked services full width beneath, then the status history as
         a compact footnote. It is a modal and not a page, so there is no
         breadcrumb and no address bar change behind it.

         Every label is a static string and every value is `x-text`, so the
         dialog reads its content out of the payload rather than out of the
         markup — one dialog serves every row, and a row's own data is what
         shows. --}}
    <div
        x-data="appointmentViewer(@js($viewRows), @js($openView), @js(route('appointments.book-again', ['appointment' => '__ID__'])))"
        x-on:view-appointment.window="show($event.detail)"
    >
        <x-ui.dialog title="Appointment Details" icon="heroicon-o-clipboard-document-list" max-width="max-w-3xl" title-id="view-appointment-title">
            <div class="grid gap-6 sm:grid-cols-2">
                {{-- LEFT — who booked it, and when. --}}
                <section>
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Appointment Information</h3>

                    <dl class="mt-3 space-y-2.5 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-muted">Reference #</dt>
                            <dd class="text-right font-mono font-semibold text-primary" x-text="detail?.reference"></dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-muted">Date</dt>
                            <dd class="text-right text-ink" x-text="detail?.date"></dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-muted">Appointment time</dt>
                            <dd class="text-right text-ink" x-text="detail?.time"></dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-muted">Staff assigned</dt>
                            <dd class="text-right text-ink" x-text="detail?.technician"></dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-muted">Status</dt>
                            {{-- The payload carries the finished CSS class, not
                                 the raw tone, so the dialog paints a Pending
                                 booking with the pending colour instead of
                                 re-deriving the map in markup. --}}
                            <dd class="text-right">
                                <span class="badge" x-bind:class="detail?.statusBadge" x-text="detail?.statusLabel"></span>
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-muted">Customer</dt>
                            <dd class="text-right text-ink" x-text="detail?.customer"></dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-muted">Contact</dt>
                            <dd class="text-right text-ink" x-text="detail?.phone"></dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-muted">Email</dt>
                            <dd class="text-right text-ink" x-text="detail?.email || '—'"></dd>
                        </div>
                    </dl>

                    {{-- The salon writes these at booking time and they are not
                         otherwise visible anywhere in the list. --}}
                    <template x-if="detail?.specialRequest">
                        <div class="mt-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Special request</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-ink" x-text="detail?.specialRequest"></p>
                        </div>
                    </template>

                    <template x-if="detail?.allergies">
                        <div class="mt-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Allergies</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-ink" x-text="detail?.allergies"></p>
                        </div>
                    </template>

                    <template x-if="detail?.cancellationReason">
                        <div class="mt-4 rounded-lg bg-status-cancelled-bg/50 px-3.5 py-2.5 text-xs text-ink">
                            <span class="font-semibold text-status-cancelled">Reason:</span>
                            <span x-text="detail?.cancellationReason"></span>
                        </div>
                    </template>
                </section>

                {{-- RIGHT — what it costs. --}}
                {{--
                    Only the total now.

                    This used to carry the Down Payment figure, a "Payment
                    status" badge, the GCash reference, and a paragraph explaining
                    that GCash is verified by hand and a deposit sits at "Awaiting
                    Verification" until somebody confirms it. Three of those four
                    were telling the customer about a deposit this salon does not
                    take for a web booking — the booking form has had no deposit
                    step for a while, and `down_payment_required` defaults to
                    false, so a booking made here carries no reference and no
                    amount at all.

                    What they rendered was therefore a set of empty rows and a
                    badge reading "Not Required", followed by reassurance about a
                    payment that had never been requested. On a booking that
                    predates the change they are real values, and an admin who
                    needs them can still open the appointment in the panel, where
                    the verification controls are.

                    The total stays: that is what the customer booked and what they
                    are being asked to pay.
                --}}
                <section>
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Payment Summary</h3>

                    <dl class="mt-3 space-y-2.5 text-sm">
                        <div class="flex items-baseline justify-between gap-4 border-b border-line/70 pb-2.5">
                            <dt class="text-ink-muted">Total Amount</dt>
                            <dd class="text-right font-display text-lg font-bold text-primary">
                                <span x-text="'₱' + (detail?.total ?? '0.00')"></span>
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>

            {{-- FULL WIDTH — the lines actually booked. --}}
            <section class="mt-6 border-t border-line/70 pt-5">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Service(s)</h3>

                <ul class="mt-3 space-y-2">
                    <template x-for="service in (detail?.services ?? [])" x-bind:key="service.name">
                        <li class="flex items-start justify-between gap-3 rounded-xl bg-linen/60 px-4 py-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-ink" x-text="service.name"></p>
                                {{-- The quantity, and only the quantity.

                                     This line read `service.quantity + ' × ' +
                                     service.duration + ' min'`, which printed
                                     "1 × 0 min" on almost every booking: the
                                     durations these lines carry are 0 because the
                                     column was never filled in, and "0 min" is a
                                     claim that a treatment takes no time rather
                                     than an admission that the figure is missing.
                                     The same reasoning is why Service's
                                     `duration_label` returns empty instead of
                                     "0 min" — see app/Models/Service.php.

                                     So the duration is gone rather than
                                     conditional. A booking made after the
                                     salon starts recording durations would want
                                     it back, and it should come back as real
                                     data in this payload, not as a zero that has
                                     to be hidden at the last moment. --}}
                                <p class="text-xs text-ink-muted">
                                    <span x-text="'× ' + service.quantity"></span>
                                </p>
                            </div>
                            <p class="shrink-0 text-sm font-semibold text-primary">
                                <span x-text="'₱' + service.total"></span>
                            </p>
                        </li>
                    </template>
                </ul>

                <p class="mt-3 text-sm text-ink-muted" x-show="(detail?.services ?? []).length === 0" x-cloak>
                    No services recorded.
                </p>
            </section>



            <x-slot:footer>
                <button type="button" class="btn-ghost" x-on:click="close()">Close</button>

                {{-- Book Again, for a finished visit only.
                     The same action as the icon in the Actions column, offered here
                     too because the details dialog is where a customer lands after
                     clicking View on a past booking and the next thing they want is
                     another one.

                     The href is built from `detail`, not from the row: the dialog
                     can be opened for a booking that is not on the current page of
                     results (the `?view=ID` fallback), so a row-derived URL would
                     point at the wrong visit. `bookAgainUrl` is null for anything
                     that is not Completed, and the button is hidden off that rather
                     than rendered as a link to a 403. --}}
                <a
                    x-show="bookAgainUrl"
                    x-cloak
                    class="btn-primary"
                    x-bind:href="bookAgainUrl"
                >
                    <i class="fas fa-repeat" aria-hidden="true"></i>
                    Book Again
                </a>
            </x-slot:footer>
        </x-ui.dialog>
    </div>

    {{-- Cancel: one real form, posting exactly what
         `CancelAppointmentController@update` validates. --}}
    <div x-data="appointmentCancelPanel()" x-on:cancel-appointment.window="ask($event.detail)">
        <x-ui.dialog title="Cancel Appointment" tone="red" icon="heroicon-o-x-circle" title-id="cancel-appointment-title">
            {{-- A refused cancellation comes back as validation errors, and the
                 dialog has to stay open to show them: the page behind it is the
                 history table, which is no help. Re-opened from the session, the
                 same way the admin's Block Date dialog re-opens on an error. --}}

            <p class="text-sm leading-relaxed text-ink">
                You are about to cancel Appointment
                <span class="font-semibold text-primary" x-text="'#' + (appointment?.reference ?? '')"></span>.
                This action cannot be undone.
            </p>

            <div class="mt-4 rounded-xl bg-linen/60 px-4 py-3">
                <dl class="space-y-1.5 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-muted">Ref #</dt>
                        <dd class="font-mono font-semibold text-primary" x-text="appointment?.reference"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-muted">Date</dt>
                        <dd class="text-ink" x-text="appointment?.date"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-muted">Time</dt>
                        <dd class="text-ink" x-text="appointment?.time"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-muted">Total</dt>
                        <dd class="font-semibold text-primary">
                            <span x-text="'₱' + (appointment?.total ?? '0.00')"></span>
                        </dd>
                    </div>
                </dl>

                <p class="mt-2.5 border-t border-line/70 pt-2.5 text-xs text-ink-muted">
                    <template x-for="service in (appointment?.services ?? [])" x-bind:key="service.name">
                        <span class="mr-2 inline-block" x-text="service.name"></span>
                    </template>
                </p>
            </div>

            <form method="POST" x-bind:action="appointment?.action ?? '#'" class="mt-5 space-y-4">
                @csrf
                @method('PATCH')

                <div>
                    <label for="cancel-reason" class="block text-sm font-medium text-ink">Reason for cancellation</label>
                    <select id="cancel-reason" name="reason_preset" class="input mt-1.5">
                        <option value="">-- Select a reason --</option>
                        @foreach ($cancelReasons as $reason)
                            <option value="{{ $reason }}" @selected(old('reason_preset') === $reason)>{{ $reason }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="cancel-note" class="block text-sm font-medium text-ink">Additional note</label>
                    <textarea id="cancel-note" name="reason" rows="2" maxlength="500" class="input mt-1.5" placeholder="Anything you would like us to know.">{{ old('reason') }}</textarea>
                </div>

                {{-- `accepted` server-side, so this is not decorative: submitting
                     without it is refused. --}}
                <label class="flex items-start gap-2.5 text-sm text-ink">
                    <input type="checkbox" name="agree_cancellation_policy" value="1" class="checkbox mt-0.5" @checked(old('agree_cancellation_policy'))>
                    <span>
                        I agree to the
                        <x-terms.link :category="'cancellation'">cancellation policy</x-terms.link>
                    </span>
                </label>

                <div class="flex flex-wrap justify-end gap-2 border-t border-line/70 pt-4">
                    <button type="button" class="btn-ghost" x-on:click="close()">Close</button>
                    <button type="submit" class="btn-danger" x-ref="confirm">Cancel Appointment</button>
                </div>
            </form>
        </x-ui.dialog>
    </div>

    {{-- Reschedule: posts `preferred_date` / `preferred_time` / `reason` to
         `RescheduleAppointmentController@update`, which re-validates them. The
         time list comes from the server rather than being hardcoded here. --}}
    <div
        x-data="appointmentReschedulePanel(@js($rescheduleContext))"
        x-on:reschedule-appointment.window="ask($event.detail)"
    >
        <x-ui.dialog title="Reschedule Appointment" tone="info" icon="heroicon-o-calendar-days" title-id="reschedule-appointment-title">

            <div class="rounded-xl bg-linen/60 px-4 py-3">
                <dl class="space-y-1.5 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-muted">Ref #</dt>
                        <dd class="font-mono font-semibold text-primary" x-text="appointment?.reference"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-muted">Currently booked</dt>
                        <dd class="text-ink">
                            <span x-text="appointment?.currentDateLabel"></span>
                            at
                            <span x-text="appointment?.currentTime"></span>
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-muted">Total</dt>
                        <dd class="font-semibold text-primary">
                            <span x-text="'₱' + (appointment?.total ?? '0.00')"></span>
                        </dd>
                    </div>
                </dl>

                <p class="mt-2.5 border-t border-line/70 pt-2.5 text-xs text-ink-muted">
                    <template x-for="service in (appointment?.services ?? [])" x-bind:key="service.name">
                        <span class="mr-2 inline-block" x-text="service.name"></span>
                    </template>
                </p>
            </div>

            <form method="POST" x-bind:action="appointment?.action ?? '#'" class="mt-5 space-y-4">
                @csrf
                @method('PATCH')

                <div>
                    <label for="reschedule-date" class="block text-sm font-medium text-ink">New appointment date</label>
                    <input
                        type="date"
                        id="reschedule-date"
                        name="preferred_date"
                        class="input mt-1.5"
                        x-model="date"
                        x-on:change="loadSlots()"
                        x-bind:min="minDate"
                        x-bind:max="maxDate"
                        value="{{ old('preferred_date') }}"
                        required
                    >
                    {{-- The salon's real hours for the chosen day, read from the
                         same `operating_hours` map the booking rules use. A fixed
                         window would be wrong on every day the salon opens
                         differently. --}}
                    <p class="mt-1.5 text-xs text-ink-muted" x-text="hoursFor(date)"></p>
                </div>

                <div>
                    <label for="reschedule-time" class="block text-sm font-medium text-ink">New appointment time</label>
                    <select id="reschedule-time" name="preferred_time" class="input mt-1.5" required>
                        <option value="">-- Select a time --</option>
                        <template x-for="slot in slots" x-bind:key="slot">
                            <option
                                x-bind:value="slot"
                                x-bind:selected="slot === @js(old('preferred_time'))"
                                x-text="formatTime(slot)"
                            ></option>
                        </template>
                    </select>
                    <p class="mt-1.5 text-xs text-ink-muted" x-show="loadingSlots">Loading available times…</p>
                    <p class="mt-1.5 text-xs text-status-cancelled" x-show="slotsError" x-cloak x-text="slotsError"></p>
                    <p class="mt-1.5 text-xs text-ink-muted" x-show="! loadingSlots && ! slotsError && slots.length === 0" x-cloak>
                        No times are free on this date.
                    </p>
                </div>

                <div>
                    <label for="reschedule-reason" class="block text-sm font-medium text-ink">Reason (optional)</label>
                    <textarea id="reschedule-reason" name="reason" rows="2" maxlength="500" class="input mt-1.5">{{ old('reason') }}</textarea>
                </div>

                {{--
                    The salon being closed is still said, because that is a fact
                    about the date the customer picked and there is nothing else on
                    screen that would tell them. The box only exists when it has
                    something to say, rather than sitting there empty on every open
                    day.

                    The other half — "Booking at least one day in advance.
                    Confirming moves your appointment and notifies us of the new
                    time. See the rescheduling terms" — is gone. It described the
                    form's own mechanics back to the person using it, and it
                    promised the rule the server enforces anyway: if the new slot
                    is too close, `RescheduleRequest` refuses it and the closed-day
                    or short-notice message comes back as an error on the date
                    field. A sentence that restates what will happen is not the
                    same as the rule happening, and the rule is what counts.
                --}}
                <template x-if="date && hoursFor(date).startsWith('The salon is closed')">
                    <p class="rounded-lg bg-linen/60 px-3.5 py-2.5 text-xs font-semibold text-status-cancelled">
                        The salon is closed that day — please choose another date.
                    </p>
                </template>

                <div class="flex flex-wrap justify-end gap-2 border-t border-line/70 pt-4">
                    <button type="button" class="btn-ghost" x-on:click="close()">Close</button>
                    <button type="submit" class="btn-info" x-ref="confirm">Confirm Reschedule</button>
                </div>
            </form>
        </x-ui.dialog>
    </div>
@endpush
