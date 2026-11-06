<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\AppointmentArchiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * Who may remove an appointment, when, and how.
 *
 * Three rules, and the tests are grouped by them because they can fail
 * independently:
 *
 *   1. only a *settled* booking (Completed or Cancelled) can be archived or
 *      deleted — never one that is Pending, Confirmed or, above all, In Progress;
 *   2. archiving is a filing decision that an admin can take at any moment, and is
 *      independent of the 30-day rule in both directions; and
 *   3. the nightly sweep archives completed bookings past the window on its own,
 *      and only those.
 *
 * Every rule is checked on the *route*, not just the model method. A view that
 * forgets to render the button is a cosmetic bug; a controller that forgets to
 * check is the actual hole, so the assertions here post crafted requests.
 */
class AppointmentArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeSalonSettings();
    }

    private function admin()
    {
        return $this->makeAdmin();
    }

    private function booking(AppointmentStatus $status, array $attributes = []): Appointment
    {
        return $this->makeAppointment($this->makeUser(), null, array_merge([
            'status' => $status,
            'preferred_date' => today()->addDays(3),
            'completed_at' => $status === AppointmentStatus::Completed ? now()->subDays(2) : null,
        ], $attributes));
    }

    /* ------------------------------------------------------------------ */
    /* 1. The settled-status rule                                          */
    /* ------------------------------------------------------------------ */

    /** @return array<string, array{0: AppointmentStatus}> */
    public static function settledStatuses(): array
    {
        return [
            'completed' => [AppointmentStatus::Completed],
            'cancelled' => [AppointmentStatus::Cancelled],
        ];
    }

    /** @dataProvider settledStatuses */
    public function test_a_settled_booking_can_be_archived(AppointmentStatus $status): void
    {
        $appointment = $this->booking($status);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.appointments.archive', $appointment))
            ->assertRedirect();

        $this->assertNotNull($appointment->fresh()->archived_at);
    }

    /** @dataProvider settledStatuses */
    public function test_a_settled_booking_can_be_deleted(AppointmentStatus $status): void
    {
        $appointment = $this->booking($status);

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.appointments.destroy', $appointment))
            ->assertRedirect(route('admin.appointments.index'));

        $this->assertSoftDeleted('appointments', ['id' => $appointment->id]);
    }

    /**
     * The rule that matters most, tested per live status.
     *
     * @return array<string, array{0: AppointmentStatus}>
     */
    public static function liveStatuses(): array
    {
        return [
            'pending' => [AppointmentStatus::Pending],
            'confirmed' => [AppointmentStatus::Confirmed],
            'in progress' => [AppointmentStatus::InProgress],
        ];
    }

    /** @dataProvider liveStatuses */
    public function test_a_live_booking_can_never_be_deleted(AppointmentStatus $status): void
    {
        $appointment = $this->booking($status);

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.appointments.destroy', $appointment))
            ->assertForbidden();

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'deleted_at' => null]);
    }

    /** @dataProvider liveStatuses */
    public function test_a_live_booking_can_never_be_archived(AppointmentStatus $status): void
    {
        $appointment = $this->booking($status);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.appointments.archive', $appointment))
            ->assertForbidden();

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'archived_at' => null]);
    }

    /**
     * "In Progress must NEVER be deletable" — spelled out on its own, because it
     * is the one status where the loss is immediate and visible: the customer is
     * on the chair.
     */
    public function test_an_in_progress_booking_is_refused_by_the_model_helper_too(): void
    {
        $appointment = $this->booking(AppointmentStatus::InProgress);

        $this->assertFalse($appointment->canBeDeletedByAdmin());
        $this->assertFalse($appointment->canBeArchivedByAdmin());
    }

    /**
     * The check belongs to the status, not to a list of screens.
     *
     * Asserted on the enum so that adding a sixth status cannot silently make it
     * removable — the failure mode of writing `in_array($status, [...])` in four
     * places separately.
     */
    public function test_the_settled_rule_is_defined_once_on_the_enum(): void
    {
        $this->assertTrue(AppointmentStatus::Completed->isSettled());
        $this->assertTrue(AppointmentStatus::Cancelled->isSettled());

        $this->assertFalse(AppointmentStatus::Pending->isSettled());
        $this->assertFalse(AppointmentStatus::Confirmed->isSettled());
        $this->assertFalse(AppointmentStatus::InProgress->isSettled());

        // And "Rescheduled" is not a status at all: rescheduling is an action that
        // moves a booking's date and leaves its status alone, so it needs no case
        // and must not be mistaken for a removable one.
        $this->assertNotContains('rescheduled', AppointmentStatus::values());
    }

    /* ------------------------------------------------------------------ */
    /* 1a. The form the admin actually submits                             */
    /* ------------------------------------------------------------------ */

    /**
     * The archive button must submit the verb its route accepts.
     *
     * Every other archive test here posts to `admin.appointments.archive`
     * directly, which is why the bug this guards went unnoticed: the route takes
     * POST, the test posts POST, and the suite is green — while the rendered
     * dialog, which inherits `method="DELETE"` from `x-ui.confirm-dialog`'s
     * default, submitted DELETE and drew a 405 from the very route the test
     * called successfully.
     *
     * So this reads the rendered working list and asserts the spoofed verb
     * against the route, rather than asserting the route works.
     */
    public function test_the_archive_button_submits_a_verb_its_route_accepts(): void
    {
        $appointment = $this->booking(AppointmentStatus::Completed, ['completed_at' => now()]);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.appointments.index'))
            ->assertOk()
            ->getContent();

        $spoofed = $this->spoofedMethodForDialog($html, 'confirm-archive-title');

        $this->assertNotNull(
            $spoofed,
            'The archive dialog should carry a _method field. Without one a browser can only POST.',
        );

        $this->assertContains(
            $spoofed,
            $this->verbsAcceptedBy('admin.appointments.archive'),
            "The archive dialog spoofs {$spoofed}, but admin.appointments.archive does not accept it.",
        );
    }

    /**
     * The delete button beside it legitimately spoofs DELETE, so this guards
     * against "fixing" the archive dialog by changing the shared default and
     * breaking every other caller.
     */
    public function test_the_delete_button_still_spoofs_delete(): void
    {
        $this->booking(AppointmentStatus::Cancelled);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.appointments.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            'DELETE',
            $this->spoofedMethodForDialog($html, 'confirm-delete-booking-title'),
            'The per-row delete form should spoof DELETE.',
        );

        $this->assertContains(
            'DELETE',
            $this->verbsAcceptedBy('admin.appointments.destroy'),
            'The delete route really is a DELETE route.',
        );
    }

    /**
     * Read the `_method` value out of the confirm dialog carrying `$titleId`.
     *
     * The dialogs bind their action with Alpine, so the target URL is in the
     * wrapper's `x-data` payload rather than in the form tag, and a row's URL is
     * only assembled in the browser. The heading id is the one identifier in the
     * rendered markup that is unique per dialog, so the form is located by
     * walking back to the nearest `<form` before it.
     */
    private function spoofedMethodForDialog(string $html, string $titleId): ?string
    {
        $heading = strpos($html, 'id="'.$titleId.'"');

        if ($heading === false) {
            return null;
        }

        $formStart = strrpos(substr($html, 0, $heading), '<form');

        if ($formStart === false) {
            return null;
        }

        $form = substr($html, $formStart, strpos($html, '</form>', $formStart) - $formStart);

        return preg_match('#name="_method"\s+value="([^"]+)"#', $form, $verb) ? $verb[1] : null;
    }

    /**
     * The verbs a named route answers to, minus the two every route gets free.
     *
     * Matched by name rather than by URI because `route()` builds a concrete URL
     * and the router holds the `appointments/{appointment}/archive` pattern, so
     * the two never compare equal.
     *
     * @return list<string>
     */
    private function verbsAcceptedBy(string $routeName): array
    {
        return collect(app('router')->getRoutes()->getByName($routeName)?->methods() ?? [])
            ->reject(fn (string $method) => in_array($method, ['HEAD', 'OPTIONS'], true))
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /* 2. Manual archiving is immediate and independent of the 30-day rule  */
    /* ------------------------------------------------------------------ */

    /**
     * No waiting. An admin who wants a row out of the queue archives it now, even
     * though it finished five minutes ago and the sweep would not touch it for
     * another thirty days.
     */
    public function test_an_admin_can_archive_a_just_completed_booking_by_hand(): void
    {
        $appointment = $this->booking(AppointmentStatus::Completed, ['completed_at' => now()]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.appointments.archive', $appointment))
            ->assertRedirect();

        $this->assertNotNull($appointment->fresh()->archived_at);
    }

    /** …and the rule records who did it, so "Automatic" is distinguishable later. */
    public function test_a_manual_archive_records_the_admin_who_asked_for_it(): void
    {
        $admin = $this->admin();
        $appointment = $this->booking(AppointmentStatus::Cancelled);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.appointments.archive', $appointment));

        $this->assertSame($admin->id, $appointment->fresh()->archived_by);
    }

    /** The 30-day sweep leaves `archived_by` null, which is how "automatic" reads. */
    public function test_the_sweep_does_not_claim_an_admin_did_it(): void
    {
        $appointment = $this->booking(AppointmentStatus::Completed, ['completed_at' => now()->subDays(45)]);

        app(AppointmentArchiver::class)->sweep();

        $this->assertNotNull($appointment->fresh()->archived_at);
        $this->assertNull($appointment->fresh()->archived_by);
    }

    /** A row archived by hand is not re-archived by the sweep, losing its author. */
    public function test_the_sweep_leaves_a_hand_archived_row_alone(): void
    {
        $admin = $this->admin();
        $appointment = $this->booking(AppointmentStatus::Completed, ['completed_at' => now()->subDays(45)]);

        $this->actingAs($admin, 'admin')->post(route('admin.appointments.archive', $appointment));

        $archivedAt = $appointment->fresh()->archived_at;

        app(AppointmentArchiver::class)->sweep();

        $this->assertEquals($archivedAt, $appointment->fresh()->archived_at, 'The original archive timestamp must stand.');
        $this->assertSame($admin->id, $appointment->fresh()->archived_by);
    }

    /** Archiving twice is a no-op rather than a second error. */
    public function test_archiving_an_already_archived_booking_is_refused_rather_than_repeated(): void
    {
        $appointment = $this->booking(AppointmentStatus::Completed);
        $this->actingAs($this->admin(), 'admin')->post(route('admin.appointments.archive', $appointment));

        $first = $appointment->fresh()->archived_at;

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.appointments.archive', $appointment))
            ->assertForbidden();

        $this->assertEquals($first, $appointment->fresh()->archived_at);
    }

    /* ------------------------------------------------------------------ */
    /* 3. Restore                                                          */
    /* ------------------------------------------------------------------ */

    public function test_a_restored_booking_returns_to_the_working_list(): void
    {
        $appointment = $this->booking(AppointmentStatus::Completed);
        $this->actingAs($this->admin(), 'admin')->post(route('admin.appointments.archive', $appointment));

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.appointments.restore', $appointment))
            ->assertRedirect();

        $this->assertNull($appointment->fresh()->archived_at);
    }

    /**
     * Both columns, not just the timestamp.
     *
     * Clearing only `archived_at` would leave the row reading as archived-by-this-admin
     * while sitting in the working list — a history that contradicts itself.
     */
    public function test_restoring_clears_the_archiving_admin_too(): void
    {
        $admin = $this->admin();
        $appointment = $this->booking(AppointmentStatus::Cancelled);

        $this->actingAs($admin, 'admin')->post(route('admin.appointments.archive', $appointment));
        $this->actingAs($admin, 'admin')->post(route('admin.appointments.restore', $appointment));

        $this->assertNull($appointment->fresh()->archived_at);
        $this->assertNull($appointment->fresh()->archived_by);
    }

    /* ------------------------------------------------------------------ */
    /* 4. The list and the Archived view are separate                       */
    /* ------------------------------------------------------------------ */

    /**
 * Distinct customer names, because the working list renders the customer's name
 * and not the reference number — the Reference column was removed from this
 * table, so asserting on it here would be asserting on a string that never
 * appears whether or not the filter works.
 */
public function test_an_archived_booking_leaves_the_working_list(): void
    {
        $this->makeSalonSettings();

        $stays = $this->makeUser(['first_name' => 'Stays', 'last_name' => 'Listed']);
        $leaves = $this->makeUser(['first_name' => 'Leaves', 'last_name' => 'Listing']);

        // `customer_name` is set explicitly rather than left to `makeAppointment`'s
        // default, because that helper derives the name from the *user object* it
        // is handed while the attributes override the id — so passing the two
        // users separately would produce two rows with the same name.
        $visible = $this->booking(AppointmentStatus::Completed, [
            'user_id' => $stays->id,
            'customer_name' => $stays->full_name,
        ]);
        $archived = $this->booking(AppointmentStatus::Cancelled, [
            'user_id' => $leaves->id,
            'customer_name' => $leaves->full_name,
        ]);

        $this->actingAs($this->admin(), 'admin')->post(route('admin.appointments.archive', $archived));

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.appointments.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Stays Listed', $html);
        $this->assertStringNotContainsString('Leaves Listing', $html);

        // And the flag itself is what moved it, not the status — the archived row
        // is still Cancelled, and still on record.
        $this->assertSame(AppointmentStatus::Cancelled, $archived->fresh()->status);
        $this->assertTrue($archived->fresh()->isArchived());
        $this->assertFalse($visible->fresh()->isArchived());
    }

    public function test_the_archived_view_lists_what_left_the_working_list(): void
    {
        $archived = $this->booking(AppointmentStatus::Completed);
        $this->actingAs($this->admin(), 'admin')->post(route('admin.appointments.archive', $archived));

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.appointments.archived'))
            ->assertOk()
            ->assertSee($archived->reference_number);
    }

    /** Archived rows are out of the status tab counts, not merely off the list. */
    public function test_an_archived_booking_stops_being_counted_in_the_status_tabs(): void
    {
        $appointment = $this->booking(AppointmentStatus::Completed);
        $this->actingAs($this->admin(), 'admin')->post(route('admin.appointments.archive', $appointment));

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.appointments.index'))
            ->assertOk()
            ->getContent();

        // The tab reads "Completed" followed by its count; with the only completed
        // row archived, the count is gone. Matched against the rendered markup
        // rather than a query, because the counts are computed in the controller
        // and a passing test here is what says the *screen* is right.
        $this->assertDoesNotMatchRegularExpression(
            '/Completed\s*<span class="ml-1 opacity-70">1<\/span>/',
            $html,
            'The Completed tab should no longer count an archived booking.',
        );
    }

    /** A live booking can never end up on the Archived screen. */
    public function test_the_archived_view_will_not_show_a_live_booking(): void
    {
        $appointment = $this->booking(AppointmentStatus::Confirmed);

        // Forced into the archive columns directly, which is the only way to get
        // there — the route refuses.
        $appointment->forceFill(['archived_at' => now(), 'archived_by' => null])->save();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.appointments.archived'))
            ->assertOk()
            ->assertDontSee($appointment->reference_number);
    }

    /* ------------------------------------------------------------------ */
    /* 5. The 30-day sweep                                                 */
    /* ------------------------------------------------------------------ */

    public function test_the_sweep_archives_a_completed_booking_past_the_window(): void
    {
        $old = $this->booking(AppointmentStatus::Completed, [
            'completed_at' => now()->subDays(AppointmentArchiver::DAYS_BEFORE_AUTO_ARCHIVE + 1),
        ]);

        $archived = app(AppointmentArchiver::class)->sweep();

        $this->assertSame(1, $archived);
        $this->assertNotNull($old->fresh()->archived_at);
    }

    public function test_the_sweep_leaves_a_recent_completed_booking_alone(): void
    {
        $recent = $this->booking(AppointmentStatus::Completed, [
            'completed_at' => now()->subDays(AppointmentArchiver::DAYS_BEFORE_AUTO_ARCHIVE - 1),
        ]);

        $this->assertSame(0, app(AppointmentArchiver::class)->sweep());
        $this->assertNull($recent->fresh()->archived_at);
    }

    /**
     * The window is 30 days, measured from `completed_at`.
     *
     * Asserted explicitly rather than derived from the constant so that changing
     * the constant to, say, 7 is a deliberate edit to a business rule rather than
     * a silent one.
     */
    public function test_the_retention_window_is_thirty_days(): void
    {
        $this->assertSame(30, AppointmentArchiver::DAYS_BEFORE_AUTO_ARCHIVE);
    }

    /**
     * Measured from when the visit *finished*, not when it was booked for.
     *
     * A booking marked complete today for a date three weeks ago has only just
     * been dealt with; archiving it on the strength of its appointment date would
     * file away a row an admin is still working with.
     */
    public function test_the_window_is_measured_from_when_the_booking_completed(): void
    {
        $justFinished = $this->booking(AppointmentStatus::Completed, [
            'preferred_date' => today()->subDays(60),
            'completed_at' => now(),
        ]);

        $this->assertSame(0, app(AppointmentArchiver::class)->sweep());
        $this->assertNull($justFinished->fresh()->archived_at);
    }

    /**
     * Cancelled rows are not swept.
     *
     * A cancelled booking is already inert — no slot, no stock, nothing to do — so
     * leaving it costs the salon nothing, and the 30-day rule is about the
     * completed history that actually grows.
     */
    public function test_the_sweep_does_not_archive_cancelled_bookings(): void
    {
        $cancelled = $this->booking(AppointmentStatus::Cancelled, [
            'preferred_date' => today()->subDays(90),
        ]);

        $this->assertSame(0, app(AppointmentArchiver::class)->sweep());
        $this->assertNull($cancelled->fresh()->archived_at);

        // …but it is still archivable by hand, which is the escape hatch.
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.appointments.archive', $cancelled))
            ->assertRedirect();

        $this->assertNotNull($cancelled->fresh()->archived_at);
    }

    /** The sweep never touches a soft-deleted row a customer already cleared. */
    public function test_the_sweep_ignores_deleted_bookings(): void
    {
        $appointment = $this->booking(AppointmentStatus::Completed, [
            'completed_at' => now()->subDays(60),
        ]);

        $appointment->delete();

        $this->assertSame(0, app(AppointmentArchiver::class)->sweep());
    }

    public function test_the_sweep_is_idempotent(): void
    {
        $this->booking(AppointmentStatus::Completed, ['completed_at' => now()->subDays(60)]);

        $this->assertSame(1, app(AppointmentArchiver::class)->sweep());
        $this->assertSame(0, app(AppointmentArchiver::class)->sweep(), 'A second run has nothing left to do.');
    }

    /* ------------------------------------------------------------------ */
    /* 6. It is actually scheduled                                         */
    /* ------------------------------------------------------------------ */

    /**
     * The sweep has to be wired to the scheduler, not just callable.
     *
     * A service that exists but is never run is the easiest version of this
     * feature to ship broken, and nothing else would catch it.
     */
    public function test_the_auto_archive_is_scheduled_daily(): void
    {
        $events = collect(Schedule::events())->filter(
            fn ($event) => str_contains($event->command ?? '', 'appointments:archive-completed')
        );

        $this->assertCount(1, $events, 'The archive command should be scheduled exactly once.');

        $this->assertSame('0 2 * * *', $events->first()->expression, 'It should run once a day, at 02:00.');
    }

    public function test_the_scheduled_command_archives_what_it_should(): void
    {
        $old = $this->booking(AppointmentStatus::Completed, ['completed_at' => now()->subDays(60)]);
        $recent = $this->booking(AppointmentStatus::Completed, ['completed_at' => now()->subDays(2)]);

        $this->artisan('appointments:archive-completed')
            ->assertSuccessful();

        $this->assertNotNull($old->fresh()->archived_at);
        $this->assertNull($recent->fresh()->archived_at);
    }

    /** `--dry-run` reports without writing, so it is safe to run by hand. */
    public function test_the_dry_run_changes_nothing(): void
    {
        $appointment = $this->booking(AppointmentStatus::Completed, ['completed_at' => now()->subDays(60)]);

        $this->artisan('appointments:archive-completed --dry-run')
            ->expectsOutputToContain('1 completed appointment would be archived')
            ->assertSuccessful();

        $this->assertNull($appointment->fresh()->archived_at);
    }

    /* ------------------------------------------------------------------ */
    /* 7. Deletion is not permanent by accident                             */
    /* ------------------------------------------------------------------ */

    /**
     * Deleting an appointment soft-deletes it.
     *
     * The revenue reports read these rows; a hard delete would quietly rewrite the
     * salon's income history. Nothing in the panel calls `forceDelete()`, so the
     * row survives in the database and only leaves the screens.
     */
    public function test_deleting_an_appointment_keeps_the_row(): void
    {
        $appointment = $this->booking(AppointmentStatus::Completed);

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.appointments.destroy', $appointment));

        $this->assertSoftDeleted('appointments', ['id' => $appointment->id]);
        $this->assertDatabaseHas('appointments', ['reference_number' => $appointment->reference_number]);
    }

    /** The service lines are not swept away with it either. */
    public function test_deleting_an_appointment_keeps_its_service_lines(): void
    {
        $appointment = $this->booking(AppointmentStatus::Completed);
        $lineCount = $appointment->serviceLines()->count();

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.appointments.destroy', $appointment));

        $this->assertSame($lineCount, $appointment->serviceLines()->count());
    }

    /* ------------------------------------------------------------------ */
    /* 8. Access control                                                   */
    /* ------------------------------------------------------------------ */

    public function test_a_customer_cannot_archive_or_delete_an_appointment(): void
    {
        $appointment = $this->booking(AppointmentStatus::Completed);
        $customer = $this->makeUser();

        $this->actingAs($customer)
            ->post(route('admin.appointments.archive', $appointment))
            ->assertRedirect(route('login'));

        $this->actingAs($customer)
            ->delete(route('admin.appointments.destroy', $appointment))
            ->assertRedirect(route('login'));

        $this->assertNull($appointment->fresh()->archived_at);
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'deleted_at' => null]);
    }

    public function test_a_guest_cannot_reach_the_archived_view(): void
    {
        $this->get(route('admin.appointments.archived'))->assertRedirect(route('login'));
    }
}