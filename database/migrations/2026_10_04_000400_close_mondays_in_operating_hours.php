<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The salon is closed every Monday.
 *
 * `operating_hours` is a stored JSON map of weekday => [open, close], and a day
 * that is absent from it is closed - `SalonSetting::hoursFor()` returns null for
 * it, `slotsFor()` then returns no slots, and the booking rules refuse the date.
 * That is the whole mechanism for a standing weekly closure: no admin has to
 * block every Monday by hand, and an admin cannot un-block one by accident.
 *
 * `SalonSetting::defaultHours()` no longer lists Monday, so a fresh install or
 * seed is already correct. This migration brings an EXISTING row into line with
 * it, because nothing in the panel edits these hours - the Salon Settings screen
 * that could write them is gone - so the stored value is otherwise whatever it
 * was seeded with and would keep offering Mondays after the code stopped them.
 *
 * Removing one key from a settings row, rather than dropping the column or
 * rewriting the map wholesale: the other six days are left exactly as they were,
 * and the migration is safe to run against a row that never listed Monday.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('salon_settings')->select('id', 'operating_hours')->get() as $row) {
            $hours = json_decode($row->operating_hours, true);

            if (! is_array($hours) || ! array_key_exists('monday', $hours)) {
                continue;
            }

            unset($hours['monday']);

            DB::table('salon_settings')
                ->where('id', $row->id)
                ->update(['operating_hours' => json_encode($hours)]);
        }
    }

    /**
     * Put Monday back on the same 09:00-17:00 window every other day uses.
     */
    public function down(): void
    {
        foreach (DB::table('salon_settings')->select('id', 'operating_hours')->get() as $row) {
            $hours = json_decode($row->operating_hours, true);

            if (! is_array($hours) || array_key_exists('monday', $hours)) {
                continue;
            }

            $hours = ['monday' => ['09:00', '17:00']] + $hours;

            DB::table('salon_settings')
                ->where('id', $row->id)
                ->update(['operating_hours' => json_encode($hours)]);
        }
    }
};