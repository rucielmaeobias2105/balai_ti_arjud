<?php

namespace App\Http\Controllers\Customer;

use App\Enums\AppointmentStatus;
use App\Enums\TermsCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\DeleteAppointmentsRequest;
use App\Http\Requests\Customer\StoreBookingRequest;
use App\Models\Appointment;
use App\Models\BlockedSlot;
use App\Models\SalonSetting;
use App\Models\Service;
use App\Models\TermsAndCondition;
use App\Models\Technician;
use App\Services\BookingAvailability;
use App\Services\BookingService;
use App\Support\BadgeTone;
use App\Support\BookingHistory;
use App\Support\PriceFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    public function __construct(protected BookingService $booking) {}

    /**
     * Customer Flow 5 (part 1) — My Appointments / Transactions.
     *
     * A history table with a status filter. View, cancel and reschedule each open
     * a dialog on this page rather than navigating away, so the list has to carry
     * what those dialogs need:
     *
     *   - `$viewRows` — one payload per appointment, keyed by id, handed to the
     *     existing `appointmentViewer` Alpine component. Opening the View dialog
     *     then costs no request, the same way the admin Appointments dialog works.
     *   - `$cancelReasons` — the reason list, read from
     *     `CancelAppointmentController::REASONS` so the dialog and the standalone
     *     page cannot offer different wording. The policy text itself the dialog
     *     links out to `terms.show`, as the standalone page does.
     *   - `$rescheduleContext` — the slots endpoint, the salon week and the first
     *     bookable date, so the Reschedule dialog can populate its time dropdown
     *     from the server rather than hardcoding an hours window.
     *
     * None of that changes what the actions *do*: they still post to the same
     * routes with the same fields, and the standalone pages still exist and
     * still work.
     */
    public function index(Request $request): View
    {
        $status = $request->string('status')->toString();
        $status = array_key_exists($status, AppointmentStatus::filterOptions()) ? $status : 'all';

        $appointments = auth()->user()
            ->appointments()
            ->with('serviceLines', 'statusHistory', 'technician', 'preferredStylist')
            ->status($status)
            ->paginate(10)
            ->withQueryString();

        $availability = BookingAvailability::make();

        $viewRows = $this->viewPayloads($appointments);

        // The retired `/appointments/{id}` page redirects here with `?view=ID`, so
        // the dialog has to open on the one booking that was asked for. It is
        // normally on this page already, but a status filter or a different page
        // can leave it off, and a dialog that silently does nothing is worse than
        // a redirect that carries the booking with it. Resolved through the same
        // ownership-checked relation as the list, so a foreign id adds nothing.
        $requested = $request->integer('view') ?: null;

        if ($requested !== null && ! array_key_exists($requested, $viewRows)) {
            $extra = auth()->user()->appointments()
                ->with('serviceLines', 'statusHistory', 'technician', 'preferredStylist')
                ->find($requested);

            if ($extra) {
                $viewRows = array_merge($viewRows, $this->viewPayloads(collect([$extra])));
            }
        }

        return view('customer.appointments.index', [
            'appointments' => $appointments,
            'status' => $status,
            'statusOptions' => AppointmentStatus::filterOptions(),
            'counts' => auth()->user()->appointments()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
            'viewRows' => $viewRows,
            'openView' => $requested,
            // Drives whether the table grows a select column at all. A page of
            // live bookings has nothing to tick, and a checkbox that cannot be
            // ticked is worse than no checkbox.
            'deletableCount' => $appointments->getCollection()
                ->filter(fn (Appointment $appointment) => $appointment->canBeDeletedByCustomer())
                ->count(),
            'cancelReasons' => CancelAppointmentController::REASONS,
            'rescheduleContext' => [
                'endpoint' => route('appointments.slots'),
                'hours' => SalonSetting::current()->operating_hours ?? [],
                'date' => $availability->firstBookableDate()->toDateString(),
                'minDate' => $availability->firstBookableDate()->toDateString(),
                'maxDate' => $availability->lastBookableDate()->toDateString(),
            ],
        ]);
    }

    /**
     * One row per appointment for the View dialog, keyed by id.
     *
     * Field names are the dialog's, not the model's, so a payload can be read
     * without knowing the appointment schema — which is what lets the same Alpine
     * component drive the admin's booking dialog.
     *
     * Money is formatted here rather than in the view so the dialog and the
     * table cannot disagree about how a peso amount reads. The same goes for
     * every status label and badge tone: both are derived from the enums here,
     * so the dialog cannot paint a Pending booking with the Cancelled tint just
     * because the class name was hardcoded in the markup.
     *
     * `statusHistory` is capped at the most recent few entries. The dialog shows
     * it as a compact footnote, not as a timeline, and a long-running booking
     * would otherwise ship every transition it ever had inside the page's JSON.
     *
     * Accepts a paginator or a plain collection: the list passes its page of
     * rows, and the `?view=ID` fallback passes a single appointment it had to
     * fetch separately.
     *
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection<int, Appointment>  $appointments
     * @return array<int, array<string, mixed>>
     */
    protected function viewPayloads($appointments): array
    {
        $rows = $appointments instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator
            ? $appointments->getCollection()
            : $appointments;

        return $rows->mapWithKeys(fn (Appointment $appointment) => [
            $appointment->id => [
                // The dialog needs its own id to build the Book Again link from.
                // `appointmentViewer` is shared with the admin list, which has no
                // such link, so the id travels in the payload rather than being
                // read off the row that opened the dialog.
                'id' => $appointment->id,
                'reference' => $appointment->reference_number,
                'status' => $appointment->status->value,
                'statusLabel' => $appointment->status->label(),
                'statusBadge' => BadgeTone::classFor($appointment->status->badge()),
                'date' => $appointment->preferred_date->format('M j, Y'),
                'time' => $appointment->getTimeLabelAttribute(),
                'technician' => $appointment->technicianLabel(),
                'customer' => $appointment->customer_name,
                'phone' => $appointment->customer_phone,
                'email' => $appointment->customer_email,
                'total' => number_format((float) $appointment->total_amount, 2),
                // `downPayment`, `downPaymentStatus`, `downPaymentBadge` and
                // `downPaymentReference` are gone from this payload. The
                // Appointment Details dialog no longer shows a Down Payment
                // figure, a Payment status badge, a GCash reference or the note
                // about manual verification, so shipping four keys into the page
                // for a panel that reads none of them was carrying the same
                // dead-payment story in a second place.
                'specialRequest' => $appointment->special_request,
                'allergies' => $appointment->allergies,
                'cancellationReason' => $appointment->cancellation_reason,
                'services' => $appointment->serviceLines->map(fn ($line) => [
                    'name' => $line->display_name,
                    // The advertised price at the time of booking, which may be a
                    // range; `total` is the amount that was charged.
                    'price' => PriceFormatter::display($line->display_price, '—'),
                    'total' => number_format((float) $line->line_total, 2),
                    'quantity' => (int) $line->quantity,
                    // No `duration`. It was here to render "1 × 0 min" under each
                    // service in the details dialog, and the durations on these
                    // lines are 0 because the column was never filled in — so the
                    // key only ever produced a zero that had to be hidden again
                    // in the view. See the note on that line in
                    // resources/views/customer/appointments/index.blade.php.
                ])->values()->all(),
                'statusHistory' => $appointment->statusHistory
                    ->take(5)
                    ->map(fn ($entry) => [
                        'label' => $entry->arrow,
                        'at' => $entry->created_at->format('M j, Y g:i A'),
                        'actor' => $entry->actorLabel(),
                        'note' => $entry->note,
                    ])->values()->all(),
            ],
        ])->all();
    }

    /**
     * The Appointment Details dialog, not a page of its own.
     *
     * There is no standalone detail screen any more: the dialog on My
     * Appointments carries everything this one did — reference, schedule,
     * payment summary, services, notes and status history — and does it without
     * a page load. Rather than 404 an old bookmark or a notification link, the
     * route redirects to the list with the dialog already open, so both arrive
     * at the same place and see the same data.
     */
    public function show(Appointment $appointment): RedirectResponse
    {
        $this->authorizeOwnership($appointment);

        return redirect()->route('appointments.index', ['view' => $appointment->id]);
    }

    /**
     * One settled appointment, removed from the customer's own list.
     *
     * A soft delete: the row stays for the salon's own records, and the
     * reference number still resolves for the admin panel. What the customer
     * loses is the row on their page, which is the point — a finished visit is
     * clutter once it has been dealt with.
     *
     * Refused unless `canBeDeletedByCustomer()`. The check is here rather than
     * only in the view because the button is not the only way in: a crafted
     * DELETE must not be able to remove a pending booking.
     */
    public function destroy(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorizeOwnership($appointment);

        abort_unless(
            $appointment->canBeDeletedByCustomer(),
            403,
            'Only completed or cancelled appointments can be deleted.',
        );

        $appointment->delete();

        return redirect()
            ->route('appointments.index')
            ->with('status', "Appointment {$appointment->reference_number} deleted.");
    }

    /**
     * The ticked set, from the "Delete selected" button.
     *
     * Two rules the bulk path enforces that the single-row path gets for free:
     *
     *   - ownership, because `whereKey()` runs against this customer's own
     *     relation rather than the table, so a foreign id matches no row; and
     *   - the same settled-status gate, re-checked in the query rather than
     *     trusted from the payload. A ticked set is still just a list of ids
     *     from the client, and the checkboxes being hidden on a live booking is
     *     a convenience, not a guarantee.
     *
     * The count in the flash comes back from the delete, not from `count($ids)`,
     * so it reports what was removed rather than what was asked for — which is
     * how a payload mixing deleted and refused ids tells the customer the truth.
     */
    public function destroyMany(DeleteAppointmentsRequest $request): RedirectResponse
    {
        $deletable = $request->user()
            ->appointments()
            ->whereKey($request->validated('ids'))
            ->whereIn('status', $this->deletableStatuses())
            ->get();

        $removed = 0;

        foreach ($deletable as $appointment) {
            $appointment->delete();
            $removed++;
        }

        return redirect()
            ->route('appointments.index')
            ->with('status', $this->deletedMessage($removed, $request->validated('ids')));
    }

    /**
     * The statuses a customer is allowed to clear off their own list.
     *
     * @return array<int, string>
     */
    protected function deletableStatuses(): array
    {
        return [
            AppointmentStatus::Completed->value,
            AppointmentStatus::Cancelled->value,
        ];
    }

    /**
     * `1 appointment deleted.` / `4 appointments deleted.`
     *
     * Counted in words so the two forms cannot collide into one ambiguous
     * string. When a payload asked for more than could be deleted, the refused
     * ones are named — silently deleting three of five and saying "3 deleted"
     * leaves the customer thinking the other two are still there when they are
     * not, or that the button is unreliable.
     */
    protected function deletedMessage(int $removed, array $requested): string
    {
        $message = $removed.' appointment'.($removed === 1 ? '' : 's').' deleted.';

        $refused = count($requested) - $removed;

        if ($refused > 0) {
            $message .= ' '.$refused.' could not be deleted — only completed or cancelled appointments can be.';
        }

        return $message;
    }

/**
     * Book Again — repeat a finished visit.
     *
     * A redirect rather than a page of its own, for the same reason
     * `appointments.show` is: the booking form is the destination, and there is no
     * second screen to keep in step with it. The redirect lands on
     * `appointments.create?from=ID`, which pre-fills the basket with the services
     * from that visit and nothing else.
     *
     * What is *not* carried over is the point. The date and time are left empty and
     * the technician is not pre-selected, because the customer is booking a new
     * appointment and a past one says nothing about when they want to come back or
     * whether they want the same person. Pre-filling those would make it far too
     * easy to confirm a booking that lands on a date the customer never meant.
     *
     * Only Completed appointments can be repeated. Cancelled ones have no settled
     * basket worth copying, and Pending or Confirmed is a visit that has not
     * happened — repeating it is Reschedule, which already exists and which the
     * status rules below keep pointing at.
     */
    public function bookAgain(Appointment $appointment): RedirectResponse
    {
        $this->authorizeOwnership($appointment);

        abort_unless(
            $appointment->status === AppointmentStatus::Completed,
            403,
            'Only a completed appointment can be booked again.',
        );

        return redirect()->route('appointments.create', ['from' => $appointment->id]);
    }

    /**
     * The finished appointment `?from=` names, if it is one this customer may
     * repeat.
     *
     * Null rather than a 403 for a foreign or unfinished id: `bookAgain()` is the
     * guarded way in, and this is the fallback for a pasted or stale URL. Sending
     * somebody to an error page about a booking they were never shown would leak
     * that it exists; dropping the parameter and showing an ordinary empty booking
     * form leaks nothing and does the job.
     */
    protected function appointmentToRepeat(Request $request): ?Appointment
    {
        $id = $request->integer('from') ?: null;

        if ($id === null || auth()->id() === null) {
            return null;
        }

        return auth()->user()->appointments()
            ->whereKey($id)
            ->where('status', AppointmentStatus::Completed->value)
            ->first();
    }

    /**
     * Pre-select the basket from a past visit.
     *
     * Exact ids, not slugs: `preselectFromRequest()` matches on slug and then takes
     * whichever variant is flagged default, which for a repeat booking is the wrong
     * answer whenever the customer chose a non-default variant the first time. The
     * line is copied across as it was booked.
     *
     * A line whose service has since been retired or deactivated is skipped rather
     * than refused — the form cannot offer something the catalogue no longer lists,
     * and one stale line should not cost the customer the rest of their basket. If
     * nothing survives, the form opens empty, which is the same as arriving at
     * `/book` by hand.
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Service>>  $grouped
     * @return array<string, array{service_id: int, service_variant_id: ?int, quantity: int}>
     */
    protected function preselectFromAppointment(Appointment $appointment, $grouped): array
    {
        $bookable = $grouped->flatten()->keyBy('id');

        $selected = [];

        foreach ($appointment->serviceLines as $line) {
            $service = $bookable->get($line->service_id);

            if (! $service) {
                continue;
            }

            /*
             * The variant has to belong to this service and still exist. A line can
             * outlive its variant — variants are deleted, and the appointment keeps
             * the name it was booked under — and pre-selecting an id the form cannot
             * render would produce a basket that fails `StoreBookingRequest`.
             */
            $variantId = $line->service_variant_id
                && $service->variants->contains('id', $line->service_variant_id)
                    ? $line->service_variant_id
                    : null;

            $selected[$service->id] = [
                'service_id' => $service->id,
                'service_variant_id' => $variantId,
                'quantity' => max(1, (int) $line->quantity),
            ];
        }

        return $selected;
    }

    /**
     * Customer Flow 5 (part 2) — Book Appointment form.
     *
     * `?from=ID` is Book Again: the same form, pre-filled with the services from a
     * past visit. `AppointmentController::bookAgain()` is what sends the customer
     * here; this method does the work, so the pre-fill logic has one home whether
     * it was reached through the button or a pasted URL.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $availability = BookingAvailability::make();
        $settings = SalonSetting::current();

        $services = Service::query()
            ->active()
            ->with('variants')
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->groupBy('category');

        /*
         * Book Again's source appointment, if the URL names one.
         *
         * Read before `preselectFromRequest()` so a `?services[]=` on the same
         * request cannot quietly override the appointment the customer asked to
         * repeat — the button is the more specific instruction.
         *
         * A source that is not this customer's, or is not finished, is discarded
         * rather than refused. `bookAgain()` refuses both at the door; a hand-typed
         * `?from=` should land on an empty ordinary form, not an error page about
         * a booking the customer was never shown.
         */
        $previous = $this->appointmentToRepeat($request);

        $selected = $previous
            ? $this->preselectFromAppointment($previous, $services)
            : $this->preselectFromRequest($request, $services);

        /*
         * A booking needs at least one valid service, and services are now chosen
         * on the Services page rather than inside this form. Opening `/book` by
         * hand or with only retired/sold-out slugs therefore goes back to the
         * catalogue instead of rendering a form whose summary can never be filled.
         */
        if ($selected === []) {
            return redirect()
                ->to(route('services.index').'#services')
                ->with('toast', [
                    'type' => 'warning',
                    'message' => 'Please select at least one service before booking.',
                ]);
        }

        /*
         * Only the chosen services are sent to the view. The picker is gone, so
         * the old full catalogue would be a page-sized JSON payload the summary
         * cannot use.
         */
        $selectedServices = $services
            ->map(fn ($group) => $group->filter(fn ($service) => isset($selected[$service->id])))
            ->filter(fn ($group) => $group->isNotEmpty());

        // Default to the next day that actually has availability.
        $prefilledDate = $request->input('date') ?: $this->nextAvailableDate($availability);

        /*
         * Step 5 of the form — "Last Service(s) Availed" — asks a different
         * question of a first-time customer than of a returning one.
         *
         * A first-timer has no record here, so the salon cannot know what they
         * had done before and has to ask. A returning customer does have a
         * record, and asking them to retype it is asking them to do the salon's
         * bookkeeping: they either remember, in which case they are guessing at
         * what the system already holds, or they do not, in which case the answer
         * is worse than the truth.
         *
         * So the two cases get different fields rather than the same field shown
         * more or less prominently. Repeat customers are shown their own history
         * and it is submitted for them; first-time customers get the manual entry
         * the step has always had, and it is required of them.
         *
         * The rule and the records both come from `BookingHistory`, which is the
         * same helper `StoreBookingRequest` asks when deciding whether this
         * answer is required — a form that showed one thing while the server
         * demanded another would fail every submission from whichever kind of
         * customer it got wrong.
         */
        $recentServices = BookingHistory::recentServices();

        return view('customer.appointments.create', [
            'settings' => $settings,
            'groupedServices' => $selectedServices,
            'selected' => $selected,
            // The technicians a customer may pick. Active only: switching
            // somebody off in the panel is how "not taking bookings today" is
            // expressed, and it must not remove them from the picker.
            'technicians' => Technician::active(),
            'availability' => $availability,
            'prefilledDate' => $prefilledDate,
            'slots' => $availability->availableSlots($prefilledDate),
            'terms' => TermsAndCondition::publishedFor(TermsCategory::Booking),
            // Kept for the step 5 history list, which quotes the visit each
            // service came from.
            'history' => BookingHistory::completedAppointments(),
            // A guest has never booked here, so a guest is always a first-timer —
            // which is why this is false rather than absent for them, and why the
            // required manual field shows.
            'isRepeatCustomer' => $recentServices !== [],
            'recentServices' => $recentServices,
            'total' => $this->totalFor($selected, $availability),
        ]);
    }

    /**
     * First date within the booking horizon that still has an open slot.
     */
    protected function nextAvailableDate(BookingAvailability $availability): string
    {
        $cursor = $availability->firstBookableDate();
        $last = $availability->lastBookableDate();

        while ($cursor->lte($last)) {
            if ($availability->availableSlots($cursor->toDateString()) !== []) {
                return $cursor->toDateString();
            }

            $cursor->addDay();
        }

        return now()->format('Y-m-d');
    }

    /**
     * Customer Flow 5 (part 2) — Store booking, then show the summary.
     *
     * The idempotency key is checked here, before any work, so a repeated
     * submission is answered with the booking it already produced rather than
     * creating a second one. `BookingService` re-checks behind a unique index for
     * the case two requests arrive together; this is the cheap path for the common
     * one, which is a double-tap on a form that is already on its way to the
     * server.
     *
     * The response is deliberately the same redirect and the same wording as a
     * first submission. A customer who double-tapped has done nothing wrong, and
     * telling them "duplicate ignored" would raise a question they have no way to
     * answer — where the extra booking went, or which of the two references is
     * real. They get their booking, once.
     */
    public function store(StoreBookingRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($existing = $this->booking->resolveIdempotent(
            $request->validated('idempotency_key'),
            $user?->id,
        )) {
            return redirect()
                ->route('appointments.index', ['view' => $existing->id])
                ->with('status', 'Your booking request has been submitted. Reference: '.$existing->reference_number);
        }

        $lines = $this->booking->resolveLines($request->input('services', []));

        $appointment = $this->booking->create(
            $request->safe()->except(['services', 'agree_terms']) + [
                'customer_email' => $user?->email,
            ],
            $lines,
            $user?->id,
        );

        if (! auth()->check()) {
            // Guests keep the reference number so they can follow up.
            session(['last_booking_reference' => $appointment->reference_number]);
        }

        return redirect()
            ->route('appointments.index', ['view' => $appointment->id])
            ->with('status', 'Your booking request has been submitted. Reference: '.$appointment->reference_number);
    }

/**
     * JSON slot lookup backing the booking form's date picker.
     *
 * `service_id` is still accepted, and still ignored: no rule depends on which
 * service is in the basket, so the map of unavailable dates does not vary by it.
     * Kept rather than dropped because the client sends it on every call, and a
     * request that no longer validated would 422 instead of answering.
     *
     * The response answers three separate questions about a date, and they are
     * separate keys because the form treats them differently:
     *
     *   `slots`        — times a customer may pick, with taken ones and blocked
     *                    ones already removed;
     *   `blockedSlots` — the times an admin blocked, so the dropdown can show them
     *                    struck through instead of silently missing;
     *   `wholeDay`     — the date itself is closed.
     *
     * `blockedSlots` is why the blocked times are not simply absent. A customer who
     * sees 9:00 and 10:30 but not 10:00 cannot tell whether 10:00 is taken, is
     * blocked, or was never offered; showing it disabled says "not today, but the
     * day is".
     */
    public function slots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')],
        ]);

        $availability = BookingAvailability::make();
        $date = $data['date'];

        return response()->json([
            'date' => $date,
            'slots' => $availability->availableSlots($date),
            'blockedSlots' => $availability->blockedTimes($date),
            'open' => $availability->isDateAvailable($date),
            'wholeDay' => $availability->isDateBlocked($date),
            'problems' => $availability->dateProblems($date),

            /*
             * Minimum notice has to be enforced by the input's own `min` too; this
             * lets the form say why a date is refused without a round trip.
             */
            'minDate' => $availability->firstBookableDate()->toDateString(),
            'maxDate' => $availability->lastBookableDate()->toDateString(),

            /*
             * Every whole day closed inside the window, so the form's calendar can
             * grey them out.
             *
             * Sent for the whole bookable range rather than the visible month: the
             * range is bounded by `booking_lead_days` and is at most a year of
             * dates, and a customer paging the calendar past the edge of the
             * already-loaded map should get the real answer rather than a day that
             * looks free.
             */
            'blockedDates' => $this->blockedDatesPayload($availability),
        ]);
    }

    /**
     * Whole days closed, keyed by date.
     *
     * @return array<string, string|null>
     */
    protected function blockedDatesPayload(BookingAvailability $availability): array
    {
        return collect(BlockedSlot::wholeDaysBetween(
            $availability->firstBookableDate(),
            $availability->lastBookableDate(),
        ))->map(fn (BlockedSlot $slot) => $slot->reason)->all();
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Pre-select services passed via the query string (?services[]=slug).
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Service>>  $grouped
     * @return array<string, array{service_id: int, service_variant_id: ?int, quantity: int}>
     */
    protected function preselectFromRequest(Request $request, $grouped): array
    {
        $selected = [];
        $raw = $request->input('services', []);

        if (is_string($raw)) {
            $slugs = array_values(array_filter(array_map('trim', explode(',', $raw))));
        } else {
            $slugs = [];
            foreach ((array) $raw as $item) {
                if (is_string($item) && str_contains($item, ',')) {
                    foreach (explode(',', $item) as $part) {
                        $part = trim($part);
                        if ($part !== '') {
                            $slugs[] = $part;
                        }
                    }
                } elseif (is_scalar($item)) {
                    $slugs[] = trim((string) $item);
                }
            }
        }

        foreach ($grouped->flatten() as $service) {
            if ($service->isUnavailable()) {
                continue;
            }

            $bookable = $service->base_price !== null
                || $service->variants->contains(fn ($v) => $v->base_price !== null);

            if (! $bookable) {
                continue;
            }

            if (in_array($service->slug, $slugs, true) || in_array((string) $service->id, array_map('strval', $slugs), true)) {
                $selected[$service->id] = [
                    'service_id' => $service->id,
                    'service_variant_id' => $service->variants->firstWhere('is_default', true)?->id
                        ?? $service->variants->first()?->id,
                    'quantity' => 1,
                ];
            }
        }

        return $selected;
    }

    /**
     * @param  array<string, array{service_id: int, service_variant_id: ?int, quantity: int}>  $selected
     */
    protected function totalFor(array $selected, BookingAvailability $availability): float
    {
        $lines = $this->booking->resolveLines(array_values($selected));

        return $this->booking->totalFor($lines);
    }

    protected function authorizeOwnership(Appointment $appointment): void
    {
        abort_unless(
            auth()->id() !== null && $appointment->user_id === auth()->id(),
            403,
            'This appointment belongs to another account.',
        );
    }
}
