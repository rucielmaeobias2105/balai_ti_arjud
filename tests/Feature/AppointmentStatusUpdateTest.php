<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\ChangedBy;
use App\Enums\DownPaymentStatus;
use App\Models\Appointment;
use App\Notifications\AppointmentCancelledByAdminNotification;
use App\Notifications\AppointmentCompletedNotification;
use App\Notifications\AppointmentConfirmedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AppointmentStatusUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_appointment_list_renders_with_status_filters(): void
    {
        $user = $this->makeUser();

        $this->makeAppointment($user, null, ['status' => AppointmentStatus::Pending]);
        $this->makeAppointment($user, null, ['status' => AppointmentStatus::Completed]);

        $this->actingAs($user)
            ->get('/appointments')
            ->assertOk()
            ->assertSee('My Appointments');

        $this->actingAs($user)
            ->get('/appointments?status=completed')
            ->assertOk();
    }

    public function test_a_pending_appointment_can_be_approved(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin();
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.appointments.index'))
            ->patch(route('admin.appointments.status', $appointment), [
                'status' => 'confirmed',
            ])
            ->assertRedirect(route('admin.appointments.index'))
            // A toast, not the old page banner: status changes now confirm
            // through the shared toast rather than an inline alert.
            ->assertSessionHas('toast', ['type' => 'success', 'message' => 'Appointment accepted.']);

        $appointment->refresh();

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertNotNull($appointment->confirmed_at);

        Notification::assertSentTo($user, AppointmentConfirmedNotification::class);
    }

    public function test_a_pending_appointment_can_be_declined(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin();
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user);

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.appointments.status', $appointment), ['status' => 'declined'])
            ->assertSessionHasNoErrors();

        $appointment->refresh();

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->status);
        $this->assertNotNull($appointment->cancelled_at);

        Notification::assertSentTo($user, AppointmentCancelledByAdminNotification::class);
    }

    public function test_a_status_can_be_advanced_to_in_progress_and_completed(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin();
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user, null, ['status' => AppointmentStatus::Confirmed]);

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.appointments.status', $appointment), ['status' => 'in_progress']);

        $this->assertSame(AppointmentStatus::InProgress, $appointment->fresh()->status);
        $this->assertNotNull($appointment->fresh()->started_at);

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.appointments.status', $appointment), ['status' => 'completed']);

        $appointment->refresh();

        $this->assertSame(AppointmentStatus::Completed, $appointment->status);
        $this->assertNotNull($appointment->completed_at);

        Notification::assertSentTo($user, AppointmentCompletedNotification::class);
    }

    public function test_every_status_transition_is_recorded_in_the_history(): void
    {
        $admin = $this->makeAdmin();
        $appointment = $this->makeAppointment($this->makeUser());

        foreach (['confirmed', 'in_progress', 'completed'] as $status) {
            $this->actingAs($admin, 'admin')
                ->patch(route('admin.appointments.status', $appointment), ['status' => $status]);
        }

        $history = $appointment->fresh()->statusHistory;

        $this->assertCount(3, $history);

        // statusHistory is ordered latest-first.
        $this->assertSame(AppointmentStatus::Pending, $history->last()->from_status);
        $this->assertSame(AppointmentStatus::Confirmed, $history->last()->to_status);
        $this->assertSame(AppointmentStatus::Completed, $history->first()->to_status);
        $this->assertSame(ChangedBy::Admin, $history->first()->changed_by);
        $this->assertSame($admin->id, $history->first()->changed_by_id);
        $this->assertSame($admin->full_name, $history->first()->changed_by_name);
    }

    public function test_admin_notes_are_stored_but_internal(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user);

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.appointments.notes', $appointment), [
                'admin_notes' => 'Prefers late afternoon slots.',
            ]);

        $this->assertSame('Prefers late afternoon slots.', $appointment->fresh()->admin_notes);

        // Never rendered on the customer-facing views. `/appointments/{id}` is
        // the redirect to the list with the dialog open, so following it is what
        // actually reaches the customer-facing rendering of this booking.
        $this->actingAs($user)
            ->get(route('appointments.show', $appointment))
            ->assertRedirect(route('appointments.index', ['view' => $appointment->id]));

        $this->actingAs($user)
            ->get(route('appointments.index', ['view' => $appointment->id]))
            ->assertOk()
            ->assertDontSee('Prefers late afternoon slots.');
    }

    public function test_admin_notes_can_be_submitted_with_a_status_change(): void
    {
        $admin = $this->makeAdmin();
        $appointment = $this->makeAppointment($this->makeUser());

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.appointments.status', $appointment), [
                'status' => 'confirmed',
                'admin_notes' => 'Confirmed by phone.',
            ]);

        $this->assertSame('Confirmed by phone.', $appointment->fresh()->admin_notes);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $appointment = $this->makeAppointment($this->makeUser());

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.appointments.status', $appointment), ['status' => 'teleported'])
            ->assertSessionHasErrors('status');

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
    }

    public function test_down_payment_can_be_verified_manually(): void
    {
        $admin = $this->makeAdmin();
        $appointment = $this->makeAppointment($this->makeUser());

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.appointments.down-payment', $appointment), [
                'down_payment_status' => DownPaymentStatus::Verified->value,
            ]);

        $this->assertSame(DownPaymentStatus::Verified, $appointment->fresh()->down_payment_status);
    }

    public function test_guests_cannot_update_an_appointment_status(): void
    {
        $appointment = $this->makeAppointment($this->makeUser());

        $this->patch(route('admin.appointments.status', $appointment), ['status' => 'confirmed'])
            ->assertRedirect(route('login'));

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
    }

    public function test_a_customer_cannot_update_an_appointment_status(): void
    {
        $appointment = $this->makeAppointment($this->makeUser());

        $this->actingAs($appointment->user)
            ->patch(route('admin.appointments.status', $appointment), ['status' => 'confirmed'])
            ->assertRedirect(route('login'));

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
    }

    public function test_no_notification_is_sent_when_the_status_does_not_change(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin();
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user, null, ['status' => AppointmentStatus::Confirmed]);

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.appointments.status', $appointment), ['status' => 'confirmed']);

        Notification::assertNothingSent();
    }

    /* ------------------------------------------------------------------ */
    /* Customer-side cancellation                                          */
    /* ------------------------------------------------------------------ */

    public function test_a_customer_can_cancel_their_appointment(): void
    {
        Notification::fake();

        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user);

        $this->actingAs($user)
            ->patch(route('appointments.cancel.update', $appointment), [
                'reason_preset' => 'Schedule conflict',
                'agree_cancellation_policy' => '1',
            ])
            ->assertRedirect(route('appointments.index', ['view' => $appointment->id]));

        $appointment->refresh();

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->status);
        $this->assertStringContainsString('Schedule conflict', $appointment->cancellation_reason);
        $this->assertNotNull($appointment->cancelled_at);
    }

    public function test_cancelling_requires_agreeing_to_the_policy(): void
    {
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user);

        $this->actingAs($user)
            ->patch(route('appointments.cancel.update', $appointment), [
                'agree_cancellation_policy' => null,
            ])
            ->assertSessionHasErrors('agree_cancellation_policy');

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
    }

    public function test_a_customer_cannot_cancel_someone_elses_appointment(): void
    {
        $appointment = $this->makeAppointment($this->makeUser());

        $this->actingAs($this->makeUser())
            ->patch(route('appointments.cancel.update', $appointment), [
                'agree_cancellation_policy' => '1',
            ])
            ->assertForbidden();

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
    }

    public function test_a_completed_appointment_cannot_be_cancelled(): void
    {
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user, null, ['status' => AppointmentStatus::Completed]);

        $this->actingAs($user)
            ->get(route('appointments.cancel', $appointment))
            ->assertSessionHasErrors('status');
    }
}
