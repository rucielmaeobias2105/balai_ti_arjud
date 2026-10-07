<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One booking attempt, so a double-tap cannot become two appointments.
 *
 * `POST /book` had no throttle and no duplicate guard of any kind, which made it
 * the only write route in the app where the same request could be replayed into
 * two rows: a double-tap on Confirm Booking, or a refresh on a connection that had
 * already delivered the first one. The slot-availability check does not help —
 * the check reads *before* the insert, so both requests see the slot as free and
 * both take it.
 *
 * The column holds a UUID the booking form renders once per page load and posts
 * with the booking. A second request carrying the same key is the same attempt,
 * not a new one, so it is answered with the appointment that already exists.
 *
 * Unique per (user_id, key) rather than on the key alone, for two reasons. Booking
 * requires an authenticated customer, so scoping by account means one customer's
 * replayed key can never resolve to — or collide with — another customer's
 * booking. And it keeps the guard from becoming a denial of service: a key is
 * client-supplied, so a bare unique index would let anybody wedge somebody else's
 * bookings out by guessing their form.
 *
 * Nullable because the column predates every booking made before it, and those
 * rows have no key to store.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('appointments', 'idempotency_key')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable()->after('reference_number');

            $table->unique(['user_id', 'idempotency_key'], 'appointments_user_idempotency_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('appointments', 'idempotency_key')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique('appointments_user_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};