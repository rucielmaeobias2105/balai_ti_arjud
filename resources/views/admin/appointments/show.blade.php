@extends('layouts.admin')

@section('title', 'Manage '.$appointment->reference_number)
@section('heading', 'Appointment')

@section('content')
    <a href="{{ route('admin.appointments.index') }}" class="mb-5 inline-flex items-center gap-1.5 text-sm text-ink-muted transition hover:text-primary">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
        Back to appointments
    </a>

    <x-ui.page-header
        :eyebrow="$appointment->reference_number"
        :title="$appointment->customer_name"
        :description="$appointment->service_names.' — '.$appointment->date_time_label"
    >
        <x-slot:actions>
            <a href="{{ route('admin.appointments.edit', $appointment) }}" class="btn-secondary btn-sm">Reschedule / Edit</a>
            <x-ui.badge :status="$appointment->status->badge()" :label="$appointment->status->label()" />
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        {{-- Left column --}}
        <div class="space-y-6 xl:col-span-2">
            {{-- Status update. These post to the same route the list's dropdown
                 does, so they honour the same transition table: a button the
                 server would refuse is disabled rather than left to fail. --}}
            <x-ui.card title="Update Status" subtitle="Changes are logged to the status history and the customer is notified.">
                <form method="POST" action="{{ route('admin.appointments.status', $appointment) }}" class="space-y-4">
                    @csrf
                    @method('PATCH')

                    <div class="flex flex-wrap gap-2">
                        @foreach (['confirmed' => 'Approve / Confirm', 'in_progress' => 'Start (In Progress)', 'completed' => 'Mark Completed', 'declined' => 'Decline'] as $value => $label)
                            @php
                                $target = App\Enums\AppointmentStatus::resolveAction($value);
                                $active = $appointment->status === $target;
                                $reachable = $appointment->status->canTransitionTo($target);
                            @endphp
                            <button
                                type="submit"
                                name="status"
                                value="{{ $value }}"
                                @disabled($active || ! $reachable)
                                @class([
                                    'btn-sm',
                                    'btn-primary' => ! $active && $value === 'confirmed',
                                    'btn-gold' => ! $active && $value === 'in_progress',
                                    'btn-ghost' => ! $active && $value === 'completed',
                                    'btn-danger' => ! $active && $value === 'declined',
                                ])
                            >{{ $label }}</button>
                        @endforeach
                    </div>

                    <div>
                        <label for="admin_notes" class="label">
                            Admin Notes
                            <span class="ml-1.5 rounded-pill bg-primary/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-primary">Internal only</span>
                        </label>
                        <textarea id="admin_notes" name="admin_notes" rows="3" class="input" placeholder="Only visible to staff — never shown to the customer.">{{ old('admin_notes', $appointment->admin_notes) }}</textarea>
                        <p class="input-hint">Saving notes here also applies them to the status update above.</p>
                    </div>
                </form>
            </x-ui.card>

            {{-- Manual down payment verification --}}
            <x-ui.card title="Down Payment (GCash)" subtitle="Verified manually — no payment gateway is integrated.">
                <dl class="mb-4 grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Reference</dt>
                        <dd class="mt-1 font-mono text-sm font-semibold text-primary">{{ $appointment->down_payment_reference ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Amount</dt>
                        <dd class="mt-1 text-sm font-semibold text-primary">
                            {{ $appointment->down_payment_amount ? '₱'.number_format((float) $appointment->down_payment_amount, 2) : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Status</dt>
                        <dd class="mt-1"><x-ui.badge :status="$appointment->downPaymentBadgeTone()" :label="$appointment->downPaymentLabel()" /></dd>
                    </div>
                </dl>

                <form method="POST" action="{{ route('admin.appointments.down-payment', $appointment) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    @method('PATCH')
                    <div class="min-w-56 flex-1">
                        <x-ui.form.select
                            name="down_payment_status"
                            label="Set verification status"
                            {{-- Blank rather than the stored value when the stored value is
                                 not one this select offers: the stored value cannot be
                                 selected anyway, so no option would render as chosen and
                                 saving here writes a real case — which is how an
                                 unrecognised status gets fixed. --}}
                            :value="$appointment->down_payment_status?->value ?? ''"
                            :options="$downPaymentOptions"
                        />
                    </div>
                    <button type="submit" class="btn-primary">Save</button>
                </form>
            </x-ui.card>

            {{-- Booking details --}}
            <x-ui.card title="Booking Details">
                <dl class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Customer</dt>
                        <dd class="mt-1 font-medium text-primary">{{ $appointment->customer_name }}</dd>
                        <dd class="text-sm text-ink-muted">{{ $appointment->customer_phone }}</dd>
                        @if ($appointment->customer_email)
                            <dd class="text-sm text-ink-muted">{{ $appointment->customer_email }}</dd>
                        @endif
                        @if ($appointment->user)
                            <a href="{{ route('admin.users.show', $appointment->user) }}" class="mt-1 inline-block text-xs font-medium text-primary underline underline-offset-2">View customer profile</a>
                        @else
                            <p class="mt-1 text-xs text-ink-muted">Guest booking (no account)</p>
                        @endif
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Schedule</dt>
                        <dd class="mt-1 font-medium text-primary">{{ $appointment->preferred_date->format('l, M j, Y') }}</dd>
                        {{-- The total duration is shown only when there is one. Every booking line
                                 written before the salon recorded durations sums to zero, and "0 min
                                 total" reads as a measurement rather than as the absence of one. The
                                 same reasoning is behind Service's `duration_label` returning empty
                                 instead of "0 min", and behind the duration being dropped outright
                                 from the customer details dialog and the admin View dialog.

                                 Note the spaces around the directives: `@endif` directly after a word
                                 is not a directive to Blade at all — its pattern needs a boundary
                                 before the `@`, so `total@endif` is emitted as literal text and the
                                 `@if` above it is left unterminated, which is a parse error rather
                                 than a wrong-looking page. --}}
                        <dd class="text-sm text-ink-muted">
                            {{ $appointment->time_label }}
                            @if ($appointment->total_duration > 0)
                                &middot; {{ $appointment->total_duration }} min total
                            @endif
                        </dd>
                        <dd class="text-sm text-ink-muted">Technician: {{ $appointment->technicianLabel() }}</dd>
                    </div>

                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Service(s)</dt>
                        <ul class="mt-2 space-y-2">
                            @foreach ($appointment->serviceLines as $line)
                                <li class="flex items-start justify-between gap-3 rounded-xl bg-linen/60 px-4 py-3">
                                    <div class="min-w-0">
                                        <p class="font-medium text-ink">{{ $line->display_name }}</p>
                                        {{-- Duration is shown only when there is one. Every booking line
                                             written before the salon recorded durations sums to
                                             zero, and "0 min total" / "0 min · Qty 1" read as a
                                             measurement rather than as the absence of one. The
                                             same reasoning is behind Service's `duration_label`
                                             returning empty instead of "0 min", and behind the
                                             duration being dropped outright from the customer
                                             details dialog and the admin View dialog. --}}
                                        <p class="text-xs text-ink-muted">
                                            @if ($line->duration_minutes > 0)
                                                {{ $line->duration_minutes }} min &middot;
                                            @endif
                                            Qty {{ $line->quantity }}
                                        </p>
                                        {{-- The price as it was advertised when booked,
                                             which may be a range like "249/499". The
                                             figure on the right is what was charged. --}}
                                        <p class="text-xs text-ink-muted">
                                            {{ \App\Support\PriceFormatter::display($line->display_price) }} each
                                        </p>
                                    </div>
                                    <p class="shrink-0 font-semibold text-primary">₱{{ number_format($line->line_total, 2) }}</p>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-3 text-right text-sm text-ink-muted">
                            Total:
                            <span class="font-display text-lg font-bold text-primary">₱{{ number_format((float) $appointment->total_amount, 2) }}</span>
                        </p>
                    </div>

                    @foreach ([
                        'Allergies' => $appointment->allergies,
                        'Last Service(s) Availed' => $appointment->last_services_availed,
                        'Special Request' => $appointment->special_request,
                        'Cancellation Reason' => $appointment->cancellation_reason,
                        'Reschedule Reason' => $appointment->reschedule_reason,
                    ] as $label => $value)
                        @if ($value)
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wider text-ink-muted">{{ $label }}</dt>
                                <dd class="mt-1 text-sm text-ink">{{ $value }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
            </x-ui.card>
        </div>

        {{-- Right column --}}
        <aside class="space-y-6">
            <x-ui.card title="Status History">
                @if ($appointment->statusHistory->isEmpty())
                    <p class="text-sm text-ink-muted">No history recorded.</p>
                @else
                    <ol class="relative space-y-5 border-l border-primary/15 pl-5">
                        @foreach ($appointment->statusHistory as $entry)
                            <li class="relative">
                                <span class="absolute -left-[1.6rem] top-1 h-2.5 w-2.5 rounded-full bg-primary ring-4 ring-cream"></span>
                                <p class="text-sm font-medium text-primary">{{ $entry->arrow }}</p>
                                <p class="mt-0.5 text-xs text-ink-muted">{{ $entry->created_at->format('M j, Y g:i A') }}</p>
                                <p class="text-xs text-ink-muted">by {{ $entry->actorLabel() }}</p>
                                @if ($entry->note)
                                    <p class="mt-1.5 text-xs italic text-ink-muted">{{ $entry->note }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        </aside>
    </div>
@endsection
