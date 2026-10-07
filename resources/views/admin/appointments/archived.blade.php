@extends('layouts.admin')

@section('title', 'Archived Appointments')
@section('heading', 'Archived Appointments')

@section('content')
    {{--
        Settled bookings that have left the working list.

        Reached from the pill on the Appointments list, and deliberately a separate
        screen rather than a filter there: an archived booking is out of the queue
        whatever you search for, so offering it as one of that list's status tabs
        would suggest it is still in it.

        Two ways a row gets here, and the "Archived by" column is what tells them
        apart — a person (the Archive action on the list) or "Automatic" (the
        nightly sweep that archives completed bookings past the retention window).
        Both refuse a live booking, so nothing on this screen is in progress.

        Read-mostly, as its own comment says: Restore is the action that changes
        anything here, and Delete is carried over from the working list so an
        archived record can still be removed outright when the admin means it.
    --}}
    {{--
        The search box and the way back sit alone on this row.

        There used to be a sentence here explaining that these are bookings which
        have been archived and are out of the working list but still on record. It
        said nothing the page does not already say: the heading says "Archived
        Appointments", every row is visibly archived, and the Search placeholder
        says what can be searched. What it really did was take a full line of the
        most valuable space on the screen to state what the user had already
        chosen to look at by clicking the Archived link.
    --}}
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <form method="GET" action="{{ route('admin.appointments.archived') }}" class="flex items-center gap-2">
                <label for="archived-search" class="sr-only">Search archived bookings</label>
                <input
                    id="archived-search"
                    type="search"
                    name="search"
                    value="{{ $search }}"
                    placeholder="Search reference or name"
                    class="input w-64 py-1.5 text-sm"
                >
                <button type="submit" class="btn-secondary btn-sm">Search</button>
                @if ($search)
                    <a href="{{ route('admin.appointments.archived') }}" class="btn-ghost btn-sm">Clear</a>
                @endif
            </form>

            <a href="{{ route('admin.appointments.index') }}" class="btn-ghost btn-sm">Back to Appointments</a>
        </div>
    </div>

    @if ($appointments->isEmpty())
        <x-ui.empty
            title="Nothing archived"
            description="Completed bookings move here automatically once they are past the retention window, and you can archive a settled booking by hand at any time."
        />
    @else
        {{--
            `appointmentBulk` is the same selection component the customer's
            appointments list and the notifications list use, reading the same
            `ids[]` checkboxes. It is deliberately not a fourth implementation of
            "tick some rows": the select-all semantics — scoped to what is on this
            page, because the list is paginated — are the fiddly part, and having
            them written once is what stops this screen disagreeing with the other
            two about what "select all" means.

            The checkboxes are outside any form. The confirmation below renders
            its own hidden `ids[]` inputs from `selected`, so the same pattern
            serves the per-row actions and the bulk one.
        --}}
        <div class="bta-card overflow-hidden" x-data="appointmentBulk()">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-primary/10 px-4 py-3">
                <p class="text-sm text-ink-muted">
                    <span x-text="selected.length" x-cloak>0</span>
                    selected
                </p>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="btn-ghost btn-sm"
                        x-show="selected.length > 0"
                        x-cloak
                        x-on:click="clear()"
                    >Clear selection</button>

                    <button
                        type="button"
                        class="btn-secondary btn-sm"
                        x-show="selected.length > 0"
                        x-cloak
                        x-on:click="$dispatch('confirm-archive-restore', { count: selected.length, ids: selected })"
                    >
                        <x-heroicon-o-arrow-uturn-left class="h-4 w-4" />
                        Restore selected
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="bta-table">
                    <thead>
                        <tr>
                            <th class="w-10">
                                {{-- "On this page" rather than "all", because the
                                     list is paginated and only the drawn rows can
                                     be ticked. --}}
                                <label class="sr-only" for="archived-select-all">Select every archived booking on this page</label>
                                <input
                                    id="archived-select-all"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-line text-primary focus:ring-primary"
                                    x-bind:checked="allSelected"
                                    x-on:change="toggleAll($event.target.checked)"
                                >
                            </th>
                            <th>Reference</th>
                            <th>Customer</th>
                            <th>Service(s)</th>
                            <th>Date & Time</th>
                            <th>Status</th>
                            <th>Technician</th>
                            <th>Archived</th>
                            <th>Archived By</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($appointments as $appointment)
                            <tr>
                                <td>
                                    <label class="sr-only" for="archived-{{ $appointment->id }}">Select booking {{ $appointment->reference_number }}</label>
                                    <input
                                        id="archived-{{ $appointment->id }}"
                                        type="checkbox"
                                        name="ids[]"
                                        value="{{ $appointment->id }}"
                                        class="h-4 w-4 rounded border-line text-primary focus:ring-primary"
                                        x-model="selected"
                                    >
                                </td>
                                <td class="whitespace-nowrap font-medium text-ink">
                                    {{ $appointment->reference_number }}
                                </td>
                                <td>
                                    <p class="whitespace-nowrap font-medium text-ink">{{ $appointment->customer_name }}</p>
                                    <p class="whitespace-nowrap text-xs text-ink-muted">{{ $appointment->customer_phone }}</p>
                                </td>
                                <td class="max-w-xs">
                                    <p class="truncate text-ink">{{ $appointment->service_names }}</p>
                                </td>
                                <td class="whitespace-nowrap">
                                    <p class="text-ink">{{ $appointment->preferred_date->format('M j, Y') }}</p>
                                    <p class="text-xs text-ink-muted">{{ $appointment->time_label }}</p>
                                </td>
                                <td class="whitespace-nowrap">
                                    {{-- Status is a badge here, not a dropdown. There is nothing
                                         to change: this screen exists to show what is filed
                                         away, and a live control on an archived row would invite
                                         an admin to edit something they cannot see in the
                                         working list. Restoring it is how you change anything. --}}
                                    <x-ui.badge :status="$appointment->status->badge()" :label="$appointment->status->label()" />
                                </td>
                                <td class="whitespace-nowrap text-ink">{{ $appointment->technicianLabel() }}</td>
                                <td class="whitespace-nowrap text-xs text-ink-muted">
                                    {{ $appointment->archived_at?->format('M j, Y g:i A') ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap text-xs text-ink-muted">
                                    {{-- The null branch is the nightly sweep, which has no actor.
                                         Labelled rather than left blank so a blank cell is not
                                         read as a missing value. --}}
                                    {{ $appointment->archivedBy?->full_name ?? 'Automatic' }}
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1.5">
                                        <x-ui.icon-action
                                            label="View this booking"
                                            icon="heroicon-o-eye"
                                            tone="secondary"
                                            href="#"
                                            x-on:click.prevent="$dispatch('view-archived-appointment', {{ $appointment->id }})"
                                        />

                                        {{-- Restore is reversible and the only way to change a row on
                                             this screen, so it is a direct form post with no
                                             confirmation — the same judgement the working list
                                             makes about Archive. The irreversible actions below
                                             are the ones that ask. --}}
                                        <x-ui.icon-action
                                            label="Restore to the working list"
                                            icon="heroicon-o-arrow-uturn-left"
                                            tone="secondary"
                                            :action="route('admin.appointments.restore', $appointment)"
                                            method="POST"
                                        />

                                        {{-- Deleting goes through the same shared Yes/No dialog the working list
                                             uses, rather than the native `confirm()` this
                                             component falls back to. The icon-action's
                                             `confirm` attribute is the browser's own prompt,
                                             and this panel replaced that everywhere else —
                                             an archived record being removed permanently is
                                             exactly the case where a styled prompt earns
                                             its keep. --}}
                                        <x-ui.icon-action
                                            label="Delete permanently"
                                            icon="heroicon-o-trash"
                                            tone="danger"
                                            href="#"
                                            x-on:click.prevent="$dispatch('confirm-delete-booking', { id: {{ $appointment->id }} })"
                                        />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6">{{ $appointments->links() }}</div>
    @endif
@endsection

@push('modals')
    {{--
        The same read-only dialog the working list opens, driven by the same
        `appointmentViewer` component and the same payloads — one implementation
        rather than a second copy that could drift.

        The delete confirmation is mounted here for the same reason, and it is the
        same component with the same `{id}` template: one dialog, not one per row.
    --}}
    <x-ui.confirm-dialog
        title="Delete booking"
        event="confirm-delete-booking"
        :action="route('admin.appointments.index').'/{id}'"
        subject="booking"
        warning="This removes the booking from the salon&rsquo;s records. It cannot be undone from this screen."
        title-id="confirm-delete-archived-title"
        confirm-label="Delete"
    />

    {{--
        Bulk restore. `verb` and `confirmLabel` are set to the restoring words
        rather than the component's deleting defaults, because the prompt reads
        as "Delete 4 bookings?" otherwise — and it sits on the same screen as a
        genuine Delete dialog, so the wording has to be unambiguous about which
        action is being confirmed.

        No `warning`: a restore is reversible, so there is nothing to warn about.
    --}}
    <x-ui.confirm-dialog
        title="Restore bookings"
        event="confirm-archive-restore"
        :action="route('admin.appointments.restore-many')"
        subject="archived booking"
        verb="Restore"
        method="POST"
        confirm-tone="primary"
        confirm-label="Restore"
        title-id="confirm-archive-restore-title"
    />
    <div
        x-data="appointmentViewer(@js($viewRows))"
        x-on:view-archived-appointment.window="show($event.detail)"
        x-on:keydown.escape.window="if (open) close()"
        x-show="open"
        x-cloak
        class="fixed inset-0 z-[60] flex items-end justify-center overflow-y-auto p-4 sm:items-center"
        role="dialog"
        aria-modal="true"
        aria-labelledby="view-archived-appointment-title"
    >
        <div class="modal-backdrop fixed inset-0" x-on:click="close()" aria-hidden="true"></div>

        <div x-on:click.stop class="modal-panel max-w-xl">
            <div class="flex items-start justify-between gap-4 border-b border-line/70 px-5 py-4">
                <div class="min-w-0">
                    <h2 id="view-archived-appointment-title" class="font-display text-lg font-semibold text-primary">Booking Details</h2>
                    <p class="mt-0.5 truncate text-xs text-ink-muted" x-text="detail?.customer"></p>
                </div>
                <button type="button" x-on:click="close()" class="btn-ghost btn-sm" aria-label="Close">&times;</button>
            </div>

            <div class="max-h-[60vh] space-y-6 overflow-y-auto px-5 py-5">
                <section>
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Services booked</h3>

                    <ul class="mt-2 space-y-2">
                        <template x-for="line in (detail?.services ?? [])" :key="line.name + line.meta">
                            <li class="flex items-start justify-between gap-3 rounded-xl bg-linen/60 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="font-medium text-ink" x-text="line.name"></p>
                                    <p class="text-xs text-ink-muted" x-text="line.meta"></p>
                                </div>
                                <p class="shrink-0 font-semibold text-primary" x-text="line.total"></p>
                            </li>
                        </template>
                    </ul>

                    <p class="mt-3 text-sm text-ink-muted" x-show="(detail?.services ?? []).length === 0" x-cloak>No services recorded.</p>

                    <p class="mt-3 text-right text-sm text-ink-muted">
                        Total:
                        <span class="font-display text-lg font-bold text-primary" x-text="detail?.total"></span>
                    </p>
                </section>

                <section class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Date</h3>
                        <p class="mt-1 font-medium text-ink" x-text="detail?.date"></p>
                    </div>
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Time</h3>
                        <p class="mt-1 font-medium text-ink" x-text="detail?.time"></p>
                    </div>
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Technician</h3>
                        <p class="mt-1 font-medium text-ink" x-text="detail?.technician"></p>
                    </div>
                </section>
            </div>

            <div class="flex flex-wrap justify-end gap-2 border-t border-line/70 bg-linen/50 px-5 py-4">
                <button type="button" class="btn-ghost" x-on:click="close()">Close</button>
            </div>
        </div>
    </div>
@endpush