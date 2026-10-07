<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Guards the technicians feature end to end.
 *
 * A technician is the person a customer asks for when booking, so the two
 * halves have to agree: the panel manages the roster, and the booking form
 * offers exactly the active ones and stores the pick on the appointment. A
 * half-built feature here fails quietly — the roster exists but the booking form
 * never offers anybody — so each step is asserted on its own.
 */
class TechnicianTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        return $this->makeAdmin();
    }

    /* ------------------------------------------------------------------ */
    /* The panel                                                          */
    /* ------------------------------------------------------------------ */

    public function test_the_technician_list_is_the_shared_admin_table_card(): void
    {
        $admin = $this->admin();
        Technician::factory()->create(['name' => 'Maria Santos']);
        Technician::factory()->inactive()->create(['name' => 'Jina Reyes']);

        $html = $this->actingAs($admin, 'admin')->get('/admin/technicians')->assertOk()->getContent();

        $this->assertStringContainsString('Maria Santos', $html);
        $this->assertStringContainsString('Jina Reyes', $html);

        // The card chrome: add button above the search row, the page-length
        // select beside it, the pager below the table.
        $this->assertMatchesRegularExpression('/<div class="bta-card p-5".*?<\/table>/s', $html);
        $this->assertStringContainsString('Add Technician', $html);
        $this->assertStringContainsString('Search:', $html);
        $this->assertStringContainsString('entries per page', $html);
        $this->assertStringContainsString('aria-label="Next page"', $html);
        $this->assertLessThan(
            strpos($html, 'table-page-btn'),
            strpos($html, '</table>'),
            'The pager should sit below the table.',
        );

        // Live filtering, not a GET form.
        $this->assertStringContainsString('x-data="adminTable(', $html);
        $this->assertStringNotContainsString('method="GET"', $html);
        $this->assertStringContainsString('data-row', $html);
    }

    public function test_the_technician_list_has_no_page_header_block(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($admin, 'admin')->get('/admin/technicians')->assertOk()->getContent();

        $this->assertStringNotContainsString('font-display text-2xl font-bold tracking-tight text-primary', $html);
    }

    public function test_the_technician_list_sorts(): void
    {
        $admin = $this->admin();
        Technician::factory()->create(['name' => 'Zeta Cruz']);
        Technician::factory()->create(['name' => 'Alpha Cruz']);

        $ascending = $this->actingAs($admin, 'admin')
            ->get('/admin/technicians?sort=name&direction=asc')
            ->assertOk()
            ->getContent();

        $this->assertLessThan(
            strpos($ascending, 'Zeta Cruz'),
            strpos($ascending, 'Alpha Cruz'),
            'Sorting by name ascending should list A before Z.',
        );

        // A column outside the allow-list falls back rather than reaching the
        // database.
        $this->actingAs($admin, 'admin')->get('/admin/technicians?sort=drop_table')->assertOk();
    }

    public function test_the_technician_forms_are_name_photo_and_active(): void
    {
        $admin = $this->admin();

        foreach ([
            '/admin/technicians/create' => 'Add Technician',
            route('admin.technicians.edit', Technician::factory()->create()) => 'Update Technician',
        ] as $path => $label) {
            $html = $this->actingAs($admin, 'admin')->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('name="name"', $html);
            $this->assertStringContainsString('name="photo"', $html);
            $this->assertStringContainsString('name="is_active"', $html);
            $this->assertStringContainsString($label, $html);

            // The lean form style: no page header block, no Back beside the
            // submit, and no fields the roster does not have.
            $this->assertStringNotContainsString('font-display text-2xl font-bold tracking-tight text-primary', $html);
            $this->assertSame(1, preg_match_all('/>\s*Back\s*</', $html));
            $this->assertStringNotContainsString('name="description"', $html);
        }
    }

    public function test_a_technician_is_created_with_a_name_and_an_active_flag(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/technicians', ['name' => '  Maria Santos  '])
            ->assertRedirect(route('admin.technicians.index'))
            ->assertSessionHas('status');

        $technician = Technician::sole();

        $this->assertSame('Maria Santos', $technician->name);
        $this->assertTrue($technician->is_active, 'A new technician should be bookable without being switched on.');
        $this->assertNull($technician->photo_path);
    }

    public function test_a_technician_needs_a_name(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/technicians', ['name' => '   '])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, Technician::count());
    }

    public function test_a_technician_photo_is_uploaded_replaced_and_removed(): void
    {
        Storage::fake('public');

        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');

        $client->post('/admin/technicians', [
            'name' => 'Maria Santos',
            'photo' => UploadedFile::fake()->image('maria.jpg'),
        ])->assertRedirect(route('admin.technicians.index'));

        $technician = Technician::sole();
        $original = $technician->photo_path;

        $this->assertNotNull($original);
        Storage::disk('public')->assertExists($original);

        // Replacing it deletes the old file rather than orphaning it.
        $client->put(route('admin.technicians.update', $technician), [
            'name' => 'Maria Santos',
            'photo' => UploadedFile::fake()->image('maria-2.jpg'),
        ])->assertRedirect(route('admin.technicians.index'));

        $replacement = $technician->fresh()->photo_path;

        $this->assertNotSame($original, $replacement);
        Storage::disk('public')->assertMissing($original);
        Storage::disk('public')->assertExists($replacement);

        // The checkbox clears it without needing a new upload.
        $client->put(route('admin.technicians.update', $technician), [
            'name' => 'Maria Santos',
            'remove_photo' => '1',
        ])->assertRedirect(route('admin.technicians.index'));

        $this->assertNull($technician->fresh()->photo_path);
        Storage::disk('public')->assertMissing($replacement);
    }

    public function test_a_technician_photo_must_be_a_reasonable_image(): void
    {
        Storage::fake('public');

        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/technicians', [
                'name' => 'Maria Santos',
                'photo' => UploadedFile::fake()->create('notes.pdf', 64),
            ])
            ->assertSessionHasErrors('photo');

        $this->assertSame(0, Technician::count());
    }

    public function test_the_technician_active_switch_flips_a_technician(): void
    {
        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');
        $technician = Technician::factory()->create();

        $client->get('/admin/technicians')->assertOk()->assertSee(route('admin.technicians.toggle', $technician));

        $client->patch(route('admin.technicians.toggle', $technician))->assertRedirect();
        $this->assertFalse($technician->fresh()->is_active);

        $client->patch(route('admin.technicians.toggle', $technician))->assertRedirect();
        $this->assertTrue($technician->fresh()->is_active);
    }

    public function test_a_technician_is_edited_and_deleted(): void
    {
        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');
        $technician = Technician::factory()->create(['name' => 'Maria Santos']);

        $client->put(route('admin.technicians.update', $technician), [
            'name' => 'Maria Santos-Reyes',
            'is_active' => '0',
        ])->assertRedirect(route('admin.technicians.index'));

        $technician->refresh();
        $this->assertSame('Maria Santos-Reyes', $technician->name);
        $this->assertFalse($technician->is_active);

        $client->delete(route('admin.technicians.destroy', $technician))
            ->assertRedirect(route('admin.technicians.index'));

        $this->assertSoftDeleted($technician);
    }

    public function test_the_sidebar_offers_technicians(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->getContent();

        preg_match('/<nav[^>]*aria-label="Admin">(.*?)<\/nav>/s', $html, $matches);
        $this->assertNotEmpty($matches);

        $this->assertStringContainsString(route('admin.technicians.index'), $matches[1]);
    }

    /* ------------------------------------------------------------------ */
    /* Booking                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * The picker offers the active technicians and only the active ones, and
     * "No preference" is one of the choices.
     */
    public function test_the_booking_form_offers_the_active_technicians(): void
    {
        $admin = $this->admin();
        $this->makeService();

        $active = Technician::factory()->create(['name' => 'Maria Santos']);
        $off = Technician::factory()->inactive()->create(['name' => 'Jina Reyes']);

        $user = $this->makeUser();

        $html = $this->actingAs($user)
            ->get('/book')
            ->assertOk()
            ->getContent();

        // The heading it used to sit under was "6. Choose Technician & Special Request".
        // That panel was merged into the last step, so the section is named by the
        // heading that exists now — what this test is really about is the dropdown
        // and its options, which are asserted either way.
        $this->assertStringContainsString('Technician &amp; Special Request', $html);
        $this->assertStringContainsString('name="technician_id"', $html);
        $this->assertStringContainsString('No preference', $html);
        $this->assertStringContainsString('Maria Santos', $html);
        $this->assertStringNotContainsString('Jina Reyes', $html, 'An inactive technician should not be offered for booking.');

        // A `<select>`, and specifically a select rather than the radio cards
        // this used to be: the picker is now a dropdown, so the face a radio
        // could show has moved beside it as a preview of the chosen technician.
        $this->assertMatchesRegularExpression('/<select[^>]*name="technician_id"/', $html);
        $this->assertStringNotContainsString('type="radio" name="technician_id"', $html);
        $this->assertStringContainsString('value="'.$active->id.'"', $html);
        $this->assertStringNotContainsString('value="'.$off->id.'"', $html);

        // The photo the dropdown cannot show is rendered beside it, so the
        // technician is still a face and not only a name.
        $this->assertStringContainsString('technicianPreview()?.photo', $html);
        $this->assertStringContainsString('technicianPreview()?.initials', $html);

        // The read-only summary names the pick, so the customer can see it
        // without scrolling back up.
        $this->assertStringContainsString('x-text="technicianName"', $html);
    }

    /**
     * With no technicians at all the form still has to work — the hidden input
     * submits an empty pick rather than a field that is simply missing.
     */
    public function test_the_booking_form_works_before_any_technician_exists(): void
    {
        $admin = $this->admin();
        $this->makeService();
        $user = $this->makeUser();

        $html = $this->actingAs($user)->get('/book')->assertOk()->getContent();

        $this->assertStringContainsString('name="technician_id" value=""', $html);
        $this->assertStringContainsString('No technicians have been set up yet', $html);
    }

    public function test_a_booking_stores_the_chosen_technician(): void
    {
        $admin = $this->admin();
        $service = $this->makeService();
        $technician = Technician::factory()->create(['name' => 'Maria Santos']);
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post('/book', $this->bookingPayload($service, [
                'technician_id' => $technician->id,
                // The allergies and last-service fields are beside the new one
                // and must keep working.
                'allergies' => 'Latex, Nickel',
                'last_services_availed' => 'Glow Manicure last month',
            ]))
            ->assertRedirect();

        $appointment = Appointment::sole();

        $this->assertSame($technician->id, $appointment->technician_id);
        $this->assertSame('Maria Santos', $appointment->technicianLabel());
        $this->assertSame('Latex, Nickel', $appointment->allergies);
        $this->assertStringContainsString('Glow Manicure', (string) $appointment->last_services_availed);
    }

    public function test_a_booking_with_no_technician_is_allowed(): void
    {
        $admin = $this->admin();
        $service = $this->makeService();
        Technician::factory()->create();
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post('/book', $this->bookingPayload($service, ['technician_id' => '']))
            ->assertRedirect();

        $appointment = Appointment::sole();

        $this->assertNull($appointment->technician_id);
        $this->assertSame('No preference', $appointment->technicianLabel());
    }

    /**
     * "No preference" is a real answer, but a technician who has been switched
     * off must not be bookable.
     */
    public function test_a_booking_cannot_name_a_technician_who_is_off(): void
    {
        $admin = $this->admin();
        $service = $this->makeService();
        $off = Technician::factory()->inactive()->create();
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post('/book', $this->bookingPayload($service, ['technician_id' => $off->id]))
            ->assertSessionHasErrors('technician_id');

        $this->assertSame(0, Appointment::count());
    }

    public function test_the_admin_sees_the_technician_on_the_appointment_list_and_detail(): void
    {
        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');

        $technician = Technician::factory()->create(['name' => 'Maria Santos']);

        // Completed, so the archived view is reachable for it — archiving a live
        // booking is refused, and the working list is checked before the archive
        // happens.
        $appointment = $this->makeAppointment(null, null, [
            'technician_id' => $technician->id,
            'status' => \App\Enums\AppointmentStatus::Completed,
        ]);

        foreach ([
            route('admin.appointments.index'),
            route('admin.appointments.show', $appointment),
        ] as $path) {
            $this->assertStringContainsString('Maria Santos', $client->get($path)->assertOk()->getContent(), $path);
        }

        // Archived, the technician still reads on the row: a retired technician
        // must not blank the history that names them.
        $client->post(route('admin.appointments.archive', $appointment))->assertRedirect();

        $this->assertStringContainsString(
            'Maria Santos',
            $client->get(route('admin.appointments.archived'))->assertOk()->getContent(),
        );

        $this->assertStringContainsString(
            'Technician',
            $client->get(route('admin.appointments.index'))->assertOk()->getContent(),
        );
    }

    /**
     * A booking made before technicians existed still reads with the stylist the
     * customer chose, rather than silently becoming "No preference".
     */
    public function test_an_older_booking_still_shows_its_stylist(): void
    {
        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');

        $appointment = $this->makeAppointment(null, null, [
            'preferred_stylist_id' => $admin->id,
        ]);

        $this->assertNull($appointment->technician_id);
        $this->assertSame($admin->full_name, $appointment->technicianLabel());
        $this->assertFalse($appointment->hasTechnician());

        $this->assertStringContainsString(
            $admin->full_name,
            $client->get(route('admin.appointments.show', $appointment))->assertOk()->getContent(),
        );
    }

    public function test_an_admin_can_reassign_the_technician(): void
    {
        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');

        $appointment = $this->makeAppointment();
        $technician = Technician::factory()->create();

        $client->put(route('admin.appointments.update', $appointment), [
            'preferred_date' => $appointment->preferred_date->addDay()->toDateString(),
            'preferred_time' => '10:00',
            'status' => $appointment->status->value,
            'technician_id' => $technician->id,
        ])->assertRedirect();

        $this->assertSame($technician->id, $appointment->fresh()->technician_id);
    }

    public function test_an_admin_can_clear_the_technician(): void
    {
        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');

        $appointment = $this->makeAppointment(null, null, [
            'technician_id' => Technician::factory()->create()->id,
        ]);

        $client->put(route('admin.appointments.update', $appointment), [
            'preferred_date' => $appointment->preferred_date->addDay()->toDateString(),
            'preferred_time' => '10:00',
            'status' => $appointment->status->value,
            'technician_id' => '',
        ])->assertRedirect();

        $this->assertNull($appointment->fresh()->technician_id);
    }

    /**
     * Retiring a technician soft deletes them, so the booking history keeps
     * naming them rather than blanking out a year of appointments.
     */
    public function test_deleting_a_technician_keeps_the_appointment(): void
    {
        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');

        $technician = Technician::factory()->create(['name' => 'Maria Santos']);
        $appointment = $this->makeAppointment(null, null, ['technician_id' => $technician->id]);

        $client->delete(route('admin.technicians.destroy', $technician))
            ->assertRedirect(route('admin.technicians.index'));

        $this->assertSoftDeleted($technician);
        $this->assertNotNull($appointment->fresh());
        $this->assertSame('Maria Santos', $appointment->fresh()->technicianLabel());
    }

    public function test_a_customer_cannot_reach_the_technician_panel(): void
    {
        $this->makeService();
        $user = $this->makeUser();

        $this->actingAs($user)->get('/admin/technicians')->assertRedirect(route('admin.login'));
        $this->actingAs($user)->post('/admin/technicians', ['name' => 'Sneaky'])->assertRedirect(route('admin.login'));
    }

    public function test_a_guest_never_sees_the_technician_panel(): void
    {
        $this->get('/admin/technicians')->assertRedirect(route('admin.login'));
    }

    /**
     * A minimal, valid booking POST. The booking rules (tomorrow, open days,
     * down payment) are exercised in BookingRulesTest; this only has to be
     * valid enough to reach the technician field.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bookingPayload($service, array $overrides = []): array
    {
        return array_merge([
            'services' => [['service_id' => $service->id, 'quantity' => 1]],
            'customer_name' => 'Juan Dela Cruz',
            'customer_phone' => '09171234567',
            'preferred_date' => $this->bookableDate(),
            'preferred_time' => '10:00',
            // Step 4 asks a first-timer what they had done before, and these
            // bookings are posted by accounts with no completed history.
            'last_services_availed_note' => 'Glow Manicure',
            'down_payment_reference' => 'GCASH1234567890',
            'agree_terms' => '1',
        ], $overrides);
    }
}
