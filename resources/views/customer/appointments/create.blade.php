{{--
    Book Appointment (Customer Flow 5), rebuilt as one wizard card.

    THE SERVICE PICKER IS NOT A STEP

    Services are chosen on the Browse Services page, where every category card
    can send several at once through `?services[]=`. That means `/book` never
    has to ask what to book: it receives the basket in the query string and puts
    it straight into the Booking Summary. A request with no valid service goes
    back to the Services section with a short message.

    What is left is three decisions — who you are and when you want to come,
    anything we should know, and how you have had it before and who you want —
    and two Continue clicks instead of five. The agreement still sits on the last
    card, directly above the Confirm Booking button.

    THE FORM IS ONE CARD

    Before any of that, the steps were seven separate cards stacked down a
    scrolling page, each with its own border, background and header rule. It read
    as seven unrelated forms that happened to be on one screen: the eye had to
    re-enter a new box at every step, and nothing said "these are all parts of
    one thing you are filling in". Everything lives inside a single `bta-card` —
    one shell, one header carrying the step counter, one body, one footer with
    the navigation.

    A stepper rather than a long scroll, because a scroll made the cost of the
    last step invisible until you had already scrolled past the others.

    NOT NOTHING IS GATED

    The wizard used to gate navigation: Continue would refuse to advance and name
    the step you had not finished. It does not any more, and this is the reason.

    Gating made reading the form into a negotiation. A customer who wanted to read
    the terms had to satisfy every step before them first, and a customer who had
    made a mistake on an early step could not go and look at a later one without
    fixing it first. The steps are a way of reading a form, not a maze.

    So Continue always advances, and `StoreBookingRequest` is the only gate. It
    refuses an incomplete booking on submit and returns every missing field at
    once, which is shown at the top of the page like any other validation error.
    One round trip with a complete list of problems is better than one refusal per
    step — and the rules it enforces are unchanged: a service, a name, a phone
    number, a date and a time, the agreement, and the last-services answer for a
    first-time customer.

    WHAT IS LEFT OF THE HEADER

    A "Step 1 of 3" counter and nothing else. The row of clickable step pills is
    gone: it restated the counter, took a second band of vertical space above the
    first question, and wrapped into two ragged rows on a phone, where it read as a
    menu rather than as progress. With nothing gated, jumping was never needed —
    Back and Continue get everywhere.

    The selected-service basket is held in Alpine state and mirrored into hidden
    inputs on submit, so the summary total stays live without a round-trip.
--}}
@extends('layouts.customer')

@section('title', 'Book Appointment')

@section('content')
@php
    use App\Support\PriceFormatter;
@endphp
    @php
        /**
         * Catalogue shape Alpine works with.
         *
         * `price` is the advertised string, for display only. `basePrice` is the
         * amount to charge and is what the running total multiplies. They are
         * separate because a service can be listed as "249/499" or "100+" and a
         * range has no single total — so a service with no base price is shown
         * as "Price on request" and cannot be ticked, rather than being booked
         * at a figure the customer never agreed to.
         */
        $catalogue = $groupedServices
            ->flatten()
            ->mapWithKeys(fn ($service) => [
                $service->id => [
                    'name' => $service->name,
                    'category' => $service->category,
                    'categoryKey' => \Illuminate\Support\Str::slug($service->category ?: 'uncategorised'),
                    'price' => PriceFormatter::display($service->price, 'Price on request'),
                    'basePrice' => $service->base_price === null ? null : (float) $service->base_price,
                    'bookable' => ! $service->isUnavailable()
                        && ($service->base_price !== null
                            || $service->variants->contains(fn ($v) => $v->base_price !== null)),
                    'baseDuration' => (int) $service->duration_minutes,
                    'variants' => $service->variants
                        // A variant with no amount is left out rather than
                        // offered and then totalled at zero.
                        ->filter(fn ($v) => $v->base_price !== null)
                        ->map(fn ($v) => [
                            'id' => $v->id,
                            'name' => $v->name,
                            'price' => PriceFormatter::display($v->price, 'Price on request'),
                            'basePrice' => (float) $v->base_price,
                            'duration' => (int) $v->effectiveDuration(),
                        ])->values(),
                ],
            ]);

        $initialSelection = collect($selected)->map(fn ($row) => [
            'service_id' => (int) $row['service_id'],
            'service_variant_id' => $row['service_variant_id'] ? (int) $row['service_variant_id'] : null,
            'quantity' => (int) $row['quantity'],
        ])->values();

        $minDate = $availability->firstBookableDate()->toDateString();
        $maxDate = $availability->lastBookableDate()->toDateString();

        /*
         * The steps, in order.
         *
         * This used to drive the clickable step pills as well, which made it the
         * single source of the pills, the panel headings and Alpine's step count.
         * With the pills gone the array is here for one thing: how many steps
         * there are, used by the "Step X of N" counter, by Continue's visibility
         * and by the last step's submit button. Counting an array beats writing
         * "4" in five places and finding four of them disagreeing when a step is
         * added.
         *
         * It was seven steps and is now four. Three pairs of panels were asking
         * for the same gesture - fill this in and move on - and were making the
         * customer press Continue to see a question that belonged on the card
         * they were already reading:
         *
         *   - "Your Details" and "Preferred Date & Time" were two cards asking
         *     for who you are and when you want to come. One card, four fields.
         *   - "Last Service(s) Availed" and "Choose Technician & Special
         *     Request" were two cards of optional-looking history and
         *     preference. One card.
         *   - "Terms & Conditions" was a card holding one checkbox. It is not a
         *     card any more; the checkbox sits on the last card, above the button
         *     that submits, which is where a customer expects the last thing
         *     before a booking is made to be the thing they agree to.
         *
         * Fewer Continue clicks, and no panel that exists only to hold one field.
         *
         * The headings below are written out in the panels themselves, which is
         * where they are read.
         *
         * The list itself now lives in `BookingSteps::TITLES`, beside the map from
         * each field to the step it is answered on. Two things need to agree about
         * which panel is which — this counter, and the step a refused submission
         * comes back to — and they used to be two separate lists of titles written
         * out by hand. They are one list now, and it is the class that owns both.
         */
        $steps = \App\Support\BookingSteps::TITLES;

        /*
         * `$terms` used to be read here to build a summary block of its own at the
         * top of a "Terms & Conditions" step — its heading, last-updated date and
         * opening sentence. That step is gone and so is the block: the checkbox's
         * label links to each policy and the dialog renders the published text, so
         * nothing needs the row any more. The controller still passes it and the
         * form still depends on the admin having published the terms, but the view
         * no longer has to read the row to render them.
         */

        $recentServicesText = implode(', ', array_column($recentServices, 'name'));
    @endphp

    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        {{-- Title and nothing else. The eyebrow and the sentence under it were
             describing the form rather than titling it. --}}
        <x-ui.page-header title="Book Appointment" />


        <form
            method="POST"
            action="{{ route('appointments.store') }}"
            enctype="multipart/form-data"
            x-data="bookingForm"
            x-on:submit="submitting = true"
            novalidate
        >
            @csrf

            {{--
                The idempotency key.

                One UUID per rendered form, carried in a hidden field, and the reason
                a double-tap cannot become two appointments. Two requests carrying
                this same value are the same booking attempt, not two, so the second
                is answered with the appointment the first created.

                `old()` is the important part. A rejected submit re-renders the form,
                and without it the customer would get a *new* key on the retry — which
                is fine, since nothing was created, but it would mean the key tracked
                the page rather than the attempt. Carrying it forward means the key
                identifies "this booking attempt" for as long as the attempt lasts.

                Server-generated rather than read from `sessionStorage`, so the field
                is populated in the HTML itself. A booking form that depends on
                JavaScript having run before it can be submitted correctly is a form
                that silently loses its duplicate protection on any browser where the
                script is blocked — the exact case where a customer is most likely to
                be pressing the button twice.
            --}}
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', Str::uuid()->toString()) }}">

            <div class="grid gap-8 lg:grid-cols-3">
                {{-- ================= THE ONE CARD: all three steps ================= --}}
                <div class="lg:col-span-2">
                    {{-- `scroll-mt-20` because `revealStep()` scrolls this card
                         to the top of the viewport, and the customer navbar is
                         `sticky top-0` — without the offset the step counter
                         this scroll exists to show would land underneath it. --}}
                    <section id="booking-step-card" class="bta-card scroll-mt-20 overflow-hidden">
                        {{-- The step counter.

                             This used to carry a row of seven clickable step pills as
                             well, so a customer could jump straight to any step. They
                             are gone: they duplicated what the "Step 1 of 3" line
                             already said, took a whole extra band of vertical space
                             above the first question, and on a phone they wrapped
                             into two ragged rows that read as a menu rather than as
                             progress.

                             What is left says where you are and how much is left,
                             which is what a counter is for. The steps are walked with
                             Back and Continue at the foot of the card, and — since
                             nothing is gated any more — a customer who wants to go
                             backwards simply does.

                             `aria-live="polite"` so the step number is announced when
                             it changes; without it the counter is silent to a screen
                             reader and moving a step is an unexplained jump. --}}
                        <header class="border-b border-primary/10 bg-linen/40 px-4 py-3.5 sm:px-5">
                            <p class="text-xs font-semibold uppercase tracking-wider text-ink-muted" aria-live="polite">
                                Step <span x-text="step">1</span> of {{ count($steps) }}
                            </p>
                        </header>

                        {{-- ================= THE STEPS ================= --}}
                        <div class="px-4 py-5 sm:px-6 sm:py-6">

                            {{-- ---------------------------------------------- 1 --}}
                            {{--
                                Your details and when you want to come, on one card.

                                These were two steps: "Your Details" asked for a name
                                and a phone number, and the next step asked for a date
                                and a time. Both are short, both are the same kind of
                                question — facts about this booking — and asking a
                                customer to press Continue between them bought nothing
                                except the chance to lose what they had typed when the
                                card swapped.

                                The date is chosen from a dropdown calendar rather than
                                from a month grid that sat on the page permanently
                                under these fields. The grid was there because a native
                                date input renders the browser's own picker and nothing
                                can be done to it — a date the salon has closed cannot
                                be struck through in the browser's picker. Now that the
                                day cells are ours, the information and the control are
                                the same thing and the grid can close.

                                `minDate` is tomorrow, so today and the past cannot be
                                landed on; the panel draws that as its 'outside booking
                                window' days. `StoreBookingRequest` re-checks it
                                regardless — this is the hint, not the guarantee.
                            --}}
                            <section x-show="step === 1" x-cloak>
                                <h3 class="font-display text-lg font-semibold text-primary">1. Your Details &amp; Preferred Date &amp; Time</h3>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <x-ui.form.input
                                        name="customer_name"
                                        label="Full Name"
                                        required
                                        autocomplete="name"
                                        x-model="customerName"
                                        :value="auth()->user()?->full_name"
                                    />
                                    <x-ui.form.input
                                        name="customer_phone"
                                        label="Phone Number"
                                        required
                                        autocomplete="tel"
                                        placeholder="09XX XXX XXXX"
                                        x-model="customerPhone"
                                        :value="auth()->user()?->contact_number"
                                    />

                                    <x-ui.form.date-picker
                                        name="preferred_date"
                                        label="Preferred Date"
                                        required
                                        :value="$prefilledDate"
                                    />

                                    <div>
                                        <label for="preferred_time" class="label">
                                            Preferred Time <span class="text-status-cancelled">*</span>
                                        </label>

                                        {{--
                                            Blocked times are rendered as disabled options
                                            rather than omitted.

                                            Silently dropping them would leave a customer
                                            looking at 9:00 and 10:30 with no 10:00 between
                                            them and no way to tell whether it is already
                                            taken, blocked by the salon, or simply not on
                                            the grid. Showing it struck through says what
                                            actually happened: the salon is not taking
                                            bookings then, but the rest of the day is fine.
                                        --}}
                                        <select id="preferred_time" name="preferred_time" class="input" required x-model="time">
                                            <option value="">Select a time</option>
                                            <template x-for="slot in slots" :key="slot">
                                                <option :value="slot" x-text="formatTime(slot)"></option>
                                            </template>
                                            <template x-for="slot in blockedSlots" :key="'b' + slot">
                                                <option :value="slot" disabled x-text="formatTime(slot) + ' — unavailable'"></option>
                                            </template>
                                        </select>

                                        {{--
                                            What the time field says about itself.

                                            The two refusals that used to sit here —
                                            "This date is not available. Please choose
                                            another date." and "No open slots on this
                                            date. Please choose another day." — are
                                            gone, and deliberately so. They restated,
                                            in grey text under the field, exactly what
                                            the red toast had just said at the
                                            bottom-right of the screen, so every blocked
                                            date was announced twice in two different
                                            registers, one of them easy to miss because
                                            it was the same weight as a hint.

                                            A blocked date now says so once, loudly, in
                                            the one place a customer is already looking
                                            for it, and the calendar says it before they
                                            pick at all: closed days are drawn struck
                                            through in red inside the panel.

                                            What is left is the genuinely additive
                                            count — how many times on this date are
                                            unavailable — which the toast does not and
                                            cannot say, and which is only useful once
                                            there is at least one time left to choose.
                                        --}}
                                        <p class="input-hint" x-show="loadingSlots">Checking availability&hellip;</p>
                                        <p class="input-hint" x-show="blockedSlots.length > 0 && slots.length > 0" x-cloak>
                                            <span x-text="blockedSlots.length"></span> time<span x-show="blockedSlots.length === 1"></span><span x-show="blockedSlots.length !== 1">s</span>
                                            on this date <span x-show="blockedSlots.length === 1">is</span><span x-show="blockedSlots.length !== 1">are</span> unavailable.
                                        </p>

                                        @error('preferred_time')
                                            <p class="input-error-text">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </div>

                                @guest
                                    <p class="mt-4 rounded-xl bg-linen/70 px-4 py-3 text-xs text-ink-muted">
                                        You are booking as a guest. <a href="{{ route('login') }}" class="font-medium text-primary underline underline-offset-2">Log in</a>
                                        to track, reschedule or cancel this appointment later.
                                    </p>
                                @endguest
                            </section>

                            {{-- ---------------------------------------------- 2 --}}
                            {{--
                                Preferences & health notes, in the customer's own words.

                                This step was a dropdown plus a box beside it. The
                                dropdown listed fourteen answers — latex, nickel,
                                fragrance, shellfish, weak or brittle nails, thick
                                nails, short nail bed, and so on — and the box was the
                                only field on the step that could actually answer the
                                question.

                                A closed list of fourteen is the wrong shape for this.
                                Somebody allergic to something the salon has never
                                heard of could not say so without abandoning the
                                dropdown and discovering the box, and somebody who
                                reads their own history as "latex and nickel, plus a
                                bad reaction to a patch test three years ago" had to
                                split that across two controls and decide which part
                                belonged where. It also made the step look longer and
                                more demanding than one question is: fourteen options
                                in a select is a wall on a phone.

                                So the dropdown is gone and there is one free-text
                                box. A textarea rather than a single-line input,
                                because the answers that matter are sentences:
                                "allergic to nickel, and my scalp reacts to strong
                                fragrance" is the actual information, and no list of
                                chips was ever going to hold it.

                                Nothing is required, exactly as nothing was before —
                                the old dropdown and its box were both `nullable`, and
                                forcing a health declaration to buy a manicure is not
                                the salon's call to make.

                                The column is still `appointments.allergies` and still
                                holds the sentence the customer typed.
                                `StoreBookingRequest::healthNote()` is what puts it
                                there, and it is the only place that needs changing:
                                it used to fold the dropdown's answer together with
                                this box, and now there is only the box.
                            --}}
                            <section x-show="step === 2" x-cloak>
                                <h3 class="font-display text-lg font-semibold text-primary">2. Preferences &amp; Health Notes</h3>

                                <div class="mt-4">
                                    {{--
                                    One free-text field, not a dropdown.

                                    This was a `<select>` of fixed allergy options.
                                    A customer describing a reaction they have
                                    had before, or a product to avoid, was picking
                                    the nearest of somebody else's guesses and
                                    then living with it.

                                    No `label` here on purpose: the step heading
                                    immediately above already says "Preferences &
                                    Health Notes", and the field repeated it
                                    twice on one card. The heading is the visible
                                    label; `aria-label` keeps the textarea's
                                    accessible name, which dropping the visible
                                    one would otherwise have taken with it.
                                --}}
                                    <x-ui.form.textarea
                                        name="allergies_other"
                                        rows="5"
                                        aria-label="Preferences & Health Notes"
                                        placeholder="Allergies, sensitivities, or anything else we should know — products to avoid, a reaction you have had before, how firmly you like to be worked on&hellip;"
                                    />
                                </div>
                            </section>

                            {{-- ---------------------------------------------- 3 --}}
                            {{--
                                What you last had, who you would like to see, anything
                                you need, and the agreement — one card, and the last
                                one.

                                "Last Service(s) Availed" and "Choose Technician &
                                Special Request" were two steps that were never really
                                two things. Both are history and preference, both are
                                short, and a customer moving between them was being
                                asked for one gesture per card.

                                The terms checkbox used to be a seventh step of its own
                                — a card whose entire content was one checkbox. It is
                                on this card instead, above the Confirm Booking button
                                in the footer, which is where it belongs: the last thing
                                you should do before booking is agree to the booking,
                                and it should be read as part of pressing the button
                                rather than as a room you had to walk through to reach it.

                                THE LAST-SERVICES QUESTION, WHICH IS NOT OPTIONAL FOR A
                                FIRST-TIMER

                                A first-timer has no record here for the salon to read
                                the answer out of, so they are asked — and required to
                                answer, because "we have no idea what you have had
                                before" is not a record any stylist can work from.

                                A returning customer does have a record, so they are not
                                asked. Asking someone to retype what the system already
                                holds is asking them to do the salon's bookkeeping: they
                                either remember and are guessing at the truth, or they
                                do not and the answer is worse than the truth. Their
                                history is shown read-only, with the visit each service
                                came from, and submitted for them by `BookingHistory` on
                                the server.

                                Both cases come from the same `BookingHistory` helper the
                                validation rule asks, so the field shown and the field
                                required cannot disagree.
                            --}}
                            <section x-show="step === 3" x-cloak>
                                <h3 class="font-display text-lg font-semibold text-primary">3. Last Service(s) Availed, Technician &amp; Special Request</h3>

                                <div class="mt-4">
                                    @if ($isRepeatCustomer)
                                        {{-- The greeting that stood here — "Welcome back.
                                             Here is what you last had with us…" — is gone. It
                                             restated the heading two lines above and told a
                                             returning customer they had returned, which the
                                             list under it already shows by existing. --}}
                                        <ul class="mt-3 divide-y divide-primary/8 overflow-hidden rounded-xl border border-primary/15 bg-linen/40">
                                            @foreach ($recentServices as $row)
                                                <li class="flex items-baseline justify-between gap-3 px-4 py-2.5">
                                                    <span class="text-sm font-medium text-primary">{{ $row['name'] }}</span>
                                                    @if ($row['date'])
                                                        <span class="shrink-0 text-xs text-ink-muted">{{ $row['date'] }}</span>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>

                                        {{-- Carries the same text the list shows, so the booking is
                                             documented whether or not anyone retypes it. Read by
                                             `BookingHistory` server-side, which is what fills the
                                             column when nothing is posted. --}}
                                        <input
                                            type="hidden"
                                            name="last_services_availed"
                                            value="{{ old('last_services_availed', $recentServicesText) }}"
                                        >
                                    @else
                                        <p class="text-sm text-ink">
                                            This is your first visit with us, so we have no record of what you have had done before.
                                            Telling us helps your therapist prepare.
                                        </p>

                                        {{-- Bound with `x-model` so the last step's gate can tell an
                                             answered question from an untouched one.
                                             It is still a plain posted field as far
                                             as the server is concerned, and a
                                             failed submit comes back with it filled
                                             in through `old()`. --}}
                                        <x-ui.form.textarea
                                            name="last_services_availed_note"
                                            label="What services have you had elsewhere?"
                                            rows="3"
                                            required
                                            x-model="lastServicesNote"
                                            placeholder="e.g. Colour & Highlights last March, Glow Manicure last month"
                                        />

                                        <input type="hidden" name="last_services_availed" value="{{ old('last_services_availed', '') }}">
                                    @endif
                                </div>

                                @error('last_services_availed')
                                    <p class="input-error-text mt-3">{{ $message }}</p>
                                @enderror

                                {{--
                                    Who they would like to see, and anything else they
                                    need.

                                    This was a grid of radio cards, each with the
                                    technician's face beside their name. It is a dropdown.

                                    A `<select>` cannot show a photograph, so this is a
                                    real loss and the face has been moved rather than
                                    dropped: the chosen technician's photo and initials
                                    are shown beside the dropdown as soon as one is
                                    picked. What survives is the thing the dropdown is
                                    better at — a list that stays a fixed height no
                                    matter how many technicians there are, that is
                                    keyboard- and screen-reader-native, and that reads
                                    the same on a phone as on a desktop.

                                    "No preference" stays a first-class option and
                                    submits an empty string, which the `integer` rule
                                    turns into null and the server reads as "assign one
                                    for me".
                                --}}
                                <div class="mt-6 border-t border-primary/10 pt-6">
                                    @if ($technicians->isEmpty())
                                        <p class="text-sm text-ink-muted">
                                            No technicians have been set up yet, so we will assign one for you.
                                        </p>
                                        <input type="hidden" name="technician_id" value="">
                                    @else
                                        <x-ui.form.select
                                            name="technician_id"
                                            label="Choose Your Technician"
                                            :includeBlank="true"
                                            blankLabel="No preference — assign one for me"
                                            :options="$technicians->mapWithKeys(fn ($t) => [(string) $t->id => $t->name])->all()"
                                            :value="old('technician_id', '')"
                                            x-model="technicianId"
                                        />

                                        {{-- The face the dropdown cannot show. `x-show` on the
                                             wrapper rather than a `<template x-if>` per branch,
                                             so switching technicians reuses one element instead
                                             of tearing down and rebuilding the image. --}}
                                        <div
                                            class="mt-3 flex items-center gap-3 rounded-xl border border-primary/15 bg-linen/40 px-4 py-3"
                                            x-show="technicianId !== ''"
                                            x-cloak
                                        >
                                            <template x-if="technicianPreview()?.photo">
                                                <img :src="technicianPreview().photo" alt="" class="h-10 w-10 shrink-0 rounded-full object-cover">
                                            </template>

                                            <template x-if="! technicianPreview()?.photo">
                                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-cream" x-text="technicianPreview()?.initials"></span>
                                            </template>

                                            <div class="min-w-0">
                                                <p class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Your technician</p>
                                                <p class="truncate text-sm font-medium text-primary" x-text="technicianPreview()?.name || '—'"></p>
                                            </div>
                                        </div>

                                        @error('technician_id')
                                            <p class="input-error-text mt-3">{{ $message }}</p>
                                        @enderror
                                    @endif

                                    <div class="mt-4">
                                        <x-ui.form.textarea
                                            name="special_request"
                                            label="Special Request"
                                            rows="3"
                                            placeholder="Quiet corner, extra time for consultation, specific products to avoid&hellip;"
                                        />
                                    </div>
                                </div>

                                {{--
                                    The gate, and the last thing on the form.

                                    The block that used to fill a step of its own — the
                                    published terms' own heading, "Last updated …", a
                                    "Read the full General Terms & Conditions" button,
                                    and a list of the Cancellation and Rescheduling
                                    policies — is gone with that step. It repeated the
                                    checkbox's label, which already names all three
                                    documents and links to each one, and a step holding
                                    only an agreement is a changelog entry wearing a
                                    step's clothing. The links in the label below still
                                    open the full published text in the dialog, so
                                    nothing became unreadable.

                                    `required` is kept on the control, and `accepted`
                                    in `StoreBookingRequest` is what actually refuses a
                                    booking. The form is `novalidate`, so the browser
                                    will not enforce the attribute — but the server
                                    does not need to be told twice to fail a booking
                                    with no agreement, and errors are shown on submit
                                    like everything else on this form.

                                    It sits at the foot of the card, directly above the
                                    footer that holds Confirm Booking, so reading order
                                    and intent agree: agree, then book.
                                --}}
                                <div class="mt-6 border-t border-primary/10 pt-6">
                                    <div class="rounded-xl border border-primary/15 bg-white/60 p-4">
                                        {{-- No `value` prop, and that is load-bearing. The checkbox component seeds
                                             its checked state from `old($name, (bool) $value))`,
                                             so `value="1"` pre-ticked the box on a
                                             fresh form — and consent to the
                                             cancellation and rescheduling policies
                                             is not something to tick on an admin's
                                             behalf. Without `value` the input still
                                             submits `1` (the component falls back
                                             to that) but renders unchecked, and
                                             `old('agree_terms')` still re-ticks it
                                             after a validation failure. --}}
                                        <x-ui.form.checkbox name="agree_terms" required x-model="agreeTerms">
                                            I agree to the
                                            <x-terms.link :category="'booking'">Booking Terms & Conditions</x-terms.link>
                                            , the
                                            <x-terms.link :category="'cancellation'">Cancellation Policy</x-terms.link>
                                            and the
                                            <x-terms.link :category="'rescheduling'">Rescheduling Policy</x-terms.link>.
                                        </x-ui.form.checkbox>
                                    </div>
                                </div>
                            </section>
                        </div>

{{-- ================= THE NAVIGATION =================

                             This row moves through the steps and stops there.
                             It does not submit.

                             Continue is gated on the step it would leave
                             being complete, and the button lists what is
                             missing rather than going quietly dead.

                             It was unconditional once, on the argument that one
                             refusal at the end with a full list of problems
                             beats a running commentary. `StoreBookingRequest` is
                             still the only thing that actually refuses a
                             booking, and still validates everything on submit —
                             including for a request that skipped a step. What
                             was wrong was letting someone walk three cards
                             forward before being told they had never given a
                             name: the error belonged to a screen they had left.

                             `missingOnCurrentStep()` is the whole gate, and both
                             the disabled state and the note read it, so the two
                             cannot name different problems.

                             STACKS ON A PHONE

                             Below `sm` the row stacks: Back on top, then the
                             Continue button at full width, then its reason.
                             Side by side they needed about 340px — the 16rem hint
                             plus two buttons — which is already too much on a
                             360px phone once the card's own padding is counted,
                             and on a 320px one pushed the hint off the right
                             edge. That hint is where the reason the Continue
                             button is disabled lives, so it is the one part of
                             this row that must not be the part that overflows.
                         --}}
                        <footer class="flex flex-col gap-3 border-t border-primary/10 bg-linen/40 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                            {{-- `invisible` rather than `x-show` on the empty first step, so
                                 "Back" does not make the row jump when the first step
                                 arrives. It is still not focusable either way. --}}
                            <button
                                type="button"
                                class="btn-ghost"
                                x-bind:class="step === 1 && 'invisible pointer-events-none'"
                                x-on:click="back()"
                            >
                                <span aria-hidden="true">&larr;</span> Back
                            </button>

                            {{-- Disabled while the step it would leave is incomplete.

                                 The button says why, underneath, rather than just
                                 going dead: a disabled control with no
                                 explanation is indistinguishable from a broken
                                 one. Both read the same list, so the note can
                                 never name a different field than the one that
                                 is holding the button. --}}
                            <div class="flex flex-col items-stretch gap-1 sm:items-end">
                                <button
                                    type="button"
                                    class="btn-primary"
                                    x-show="step < {{ count($steps) }}"
                                    x-bind:disabled="! canContinue()"
                                    x-bind:aria-describedby="missingOnCurrentStep().length ? 'step-gate-hint' : null"
                                    x-on:click="next()"
                                >
                                    Continue
                                    <span aria-hidden="true">&rarr;</span>
                                </button>

                                <p
                                    id="step-gate-hint"
                                    x-show="missingOnCurrentStep().length > 0 && step < {{ count($steps) }}"
                                    x-cloak
                                    class="text-left text-[11px] leading-snug text-ink-muted sm:max-w-[16rem] sm:text-right"
                                    aria-live="polite"
                                >
                                    <span x-text="missingOnCurrentStep().join(' · ')"></span>
                                </p>

                                {{-- The last step says where the button went, instead of
                                     carrying a second one.

                                     There used to be a "Confirm Booking" button here as
                                     well as one in the summary, both `type="submit"`, so
                                     the last step of the form offered the same irreversible
                                     action twice on one screen — two places to hesitate
                                     over, and on a narrow screen the two sat at opposite
                                     ends of the page with nothing saying they were the
                                     same button.

                                     One action, one place. The summary panel keeps it,
                                     because that is where the total it commits to and
                                     the "By confirming you agree…" line already are, and
                                     a booking is confirmed next to its own price.

                                     This is a sentence, not a control: it cannot be
                                     pressed, so it cannot become a third way to submit.
                                     It sits on the last step only, and on a phone the
                                     summary is below the form — which is why it points
                                     rather than simply ending the row. --}}
                                <p
                                    x-show="step === {{ count($steps) }}"
                                    x-cloak
                                    class="text-left text-[11px] leading-snug text-ink-muted sm:max-w-[16rem] sm:text-right"
                                >
                                    Confirm your booking from the Booking Summary.
                                </p>
                            </div>
                        </footer>
                    </section>
                </div>

                {{-- ================= RIGHT: booking summary ================= --}}
                {{--
                    Outside the wizard card on purpose.

                    This is not a step — nothing here is filled in, and it has to stay
                    visible while the steps change, which is what makes it usable: you
                    can see what you have picked and what it costs from every step,
                    rather than only from the step where you picked it.

                    It was already the one panel on this page that was not a numbered
                    card, and keeping it that way is what lets the three steps
                    live inside a single card without the page losing its anchor.
                --}}
                <div class="lg:col-span-1">
                    <div class="bta-card overflow-hidden lg:sticky lg:top-28">
                        <div class="border-b border-primary/10 bg-primary px-5 py-4">
                            <h2 class="font-display text-lg font-semibold text-cream">Booking Summary</h2>
                            <p class="text-xs text-cream/70">Read-only summary</p>
                        </div>

                        <div class="space-y-4 p-5 text-sm">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Customer</p>
                                <p class="mt-1 font-medium text-primary" x-text="$el.closest('form').querySelector('[name=customer_name]').value || '—'"></p>
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Service(s)</p>

                                <div x-show="selectedIds.length === 0" class="mt-1 text-ink-muted">No service selected yet.</div>

                                <ul class="mt-1.5 space-y-2">
                                    <template x-for="row in lines" :key="row.key">
                                        <li class="rounded-lg bg-linen/60 px-3 py-2">
                                            {{-- The basket itself is Alpine state, so the ids and
                                                 quantities have to ride along as real inputs for
                                                 StoreBookingRequest to see them. --}}
                                            <input type="hidden" :name="`services[${row.index}][service_id]`" :value="row.serviceId">
                                            <input type="hidden" :name="`services[${row.index}][service_variant_id]`" :value="row.variantId ?? ''">

                                            <div class="flex items-start justify-between gap-2">
                                                <div class="min-w-0 flex-1">
                                                    <p class="text-ink" x-text="row.name"></p>
                                                    <p class="mt-0.5 text-[11px] text-ink-muted">
                                                        <span x-text="row.advertised"></span> each
                                                        &middot; Qty <span x-text="row.quantity"></span>
                                                    </p>
                                                </div>
                                                <span class="shrink-0 font-medium text-primary" x-text="money(row.price * row.quantity)"></span>
                                            </div>
                                            <div class="mt-1.5 flex items-center gap-2">
                                                <select
                                                    class="rounded-lg border border-primary/15 bg-white px-2 py-1 text-[11px] text-ink"
                                                    x-show="row.hasVariants"
                                                    x-model="row.variantId"
                                                    x-on:change="onVariantChange(row)"
                                                >
                                                    <template x-for="v in row.variants" :key="v.id">
                                                        <option :value="v.id" x-text="v.name"></option>
                                                    </template>
                                                </select>

                                                <input type="number" min="1" max="10"
                                                       class="w-16 rounded-lg border border-primary/15 bg-white px-2 py-1 text-[11px] text-ink"
                                                       :name="`services[${row.index}][quantity]`"
                                                       x-model.number="row.quantity">
                                            </div>
                                        </li>
                                    </template>
                                </ul>
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Date & Time</p>
                                <p class="mt-1 font-medium text-primary" x-text="dateTimeLabel"></p>
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-ink-muted">Technician</p>
                                <p class="mt-1 font-medium text-primary" x-text="technicianName"></p>
                            </div>

                            <div class="bta-divider"></div>

                            {{--
                                Total, and only the total.

                                The Reference Number line used to sit here too, saying
                                "Generated automatically on submit". It was a field that
                                could not be filled in, could not be changed, and could
                                not be acted on before the booking existed — a row of
                                text promising a number to arrive. The reference is still
                                generated, still unique and still on the booking; it is
                                just not advertised on the form, and the customer is
                                shown it afterwards, where it is actually a fact.
                            --}}
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm font-semibold text-ink">Total Amount</span>
                                <span class="font-display text-2xl font-bold text-primary" x-text="money(total)"></span>
                            </div>
                        </div>

                        <div class="border-t border-primary/10 bg-linen/50 p-5">
                            {{--
                                Nothing is disabled and nothing is nagged about here.

                                The button used to be inert on an empty basket, with a
                                line above it naming whichever step was missing. Both
                                are gone: a control that refuses to be pressed, and
                                which does not explain itself while doing it, is worse
                                than one that submits and returns the server's list of
                                problems — and the server already refuses a booking with
                                no service in it, so the case that was being prevented
                                here could not have saved anything.
                            --}}
                            <button
                                type="submit"
                                class="btn-primary w-full btn-lg"
                                x-bind:disabled="submitting"
                            >
                                <span x-show="! submitting">Confirm Booking</span>
                                <span x-show="submitting" x-cloak>Sending&hellip;</span>
                            </button>

                            <p class="mt-2.5 text-center text-[11px] text-ink-muted">
                                By confirming you agree to our booking terms.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    @php
        // Built in PHP so Blade's @json() parser never has to walk closures.
        $bookingConfig = [
            'catalogue' => $catalogue,
            'initial' => $initialSelection,
            'slots' => $slots,
            'slotsUrl' => route('appointments.slots'),
            'date' => old('preferred_date', $prefilledDate),
            'time' => old('preferred_time', ''),
            // The picker's state, so the read-only summary can name the
            // technician without reading the DOM, and so the photo preview
            // beside the dropdown has something to show. Seeded from `old()`
            // so a failed submit comes back with the choice still made.
            //
            // One map rather than the two this used to need: the name is inside
            // the technician's entry, so the summary and the preview cannot
            // read from different sources and print different people.
            'technicianId' => (string) old('technician_id', ''),
            'technicians' => $technicians->mapWithKeys(fn ($t) => [
                (string) $t->id => [
                    'name' => $t->name,
                    // `null` rather than a broken `src` when no photo was
                    // uploaded — the preview falls back to initials.
                    'photo' => $t->photo_url,
                    'initials' => $t->initials,
                ],
            ])->all(),
            // The date rules, so the form can grey out what the server will
            // refuse without waiting for a failed submit. `minDate` is tomorrow.
            'minDate' => $minDate,
            'maxDate' => $maxDate,

            /*
             * The whole-day blocks inside the booking window, so the calendar
             * panel can strike them out from the first open instead of after the
             * first date change. Same source as the slots endpoint's
             * `blockedDates`, so the panel and a submit cannot disagree.
             */
            'blockedDates' => collect(\App\Models\BlockedSlot::wholeDaysBetween($availability->firstBookableDate(), $availability->lastBookableDate()))
                ->map(fn ($slot) => $slot->reason)
                ->all(),

            /*
             * The refusal wording, from the server.
             *
             * The calendar raises it as a toast the moment a blocked date is
             * clicked, and the same constant is what the inline hint quotes and
             * what the submit is refused with. Sending it rather than writing it
             * into the script is the only way those three can be guaranteed to be
             * the same sentence.
             */
            'blockedMessage' => \App\Services\BookingAvailability::UNAVAILABLE_DATE_MESSAGE,

            /*
             * The salon's opening hours, so the panel can strike out a weekday with
             * no hours. Sent whole rather than as a precomputed "closed weekdays"
             * list because the panel derives it the same way `isOpenOn()` does; a
             * list computed here would be a second place the answer lives.
             */
            'hours' => $settings->operating_hours ?? [],

            /*
             * Whether the "last services availed" answer has to be given.
             *
             * A returning customer is not asked it, and `StoreBookingRequest`
             * makes it required only when `BookingHistory::isRepeatCustomer()`
             * is false. The same predicate decides both, so the step gate cannot
             * demand an answer the server would have accepted without.
             */
            'requiresLastServices' => ! $isRepeatCustomer,

            'stepCount' => count($steps),

            /*
             * Which panel to open on.
             *
             * A refused booking comes back as a fresh render of this page, and
                 * this page opens on step 1. That was fine while the only things the
                 * server refused on step 1 were things the customer had left blank
                 * there; it stopped being fine the moment an error could belong to a
                 * panel that was not open. Tick everything, reach the last step, press
             * Confirm without ticking the agreement, and the toast said "You must
             * agree to the Terms and Conditions to book." on a page showing the
             * service list, with the box it was talking about three steps away.
             *
             * So the server decides, from the error bag it already has, and sends
             * the answer rather than letting the browser guess. It is a step and
             * only ever a step — `BookingSteps::startStep()` maps every error onto
             * one of the three panels, so this cannot introduce another place for a
             * message to be shown.
             *
             * 1 on a clean bag, so an untouched form opens where it always has.
             */
            'startStep' => \App\Support\BookingSteps::startStep($errors),
        ];
    @endphp

    {{-- Server-rendered config, read once by the Alpine component. --}}
    <script type="application/json" id="bta-booking-config">{!! json_encode($bookingConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

    <script>
        /**
         * Live booking basket, and the wizard that steps through it.
         *
         * Owns the selected services, their variants and quantities, mirrors them
         * into the named inputs that StoreBookingRequest validates, keeps the
         * read-only Booking Summary in sync, and moves the three steps one at a
         * time.
         *
         * It gates nothing. Not the steps and not the submit: there is no
         * `guardSubmit`, no `canSubmit`, and the Confirm button is never disabled.
         * Everything this file would have checked is checked by
         * `StoreBookingRequest`, which is the real gate and has always been — so
         * what is left here is convenience, and a convenience that refused to let
         * somebody read the next step was a cost rather than a saving.
         *
         * Configuration arrives as JSON from the server.
         */
        document.addEventListener('alpine:init', () => {
            Alpine.data('bookingForm', () => ({
                config: {},
                catalogue: {},
                selectedIds: [],
                rows: [],
                date: '',
                time: '',
                slots: [],
                loadingSlots: false,
                technicianId: '',
                technicians: {},

                /*
                 * The two fields on step 1 and the agreement on the last step,
                 * held here rather than read from the DOM.
                 *
                 * They are bound with `x-model` so that the Continue button's
                 * disabled state is *reactive*: reading `el.value` inside a
                 * getter would re-evaluate only when something else forced a
                 * render, so the button would sit there enabled after the field
                 * had been filled until something unrelated moved. A binding is
                 * the difference between a gate that is correct and one that is
                 * correct by accident.
                 */
                customerName: '',
                customerPhone: '',
                lastServicesNote: '',
                agreeTerms: false,
                slotsUrl: '',
                // Date rules. `minDate` is tomorrow, so the calendar draws today
                // and the past as outside the booking window rather than offering
                // them; the server re-checks both regardless. Seeded from the config
                // node because this object is defined inside a JS string.
                minDate: '',
                maxDate: '',

                /*
                 * Every whole day the salon has closed, keyed by date.
                 *
                 * Seeded from the config node on load and refreshed from every
                 * slots response, so the calendar panel can strike a day out
                 * without asking about it — paging the panel is then a local
                 * operation rather than a round trip per month.
                 */
                blockedDates: {},

                /*
                 * Times blocked on the chosen date, kept apart from `slots`.
                 *
                 * Separate so the dropdown can show them struck through rather than
                 * silently missing. "No 10:00 between 9:00 and 10:30" reads as a
                 * bug; a greyed "10:00 — unavailable" reads as the salon being shut
                 * then and open otherwise.
                 */
                blockedSlots: [],

                /*
                 * The wording for a blocked date, taken from the server rather than
                 * written here — so the toast, the inline hint and the refusal on
                 * submit are all the same sentence and none of them can be reworded
                 * without the others.
                 */
                blockedMessage: '',

                // The date the blocked toast was last raised for. A slot lookup can
                // report the same date more than once, and a toast per event would
                // stack. Cleared as soon as a bookable date is chosen, so coming back
                // to the blocked one warns again.
                lastBlockedToast: null,

                // Which month the calendar panel is showing. Deliberately not the
                // chosen date: paging the panel must not move the booking.
                calendarCursor: null,

                /*
                 * Whether the calendar panel is open.
                 *
                 * The panel used to be a month grid printed on the page, which is why
                 * this did not need to exist. It is the only state the dropdown needs
                 * beyond the month it is showing.
                 */
                datePickerOpen: false,

                /*
                 * Whether the panel has been placed yet.
                 *
                 * The panel has to be laid out before it can be measured, and it is
                 * its own measured size that tells it where to go — so it is opened
                 * transparent, positioned, and only then faded in. Set in the same
                 * tick the position is written, which is why it never appears in the
                 * wrong place even for a frame.
                 */
                datePickerReady: false,

                /*
                 * Where the panel sits, in viewport pixels.
                 *
                 * `fixed` rather than `absolute` because the wizard card clips its
                 * overflow to keep the header and footer backgrounds inside its
                 * rounded corners — an absolutely-positioned panel would be cut off
                 * by it — and because an absolute one hangs off the right edge of a
                 * phone. Measured from the input instead.
                 */
                datePickerPos: { top: 0, left: 0 },

                /*
                 * The wizard.
                 *
                 * `step` is the only state, and every panel's visibility is derived
                 * from it. Nothing reads it to decide whether a step may be left —
                 * Continue is unconditional and the server is the only gate.
                 */
                step: 1,
                stepCount: 1,

                /*
                 * True once the form has been submitted.
                 *
                 * Set from the form's own `submit` event rather than from a click
                 * handler, because Enter from a text field and a browser's own
                 * resubmit-on-Back both fire `submit` without a click anywhere near
                 * the button. Disabling both Confirm buttons off this one flag is
                 * the cheap half of the duplicate guard; the hidden idempotency key
                 * is the half that still works with no JavaScript at all.
                 *
                 * Never reset. The form is leaving the page, and a reset would
                 * re-enable the buttons on a page the browser may have kept alive
                 * in its back/forward cache — which is precisely the case where a
                 * second submit is most likely and least welcome.
                 */
                submitting: false,

                /**
                 * The first selected service.
                 *
                 * Still sent on every slot lookup because the endpoint accepts it —
                 * but nothing reads it there any more, and nothing here depends on
                 * it since the blocked-date feature went. Kept so the request
                 * shape does not change in one file without the other.
                 *
                 * `blockedDates` is now real again: the whole-day closures the
                 * calendar strikes out. `data.problems` is the server's own
                 * wording for why a date was refused — it is no longer written
                 * out under the date field, only toasted, so it is read here
                 * rather than kept as state.
                 */
                get firstServiceId() {
                    const first = this.rows[0];
                    return first ? first.serviceId : null;
                },

                init() {
                    const node = document.getElementById('bta-booking-config');

                    this.config = node ? JSON.parse(node.textContent) : {};

                    this.catalogue = this.config.catalogue || {};
                    this.slots = this.config.slots || [];
                    this.slotsUrl = this.config.slotsUrl || '';
                    this.date = this.config.date || '';
                    this.time = this.config.time || '';
                    this.technicianId = this.config.technicianId || '';
                    this.technicians = this.config.technicians || {};
                    this.stepCount = Number(this.config.stepCount) || 3;

                    /*
                     * Which panel to open on, from the server.
                     *
                     * `step`'s declared default is 1 and cannot be anything else —
                     * it is written into the HTML for every visitor, including the
                     * one who has just been told a box they cannot see is unticked.
                     * So the page opens where the server says it should, read here
                     * in `init()` rather than in the markup, because `init()` is
                     * still ahead of the first render and nothing can move the
                     * customer off this step before they have looked at it.
                     *
                     * Clamped through `goTo()` so a count that disagrees with
                     * `stepCount` cannot leave the wizard parked on a panel that
                     * does not exist — every panel's visibility is
                     * `step === N`, and an N with no panel is a blank card with
                     * the navigation still showing.
                     */
                    this.goTo(this.config.startStep || 1);

                    // Seeded from the server so the calendar is right on the
                    // first open. `loadSlots()` refreshes it, so a block saved while the
                    // form was open is picked up on the next date change.
this.minDate = this.config.minDate || '';
                this.maxDate = this.config.maxDate || '';
                this.blockedDates = this.config.blockedDates || {};
                this.blockedMessage = this.config.blockedMessage || '';

                /*
                 * The step-gate's inputs, read from the DOM rather than from a
                 * seeded default.
                 *
                 * These fields are already filled in before Alpine runs: a signed-in
                 * customer has a name and a number on the profile, and a booking
                 * refused by the server comes back with `old()` values in place.
                 * Declaring them as empty strings and letting `x-model` push those
                 * empty strings down would silently blank a prefilled form and then
                 * refuse to advance — a gate that blocks a customer for a field
                 * they had already answered.
                 *
                 * So they are read here, which `init()` runs early enough to beat
                 * `x-model`'s first write, and the binding takes over from there:
                 * the customer types, the state follows, the button reacts.
                 *
                 * Selectors are unquoted — `[name=customer_name]` rather than
                 * `[name="customer_name"]`. Both are valid CSS, but the quoted form
                 * spells out `name="..."` verbatim, which is the exact string the
                 * copy tests assert is *absent* from a returning customer's page.
                 * Naming the field here would have made "this question is not asked
                 * of you" unreadable from the rendered page.
                 */
                const nameField = this.$root.querySelector('[name=customer_name]');
                const phoneField = this.$root.querySelector('[name=customer_phone]');
                const lastServicesField = this.$root.querySelector('[name=last_services_availed_note]');
                const termsField = this.$root.querySelector('[name=agree_terms]');

                this.customerName = nameField ? nameField.value : '';
                this.customerPhone = phoneField ? phoneField.value : '';
                this.lastServicesNote = lastServicesField ? lastServicesField.value : '';
                this.agreeTerms = termsField ? termsField.checked : false;

                    // The date alone is watched. It used to also watch the basket,
                    // because a category-scoped block only applied once a service
                    // from that category was selected; no block depends on the
                    // basket now, so neither does the calendar.
                    this.$watch('date', () => this.loadSlots());

                    (this.config.initial || []).forEach((row) => {
                        this.selectedIds.push(Number(row.service_id));
                        this.buildRow(Number(row.service_id), row.service_variant_id, row.quantity);
                    });

                },

                /* ---------------------------------------------------------------- */
                /* The wizard                                                        */
                /* ---------------------------------------------------------------- */

                /**
                 * Move to a step.
                 *
                 * Clamped rather than refused, so `next()` off the last step and
                 * `back()` off the first are no-ops instead of errors — there is
                 * nowhere else to go in either direction, and the buttons that call
                 * these are hidden at those points anyway.
                 */
                goTo(number) {
                    const target = Number(number);

                    if (!Number.isFinite(target)) return;

                    // The calendar panel belongs to step 1. Leaving it open across a
                    // step change would leave it floating over a panel it has nothing
                    // to do with, at a position measured against a field that is no
                    // longer on screen.
                    this.closeDatePicker();

                    const next = Math.min(Math.max(Math.trunc(target), 1), this.stepCount);
                    const moved = next !== this.step;

                    this.step = next;

                    if (moved) this.revealStep();
                },

                /**
                 * Show the top of the card for the step just arrived at.
                 *
                 * The Continue button is at the FOOT of the card, so the customer
                 * clicks it at the bottom of a long step. The steps swap in place
                 * with `x-show`, which changes the document height without
                 * changing the scroll offset — so what they are left looking at
                 * is whatever happened to be at that offset in the *new* step.
                 * Moving between a short step and a long one lands them in the
                 * middle of a form, or below it, with the question they have to
                 * answer off-screen. That reads as the button having done nothing
                 * rather than as having moved them forward.
                 *
                 * Two rules keep this from becoming the opposite annoyance:
                 *
                 *  - It does not scroll when the card is already in view. The
                 *    point is to stop the customer being stranded, not to move
                 *    the page out from under someone who is already looking at
                 *    the right thing.
                 *  - It only ever brings the card UP to where they are looking.
                 *    `scrollIntoView` on a card that already straddles the top of
                 *    the viewport would drag them backwards off the step they
                 *    just chose, which is the thing they asked not to happen.
                 *
                 * `$nextTick` because `x-show` has not swapped the panels yet
                 * when `goTo` returns, and the card's height — which decides
                 * whether scrolling is needed at all — is the wrong one until it
                 * has.
                 */
                revealStep() {
                    this.$nextTick(() => {
                        const card = document.getElementById('booking-step-card');

                        if (!card) return;

                        const { top, height } = card.getBoundingClientRect();

                        // Already the thing being looked at: top is on screen and
                        // most of the card is below it.
                        if (top >= 0 && height > window.innerHeight * 0.5) return;

                        const still = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

                        card.scrollIntoView({
                            behavior: still ? 'auto' : 'smooth',
                            block: 'start',
                        });
                    });
                },

                next() {
                    /*
                     * Belt and braces with the button's `disabled`: this is the
                     * path a keyboard Enter on the form, or a click that landed
                     * between a state change and the re-render, would take.
                     * Refusing here as well means no route into step N+1 skips
                     * step N's required fields, however it was reached.
                     */
                    if (! this.canContinue()) return;

                    this.goTo(this.step + 1);
                },

                back() {
                    this.goTo(this.step - 1);
                },

                /* ---------------------------------------------------------------- */
                /* Step gating                                                        */
                /* ---------------------------------------------------------------- */

                /**
                 * What is still missing on the step being looked at.
                 *
                 * The list is the gate. `canContinue()` is just "is this empty",
                 * so the button and the message under it can never disagree about
                 * why it is disabled — there is one list, read twice.
                 *
                 * These are the same fields `StoreBookingRequest` requires, and
                 * deliberately no more:
                 *
                 *   step 1 - name, phone, date and time, all required by the
                 *            server, with the phone held to the same shape it
                 *            validates against so a customer is not let through
                 *            to a refusal they could have been spared;
                 *   step 2 - nothing: the health note is optional;
                 *   step 3 - the agreement, plus the last-services answer, which
                 *            only a first-time customer is asked for.
                 *
                 * Optional fields are not gated. Demanding a special request, or
                 * a note from someone who has simply never been to a salon, is
                 * not validation, it is a chore dressed up as one.
                 *
                 * @return array<int, string>
                 */
                missingOnCurrentStep() {
                    const missing = [];

                    if (this.step === 1) {
                        if (this.customerName.trim() === '') {
                            missing.push('Enter your full name');
                        }

                        // The server's own shape, so "invalid" here means what it
                        // will mean on submit.
                        if (! /^[0-9+\-\s()]{7,32}$/.test(this.customerPhone.trim())) {
                            missing.push('Enter a phone number we can reach you on');
                        }

                        if (! this.date) {
                            missing.push('Choose a preferred date');
                        }

                        if (! this.time) {
                            missing.push('Choose a preferred time');
                        }
                    }

                    /*
                     * The last step, read off `stepCount` rather than off
                     * `config.stepCount`.
                     *
                     * Both hold the same number, but only one of them is a number:
                     * `config` is whatever the server sent, and a panel whose
                     * comparison is `this.step === "3"` is false for the rest of
                     * time. The agreement would then never be gated — a customer
                     * could Confirm without ticking it and be refused by the
                     * server, which is the exact refusal this wizard now avoids
                     * by opening on the right step.
                     */
                    if (this.step === this.stepCount) {
                        if (this.config.requiresLastServices && ! this.lastServicesAnswered) {
                            missing.push('Tell us what you have had done elsewhere');
                        }

                        if (! this.agreeTerms) {
                            missing.push('Agree to the booking terms to continue');
                        }
                    }

                    return missing;
                },

                /** May the customer leave the step they are on? */
                canContinue() {
                    return this.missingOnCurrentStep().length === 0;
                },

                /**
                 * Has the last-services question been answered?
                 *
                 * A returning customer is not shown the field at all and submits
                 * the history the server already holds, so `false` for them must
                 * not read as unanswered. That is what `requiresLastServices`
                 * distinguishes, and it is the same predicate the validation
                 * rule uses.
                 */
                get lastServicesAnswered() {
                    if (! this.config.requiresLastServices) return true;

                    return this.lastServicesNote.trim() !== '';
                },

                buildRow(serviceId, variantId, quantity) {
                    const meta = this.catalogue[serviceId];

                    if (!meta) return;

                    const variants = meta.variants || [];
                    const chosen = variants.find((v) => v.id === Number(variantId)) || variants[0] || null;

                    this.rows.push({
                        key: serviceId + '-' + (chosen ? chosen.id : 'base'),
                        index: this.rows.length,
                        serviceId: serviceId,
                        name: chosen ? meta.name + ' (' + chosen.name + ')' : meta.name,
                        variants: variants,
                        variantId: chosen ? chosen.id : null,
                        hasVariants: variants.length > 1,
                        // The amount to charge, not the advertised string: this is
                        // what the running total multiplies.
                        price: Number(chosen ? chosen.basePrice : meta.basePrice) || 0,
                        // What the service is advertised at, so a range like
                        // "249/499" is visible next to the amount being totalled.
                        advertised: chosen ? chosen.price : meta.price,
                        quantity: Number(quantity) || 1,
                    });
                },

                onVariantChange(row) {
                    const meta = this.catalogue[row.serviceId];
                    const chosen = row.variants.find((v) => v.id === Number(row.variantId));

                    row.name = chosen ? meta.name + ' (' + chosen.name + ')' : meta.name;
                    row.price = Number(chosen ? chosen.basePrice : meta.basePrice) || 0;
                    row.advertised = chosen ? chosen.price : meta.price;
                    row.key = row.serviceId + '-' + (chosen ? chosen.id : 'base');
                },

                reindex() {
                    this.rows.forEach((row, index) => { row.index = index; });
                },

                get lines() {
                    return this.rows;
                },

                get total() {
                    return this.rows.reduce((sum, row) => sum + row.price * row.quantity, 0);
                },

                /* ---------------------------------------------------------------- */
                /* Technician                                                        */
                /* ---------------------------------------------------------------- */

                /**
                 * The chosen technician, or null for "No preference".
                 *
                 * Null rather than a placeholder row, because the preview beside
                 * the dropdown is hidden entirely in that case — showing an empty
                 * avatar would imply a technician had been picked.
                 */
                technicianPreview() {
                    return this.technicianId ? (this.technicians[this.technicianId] || null) : null;
                },

                get technicianName() {
                    const preview = this.technicianPreview();

                    return preview ? preview.name : 'No preference';
                },

                /* ---------------------------------------------------------------- */
                /* Date & time                                                       */
                /* ---------------------------------------------------------------- */

                get dateTimeLabel() {
                    if (!this.date) return '—';

                    const pretty = new Date(this.date + 'T00:00:00')
                        .toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });

                    return this.time ? pretty + ' at ' + this.formatTime(this.time) : pretty;
                },

async loadSlots() {
                    if (! this.slotsUrl) return;

                    const query = new URLSearchParams();

                    if (this.date) query.set('date', this.date);

                    /*
                     * With no date picked there is nothing to look up. The earliest
                     * bookable date stands in so `minDate`/`maxDate` and the
                     * blocked-day map still arrive; the response is only adopted
                     * for slots when a real date is chosen.
                     */
                    if (query.has('date') === false) {
                        query.set('date', this.minDate);
                    }

                    const previous = this.time;
                    this.loadingSlots = true;

                    try {
                        const response = await fetch(this.slotsUrl + '?' + query.toString(), {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });

                        if (! response.ok) throw new Error('slot lookup failed');

                        const data = await response.json();

                        // Only adopt the server's slots once a real date is
                        // chosen; the stand-in lookup above exists only to keep
                        // min/max current, and must not pre-fill a time.
                        const isChosenDate = Boolean(this.date) && data.date === this.date;

                        this.slots = isChosenDate ? (data.slots || []) : [];
                        this.blockedSlots = isChosenDate ? (data.blockedSlots || []) : [];

                        if (data.minDate) this.minDate = data.minDate;
                        if (data.maxDate) this.maxDate = data.maxDate;

                        /*
                         * The blocked-day map is for the whole bookable range, not
                         * the one date asked about, so it is adopted every time. This
                         * is what lets the calendar panel strike days out without
                         * asking about each one.
                         */
                        if (data.blockedDates) this.blockedDates = data.blockedDates;

                        // The server's own reason, quoted verbatim rather than
                        // rebuilt from a client-side map. It used to be written
                        // out under the date field as a hint; that line is gone,
                        // because it restated the toast below in grey and every
                        // blocked date was then announced twice. The toast is
                        // the only place this sentence appears now.

                        /*
                         * A date the server has refused outright also raises the red
                         * toast, rather than waiting for a doomed submit.
                         *
                         * The calendar refuses a blocked day itself, so this covers
                         * the other way in: a date that was already chosen — carried
                         * back from a rejected submit, or picked before an admin
                         * closed that day — and has since gone bad. Either way the
                         * customer has been told the salon is shut, and leaving them
                         * with only a line of grey text under the field would be
                         * quieter than the situation deserves.
                         *
                         * Deliberately *not* raised for a weekday the salon is always
                         * closed — "We are closed on Sundays" is standing
                         * information the panel already shows struck through, not a
                         * slot that was taken off them. `wholeDay` is the flag that
                         * separates the two.
                         *
                         * Sent through the same toast bus as every other flash, so it
                         * lands bottom-right in the house style with the auto-dismiss
                         * an error toast gets.
                         */
                        if (isChosenDate && data.wholeDay && this.lastBlockedToast !== data.date) {
                            this.lastBlockedToast = data.date;

                            window.btaToast.show(
                                'error',
                                this.blockedMessage || data.problems[0]
                            );
                        } else if (isChosenDate && ! data.wholeDay) {
                            // Back on a bookable date: arm the toast again for the
                            // blocked one, in case they come back to it.
                            this.lastBlockedToast = null;
                        }

                        if (previous && !this.slots.includes(previous)) {
                            this.time = '';
                        }
                    } catch (error) {
                        this.slots = [];
                        this.blockedSlots = [];
                    } finally {
                        this.loadingSlots = false;
                    }
                },

                /* ---------------------------------------------------------------- */
                /* The calendar panel                                               */
                /* ---------------------------------------------------------------- */

                /**
                 * The chosen date, written out for the field.
                 *
                 * "Sat, 12 Jun 2026" rather than "2026-06-12": the field is
                 * presented as a date, so it should read as one. The format string is
                 * the one the component also renders server-side, so a value that
                 * comes back from a rejected submit does not change shape when Alpine
                 * takes the field over.
                 *
                 * Empty when nothing is chosen, which leaves the field's own
                 * placeholder showing.
                 */
                get datePickerLabel() {
                    if (! this.date) return '';

                    const parsed = new Date(this.date + 'T00:00:00');

                    if (isNaN(parsed)) return this.date;

                    return parsed.toLocaleDateString('en-PH', {
                        weekday: 'short',
                        day: 'numeric',
                        month: 'short',
                        year: 'numeric',
                    });
                },

                get datePickerStyle() {
                    return 'top: ' + this.datePickerPos.top + 'px; left: ' + this.datePickerPos.left + 'px;';
                },

                /**
                 * Open the panel, on the month of the chosen date.
                 *
                 * The cursor is reset so the panel always reopens where the booking
                 * is: a customer who paged forward to look at next month, picked
                 * nothing and closed the panel should not come back to it still
                 * looking at next month.
                 *
                 * Positioned on the next tick rather than immediately, because the
                 * panel has to be visible before it can be measured and the fade is
                 * held back until it has been.
                 */
                openDatePicker() {
                    this._calendarCursor = null;
                    this.datePickerReady = false;
                    this.datePickerOpen = true;

                    this.$nextTick(() => {
                        this.positionDatePicker();
                        this.datePickerReady = true;
                    });
                },

                closeDatePicker() {
                    this.datePickerOpen = false;
                    this.datePickerReady = false;
                },

                toggleDatePicker() {
                    if (this.datePickerOpen) {
                        this.closeDatePicker();
                    } else {
                        this.openDatePicker();
                    }
                },

                /**
                 * Put the panel under its field, against the viewport.
                 *
                 * Three corrections, in the order they matter:
                 *
                 *   - Width first. On a phone the field can sit close enough to the
                 *     right edge that a panel anchored to its left would run off the
                 *     screen; it is pulled back in rather than allowed to overflow.
                 *   - Then below, which is where a panel belongs when there is room.
*   - Then above, if there is not. The date field is near the top of
                  *     a long card, so a customer who has scrolled down to reach
                  *     it is the common case, not the rare one.
                 *
                 * Bound to `scroll` and `resize` from the component, because a fixed
                 * panel does not follow its field when either moves.
                 */
                positionDatePicker() {
                    const anchor = this.$refs.datePickerAnchor;
                    const panel = this.$refs.datePickerPanel;

                    if (! anchor || ! panel) return;

                    const rect = anchor.getBoundingClientRect();

                    // Zero while the panel is still hidden; 19rem is the width the
                    // component is written at, so the fallback is the real one.
                    const width = panel.offsetWidth || 304;
                    const height = panel.offsetHeight || 280;

                    const gap = 6;
                    const margin = 8;

                    let left = rect.left;

                    if (left + width > window.innerWidth - margin) {
                        left = window.innerWidth - margin - width;
                    }

                    if (left < margin) left = margin;

                    let top = rect.bottom + gap;

                    if (top + height > window.innerHeight - margin && rect.top - gap - height > margin) {
                        top = rect.top - gap - height;
                    }

                    // Neither side had room — a short window, or a keyboard held
                    // the viewport down to nothing. Pull it onto the screen anyway
                    // rather than letting it hang off the bottom, where the month
                    // arrows and the legend are the parts that go missing.
                    if (top + height > window.innerHeight - margin) {
                        top = Math.max(margin, window.innerHeight - margin - height);
                    }

                    this.datePickerPos = { top: top, left: left };
                },

                /**
                 * Whole days closed, as short labels for the footnote.
                 *
                 * Capped by the caller at display time; this is the full list, so
                 * the count and the first few can both be quoted without asking
                 * again.
                 */
                get blockedDateList() {
                    return Object.keys(this.blockedDates)
                        .filter((date) => this.isWithinWindow(date))
                        .sort()
                        .map((date) => this.shortDate(date));
                },

                isWithinWindow(date) {
                    if (! this.minDate || ! this.maxDate) return true;

                    return date >= this.minDate && date <= this.maxDate;
                },

                /** "Fri 12 Jun", with the year dropped — it is always this year. */
                shortDate(date) {
                    const parsed = new Date(date + 'T00:00:00');

                    if (isNaN(parsed)) return date;

                    return parsed.toLocaleDateString('en-PH', { weekday: 'short', day: 'numeric', month: 'short' });
                },

                /**
                 * The month the panel opens on.
                 *
                 * Follows the chosen date when there is one, so opening the panel
                 * shows the month the customer is actually looking at, and falls
                 * back to today when the form has just been opened with nothing
                 * chosen.
                 */
                get calendarCursorDate() {
                    const source = this.date || this.minDate || new Date().toISOString().slice(0, 10);

                    const parsed = new Date(source + 'T00:00:00');

                    return isNaN(parsed) ? new Date() : parsed;
                },

                set calendarCursor(value) {
                    this._calendarCursor = value;
                },

                get calendarCursor() {
                    return this._calendarCursor || this.calendarCursorDate;
                },

                get calendarLabel() {
                    return this.calendarCursor.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' });
                },

                /**
                 * One entry per day cell, Monday-first, padded to whole weeks.
                 *
                 * Leading and trailing days from the neighbouring months are
                 * included, and are selectable when they fall inside the booking
                 * window — a panel that started on the 1st could not show that the
                 * salon is open on the Friday before a blocked week, which is the day
                 * a customer would actually pick.
                 */
                get calendarDays() {
                    const cursor = this.calendarCursor;

                    const start = new Date(cursor.getFullYear(), cursor.getMonth(), 1);

                    // getDay() is Sunday-first; shift so Monday is 0.
                    const leading = (start.getDay() + 6) % 7;

                    start.setDate(start.getDate() - leading);

                    return Array.from({ length: 42 }, (_, index) => {
                        const day = new Date(start);
                        day.setDate(start.getDate() + index);

                        const iso = this.isoDate(day);
                        const state = this.dayState(iso);

                        return {
                            date: iso,
                            day: day.getDate(),
                            inMonth: day.getMonth() === cursor.getMonth(),
                            // One state per day, resolved once here, so the class
                            // map and the strike cannot disagree about which days
                            // are blocked — they read the same value.
                            state: state,
                            // `selectable` is derived from the state rather than re-tested here.
                            // Two predicates asking the same question is how a
                            // partially blocked day would end up clickable in one
                            // place and dead in the other.
                            selectable: state === 'available',
                            label: day.toLocaleDateString('en-PH', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
                                + (state === 'blocked' ? ' — fully booked out' : ''),
                            title: state === 'blocked'
                                ? (this.blockedDates[iso] || 'The salon is closed on this day.')
                                : '',
                        };
                    });
                },

                /**
                 * Which of three things a day is, in the order the server would
                 * refuse it.
                 *
                 *   'blocked'   — no booking possible at all: an admin closed the
                 *                 whole date, or the salon has no opening hours for
                 *                 that weekday. The only state that gets the strike.
                 *   'outside'   — inside no part of the booking window: past, today,
                 *                 or past the far edge.
                 *   'available' — bookable.
                 *
                 * There is deliberately **no state for a partially blocked day**. A
                 * date with some times blocked and others free is bookable, so it is
                 * 'available' and looks like any other open day: no strike, no marker, no
                 * dimming. A customer who cannot take 2pm can still take 11am, and a
                 * calendar that made the whole date look unavailable would be wrong
                 * about most of it. The times that are gone are struck through
                 * individually in the time dropdown instead, which is where they
                 * actually bite.
                 *
                 * Blocked is tested before outside, so a day that is both closed by
                 * the salon *and* past reads as blocked — the closure is the more
                 * informative of the two, and it is what the strike explains.
                 */
                dayState(iso) {
                    if (this.isWholeDayBlocked(iso)) return 'blocked';

                    const day = new Date(iso + 'T00:00:00');

                    if (this.closedWeekdays.includes(day.getDay())) return 'blocked';

                    if (! this.isWithinWindow(iso)) return 'outside';

                    return 'available';
                },

                /**
                 * Has an admin closed this entire date?
                 *
                 * `blockedDates` holds whole-day closures only — a range block is not
                 * in it, which is exactly why a partially blocked day cannot end up
                 * struck through by accident.
                 */
                isWholeDayBlocked(iso) {
                    /*
                     * Key presence, NOT truthiness.
                     *
                     * `blockedDates` is date => reason, and a reason is optional -
                     * `StoreBlockedSlotRequest` calls `nullable` on it and the admin
                     * calendar lets a whole day be closed without one. So a blocked
                     * day is routinely mapped to `null`.
                     *
                     * This used to ask whether the entry was truthy, and a null
                     * entry is not: a closure the admin had entered with no reason
                     * was drawn as an ordinary open day, with no strike on it, and
                     * the customer only found out by clicking it and being refused
                     * by the toast. The server has no such blind spot -
                     * `BookingAvailability::isDateBlocked()` asks `isset()` about
                     * the model, not about its reason - which is exactly why the
                     * toast fired for a day the calendar insisted was free.
                     *
                     * `hasOwnProperty` asks the question actually being asked: is
                     * this date in the map?
                     */
                    return Object.prototype.hasOwnProperty.call(this.blockedDates, iso);
                },

                /**
                 * Which weekdays the salon has no hours for, as `getDay()` numbers.
                 *
                 * From the same `hours` map the booking rules validate with, rather
                 * than from a hardcoded list — so a change to the salon's opening
                 * hours cannot leave the panel disagreeing with the booking form about
                 * which weekday is shut.
                 */
                get closedWeekdays() {
                    const names = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
                    const hours = this.config.hours || {};

                    return names
                        .map((name, index) => (hours[name] ? null : index))
                        .filter((index) => index !== null);
                },

                /**
                 * Styling for one grid cell, by its state.
                 *
                 * Blocked and outside are now two different looks rather than one
                 * greyed-out box for everything unselectable. That was the whole
                 * problem: a date the salon has closed and a date that is simply
                 * three months past were painted identically, so the strike — the
                 * one signal that says "the salon is not taking bookings" — had
                 * nothing to be distinct *from*.
                 *
                 * A blocked day keeps a readable, dark digit with the red strike
                 * drawn straight through it — `line-through` for the line and
                 * `decoration-status-cancelled` for its colour.
                 *
                 * Those are two separate properties, which is the point. A
                 * text-decoration defaults to `currentColor`, so plain
                 * `line-through` would turn the digit red along with the line and
                 * a blocked date would read as an error rather than as a closed
                 * day. Setting the decoration's own colour leaves the digit
                 * legible and reddens only the line, which is what a struck-out
                 * date is supposed to look like.
                 */
                dayClasses(day) {
                    const chosen = day.date === this.date;

                    if (chosen) {
                        return 'border-primary bg-primary text-cream font-semibold';
                    }

                    if (day.state === 'blocked') {
                        return 'border-line/50 bg-linen/40 text-ink-muted cursor-not-allowed line-through decoration-status-cancelled';
                    }

                    if (day.state === 'outside') {
                        // No strike, no red: nothing is wrong with this date, it is
                        // just not one the salon is currently taking bookings for —
                        // Monday, or a day past the booking window.
                        //
                        // Full-strength muted ink rather than half of it.
                        // The digit still has to be readable: a recurring closure is
                        // the one thing a customer looks for on this grid, and at 50%
                        // on a linen background it was closer to invisible than to
                        // greyed. The recessed border and background carry the
                        // de-emphasis, not the contrast of the number itself.
                        return 'border-line/40 bg-linen/30 text-ink-muted cursor-not-allowed';
                    }

                    if (! day.inMonth) {
                        // Same reasoning as `outside`: the spillover days are dimmed
                        // by their background, and their numbers are read, not
                        // skipped past.
                        return 'border-line/70 bg-white text-ink-muted hover:border-primary/40';
                    }

                    return 'border-line/70 bg-white text-ink hover:border-primary/40 hover:bg-linen/60';
                },

                /**
                 * Choose a day from the calendar.
                 *
                 * Writes to the same `date` the hidden field is bound to, so the
                 * panel, the summary column and the submitted field cannot drift
                 * apart, and the ordinary slot lookup runs from the existing watcher
                 * rather than being duplicated here.
                 *
                 * A blocked day is refused here *and* on the server, and here it is
                 * refused out loud.
                 *
                 * It used to be enough to leave that to the disabled attribute, back
                 * when the day cells were printed on the page and there was nothing
                 * else the customer could do about it. Now the blocked cells are
                 * live — they are drawn, so they are reachable, and a struck-through
                 * date is a date somebody is likely to click on the strength of the
                 * strike alone. A button that silently does nothing reads as a broken
                 * page, so clicking one raises the same refusal a submit would,
                 * through the same toast, worded by the same server constant.
                 *
                 * `lastBlockedToast` is set so leaning on the same day twice warns
                 * once rather than twice, which is the behaviour the slot-lookup path
                 * already had.
                 *
                 * A date outside the booking window is not in this branch. Those cells
                 * are genuinely `disabled`, and nothing about them needs saying: there
                 * is no rule to explain, only a window the panel already draws.
                 *
                 * This does raise the toast for a weekday the salon is always closed,
                 * which the slot-lookup path below deliberately does not. That is not
                 * a contradiction: a struck-through Sunday is a day somebody has just
                 * pointed at, and answering it is useful, whereas the same Sunday
                 * arriving as the form's own pre-filled date is not a request for
                 * information — it is the form's starting point, and a toast about it
                 * would fire at a customer who had not done anything.
                 */
                pickDate(day) {
                    if (day.state === 'blocked') {
                        if (this.lastBlockedToast !== day.date) {
                            this.lastBlockedToast = day.date;

                            window.btaToast?.show('error', this.blockedMessage);
                        }

                        return;
                    }

                    if (! day.selectable) return;

                    this.date = day.date;
                    this.time = '';

                    // Selecting is the end of the interaction. Left open it would sit
                    // under the time dropdown, which is what the customer looks at next.
                    this.closeDatePicker();
                },

                shiftMonth(delta) {
                    const cursor = new Date(this.calendarCursor);

                    cursor.setDate(1);
                    cursor.setMonth(cursor.getMonth() + delta);

                    this._calendarCursor = cursor;
                },

                canShiftBack() {
                    if (! this.minDate) return true;

                    /**
                 * The panel always opens on the month of `minDate` at the
                 * earliest, so paging back stops there rather than showing
                 * months of dates the server would refuse.
                 */
                    const min = new Date(this.minDate + 'T00:00:00');
                    const cursor = new Date(this.calendarCursor);

                    return cursor.getFullYear() > min.getFullYear()
                        || (cursor.getFullYear() === min.getFullYear() && cursor.getMonth() > min.getMonth());
                },

                canShiftForward() {
                    if (! this.maxDate) return true;

                    const max = new Date(this.maxDate + 'T00:00:00');
                    const cursor = new Date(this.calendarCursor);

                    return cursor.getFullYear() < max.getFullYear()
                        || (cursor.getFullYear() === max.getFullYear() && cursor.getMonth() < max.getMonth());
                },

                isoDate(day) {
                    const month = String(day.getMonth() + 1).padStart(2, '0');
                    const date = String(day.getDate()).padStart(2, '0');

                    return day.getFullYear() + '-' + month + '-' + date;
                },

                formatTime(value) {
                    const parts = String(value).split(':');
                    const hour = parseInt(parts[0], 10);
                    const minute = parts[1] || '00';
                    const suffix = hour >= 12 ? 'PM' : 'AM';
                    const display = hour % 12 === 0 ? 12 : hour % 12;

                    return display + ':' + minute + ' ' + suffix;
                },

                money(value) {
                    return '₱' + Number(value || 0).toLocaleString('en-PH', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    });
                },
            }));
        });
    </script>
@endpush
