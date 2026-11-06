<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\DownPaymentStatus;
use App\Models\Appointment;
use App\Models\ServiceVariant;
use App\Notifications\AppointmentBookedNotification;
use App\Services\BookingAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BookingCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bookings are always tied to an account, so the suite signs in by
        // default. Tests that need a guest call forgetGuards() first.
        $this->actingAs($this->makeUser());
    }

    private function payload(array $overrides = []): array
    {
        $service = $this->makeService(['price' => 600]);

        return array_merge([
            'services' => [
                ['service_id' => $service->id, 'service_variant_id' => null, 'quantity' => 1],
            ],
            'customer_name' => 'Juan Dela Cruz',
            'customer_phone' => '09171234567',
            'preferred_date' => $this->bookableDate(),
            'preferred_time' => '10:00',
            'allergies_other' => 'Latex',
            'last_services_availed_note' => 'Glow Manicure',
            'down_payment_reference' => 'GCASH1234567890',
            'agree_terms' => '1',
        ], $overrides);
    }

    public function test_booking_form_renders(): void
    {
        $this->makeSalonSettings();
        $this->makeService(['name' => 'Glow Manicure', 'slug' => 'glow-manicure']);

        $this->get($this->bookingUrl())
            ->assertOk()
            ->assertSee('Book Appointment')
            ->assertSee('Booking Summary')
            ->assertSee('Glow Manicure');
    }

    public function test_a_signed_in_customer_can_create_a_booking(): void
    {
        Notification::fake();
        $user = $this->makeUser();
        $service = $this->makeService(['price' => 600]);

        $payload = $this->payload([
            'services' => [['service_id' => $service->id, 'quantity' => 1]],
        ]);

        // Deposits are off by default now — the booking form does not ask for a
        // GCash reference. Turn the setting on explicitly so this still covers
        // the branch `BookingService` takes when a booking *does* arrive with a
        // reference, which legacy and admin-created rows still do.
        //
        // After `payload()`, not before: it reaches `bookableDate()` →
        // `makeSalonSettings()`, which resets the flag to the shipping default,
        // so an earlier `update` is silently undone.
        $this->makeSalonSettings()->update(['down_payment_required' => true]);

        $response = $this->actingAs($user)->post('/book', $payload);

        $appointment = Appointment::first();

        $this->assertNotNull($appointment);
        $response->assertRedirect(route('appointments.index', ['view' => $appointment->id]));

        $this->assertSame($user->id, $appointment->user_id);
        $this->assertSame('Juan Dela Cruz', $appointment->customer_name);
        $this->assertSame('09171234567', $appointment->customer_phone);
        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        $this->assertEquals(600, (float) $appointment->total_amount);
        $this->assertSame('GCASH1234567890', $appointment->down_payment_reference);
        $this->assertSame(DownPaymentStatus::Unverified, $appointment->down_payment_status);
        $this->assertEquals(300, (float) $appointment->down_payment_amount);
    }

    /**
     * With deposits off — the shipping default — a booking that carries no
     * reference is stored as `NotRequired` with no amount.
     *
     * The mirror image of the case above, and the one the form now always takes.
     * Asserted separately so the two branches cannot be collapsed into one by a
     * change to `BookingService`.
     */
    public function test_a_booking_with_deposits_off_is_stored_as_not_required(): void
    {
        Notification::fake();
        $service = $this->makeService(['price' => 600]);

        $this->actingAs($this->makeUser())->post('/book', $this->payload([
            'services' => [['service_id' => $service->id, 'quantity' => 1]],
            'down_payment_reference' => null,
        ]))->assertSessionHasNoErrors();

        $appointment = Appointment::sole();

        $this->assertSame(DownPaymentStatus::NotRequired, $appointment->down_payment_status);
        $this->assertNull($appointment->down_payment_reference);
        $this->assertNull($appointment->down_payment_amount);
        $this->assertEquals(600, (float) $appointment->total_amount);
    }

    public function test_a_guest_must_log_in_before_booking(): void
    {
        Notification::fake();
        $this->app['auth']->forgetGuards();

        // Guests may browse the catalogue but every booking is tied to an
        // account, so the form and the POST are both behind auth.
        $this->get($this->bookingUrl())->assertRedirect(route('login'));

        $this->post('/book', $this->payload())->assertRedirect(route('login'));

        $this->assertNull(Appointment::first());
    }

    public function test_a_deactivated_customer_cannot_book(): void
    {
        Notification::fake();

        $user = $this->makeUser(['is_active' => false]);

        $this->actingAs($user)->post('/book', $this->payload());

        $this->assertNull(Appointment::first());
    }

    public function test_a_reference_number_is_generated_automatically(): void
    {
        $this->post('/book', $this->payload());

        $this->assertMatchesRegularExpression(
            '/^BTA-\d{8}-[0-9A-F]{4}$/',
            Appointment::first()->reference_number,
        );
    }

    public function test_service_lines_snapshot_the_name_and_price(): void
    {
        $service = $this->makeService(['name' => 'Gelish Manicure', 'price' => 750]);

        $this->post('/book', $this->payload([
            'services' => [['service_id' => $service->id, 'quantity' => 2]],
        ]));

        $line = Appointment::first()->serviceLines->first();

        $this->assertSame('Gelish Manicure', $line->service_name);
        $this->assertEquals(750, (float) $line->price);
        $this->assertSame(2, $line->quantity);
        $this->assertEquals(1500, (float) Appointment::first()->total_amount);
    }

    public function test_a_variant_price_overrides_the_base_service_price(): void
    {
        $service = $this->makeService(['price' => 400]);

        $variant = ServiceVariant::create([
            'service_id' => $service->id,
            'name' => 'Long Hair',
            'price' => 550,
            'base_price' => 550,
            'duration_minutes' => 90,
            'is_default' => false,
        ]);

        $this->post('/book', $this->payload([
            'services' => [['service_id' => $service->id, 'service_variant_id' => $variant->id, 'quantity' => 1]],
        ]));

        $appointment = Appointment::first();

        $this->assertEquals(550, (float) $appointment->total_amount);
        $this->assertSame('Long Hair', $appointment->serviceLines->first()->variant_name);
        $this->assertSame(90, $appointment->serviceLines->first()->duration_minutes);
    }

    public function test_multiple_services_are_summed(): void
    {
        $first = $this->makeService(['price' => 500]);
        $second = $this->makeService(['price' => 1200]);

        $this->post('/book', $this->payload([
            'services' => [
                ['service_id' => $first->id, 'quantity' => 1],
                ['service_id' => $second->id, 'quantity' => 1],
            ],
        ]));

        $this->assertEquals(1700, (float) Appointment::first()->total_amount);
        $this->assertCount(2, Appointment::first()->serviceLines);
    }

    public function test_at_least_one_service_is_required(): void
    {
        $this->post('/book', $this->payload(['services' => []]))
            ->assertSessionHasErrors('services');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_terms_must_be_accepted(): void
    {
        $this->post('/book', $this->payload(['agree_terms' => null]))
            ->assertSessionHasErrors('agree_terms');

        $this->assertDatabaseCount('appointments', 0);
    }

    /**
     * A booking goes through without any down payment reference.
     *
     * This used to be the opposite: the form asked for a GCash reference and
     * `StoreBookingRequest` refused the submission without one whenever the
     * salon had `down_payment_required` on, which it did by default. The salon
     * takes no deposit for a web booking now, so the requirement went with the
     * field.
     *
     * Asserted in the shape that actually matters — the booking is created, not
     * merely that no error was raised — because "no validation error" and "the
     * customer got an appointment" are different claims, and only the second one
     * is the thing that broke when the field was removed without this rule.
     */
    public function test_a_booking_needs_no_down_payment_reference(): void
    {
        $response = $this->post('/book', $this->payload(['down_payment_reference' => null]));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('appointments', 1);

        // Stored with nothing to verify, which is what `BookingService` decides.
        $appointment = \App\Models\Appointment::sole();

        $this->assertNull($appointment->down_payment_reference);
        $this->assertNull($appointment->down_payment_amount);
        $this->assertSame(\App\Enums\DownPaymentStatus::NotRequired, $appointment->down_payment_status);
    }

    /**
     * …and the rule is not reinstated by turning the salon setting back on.
     *
     * The old check keyed off `down_payment_required`. Had it been left in place
     * behind a flag, a salon that re-enabled the setting would have found every
     * booking failing on a field the form no longer shows — a payment error
     * message with no payment field to fix it with.
     */
    public function test_a_booking_still_needs_no_reference_when_the_setting_is_switched_on(): void
    {
        $this->makeSalonSettings()->update(['down_payment_required' => true]);

        $this->post('/book', $this->payload(['down_payment_reference' => null]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('appointments', 1);
    }

    /** A reference that *is* posted is still accepted and still recorded. */
    public function test_a_supplied_reference_is_still_stored(): void
    {
        $this->post('/book', $this->payload(['down_payment_reference' => '09123456789']))
            ->assertSessionHasNoErrors();

        $appointment = \App\Models\Appointment::sole();

        $this->assertSame('09123456789', $appointment->down_payment_reference);
    }

    public function test_a_past_date_is_rejected(): void
    {
        $this->post('/book', $this->payload([
            'preferred_date' => today()->subDay()->toDateString(),
        ]))->assertSessionHasErrors('preferred_date');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_a_date_outside_operating_hours_is_rejected(): void
    {
        // Fix the date first (the payload helper resets the hours), then
        // narrow the opening hours to Monday mornings only.
        $date = today()->copy()->next(\Illuminate\Support\Carbon::MONDAY);
        $date->addWeek();

        $payload = $this->payload([
            'preferred_date' => $date->toDateString(),
            'preferred_time' => '18:00',
        ]);

        $this->makeSalonSettings()->update([
            'operating_hours' => ['monday' => ['09:00', '12:00']],
        ]);

        $this->post('/book', $payload)->assertSessionHasErrors('preferred_time');

        $this->assertDatabaseCount('appointments', 0);
    }


    public function test_a_slot_already_taken_is_rejected(): void
    {
        $date = $this->bookableDate();
        $service = $this->makeService();

        $this->makeAppointment(null, $this->makeService(), [
            'preferred_date' => $date,
            'preferred_time' => '10:00',
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->post('/book', $this->payload([
            'preferred_date' => $date,
            'preferred_time' => '10:00',
            'services' => [['service_id' => $service->id, 'quantity' => 1]],
        ]))->assertSessionHasErrors('preferred_time');

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_a_cancelled_appointment_does_not_block_the_slot(): void
    {
        $date = $this->bookableDate();
        $service = $this->makeService();

        $this->makeAppointment(null, $this->makeService(), [
            'preferred_date' => $date,
            'preferred_time' => '10:00',
            'status' => AppointmentStatus::Cancelled,
        ]);

        $this->post('/book', $this->payload([
            'preferred_date' => $date,
            'preferred_time' => '10:00',
            'services' => [['service_id' => $service->id, 'quantity' => 1]],
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_allergy_checklist_and_free_text_are_combined(): void
    {
        $this->post('/book', $this->payload([
            'allergies' => ['Latex', 'Fragrance'],
            'allergies_other' => 'Reacts to nickel plating',
        ]));

        $stored = Appointment::first()->allergies;

        $this->assertStringContainsString('Latex', $stored);
        $this->assertStringContainsString('Fragrance', $stored);
        $this->assertStringContainsString('Reacts to nickel plating', $stored);
    }

    public function test_a_status_history_entry_is_written_on_creation(): void
    {
        $this->post('/book', $this->payload());

        $this->assertDatabaseHas('appointment_status_history', [
            'appointment_id' => Appointment::first()->id,
            'to_status' => 'pending',
            'changed_by' => 'customer',
        ]);
    }

    public function test_the_customer_is_notified_when_signed_in(): void
    {
        Notification::fake();

        $user = $this->makeUser();

        $this->actingAs($user)->post('/book', $this->payload());

        Notification::assertSentTo($user, AppointmentBookedNotification::class);
    }

    public function test_the_slot_lookup_endpoint_returns_open_slots(): void
    {
        $date = $this->bookableDate();

        $response = $this->getJson('/book/slots?date='.$date);

        $response->assertOk()
            ->assertJsonStructure(['date', 'slots', 'open', 'problems'])
            ->assertJsonPath('date', $date)
            ->assertJsonPath('open', true);

        $this->assertContains('10:00', $response->json('slots'));
    }


}
