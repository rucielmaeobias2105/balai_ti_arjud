@extends('layouts.customer')

@section('title', 'Reschedule Appointment')

@section('content')
    @php
        // Tomorrow, not today: same-day bookings are not accepted. The server
        // re-checks this in RescheduleRequest.
        $minDate = $availability->firstBookableDate()->toDateString();
        $maxDate = $availability->lastBookableDate()->toDateString();
    @endphp

    <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">
        <a href="{{ route('appointments.index', ['view' => $appointment->id]) }}" class="mb-6 inline-flex items-center gap-1.5 text-sm text-ink-muted transition hover:text-primary">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
            Back to appointment
        </a>

        <x-ui.page-header
            eyebrow="Reschedule"
            title="Reschedule Appointment"
            description="Pick a new date and time. Your new slot is re-validated against our calendar."
        />


        {{-- Current date/time — read-only --}}
        <div class="bta-card mb-6 border-l-4 border-l-gold p-5">
            <p class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Current Date & Time (read-only)</p>
            <p class="mt-1.5 font-display text-xl font-bold text-primary">{{ $appointment->date_time_label }}</p>
            <p class="mt-0.5 text-sm text-ink-muted">{{ $appointment->service_names }} &middot; Ref {{ $appointment->reference_number }}</p>
        </div>

        @php
            $rescheduleConfig = [
                'date' => old('preferred_date', $prefilledDate),
                'slots' => $slots,
                'url' => route('appointments.slots'),
                'serviceId' => $serviceId,
                'minDate' => $minDate,
                'maxDate' => $maxDate,
                // Was this appointment's service-scoped blocked-date map. Empty
                // now that the Calendar & Blocked Dates feature is gone; kept so
                // the client's shape does not change.
                'blockedDates' => (object) [],
            ];
        @endphp

        <form
            method="POST"
            action="{{ route('appointments.reschedule.update', $appointment) }}"
            x-data="rescheduleSlots"
            novalidate
        >
            @csrf
            @method('PATCH')

            <script type="application/json" id="bta-reschedule-config">{!! json_encode($rescheduleConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

            <x-ui.card title="New Date & Time">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.form.input
                        name="preferred_date"
                        type="date"
                        label="New Preferred Date"
                        required
                        :value="old('preferred_date', $prefilledDate)"
                        :min="$minDate"
                        :max="$maxDate"
                        x-model="date"
                        x-bind:min="minDate"
                    />

                    <div>
                        <label for="preferred_time" class="label">
                            New Preferred Time <span class="text-status-cancelled">*</span>
                        </label>

                        <select id="preferred_time" name="preferred_time" class="input" required x-model="time">
                            <option value="">Select a time</option>
                            <template x-for="slot in slots" :key="slot">
                                <option :value="slot" x-text="formatTime(slot)"></option>
                            </template>
                        </select>

                        <p class="input-hint" x-show="loading" x-cloak>Checking availability…</p>
                        <p class="input-hint" x-show="! loading && slots.length === 0" x-cloak>
                            No open slots on this date. Please choose another day.
                        </p>
                    </div>
                </div>

                <div class="mt-5">
                    <x-ui.form.textarea
                        name="reason"
                        label="Reason (optional)"
                        rows="3"
                        :value="old('reason')"
                        placeholder="Let us know why you need to move your appointment."
                    />
                </div>
            </x-ui.card>

            {{-- No closure list here any more.

                 This used to print the salon's blocked dates and their reasons as
                 prose, under a heading reading "Dates we are closed". It went with
                 the Calendar & Blocked Dates feature, and with it went the ability
                 to block a date at all — a closure is now expressed through the
                 salon's operating hours, which the slot list already reflects: a
                 closed weekday simply comes back with no slots.
             --}}

            {{--
                A way in to the policy rather than the policy itself, for the
                same reason the cancel page stopped inlining it: the dialog is
                mounted by the layout, and a wall of rescheduling rules sitting
                above the confirm button is a worse read than a link next to the
                checkbox the customer is about to tick.
            --}}
            <p class="mt-6 text-sm text-ink-muted">
                Moving an appointment is free up to 24 hours beforehand, subject
                to availability.
                <x-terms.link :category="'rescheduling'">Read the Rescheduling Policy</x-terms.link>
            </p>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                <button type="submit" class="btn-primary sm:min-w-44">Confirm Reschedule</button>
                <a href="{{ route('appointments.index', ['view' => $appointment->id]) }}" class="btn-ghost sm:min-w-44">Keep Current Slot</a>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        // Slot picker for the reschedule form. Mirrors the booking form's
        // availability endpoint but excludes this appointment's own slot.
        document.addEventListener('alpine:init', () => {
            Alpine.data('rescheduleSlots', () => ({
                date: '',
                time: '',
                slots: [],
                loading: false,
                url: '',
                serviceId: null,

                // Date rules, mirroring the booking form. `minDate` binds the
                // date input's `min`. `blockedDates` is kept and always empty —
                // it was this appointment's service-scoped blocked-date map, and
                // the feature that filled it is gone.
                minDate: '',
                maxDate: '',
                blockedDates: {},

                init() {
                    const node = document.getElementById('bta-reschedule-config');
                    const config = node ? JSON.parse(node.textContent) : {};

                    this.date = config.date || '';
                    this.slots = config.slots || [];
                    this.url = config.url || '';
                    this.serviceId = config.serviceId || null;
                    this.minDate = config.minDate || '';
                    this.maxDate = config.maxDate || '';

                    this.$watch('date', () => this.load());
                },

                async load() {
                    if (!this.date || !this.url) return;

                    this.loading = true;

                    try {
                        const params = new URLSearchParams({ date: this.date });

                        if (this.serviceId) params.set('service_id', this.serviceId);

                        const response = await fetch(this.url + '?' + params.toString(), {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });

                        if (!response.ok) throw new Error('slot lookup failed');

                        const data = await response.json();

                        this.slots = data.slots || [];
                        if (data.minDate) this.minDate = data.minDate;
                        if (data.maxDate) this.maxDate = data.maxDate;
                    } catch (error) {
                        this.slots = [];
                    } finally {
                        this.loading = false;
                    }
                },

                formatTime(value) {
                    const parts = String(value).split(':');
                    const hour = parseInt(parts[0], 10);
                    const minute = parts[1] || '00';
                    const suffix = hour >= 12 ? 'PM' : 'AM';
                    const display = hour % 12 === 0 ? 12 : hour % 12;

                    return display + ':' + minute + ' ' + suffix;
                },
            }));
        });
    </script>
@endpush
