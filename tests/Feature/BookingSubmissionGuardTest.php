<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BlockedSlot;
use App\Services\BookingAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `POST /book` could not be submitted twice.
 *
 * The route had no throttle — the only write in the app without one, and the most
 * expensive, since it validates a basket, opens a transaction, writes an
 * appointment and its service lines, decrements linked stock and sends a
 * notification. And it had no duplicate check at all.
 *
 * The availability re-check cannot stand in for one, which is the part worth
 * understanding: it reads *before* the insert, so a double-tap that fired two
 * requests saw the same slot free in both and both took it. Two appointments, one
 * slot, and a customer who then has to ring the salon to say so.
 *
 * Three layers are asserted, and each is worth having on its own:
 *
 *   1. a *rate limit*, which bounds how fast the endpoint can be hit at all;
 *   2. an *idempotency key*, which identifies the same attempt and returns the
 *      booking it already made; and
 *   3. a *unique index*, which is what actually closes the race between two
 *      requests that both get past the key lookup before either commits.
 *
 * Layer 2 alone would be a check-then-act with a window in it. The test that
 * proves layer 3 exists is the concurrent one at the bottom.
 */
class BookingSubmissionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeSalonSettings();
    }

    /* ------------------------------------------------------------------ */
    /* The key reaches the server at all                                   */
    /* ------------------------------------------------------------------ */

    public function test_the_booking_form_carries_a_fresh_idempotency_key(): void
    {
        $html = $this->actingAs($this->makeUser())
            ->get('/book')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="idempotency_key"', $html);

        // And it is a UUID, server-rendered rather than left to JavaScript, so the
        // duplicate protection survives a browser where the script is blocked.
        $this->assertMatchesRegularExpression(
            '/name="idempotency_key" value="[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}"/',
            $html,
        );
    }

    public function test_the_key_survives_a_rejected_submit(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();

        $key = (string) Str::uuid();

        $this->actingAs($user)
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->payload($service, [
                'agree_terms' => null,
                'idempotency_key' => $key,
            ]))
            ->assertSessionHasErrors('agree_terms');

        // The re-render has to carry the *same* key, or the retry is a different
        // attempt as far as the guard is concerned. Harmless while nothing was
        // created — but it would stop being harmless the moment a submit could
        // fail after its appointment was written.
        $html = $this->actingAs($user)->get('/book')->assertOk()->getContent();

        $this->assertStringContainsString('name="idempotency_key" value="'.$key.'"', $html);
    }

    public function test_a_submission_with_no_key_still_books(): void
    {
        // The key is a convention, not a contract. A request that does not send one
        // still books — it just relies on the throttle instead of the key, and
        // failing it would break any client that has not been taught the field.
        $this->actingAs($this->makeUser())
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->payload($this->makeService()))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('appointments', 1);
        $this->assertNull(Appointment::sole()->idempotency_key);
    }

    public function test_a_malformed_key_is_ignored_rather_than_stored(): void
    {
        // The value goes into a column behind a unique index. A client able to write
        // arbitrary text there could aim a collision at somebody else's key, so
        // anything that is not a UUID is dropped and the booking proceeds.
        $this->actingAs($this->makeUser())
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->payload($this->makeService(), [
                'idempotency_key' => 'not-a-uuid',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull(Appointment::sole()->idempotency_key);
    }

    /* ------------------------------------------------------------------ */
    /* The duplicate itself                                                 */
    /* ------------------------------------------------------------------ */

    public function test_submitting_the_same_key_twice_creates_one_appointment(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();
        $date = $this->bookableDate();

        $payload = $this->payload($service, [
            'preferred_date' => $date,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        // First tap.
        $this->actingAs($user)
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $payload)
            ->assertSessionHasNoErrors();

        $first = Appointment::sole();
        $this->assertSame($date, $first->preferred_date->toDateString());

        // Second tap, same key — the customer pressed the button twice, or the
        // browser resent it.
        $this->actingAs($user)
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame($first->id, Appointment::sole()->id, 'The second tap must not have made a second booking.');
    }

    public function test_a_duplicate_is_answered_with_the_original_booking(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();
        $date = $this->bookableDate();
        $key = (string) Str::uuid();

        $payload = $this->payload($service, ['preferred_date' => $date, 'idempotency_key' => $key]);

        $this->actingAs($user)->post(route('appointments.store'), $payload);

        $first = Appointment::sole();

        $response = $this->actingAs($user)
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $payload);

        // The same redirect, the same reference, the same wording. A customer who
        // double-tapped has done nothing wrong, and being told "duplicate ignored"
        // would raise a question they cannot answer — where the extra booking went,
        // or which of the two references is real.
        $response->assertRedirect(route('appointments.index', ['view' => $first->id]));
        $response->assertSessionHas('status', 'Your booking request has been submitted. Reference: '.$first->reference_number);

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_a_duplicate_does_not_take_the_stock_twice(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();
        $item = $this->makeItem(['quantity' => 10]);
        $this->linkItemToService($service, $item, 2);

        $payload = $this->payload($service, [
            'preferred_date' => $this->bookableDate(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->actingAs($user)->post(route('appointments.store'), $payload);

        $this->assertSame(8, (int) $item->fresh()->quantity);

        $this->actingAs($user)->post(route('appointments.store'), $payload);

        // The inventory is the quiet casualty of a double booking: two bookings for
        // one slot, and two treatments' worth of stock gone.
        $this->assertSame(8, (int) $item->fresh()->quantity, 'A duplicate must not consume stock again.');
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_a_duplicate_does_not_notify_the_customer_twice(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $user = $this->makeUser();
        $service = $this->makeService();

        $payload = $this->payload($service, [
            'preferred_date' => $this->bookableDate(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->actingAs($user)->post(route('appointments.store'), $payload);
        $this->actingAs($user)->post(route('appointments.store'), $payload);

        \Illuminate\Support\Facades\Notification::assertSentToTimes($user, \App\Notifications\AppointmentBookedNotification::class, 1);
    }

    public function test_one_customers_key_cannot_resolve_to_anothers_booking(): void
    {
        $mine = $this->makeUser();
        $theirs = $this->makeUser();
        $service = $this->makeService();

        $key = (string) Str::uuid();

        $this->actingAs($theirs)->post(route('appointments.store'), $this->payload($service, [
            'preferred_date' => $this->bookableDate(),
            'idempotency_key' => $key,
        ]));

        $theirAppointment = Appointment::sole();

        // The same key, replayed by a different account. The lookup is scoped by
        // user_id, so this finds nothing and books normally — two bookings, two
        // customers, and nobody handed back a stranger's reference number.
        $this->actingAs($mine)
            ->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->payload($service, [
                'preferred_date' => $this->bookableDate(2),
                'idempotency_key' => $key,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('appointments', 2);
        $this->assertNotSame(
            $theirAppointment->id,
            Appointment::where('user_id', $mine->id)->sole()->id,
        );
    }

    /**
     * The layer the key lookup alone cannot provide.
     *
     * Two requests can both find no appointment for a key and both reach the
     * insert before either commits. Only the unique index can refuse the second, so
     * the index is asserted to exist rather than left implied — without it the guard
     * is a check-then-act with a window in it exactly where the bug would hurt.
     */
    public function test_the_database_itself_refuses_a_second_row_for_one_key(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();
        $key = (string) Str::uuid();
        $date = $this->bookableDate();

        $lines = $this->bookingService()->resolveLines([['service_id' => $service->id, 'quantity' => 1]]);

        $this->bookingService()->create([
            'customer_name' => 'Ana Reyes',
            'customer_phone' => '09171234567',
            'preferred_date' => $date,
            'preferred_time' => '10:00',
            'idempotency_key' => $key,
        ], $lines, $user->id);

        // Straight at the model, bypassing the lookup the service does first — which
        // is exactly the state two simultaneous requests are in.
        $this->expectException(\Illuminate\Database\QueryException::class);

        Appointment::create([
            'reference_number' => Appointment::generateReferenceNumber(),
            'idempotency_key' => $key,
            'user_id' => $user->id,
            'customer_name' => 'Ana Reyes',
            'customer_phone' => '09171234567',
            'preferred_date' => $date,
            'preferred_time' => '11:00',
            'total_amount' => 500,
            'status' => 'pending',
        ]);
    }

    public function test_the_racing_second_request_gets_the_first_ones_booking_back(): void
    {
        // The same situation as above, but handled the way the service handles it:
        // the index refuses the insert and the service answers with the winner
        // rather than surfacing a database error to a customer whose booking
        // actually succeeded.
        $user = $this->makeUser();
        $service = $this->makeService();
        $key = (string) Str::uuid();
        $date = $this->bookableDate();
        $time = '10:00';

        $lines = $this->bookingService()->resolveLines([['service_id' => $service->id, 'quantity' => 1]]);

        $data = [
            'customer_name' => 'Ana Reyes',
            'customer_phone' => '09171234567',
            'preferred_date' => $date,
            'preferred_time' => $time,
            'idempotency_key' => $key,
        ];

        $winner = $this->bookingService()->create($data, $lines, $user->id);

        // The lookup is what would let a duplicate through; skipping it reproduces
        // the interleaving.
        $loser = $this->bookingService()->create($data, $lines, $user->id);

        $this->assertSame($winner->id, $loser->id);
        $this->assertDatabaseCount('appointments', 1);
    }

    /* ------------------------------------------------------------------ */
    /* The throttle                                                         */
    /* ------------------------------------------------------------------ */

    public function test_repeated_bookings_are_rate_limited(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();

        // A distinct key each time, so this exercises the limiter and not the
        // idempotency guard — twelve is the allowance, the thirteenth is refused.
        for ($i = 0; $i < 12; $i++) {
            $this->actingAs($user)
                ->post(route('appointments.store'), $this->payload($service, [
                    'preferred_date' => $this->bookableDate($i + 1),
                    'idempotency_key' => (string) Str::uuid(),
                ]))
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('appointments', 12);

        $this->actingAs($user)
            ->post(route('appointments.store'), $this->payload($service, [
                'preferred_date' => $this->bookableDate(20),
                'idempotency_key' => (string) Str::uuid(),
            ]))
            ->assertStatus(429);

        $this->assertDatabaseCount('appointments', 12);
    }

    public function test_a_normal_customer_is_never_rate_limited(): void
    {
        // The guard has to stop a loop without getting in a real customer's way.
        // Two bookings in a row, which is what somebody booking for two people
        // does, has to work.
        $user = $this->makeUser();
        $service = $this->makeService();

        foreach ([1, 2] as $offset) {
            $this->actingAs($user)
                ->post(route('appointments.store'), $this->payload($service, [
                    'preferred_date' => $this->bookableDate($offset),
                    'idempotency_key' => (string) Str::uuid(),
                ]))
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('appointments', 2);
    }

    /* ------------------------------------------------------------------ */
    /* The client-side half                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * The one Confirm button disables once the form is sent.
     *
     * The last step used to carry two of them — one in the navigation footer,
     * one in the summary — both `type="submit"`, both disabled on submit. Only
     * the summary's remains, so the count is 1.
     *
     * Still driven off the form's `submit` event rather than a click handler,
     * because that is what covers an Enter keypress and a browser's own
     * resubmit-on-Back as well as a click.
     */
    public function test_the_confirm_button_disables_once_the_form_is_sent(): void
    {
        $html = $this->actingAs($this->makeUser())->get('/book')->assertOk()->getContent();

        // Driven off the form's `submit` event rather than a click handler, so an
        // Enter keypress and a browser's own resubmit-on-Back are covered too.
        $this->assertStringContainsString('x-on:submit="submitting = true"', $html);

        // Exactly one Confirm action left on the booking form. Scoped to the
        // form, because the customer navigation wraps its own forms around the
        // logout links — `BookingWizardTest` makes the same assertion against
        // the form alone, with the reasoning.
        $from = strpos($html, route('appointments.store'));
        $form = substr($html, $from, strpos($html, '</form>', $from) - $from);

        $this->assertSame(
            1,
            substr_count($form, 'type="submit"'),
            'The booking form should have exactly one submit control.',
        );
        $this->assertSame(
            1,
            substr_count($html, 'x-bind:disabled="submitting"'),
            'The Confirm button should be disabled on submit.',
        );

        // And it must not be able to *block* the submission, only stop a second one.
        $this->assertStringNotContainsString('x-on:submit.prevent', $html);
    }

    /* ------------------------------------------------------------------ */

    private function bookingService(): \App\Services\BookingService
    {
        return app(\App\Services\BookingService::class);
    }

    /**
     * A complete, valid booking payload.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload($service, array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Ana Reyes',
            'customer_phone' => '09171234567',
            'preferred_date' => $this->bookableDate(),
            'preferred_time' => '10:00',
            'services' => [['service_id' => $service->id, 'quantity' => 1]],
            'last_services_availed_note' => 'Glow Manicure',
            'agree_terms' => '1',
        ], $overrides);
    }
}