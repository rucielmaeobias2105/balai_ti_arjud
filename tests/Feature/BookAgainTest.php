<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Service;
use App\Models\ServiceVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Book Again — repeat a finished visit.
 *
 * The button only earns its place if it saves a returning customer the work of
 * reassembling a basket they have given the salon before, and if it *cannot* turn
 * into a second booking by accident. So the three things worth proving are:
 *
 *   - the services come across exactly, including the variant and the quantity,
 *     because "book again" that silently substitutes the default variant is a
 *     different appointment than the one they had;
 *   - nothing else comes across — not the date, not the time, not the technician,
 *     because a past booking says nothing about when they want to come back; and
 *   - it is a pre-fill, not a submission. The customer still chooses a slot and
 *     still presses Confirm.
 *
 * Plus the gate: only a Completed appointment can be repeated, and only by the
 * customer who owns it.
 */
class BookAgainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeSalonSettings();
    }

    /* ------------------------------------------------------------------ */
    /* Reaching it                                                         */
    /* ------------------------------------------------------------------ */

    public function test_the_button_appears_on_a_completed_appointment(): void
    {
        $user = $this->makeUser();

        $this->makeAppointment($user, $this->makeService(), [
            'status' => AppointmentStatus::Completed,
            'preferred_date' => today()->subDays(10),
        ]);

        $html = $this->actingAs($user)->get('/appointments')->assertOk()->getContent();

        $this->assertStringContainsString('Book Again', $html);
        $this->assertStringContainsString('fa-repeat', $html, 'The action has its own icon in the Actions column.');
    }

    public function test_the_button_is_absent_from_every_other_status(): void
    {
        $user = $this->makeUser();

        foreach ([
            AppointmentStatus::Pending,
            AppointmentStatus::Confirmed,
            AppointmentStatus::InProgress,
            AppointmentStatus::Cancelled,
        ] as $status) {
            $this->makeAppointment($user, $this->makeService(), [
                'status' => $status,
                'preferred_date' => today()->addDays(3),
            ]);
        }

        $html = $this->actingAs($user)->get('/appointments')->assertOk()->getContent();

        // Asserted on the row's href rather than on the words "Book Again", which
        // are in the page either way: the View dialog's footer button carries that
        // label unconditionally and hides itself off a non-Completed payload.
        $this->assertStringNotContainsString('/book-again/', $html);
    }

    public function test_it_redirects_into_the_booking_form_with_the_services_kept(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService(['name' => 'Glow Manicure']);

        $appointment = $this->completedAppointment($user, [$service->id => ['quantity' => 2]]);

        $this->actingAs($user)
            ->get(route('appointments.book-again', $appointment))
            // One booking form, not a second one. The destination is
            // `appointments.create?from=ID`, which is where the pre-fill happens.
            ->assertRedirect(route('appointments.create', ['from' => $appointment->id]));
    }

    /* ------------------------------------------------------------------ */
    /* What is carried across, and what is not                             */
    /* ------------------------------------------------------------------ */

    public function test_the_previous_services_are_pre_selected(): void
    {
        $user = $this->makeUser();
        $manicure = $this->makeService(['name' => 'Glow Manicure']);
        $pedicure = $this->makeService(['name' => 'Spa Pedicure']);

        $appointment = $this->completedAppointment($user, [
            $manicure->id => ['quantity' => 1],
            $pedicure->id => ['quantity' => 2],
        ]);

        // The basket is asserted on the decoded config island rather than on
        // substrings of the page. The names appear all over this template — in the
        // catalogue, in the summary, in the customer's own booking history — so a
        // name assertion proves nothing about what is actually in the basket.
        $config = $this->bookingConfig($this->actingAs($user)
            ->get(route('appointments.create', ['from' => $appointment->id]))
            ->assertOk()
            ->getContent());

        $basket = collect($config['initial'])->keyBy('service_id');

        $this->assertCount(2, $basket, 'Both services should be in the basket.');

        $this->assertTrue($basket->has($manicure->id));
        $this->assertSame(1, (int) $basket[$manicure->id]['quantity']);

        $this->assertTrue($basket->has($pedicure->id));
        $this->assertSame(2, (int) $basket[$pedicure->id]['quantity'], 'Quantities come across too.');
    }

    public function test_the_same_variant_is_kept_rather_than_the_default_one(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();

        // Two variants, one of them the default. Book Again has to bring back the
        // one they actually had — `preselectFromRequest()` takes the default, which
        // for a repeat booking is the wrong answer.
        $default = ServiceVariant::create([
            'service_id' => $service->id,
            'name' => 'Standard',
            'price' => '500',
            'base_price' => 500,
            'duration_minutes' => 60,
            'is_default' => true,
        ]);

        $premium = ServiceVariant::create([
            'service_id' => $service->id,
            'name' => 'Premium',
            'price' => '900',
            'base_price' => 900,
            'duration_minutes' => 90,
            'is_default' => false,
        ]);

        $appointment = $this->completedAppointment($user, [
            $service->id => ['quantity' => 1, 'service_variant_id' => $premium->id],
        ]);

        $html = $this->actingAs($user)
            ->get(route('appointments.create', ['from' => $appointment->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"service_variant_id":'.$premium->id, $html);
        $this->assertStringNotContainsString('"service_variant_id":'.$default->id, $html);
    }

    public function test_the_date_and_time_are_not_carried_over(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();

        $past = today()->subDays(20);

        $appointment = $this->completedAppointment($user, [$service->id => ['quantity' => 1]], [
            'preferred_date' => $past,
            'preferred_time' => '14:00',
        ]);

        $html = $this->actingAs($user)
            ->get(route('appointments.create', ['from' => $appointment->id]))
            ->assertOk()
            ->getContent();

        /*
         * The point of the whole feature. Pre-filling the date would make it far too
         * easy to confirm a booking that lands three weeks ago, and the form's own
         * `min` would refuse it anyway — so the pre-fill has to leave it alone
         * rather than fill it in and let the customer discover the problem.
         */
        $config = $this->bookingConfig($html);

        $this->assertNotSame(
            $past->toDateString(),
            $config['date'] ?? null,
            'The form must not open on the date of the visit being repeated.',
        );
        $this->assertSame('', $config['time'] ?? '', 'No time should be carried over either.');
    }

    public function test_the_technician_is_not_carried_over(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();
        $technician = \App\Models\Technician::factory()->create(['name' => 'Marco Reyes']);

        $appointment = $this->completedAppointment($user, [$service->id => ['quantity' => 1]], [
            'technician_id' => $technician->id,
        ]);

        $html = $this->actingAs($user)
            ->get(route('appointments.create', ['from' => $appointment->id]))
            ->assertOk()
            ->getContent();

        $config = $this->bookingConfig($html);

        // "No preference" is left as the starting point. A customer booking another
        // treatment a month later has no reason to want the same pair of hands, and
        // silently booking them with somebody they did not choose is worse than
        // asking.
        $this->assertSame('', $config['technicianId'] ?? '');
    }

    public function test_nothing_is_submitted_by_going_there(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();

        $appointment = $this->completedAppointment($user, [$service->id => ['quantity' => 1]]);

        $before = Appointment::count();

        $this->actingAs($user)
            ->get(route('appointments.book-again', $appointment))
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('appointments.create', ['from' => $appointment->id]))
            ->assertOk();

        $this->assertSame($before, Appointment::count(), 'Book Again is a pre-fill, not a booking.');
    }

    public function test_the_prefilled_basket_can_be_booked_on_a_new_slot(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();
        $date = $this->bookableDate();

        $appointment = $this->completedAppointment($user, [$service->id => ['quantity' => 2]]);

        $this->actingAs($user)
            ->get(route('appointments.create', ['from' => $appointment->id]))
            ->assertOk();

        $this->actingAs($user)
            ->from(route('appointments.create', ['from' => $appointment->id]))
            ->post(route('appointments.store'), [
                'customer_name' => 'Test Customer',
                'customer_phone' => '09171234567',
                'preferred_date' => $date,
                'preferred_time' => '10:00',
                'services' => [['service_id' => $service->id, 'quantity' => 2]],
                'last_services_availed_note' => 'Glow Manicure',
                'agree_terms' => '1',
            ])
            ->assertSessionHasNoErrors();

        $new = $user->appointments()->where('id', '!=', $appointment->id)->sole();

        $this->assertSame($date, $new->preferred_date->toDateString());
        $this->assertSame(AppointmentStatus::Pending, $new->status);
        $this->assertSame(2, (int) $new->serviceLines->sole()->quantity);
    }

    /* ------------------------------------------------------------------ */
    /* The gate                                                            */
    /* ------------------------------------------------------------------ */

    public function test_a_booking_that_has_not_happened_yet_cannot_be_repeated(): void
    {
        $user = $this->makeUser();

        // Pending and Confirmed are a visit that has not happened. Repeating one is
        // Reschedule, which already exists — offering both would give a customer
        // two buttons that do the same job.
        foreach ([AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::InProgress] as $status) {
            $appointment = $this->makeAppointment($user, $this->makeService(), [
                'status' => $status,
                'preferred_date' => today()->addDays(3),
            ]);

            $this->actingAs($user)
                ->get(route('appointments.book-again', $appointment))
                ->assertForbidden();
        }
    }

    public function test_a_cancelled_appointment_cannot_be_repeated(): void
    {
        $user = $this->makeUser();

        $appointment = $this->makeAppointment($user, $this->makeService(), [
            'status' => AppointmentStatus::Cancelled,
            'preferred_date' => today()->subDays(5),
        ]);

        $this->actingAs($user)
            ->get(route('appointments.book-again', $appointment))
            ->assertForbidden();
    }

    public function test_one_customer_cannot_repeat_anothers_booking(): void
    {
        $mine = $this->makeUser();
        $theirs = $this->makeUser();

        $appointment = $this->completedAppointment($theirs, [$this->makeService()->id => ['quantity' => 1]]);

        $this->actingAs($mine)
            ->get(route('appointments.book-again', $appointment))
            ->assertForbidden();
    }

    public function test_a_foreign_id_in_the_query_string_sends_the_customer_to_the_services(): void
    {
        // `bookAgain()` is the guarded way in. A hand-typed `?from=` should not be
        // an error page about a booking the customer was never shown — that would
        // confirm it exists — so the parameter is dropped. With no services to
        // pre-select, the wizard sends them back to the catalogue rather than
        // rendering a booking form that has nothing to book.
        $user = $this->makeUser();
        $theirs = $this->makeUser();

        $appointment = $this->completedAppointment($theirs, [$this->makeService()->id => ['quantity' => 1]]);

        $this->actingAs($user)
            ->get(route('appointments.create', ['from' => $appointment->id]))
            ->assertRedirect(route('services.index').'#services')
            ->assertSessionHas('toast');
    }

    public function test_a_service_that_has_been_retired_does_not_break_the_pre_fill(): void
    {
        $user = $this->makeUser();
        $kept = $this->makeService(['name' => 'Still Offered']);
        $gone = $this->makeService(['name' => 'Retired']);

        $appointment = $this->completedAppointment($user, [
            $kept->id => ['quantity' => 1],
            $gone->id => ['quantity' => 1],
        ]);

        // The catalogue no longer offers it.
        $gone->update(['is_active' => false]);

        $config = $this->bookingConfig($this->actingAs($user)
            ->get(route('appointments.create', ['from' => $appointment->id]))
            ->assertOk()
            ->getContent());

        $basket = collect($config['initial'] ?? [])->keyBy('service_id');

        // One stale line is skipped; the rest of the basket still comes across. A
        // retired service should not cost the customer everything else they had.
        $this->assertTrue($basket->has($kept->id));
        $this->assertFalse(
            $basket->has($gone->id),
            'A service the catalogue no longer offers must not be put in the basket.',
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * The booking form's server-rendered config island, decoded.
     *
     * The page is 1,500 lines of Blade and mentions every service name in the
     * catalogue, in the summary and in the customer's own booking history — so
     * "is this service in the basket?" cannot be answered by looking for its name.
     * This is the JSON the Alpine component is handed, which is the one place the
     * answer actually lives.
     *
     * @return array<string, mixed>
     */
    private function bookingConfig(string $html): array
    {
        $this->assertMatchesRegularExpression(
            '/<script type="application\/json" id="bta-booking-config">(.*?)<\/script>/s',
            $html,
            'The booking form should render its config island.',
        );

        preg_match('/<script type="application\/json" id="bta-booking-config">(.*?)<\/script>/s', $html, $matches);

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true) ?? [];
    }

    /**
     * A finished appointment with the given service lines.
     *
     * @param  array<int, array{quantity: int, service_variant_id?: int|null}>  $lines
     * @param  array<string, mixed>  $attributes
     */
    private function completedAppointment($user, array $lines, array $attributes = []): Appointment
    {
        $appointment = $this->makeAppointment($user, null, array_merge([
            'status' => AppointmentStatus::Completed,
            'preferred_date' => today()->subDays(10),
            'preferred_time' => '10:00',
        ], $attributes));

        $appointment->serviceLines()->delete();

        foreach ($lines as $serviceId => $line) {
            $service = Service::findOrFail($serviceId);

            AppointmentService::create([
                'appointment_id' => $appointment->id,
                'service_id' => $service->id,
                'service_variant_id' => $line['service_variant_id'] ?? null,
                'service_name' => $service->name,
                'price' => (float) $service->base_price,
                'duration_minutes' => (int) $service->duration_minutes,
                'quantity' => $line['quantity'],
            ]);
        }

        return $appointment->fresh();
    }
}