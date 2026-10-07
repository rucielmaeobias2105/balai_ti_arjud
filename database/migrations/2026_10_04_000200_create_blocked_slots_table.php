<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time ranges the salon is not taking bookings in.
 *
 * A fresh table rather than the revival of the old `blocked_dates`. That table was
 * one row per date and could only say "closed that day", which is why it was
 * dropped: a salon needs to close for a public holiday *and* to block 2–4pm on an
 * ordinary Tuesday for a staff meeting, and a one-row-per-date table can express
 * the first and not the second. Reinstatement would also have brought back a
 * schema whose every consumer had been deleted along with it.
 *
 * A row is a range, and there are many rows per date:
 *
 *   - `start_time` and `end_time` both null → the whole day is closed.
 *   - both set → only that window is closed; the rest of the day still books.
 *   - several rows on one date → 9–10am and 2–4pm blocked, everything else open.
 *
 * `end_time` is exclusive, so a 2:00–4:00 block refuses a 2:00, 2:30, 3:00 and 3:30
 * start but leaves 4:00 bookable. BookingAvailability asks
 * `BlockedSlot::blocksTime()` rather than comparing strings itself, so the rule
 * lives with the row that states it.
 *
 * No unique constraint on `date`, deliberately: uniqueness there is exactly the
 * restriction this table exists to remove.
 *
 * `created_by` points at `admins.id` and is nullable, so a block can be recorded
 * before an admin session is attributed and so a deleted admin does not take the
 * salon's closures with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blocked_slots')) {
            return;
        }

        Schema::create('blocked_slots', function (Blueprint $table) {
            $table->id();

            // The day this row is about. Indexed on its own because every read is
            // "what is blocked on this date" — the booking form's date change, the
            // calendar's month grid, the admin's summary list.
            $table->date('date')->index();

            // Both null closes the whole day; both set closes that window.
            // `time` rather than a string so the database itself rejects an
            // unparseable value instead of the availability check quietly
            // mis-comparing it forever.
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            // Why. Optional: "Public holiday", "Staff meeting", "Maintenance".
            // Shown to the customer nowhere — a block is a door, not a noticeboard
            // — but it is the difference between an admin remembering a closure in
            // six months and not.
            $table->string('reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_slots');
    }
};