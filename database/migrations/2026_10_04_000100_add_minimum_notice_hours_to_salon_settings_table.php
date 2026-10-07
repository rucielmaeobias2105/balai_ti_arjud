<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The salon sets its own minimum notice.
 *
 * The rule "you must book at least a day ahead" was hardcoded in
 * `BookingAvailability::firstBookableDate()` as `today()->addDay()`, and
 * `MINIMUM_NOTICE_MESSAGE` said so in fixed words. Every other part of the
 * booking window — how far ahead bookings open, how long a slot is, what time
 * the salon opens — was already a column on `salon_settings` that the booking
 * form read live. This one was not, so a salon that wanted to take a booking for
 * the same afternoon, or to insist on two days' notice, had no way to say so.
 *
 * Default 24, which is exactly what the hardcoded rule computed, so nothing about
 * existing bookings changes: `now()->addHours(24)->startOfDay()` is the next
 * calendar day for every time of day, which is what `today()->addDay()` returned.
 *
 * Hours rather than days on purpose. "At least one day in advance" and "at least
 * 24 hours in advance" sound alike and are not the same rule — the first lets a
 * 9am booking be made at 11pm the night before, the second does not — and an
 * hours field can express both. `booking_lead_days` stays as it was: that is the
 * far edge of the window, not the near one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('salon_settings', 'minimum_notice_hours')) {
            return;
        }

        Schema::table('salon_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('minimum_notice_hours')->default(24)->after('booking_lead_days');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('salon_settings', 'minimum_notice_hours')) {
            return;
        }

        Schema::table('salon_settings', function (Blueprint $table) {
            $table->dropColumn('minimum_notice_hours');
        });
    }
};