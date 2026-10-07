<?php

namespace App\Models;

use App\Casts\TolerantEnum;
use App\Enums\AppointmentStatus;
use App\Enums\ChangedBy;
use App\Enums\DownPaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'reference_number',
        'idempotency_key',
        'user_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'preferred_date',
        'preferred_time',
        'allergies',
        'last_services_availed',
        'preferred_stylist_id',
        'technician_id',
        'special_request',
        'down_payment_reference',
        'down_payment_amount',
        'down_payment_status',
        'total_amount',
        'status',
        'admin_seen_at',
        'source',
        'admin_notes',
        'cancellation_reason',
        'cancelled_at',
        'reschedule_reason',
        'confirmed_at',
        'completed_at',
        'started_at',
        'archived_at',
        'archived_by',
    ];

    protected function casts(): array
    {
        return [
            'preferred_date' => 'date',
            'down_payment_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'status' => AppointmentStatus::class,
            // When the salon last looked at this booking, which is not the same
            // as where it is in their process — see `scopeUnseenForAdmin()`.
            'admin_seen_at' => 'datetime',
            // Read tolerantly, written strictly. A plain enum cast throws on any
            // value it cannot parse, and this column spent years with a DEFAULT of
            // `'pending'` — a string this enum has no case for. `/appointments`
            // reads the status for every row it lists, so a single such row raised
            // `"pending" is not a valid backing value` and took the customer's
            // whole list down rather than showing as one bad cell.
            //
            // `TolerantEnum` maps the legacy spelling to the case it meant and
            // returns null for anything genuinely unknown, which the two helpers
            // below render as a request for a human to look. Writing is unchanged:
            // `set` does no guessing, so a bad value still fails where it is
            // written rather than being laundered into a valid-looking one.
            'down_payment_status' => TolerantEnum::class.':'.DownPaymentStatus::class,
            'cancelled_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'started_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /* ----------------------------------------------------------------- */
    /* Relations                                                          */
    /* ----------------------------------------------------------------- */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function preferredStylist(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'preferred_stylist_id');
    }

    /**
     * Who the customer asked to serve them.
     *
     * Nullable on purpose: "No preference" is a real answer, and retiring a
     * technician must not cascade a delete through the bookings that name them.
     *
     * `withTrashed()` because a soft-deleted technician is still the answer to
     * "who did this". The booking form offers the active ones from its own
     * query, so a retired technician still cannot be *chosen* — this only stops
     * the history from going blank.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class)->withTrashed();
    }

    /**
     * Who is serving this appointment, for every screen that only needs a name.
     *
     * Falls back to the legacy `preferred_stylist_id` so a booking made before
     * technicians existed still reads with the name the customer chose, rather
     * than silently becoming "No preference" the day the technicians table did.
     */
    public function technicianLabel(): string
    {
        return $this->technician?->name
            ?? $this->preferredStylist?->full_name
            ?? 'No preference';
    }

    /**
     * True when a live `technicians` row is behind this booking, as opposed to the
     * legacy stylist fallback. Lets a screen tell the two apart if it needs
     * to — a photo can only come from a real technician.
     */
    public function hasTechnician(): bool
    {
        return $this->technician !== null;
    }

    /**
     * The down-payment status as a sentence, for a person to read.
     *
     * Null when the stored value is not one this enum can stand behind — see the
     * `down_payment_status` cast. That is the one case where the label is a
     * question rather than a fact, and it has to say so: this column is what an
     * admin looks at to decide whether money arrived, so guessing a reading for a
     * value nobody defined could have them sign off on a deposit they never
     * checked.
     *
     * Always returns something, so no call site can be the thing that fatals.
     */
    public function downPaymentLabel(): string
    {
        return $this->down_payment_status?->label() ?? 'Needs review';
    }

    /**
     * The badge tone for `downPaymentLabel()`.
     *
     * Amber for the unknown case — the same tone `Unverified` uses, because it is
     * the same situation from the reader's side: this has not been confirmed, so
     * it should not be painted like something that has.
     */
    public function downPaymentBadgeTone(): string
    {
        return $this->down_payment_status?->badge() ?? 'pending';
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'appointment_service')
            ->withPivot(['service_variant_id', 'service_name', 'price', 'duration_minutes', 'quantity'])
            ->withTimestamps();
    }

    /** Ordered pivot rows — used to render the booking summary exactly as entered. */
    public function serviceLines()
    {
        return $this->hasMany(AppointmentService::class)->orderBy('id');
    }

    public function statusHistory(): HasMany
    {
        // Newest first, with id as the tiebreaker: several transitions can
        // land in the same second, and `latest()` alone is then non-deterministic.
        return $this->hasMany(AppointmentStatusHistory::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /* ----------------------------------------------------------------- */
    /* Scopes                                                            */
    /* ----------------------------------------------------------------- */

    public function scopeForUser(Builder $query, ?int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if (! $status || $status === 'all') {
            return $query;
        }

        return $query->where('status', $status);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query
            ->whereDate('preferred_date', '>=', today())
            ->whereIn('status', [
                AppointmentStatus::Pending->value,
                AppointmentStatus::Confirmed->value,
            ]);
    }

    public function scopeBetweenDates(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate('preferred_date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('preferred_date', '<=', $to));
    }

    /* ----------------------------------------------------------------- */
    /* Archiving                                                          */
    /* ----------------------------------------------------------------- */

    /**
     * Rows still in the working list — not archived.
     *
     * The default for every screen that shows "the queue", which is what keeps an
     * archived booking out of the admin list, the dashboard and the bell without
     * each of them remembering to filter.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Bookings the salon has not looked at yet.
     *
     * This is what the sidebar badge and the tab title's "(n)" count, and it is
     * deliberately *not* the same question as "which bookings are Pending".
     *
     * The two used to be the same query, which made the badge impossible to
     * clear: it counted a status, and the only way to change a status was to
     * approve or decline the booking. An admin who opened the Appointments page,
     * read every row and closed the tab still saw the number, because reading is
     * not deciding — so the badge reported the same thing all day, and the only
     * way to silence it without acting was not to open the page at all.
     *
     * `admin_seen_at` is the acknowledgement that separates them. It is set when
     * the Appointments screen is opened, so looking is enough to clear the
     * badge, and a booking that arrives afterwards is unseen again on its own.
     *
     * No status filter, on purpose. Whether a booking is Pending is the salon's
     * decision to make; whether anyone has looked at it is not. Filtering by
     * status here would re-couple the two and put the old bug back — a booking
     * approved without ever being read would go straight from unseen to
     * "not in the badge", and the admin would never know it was there.
     *
     * Pair with `active()` to exclude archived rows.
     */
    public function scopeUnseenForAdmin(Builder $query): Builder
    {
        return $query->whereNull('admin_seen_at');
    }

    /** Bookings the salon has already looked at. */
    public function scopeSeenForAdmin(Builder $query): Builder
    {
        return $query->whereNotNull('admin_seen_at');
    }

    /**
     * How many bookings the badge should show.
     *
     * `active()` here, and only here, so an archived booking cannot keep
     * badging a screen it is no longer on. Every caller goes through this rather
     * than writing the query, so the badge, the tab title and the poll cannot
     * disagree about what counts.
     */
    public static function unseenForAdminCount(): int
    {
        return (int) static::query()->active()->unseenForAdmin()->count();
    }

    /**
     * Mark everything unseen as seen, and say how many rows that was.
     *
     * One statement, not a loop: this runs on every visit to the Appointments
     * screen and there is no reason for it to be O(n) round trips.
     *
     * The `whereNull` in the update is not redundant with the scope — it is what
     * keeps a second visit from rewriting `admin_seen_at` on rows that are
     * already seen, which would churn `updated_at` on every settled booking and
     * make the column a record of page loads rather than of when a booking was
     * last looked at.
     *
     * Deliberately unfiltered by status and by `active()`: "the admin opened the
     * Appointments screen" is true of the whole table, and a row archived while
     * unseen should not resurface as a badge the moment it is restored.
     *
     * @return int  Rows updated.
     */
    public static function markUnseenAsSeenForAdmin(): int
    {
        return static::query()
            ->whereNull('admin_seen_at')
            ->update(['admin_seen_at' => now()]);
    }

    /** Has anyone at the salon looked at this booking yet? */
    public function isUnseenForAdmin(): bool
    {
        return $this->admin_seen_at === null;
    }

    /** Rows that have been moved out of the working list. */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archivedBy(): BelongsTo
    {
        // `withTrashed()` for the same reason as `technician()`: the answer to
        // "who archived this" must not go blank because that admin was later
        // retired. Without it the history would rewrite itself silently.
        return $this->belongsTo(Admin::class, 'archived_by')->withTrashed();
    }

    /**
     * Whether an admin may archive this booking.
     *
     * The status check is `isSettled()` and nothing else, so it is identical to
     * the delete rule: a booking can be moved out of the queue exactly when it can
     * be removed from it entirely. An In Progress row is neither.
     *
     * Already-archived is also false, so the action is not offered twice.
     */
    public function canBeArchivedByAdmin(): bool
    {
        return ! $this->isArchived() && $this->status->isSettled();
    }

    /**
     * Whether an admin may delete this booking outright.
     *
     * The same settled-status rule as archiving, and deliberately independent of
     * it: an archived booking can still be permanently removed, because the
     * archive is a filing decision and the delete is the admin's final say over
     * whether the record should exist at all.
     *
     * @see \App\Enums\AppointmentStatus::isSettled() for why "Rescheduled" is not
     *      a case here — rescheduling is an action, not a status.
     */
    public function canBeDeletedByAdmin(): bool
    {
        return $this->status->isSettled();
    }

    /* ----------------------------------------------------------------- */
    /* Presentation helpers                                              */
    /* ----------------------------------------------------------------- */

    public function getStatusLabelAttribute(): string
    {
        return $this->status->label();
    }

    public function getStatusBadgeAttribute(): string
    {
        return $this->status->badge();
    }

    public function getDateTimeLabelAttribute(): string
    {
        return $this->preferred_date->format('M j, Y').' at '.$this->getTimeLabelAttribute();
    }

    public function getTimeLabelAttribute(): string
    {
        $time = \Illuminate\Support\Carbon::parse($this->preferred_time);

        return $time->format('g:i A');
    }

    public function getServiceNamesAttribute(): string
    {
        $lines = $this->serviceLines;

        if ($lines->isEmpty()) {
            return '—';
        }

        return $lines
            ->map(fn (AppointmentService $line) => $line->display_name)
            ->join(', ');
    }

    public function getTotalDurationAttribute(): int
    {
        return (int) $this->serviceLines->sum(
            fn (AppointmentService $line) => $line->duration_minutes * $line->quantity
        );
    }

    public function canBeCancelled(): bool
    {
        return $this->status->isCustomerActionable();
    }

    public function canBeRescheduled(): bool
    {
        return $this->status->isCustomerActionable();
    }

    /**
     * Whether the customer may clear this booking off their own list.
     *
     * Only a settled one. A pending, confirmed or in-progress appointment is a
     * live commitment the salon still has to act on — deleting it would hide a
     * booking nobody has dealt with yet — so the trash icon is not offered and,
     * more importantly, the delete route refuses it server-side too. The
     * checkbox in the table is gated on this same method, so the two cannot
     * disagree about which rows are deletable.
     */
    public function canBeDeletedByCustomer(): bool
    {
        return in_array($this->status, [
            AppointmentStatus::Completed,
            AppointmentStatus::Cancelled,
        ], true);
    }

    /**
     * Record a status transition.
     *
     * `$from` must be passed explicitly: by the time a transition is logged the
     * model's own `status` has usually already been overwritten, so reading
     * `$this->status` here would record from == to. Pass null for the initial
     * creation of an appointment.
     */
    public function recordStatusChange(
        AppointmentStatus $to,
        ChangedBy $by,
        ?int $actorId = null,
        ?string $actorName = null,
        ?string $note = null,
        ?AppointmentStatus $from = null,
    ): void {
        $this->statusHistory()->create([
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $by,
            'changed_by_id' => $actorId,
            'changed_by_name' => $actorName,
            'note' => $note,
        ]);
    }

    /** Static reference generator, e.g. BTA-20260926-4F2A. */
    public static function generateReferenceNumber(): string
    {
        do {
            $reference = 'BTA-'.now()->format('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
        } while (static::where('reference_number', $reference)->exists());

        return $reference;
    }
}
