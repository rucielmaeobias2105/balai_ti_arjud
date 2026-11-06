<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Support\Carbon;

/**
 * Moving finished bookings out of the working list.
 *
 * Two ways in, one decision:
 *
 *   - `archive()`, called by the admin's row action, which takes effect at once
 *     and records who asked for it; and
 *   - `sweep()`, called by the nightly scheduled command, which archives anything
 *     Completed more than 30 days ago and nobody has touched since.
 *
 * The 30-day rule applies to *Completed only*, not to Cancelled. A cancelled
 * booking is already inert — it holds no slot, no stock reservation and nothing
 * anybody has to do — so leaving it in the list costs the salon nothing, whereas a
 * completed one is real history that quietly grows the queue. Cancelled rows are
 * still archivable by hand, and still deletable; they are simply not swept.
 */
class AppointmentArchiver
{
    /** How long a completed booking stays in the working list. */
    public const DAYS_BEFORE_AUTO_ARCHIVE = 30;

    /**
     * Archive one booking, recording who did it.
     *
     * Refuses anything that is not settled. The caller checks too, but the check
     * is repeated here because this is the one place that actually writes the
     * column — a controller that forgot its own check would otherwise archive an
     * in-progress booking with no error anywhere.
     *
     * @param  int|null  $adminId  Null for the automatic sweep, which is what
     *                             distinguishes "an admin tidied up" from "the
     *                             30-day rule fired" when the row is read back.
     */
    public function archive(Appointment $appointment, ?int $adminId = null): bool
    {
        if (! $appointment->canBeArchivedByAdmin()) {
            return false;
        }

        $appointment->forceFill([
            'archived_at' => now(),
            'archived_by' => $adminId,
        ])->save();

        return true;
    }

    /**
     * Archive a settled booking permanently — the delete is the final say.
     *
     * Separate from `archive()` on purpose. Archiving is reversible and keeps the
     * record; this does not, so it is only offered on rows that are already out of
     * the working list *or* settled — the two states are not the same thing, and an
     * admin who wants a completed row gone entirely should be able to say so
     * without archiving it first as a formality.
     */
    public function destroy(Appointment $appointment): bool
    {
        if (! $appointment->canBeDeletedByAdmin()) {
            return false;
        }

        $appointment->delete();

        return true;
    }

    /**
     * The nightly sweep: completed bookings older than the window.
     *
     * One query to find the rows and one to stamp them, rather than looping over
     * the model — the point of a sweep is that nobody is looking at it, so there
     * is nothing to hydrate.
     *
     * Restricted to `archived_at IS NULL` so a second run on the same night, or a
     * manual archive that happened after the row qualified, is not overwritten: a
     * re-archived row would otherwise have its original `archived_by` replaced by
     * null, losing the fact that an admin had already filed it by hand.
     *
     * Batched, because a salon that has been running this for a year can have
     * thousands of qualifying rows and a single UPDATE against all of them is a
     * long-held lock on a table the booking form writes to.
     *
     * @return int Number of appointments archived.
     */
    public function sweep(?Carbon $asOf = null, int $days = self::DAYS_BEFORE_AUTO_ARCHIVE): int
    {
        $cutoff = ($asOf ?? now())->copy()->subDays($days);

        // `completed_at` rather than `preferred_date`: the clock that matters is
        // when the visit finished, not when it was booked for. A booking marked
        // complete today for a date three weeks ago has just been dealt with and
        // should stay in the list.
        $archived = 0;

        Appointment::query()
            ->active()
            ->where('status', AppointmentStatus::Completed)
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$archived) {
                $ids = $rows->pluck('id');

                // A bulk update rather than per-row saves, but the query still
                // states the conditions it is updating: `archived_by` is left null
                // rather than written, which is what marks this as the automatic
                // sweep to anyone reading the row later.
                $archived += Appointment::query()
                    ->whereIn('id', $ids)
                    ->whereNull('archived_at')
                    ->update(['archived_at' => now()]);
            });

        return $archived;
    }

    /**
     * How many rows the next sweep would archive.
     *
     * So the command can say something useful before doing it, and so there is one
     * place that defines the sweep's scope rather than the query living in the
     * console command.
     */
    public function sweepableCount(?Carbon $asOf = null, int $days = self::DAYS_BEFORE_AUTO_ARCHIVE): int
    {
        $cutoff = ($asOf ?? now())->copy()->subDays($days);

        return Appointment::query()
            ->active()
            ->where('status', AppointmentStatus::Completed)
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', $cutoff)
            ->count();
    }
}