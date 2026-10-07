<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Enums\ChangedBy;
use App\Enums\DownPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RestoreAppointmentsRequest;
use App\Models\Admin;
use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Technician;
use App\Notifications\AppointmentCancelledByAdminNotification;
use App\Notifications\AppointmentCompletedNotification;
use App\Notifications\AppointmentConfirmedNotification;
use App\Notifications\AppointmentInProgressNotification;
use App\Notifications\AppointmentRescheduledNotification;
use App\Services\AppointmentArchiver;
use App\Services\BookingAvailability;
use App\Services\BookingService;
use App\Support\PriceFormatter;
use App\Support\SendsNotificationsQuietly;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    use SendsNotificationsQuietly;

    public function __construct(
    protected BookingService $booking,
    protected AppointmentArchiver $archiver,
) {}

    /**
     * Admin Flow 3 — Appointment Management (list + filters).
     *
     * The working list: un-archived rows only. Archived bookings are not missing
     * data, they are filed elsewhere — `archived()` — so an admin who wants them
     * follows one link rather than filtering a growing list by a flag.
     *
     * Opening this page is also what marks the bookings on it as seen.
     *
     * The sidebar badge and the tab title's "(n)" are built from unseen bookings
     * rather than Pending ones, and this is where "unseen" stops being true. It
     * happens here, in the action, and first — before any query below runs and
     * long before the layout renders — so the very first response already has no
     * badge and no title prefix on it. Nothing about that depends on JavaScript,
     * a later fetch, or the order two pieces of markup happen to render in: by
     * the time the sidebar partial asks how many bookings are unseen, the answer
     * is genuinely zero.
     *
     * Server-side because the alternative is a badge that forgets. The admin
     * reloads, or navigates to Reports and comes back, and a count that lived in
     * a `sessionStorage` key or a one-shot DOM tweak would be back on the page.
     */
    public function index(Request $request): View
    {
        Appointment::markUnseenAsSeenForAdmin();

        $filters = $request->only(['search', 'status', 'from', 'to']);

        $appointments = Appointment::query()
            ->active()
            ->with(['serviceLines', 'user', 'technician', 'preferredStylist'])
            ->status($filters['status'] ?? null)
            ->betweenDates($filters['from'] ?? null, $filters['to'] ?? null)
            ->when($filters['search'] ?? null, function ($q, $term) {
                $like = '%'.str_replace('%', '\%', $term).'%';

                $q->where(fn ($inner) => $inner
                    ->where('reference_number', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)
                    ->orWhere('customer_phone', 'like', $like)
                    ->orWhere('customer_email', 'like', $like));
            })
            ->orderByDesc('preferred_date')
            ->orderByDesc('preferred_time')
            ->paginate(15)
            ->withQueryString();

        $rows = $appointments->getCollection();

        return view('admin.appointments.index', [
            'appointments' => $appointments,
            'filters' => $filters,
            'statusOptions' => AppointmentStatus::options(),
            'counts' => $this->statusCounts(),
            // The two dropdown/dialog payloads, keyed by appointment id. Both
            // are rendered into the page once instead of being fetched per row:
            // the list already eager-loads the service lines, so the View dialog
            // opens without a request and the status menu needs no endpoint
            // beyond the one it posts to.
            'statusChoices' => $rows->mapWithKeys(
                fn (Appointment $appointment) => [$appointment->id => $this->statusChoices($appointment)]
            ),
            'viewRows' => $rows->mapWithKeys(
                fn (Appointment $appointment) => [$appointment->id => $this->viewRow($appointment)]
            ),
        ]);
    }

    /**
     * The Archived view: settled bookings that have left the working list.
     *
     * Its own screen rather than a filter on the main list, and the distinction is
     * deliberate. Archiving is a filing decision, not a filter — a row that is
     * archived is out of the queue whatever you search for, so offering it as one
     * of the main list's filters would suggest it is still in it. This screen is
     * read-mostly: the only thing it offers is putting a row back, plus the same
     * permanent delete the working list has for settled rows.
     *
     * Filtered to settled statuses as well as to `archived_at`. The two
     * conditions overlap in practice — the sweep and the manual archive both refuse
     * live bookings — but stating it means a row cannot be un-archived into a
     * status nobody should be archiving, which is the one way this view could
     * produce an inconsistent list.
     */
    public function archived(Request $request): View
    {
        $appointments = Appointment::query()
            ->archived()
            ->whereIn('status', AppointmentStatus::settledValues())
            ->with(['serviceLines', 'user', 'technician', 'preferredStylist', 'archivedBy'])
            ->when($request->string('search')->toString(), function ($query, $term) {
                $like = '%'.str_replace('%', '\%', $term).'%';

                $query->where(fn ($inner) => $inner
                    ->where('reference_number', 'like', $like)
                    ->orWhere('customer_name', 'like', $like));
            })
            // Newest first, by when it was archived rather than by when the visit
            // was: this screen is read as a log of filing, so the row you most
            // recently dealt with is the one you want to find.
            ->orderByDesc('archived_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $rows = $appointments->getCollection();

        return view('admin.appointments.archived', [
            'appointments' => $appointments,
            'search' => $request->input('search'),
            'viewRows' => $rows->mapWithKeys(
                fn (Appointment $appointment) => [$appointment->id => $this->viewRow($appointment)]
            ),
        ]);
    }

    /**
     * Move one booking out of the working list, by hand.
     *
     * Independent of the 30-day rule in both directions: the rule sweeps by itself
     * each night, and this takes effect the moment it is pressed. `archived_by` is
     * recorded, which is what later tells an admin apart from the sweep when they
     * read the Archived view.
     *
     * Refuses a live booking with a 403 rather than a toast. The row action is not
     * rendered for those rows, but the button being absent is a view decision and
     * the server is what has to hold.
     */
    public function archive(Request $request, Appointment $appointment): RedirectResponse
    {
        abort_unless(
            $appointment->canBeArchivedByAdmin(),
            403,
            'Only completed or cancelled appointments can be archived.',
        );

        $this->archiver->archive($appointment, $request->user('admin')?->id);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Booking {$appointment->reference_number} archived.",
        ]);
    }

    /**
     * Put an archived booking back in the working list.
     *
     * Clearing both columns rather than just `archived_at`: leaving `archived_by`
     * set would record an admin as having archived a row that is now back in the
     * queue, which is a history that contradicts itself.
     */
    public function restore(Request $request, Appointment $appointment): RedirectResponse
    {
        abort_if($appointment->isArchived() === false, 404);

        $appointment->forceFill([
            'archived_at' => null,
            'archived_by' => null,
        ])->save();

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Booking {$appointment->reference_number} restored to the list.",
        ]);
    }

    /**
     * Put several archived bookings back in the working list at once.
     *
     * The bulk twin of `restore()`, and deliberately the same write: clear both
     * columns, so a restored row does not keep naming the admin who archived it.
     * One query rather than a loop, because the archive screen's select-all can
     * name a whole page of rows and a per-row save would issue a query each.
     *
     * The guard that the ids are all archived lives in the Form Request, not
     * here — a POST can name any id in the table, so "only rows that are
     * actually archived" has to be enforced before the update is built, not
     * filtered afterwards.
     */
    public function restoreMany(RestoreAppointmentsRequest $request): RedirectResponse
    {
        $ids = $request->appointmentIds();

        $count = Appointment::query()
            ->whereIn('id', $ids)
            ->whereNotNull('archived_at')
            ->update([
                'archived_at' => null,
                'archived_by' => null,
                'updated_at' => now(),
            ]);

        $toast = [
            'type' => 'success',
            'message' => trans_choice(
                '{1} :count booking restored to the list.|[2,*] :count bookings restored to the list.',
                $count,
                ['count' => $count],
            ),
        ];

        /*
         * Always back to the archive, never `back()`.
         *
         * This endpoint is only reachable from the archive screen, and the rows
         * it acted on have just left it — so `back()` would land on a list that
         * no longer contains what was restored, which reads as the restore
         * having failed. Redirecting to a known-good screen is also why this
         * needs no hidden "where did you come from" field: a crafted POST
         * cannot use the endpoint to bounce an admin somewhere unexpected.
         */
        return redirect()
            ->route('admin.appointments.archived')
            ->with('toast', $toast);
    }

    /**
     * Delete a booking outright.
     *
     * The same settled-status gate as archiving, and independent of it: an admin
     * can permanently remove a completed or cancelled booking whether or not it
     * was archived first. An in-progress, pending or confirmed booking cannot be
     * deleted by any path — not from this row, not from the Archived view, not
     * from a crafted request.
     *
     * A soft delete, so the row survives in the database and the revenue reports
     * keep reading it. "Not permanently deleted unless explicitly deleted by admin"
     * is satisfied here by making this an explicit, confirmed action; the hard
     * delete is `forceDelete()`, which nothing in the panel calls.
     */
    public function destroy(Request $request, Appointment $appointment): RedirectResponse
    {
        abort_unless(
            $appointment->canBeDeletedByAdmin(),
            403,
            'Only completed or cancelled appointments can be deleted.',
        );

        $reference = $appointment->reference_number;

        $this->archiver->destroy($appointment);

        return redirect()
            ->route('admin.appointments.index')
            ->with('toast', [
                'type' => 'success',
                'message' => "Booking {$reference} deleted.",
            ]);
    }

    /**
     * The list's status dropdown: where the booking is now, then everywhere it
     * can legally go from there.
     *
     * The first option is the current status and is not selectable — it is the
     * selected value, which is what the admin reads off the row.
     *
     * The control is never disabled. Every status has at least one legal way
     * out (AppointmentStatus::MENUS), so there is always something to pick; an
     * earlier version made `completed` terminal and disabled the select on
     * those rows, which rendered as a dead control rather than as an empty menu.
     *
     * @return array<int, array{value: string, label: string, selectable: bool}>
     */
    protected function statusChoices(Appointment $appointment): array
    {
        $current = $appointment->status;

        $choices = [[
            'value' => $current->value,
            'label' => $current->label(),
            'selectable' => false,
        ]];

        foreach ($current->menu() as $value => $label) {
            $choices[] = ['value' => $value, 'label' => $label, 'selectable' => true];
        }

        return $choices;
    }

    /**
     * Read-only detail behind the list's View dialog.
     *
     * @return array<string, mixed>
     */
    protected function viewRow(Appointment $appointment): array
    {
        $appointment->loadMissing('serviceLines');

        return [
            'id' => $appointment->id,
            'customer' => trim($appointment->customer_name.' · '.$appointment->customer_phone),
            // Who is serving it, resolved the same way the list resolves it, so
            // the dialog can never disagree with the row it was opened from —
            // including the legacy stylist fallback and "No preference".
            'technician' => $appointment->technicianLabel(),
            'services' => $appointment->serviceLines->map(fn (AppointmentService $line) => [
                'name' => $line->display_name,
                // No duration. This read `$line->duration_minutes.' min · Qty '`,
                // which rendered "0 min · Qty 1" on every booking whose line never
                // had a duration filled in — a zero presented as a measurement.
                // The quantity and the price are real; the duration was not, so it
                // is not here to be hidden in the view either.
                'meta' => 'Qty '.$line->quantity
                    // The advertised price at booking time, which may be a
                    // range; `total` below is the amount actually charged.
                    .' · '.PriceFormatter::display($line->display_price).' each',
                'total' => '₱'.number_format($line->line_total, 2),
            ])->all(),
            'total' => '₱'.number_format((float) $appointment->total_amount, 2),
            'date' => $appointment->preferred_date->format('l, M j, Y'),
            'time' => $appointment->time_label,
            // Whatever the customer told us at booking time, under the label the
            // booking form gave each field.
            'notes' => collect([
                'Allergies' => $appointment->allergies,
                'Special Request' => $appointment->special_request,
                'Last Service(s) Availed' => $appointment->last_services_availed,
            ])
                ->filter(fn ($value) => filled($value))
                ->map(fn ($value, $label) => ['label' => $label, 'value' => $value])
                ->values()
                ->all(),
        ];
    }

    /**
     * One row's live state, for the polling endpoint below.
     *
     * Everything the browser needs to redraw a single row without being sent back
     * to the server for the page: the status it is in, the menu from there, and
     * which row actions that status permits.
     *
     * `canArchive`/`canDelete` are the model's own answers, not the view's, so a
     * row the list chose not to offer Archive for and a row the poll decides not
     * to offer it for are decided by the same rule. The routes re-check those
     * answers regardless; this only keeps what is on screen honest.
     *
     * @return array<string, mixed>
     */
    protected function rowState(Appointment $appointment): array
    {
        return [
            'status' => $appointment->status->value,
            'label' => $appointment->status->label(),
            // The class suffix the list tints the dropdown with, so a row can be
            // recoloured as well as re-read. Sent as data rather than as a class
            // name to apply: the server decides what the status is, and the
            // browser composes the class from that answer.
            'badge' => $appointment->status->badge(),
            // Whether the dropdown itself is dead. Sent alongside the row's
            // actions because they are the same status read twice, and a repaint
            // that re-enabled the control on a completed row would offer a move
            // the route refuses.
            'locked' => $appointment->status->isLocked(),
            'choices' => $this->statusChoices($appointment),
            'can_archive' => $appointment->canBeArchivedByAdmin(),
            'can_delete' => $appointment->canBeDeletedByAdmin(),
        ];
    }

    /**
     * How many active bookings sit in each status — the numbers on the status
     * tabs.
     *
     * Extracted rather than repeated, so the page and the two JSON answers cannot
     * drift: a tab counting differently from the list it filters would send an
     * admin to an empty page and call it "no appointments".
     *
     * @return array<string, int>
     */
    protected function statusCounts(): array
    {
        return Appointment::query()
            ->active()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * The live state of every row on the page, for the list's poller.
     *
     * Scoped to the rows actually on screen rather than to the whole table: the
     * caller sends the ids it rendered, and this answers only for those. That
     * keeps a filtered or paginated list from being told about bookings it is not
     * showing, and keeps one row's actions from being driven by another page's
     * data.
     *
     * Ids the caller does not own are dropped by `whereIn`, so a crafted list of
     * ids narrows the answer rather than widening it — the row states carry no
     * customer detail, and the endpoint is behind the same ability gate as the
     * list it feeds.
     */
    public function rowStates(Request $request): JsonResponse
    {
        $ids = collect($request->query('ids', []))

            // Only the shape the list itself produces. Anything else is dropped
            // rather than coerced, so this cannot be turned into a way of
            // scanning for ids that happen to exist.
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            // The page renders fifteen rows. A longer list is a crafted request,
            // and answering it would mean reading the table to serve a response
            // no page can use.
            ->take(60)
            ->values();

        if ($ids->isEmpty()) {
            return response()->json(['rows' => [], 'counts' => $this->statusCounts()]);
        }

        $rows = Appointment::query()
            ->active()
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (Appointment $appointment) => [
                $appointment->id => $this->rowState($appointment),
            ]);

        // The tab counts ride along with the rows for the same reason they ride
        // along with a save: one poll that keeps the whole list honest, rather
        // than a second request for two numbers.
        return response()->json([
            'rows' => $rows,
            'counts' => $this->statusCounts(),
        ]);
    }

    public function show(Appointment $appointment): View
    {
        $appointment->load(['serviceLines', 'user', 'technician', 'preferredStylist', 'statusHistory']);

        return view('admin.appointments.show', [
            'appointment' => $appointment,
            'statusOptions' => AppointmentStatus::options(),
            'downPaymentOptions' => DownPaymentStatus::options(),
        ]);
    }

    public function edit(Appointment $appointment): View
    {
        $appointment->load(['serviceLines', 'user', 'technician', 'preferredStylist']);

        return view('admin.appointments.edit', [
            'appointment' => $appointment,
            'statusOptions' => AppointmentStatus::options(),
            // Every technician, not just the active ones: the booking in front
            // of the admin has to stay assignable even if that person has since
            // been switched off.
            'technicians' => Technician::query()->orderBy('name')->get(),
        ]);
    }

    /**
     * Change an appointment's status — the list's dropdown and the Manage
     * screen's buttons both land here.
     *
     * Records an appointment_status_history row and notifies the customer. The
     * menu the admin picks from is rendered from AppointmentStatus::TRANSITIONS
     * and the same table is re-checked below, so a hand-built POST cannot skip
     * a step the UI would not have offered.
     *
     * Answers JSON to the dropdown (so a refused transition leaves the page
     * standing) and redirects to a toast for everything else, which keeps the
     * control working as a plain form submission with JavaScript switched off.
     */
    public function updateStatus(Request $request, Appointment $appointment): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(AppointmentStatus::actionKeys())],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $target = AppointmentStatus::resolveAction($data['status']);
        $previous = $appointment->status;

        if (! $previous->canTransitionTo($target)) {
            $message = "An appointment that is {$previous->label()} cannot be marked as {$target->label()}.";

            return $request->expectsJson()
                ? response()->json(['message' => $message], 422)
                : back()->withErrors(['status' => $message]);
        }

        $this->applyStatus(
            $appointment,
            $target,
            $previous,
            $request->user('admin'),
            $data['admin_notes'] ?? null,
        );

        $toast = [
            'type' => $target === AppointmentStatus::Cancelled ? 'warning' : 'success',
            'message' => $this->statusToastMessage($target),
        ];

        if ($request->expectsJson()) {
            /*
             * The toast is returned rather than flashed, and that is a change.
             *
             * It used to be flashed as well, because the list reloaded the page on
             * success and the reloaded page rendered it. Nothing reloads now — the
             * row is redrawn in place — so a flash would sit in the session until
             * the admin happened to navigate somewhere and then announce a status
             * change that happened minutes ago. `appointmentStatus` raises the
             * toast from this payload instead, which puts it on screen at the
             * moment of the save.
             *
             * A plain form submission still redirects with the flash, so the
             * JavaScript-off path is unchanged.
             */
            return response()->json($toast + [
                'status' => $target->value,

                // The row's new state, so the browser can put the row back the way
                // this save made it without asking for the page. This is what
                // reveals Archive and Delete the moment a booking is completed —
                // the two are rendered from a status the row did not have when the
                // page was built.
                'row' => $this->rowState($appointment->refresh()),

                // The status tabs count the same rows the page does, so a change
                // saved without a reload would otherwise leave them describing the
                // list as it was a moment ago.
                'counts' => $this->statusCounts(),
            ]);
        }

        return back()->with('toast', $toast);
    }

    /**
     * Write a status transition and everything that hangs off it.
     *
     * Shared by the list dropdown and the reschedule form so a booking can
     * never take a status change without its history row, its customer
     * notification and its inventory movement — whichever screen asked for it.
     */
    protected function applyStatus(
        Appointment $appointment,
        AppointmentStatus $target,
        AppointmentStatus $previous,
        ?Admin $admin = null,
        ?string $notes = null,
    ): void {
        $appointment->update([
            'status' => $target,
            'admin_notes' => $notes ?? $appointment->admin_notes,
            'confirmed_at' => $target === AppointmentStatus::Confirmed ? ($appointment->confirmed_at ?? now()) : $appointment->confirmed_at,
            'started_at' => $target === AppointmentStatus::InProgress ? ($appointment->started_at ?? now()) : $appointment->started_at,
            // Cleared when a booking is reopened, so a row that is in progress
            // again does not still look like it was finished at some earlier
            // point. Every other move leaves the stamp alone.
            'completed_at' => $target === AppointmentStatus::Completed
                ? now()
                : ($previous === AppointmentStatus::Completed ? null : $appointment->completed_at),
            'cancelled_at' => $target === AppointmentStatus::Cancelled ? now() : null,
        ]);

        $appointment->recordStatusChange(
            $target,
            ChangedBy::Admin,
            $admin?->id,
            $admin?->full_name,
            $notes,
            $previous,
        );

        $this->dispatchCustomerNotification($appointment, $target, $previous);
        $this->handleInventorySideEffects($appointment, $previous, $target);
    }

    /**
     * Short confirmation for a status change.
     *
     * The spec's two names are for the accept and decline paths, which is what
     * "confirmed" and "cancelled" mean here — the UI labels them Approve and
     * Decline. The wording is deliberately identical to the customer's own
     * meaning of the same action.
     */
    protected function statusToastMessage(AppointmentStatus $status): string
    {
        return match ($status) {
            AppointmentStatus::Confirmed => 'Appointment accepted.',
            AppointmentStatus::Cancelled => 'Appointment declined.',
            default => "Appointment marked as {$status->label()}.",
        };
    }

    /**
     * Admin Notes are internal only.
     */
    public function updateNotes(Request $request, Appointment $appointment): RedirectResponse
    {
        $data = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $appointment->update(['admin_notes' => $data['admin_notes']]);

        return back()->with('status', 'Internal notes saved.');
    }

    /**
     * Manual GCash down-payment verification (no payment gateway).
     */
    public function updateDownPayment(Request $request, Appointment $appointment): RedirectResponse
    {
        $data = $request->validate([
            'down_payment_status' => ['required', Rule::in(array_keys(DownPaymentStatus::options()))],
        ]);

        $appointment->update([
            'down_payment_status' => DownPaymentStatus::from($data['down_payment_status']),
        ]);

        return back()->with('status', 'Down payment status updated.');
    }

    /**
     * Admin-initiated date/time move.
     */
    public function update(Request $request, Appointment $appointment): RedirectResponse
    {
        $data = $request->validate([
            'preferred_date' => ['required', 'date'],
            'preferred_time' => ['required', 'date_format:H:i'],
            'status' => ['required', Rule::in(AppointmentStatus::values())],
            'technician_id' => ['nullable', 'integer', Rule::exists('technicians', 'id')->whereNull('deleted_at')],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $availability = BookingAvailability::make();

        $problems = $availability->timeProblems(
            $data['preferred_date'],
            $data['preferred_time'],
            // The appointment's own id, so moving it to another slot on the same
            // time does not read as a clash with itself.
            $appointment->id,
        );

        if ($problems !== []) {
            return back()
                ->withInput()
                ->withErrors(['preferred_date' => $problems[0]]);
        }

        $target = AppointmentStatus::from($data['status']);
        $previous = $appointment->status;
        $dateChanged = $appointment->preferred_date->toDateString() !== $data['preferred_date'];

        if ($dateChanged) {
            $this->booking->reschedule(
                $appointment,
                $data['preferred_date'],
                $data['preferred_time'],
                'Moved by the salon.',
            );
        } else {
            $appointment->update(['preferred_time' => $data['preferred_time']]);
        }

        $appointment->update(['technician_id' => $data['technician_id'] ?? null]);

        if ($dateChanged) {
            $this->notifyQuietly(
                $appointment->user,
                new AppointmentRescheduledNotification($appointment),
                'appointment rescheduled by staff'
            );
        }

        // `update()` keeps the full status list rather than the dropdown's
        // transition table: this form is the Manage screen, where correcting a
        // status that was entered wrongly is the whole point. The side effects
        // still run through the one shared path.
        $this->applyStatus(
            $appointment,
            $target,
            $previous,
            $request->user('admin'),
            $data['admin_notes'] ?? null,
        );

        return back()->with('status', "Appointment {$appointment->reference_number} updated.");
    }

    protected function dispatchCustomerNotification(Appointment $appointment, AppointmentStatus $target, AppointmentStatus $previous): void
    {
        if ($target === $previous || ! $appointment->user) {
            return;
        }

        $map = [
            AppointmentStatus::Confirmed->value => AppointmentConfirmedNotification::class,
            AppointmentStatus::InProgress->value => AppointmentInProgressNotification::class,
            AppointmentStatus::Completed->value => AppointmentCompletedNotification::class,
            AppointmentStatus::Cancelled->value => AppointmentCancelledByAdminNotification::class,
        ];

        if ($map[$target->value] ?? null) {
            $notification = $map[$target->value];

            $this->notifyQuietly(
                $appointment->user,
                new $notification($appointment),
                'appointment status changed to '.$target->value
            );
        }
    }

    /**
     * Cancelling frees the reserved stock; un-cancelling re-reserves it.
     */
    protected function handleInventorySideEffects(
        Appointment $appointment,
        AppointmentStatus $previous,
        AppointmentStatus $target,
    ): void {
        $appointment->loadMissing('serviceLines.service');

        if ($target === AppointmentStatus::Cancelled && $previous !== AppointmentStatus::Cancelled) {
            $this->booking->restoreInventory($appointment);
        }

        if ($previous === AppointmentStatus::Cancelled && $target !== AppointmentStatus::Cancelled) {
            foreach ($appointment->serviceLines as $line) {
                if (! $line->service) {
                    continue;
                }

                foreach ($line->service->inventoryItems as $item) {
                    $perService = (float) ($item->pivot->quantity_per_service ?: 1);
                    $item->decrement('quantity', $perService * $line->quantity);
                    $item->refresh()->syncStatusTag();
                }
            }
        }
    }
}
