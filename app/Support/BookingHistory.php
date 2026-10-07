<?php

namespace App\Support;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Support\Collection;

/**
 * Whether this customer has been here before, and what they had done.
 *
 * Step 4 of the booking form branches on this — a first-timer is asked what they
 * had done elsewhere, a returning customer is shown their own record instead —
 * and `StoreBookingRequest` branches on the same thing to decide whether that
 * answer is required.
 *
 * Those two must never disagree. A form that shows the read-only history while
 * the server still demands the manual field fails every submission from a
 * returning customer, and the reverse fails every first-timer's; both are the
 * kind of bug that only shows up once there is real traffic. So the rule lives
 * here once and both callers read it.
 *
 * The rule is a *completed* appointment, not merely an existing one. A pending
 * booking says the customer intends to come; a cancelled one says they decided
 * not to. Neither is something that was done to their hair and nails, and
 * neither tells a stylist anything useful. A guest has no account and therefore
 * no history, so a guest is always a first-timer — which is why this returns
 * false rather than null for them.
 */
final class BookingHistory
{
    /** How many past services step 4 shows before it stops listing. */
    public const DISPLAY_LIMIT = 5;

    /**
     * Has this customer had a completed appointment with the salon?
     */
    public static function isRepeatCustomer(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->appointments()
            ->where('status', AppointmentStatus::Completed)
            ->exists();
    }

    /**
     * The customer's completed appointments, most recent first.
     *
     * `preferred_date` leads the ordering because that is when the service was
     * had; `id` breaks ties so two bookings on one day — a rebooked slot, or an
     * import that gave them the same date — do not swap places between renders.
     *
     * @return \Illuminate\Support\Collection<int, Appointment>
     */
    public static function completedAppointments(): Collection
    {
        $user = auth()->user();

        if (! $user) {
            return collect();
        }

        return $user->appointments()
            ->with('serviceLines')
            ->where('status', AppointmentStatus::Completed)
            ->orderByDesc('preferred_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * What they last had here, newest first, for the read-only history in step 4.
     *
     * Distinct names, and in the order they were most recently had rather than
     * alphabetically — the field is asking "what did you last have", so the
     * answer should lead with what they had most recently rather than with
     * whatever sorts first.
     *
     * Each entry carries the date it came from, so the list can say which visit
     * is being quoted. Without it a customer looking at three nail services has
     * no way to tell which one the salon is about to treat as their last.
     *
     * @return array<int, array{name: string, date: string}>
     */
    public static function recentServices(int $limit = self::DISPLAY_LIMIT): array
    {
        $seen = [];
        $rows = [];

        foreach (static::completedAppointments() as $appointment) {
            foreach ($appointment->serviceLines as $line) {
                $name = trim((string) $line->service_name);

                if ($name === '' || in_array($name, $seen, true)) {
                    continue;
                }

                $seen[] = $name;

                $rows[] = [
                    'name' => $name,
                    'date' => $appointment->preferred_date?->format('M j, Y') ?? '',
                ];

                if (count($rows) >= $limit) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    /**
     * The history as one comma-joined string, for storing on the booking.
     *
     * What a returning customer's booking records without them having typed it.
     * The `last_services_availed` column is a single nullable text field that has
     * always held a comma-joined list, so this fits it exactly — and it means the
     * booking is no less well documented than one the customer filled in.
     */
    public static function recentServicesAsText(int $limit = self::DISPLAY_LIMIT): ?string
    {
        $names = array_column(static::recentServices($limit), 'name');

        return $names === [] ? null : implode(', ', $names);
    }
}
