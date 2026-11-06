<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserAnonymizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Admin Flow 9 — deleting a registered user.
 *
 * The behaviour was agreed before it was written: **soft-delete and anonymise,
 * never cascade.** So the tests are split into what must survive and what must
 * go, and the two are asserted separately — a delete that kept the personal data
 * would pass "nothing was lost" and fail "nothing identifies anybody", and one
 * that cascaded would fail the first.
 *
 * The delete goes through the shared Yes/No dialog rather than the browser's
 * `confirm()`. Asserted on the rendered page rather than in a browser: what is
 * testable is that the button asks the dialog and that the dialog carries a real
 * form with a CSRF token and a `_method` spoof, which is the part that makes it
 * the house pattern.
 */
class AdminDeleteUserTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        return $this->makeAdmin();
    }

    /* ------------------------------------------------------------------ */
    /* 1. What goes                                                        */
    /* ------------------------------------------------------------------ */

    public function test_deleting_a_user_soft_deletes_the_row(): void
    {
        $user = $this->makeUser();

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.users.destroy', $user))
            ->assertRedirect(route('admin.users.index'));

        $this->assertSoftDeleted('users', ['id' => $user->id]);

        // Not gone: soft delete, so the row is still recoverable.
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'deleted-user-'.$user->id.'@anonymized.invalid',
        ]);
    }

    /** The point of the exercise: no personal data survives. */
    public function test_deleting_a_user_erases_their_personal_details(): void
    {
        $user = $this->makeUser([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'contact_number' => '09171234567',
            'username' => 'anareyes',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.users.destroy', $user));

        $row = User::withTrashed()->findOrFail($user->id);

        $this->assertSame('Deleted', $row->first_name);
        $this->assertSame('Customer', $row->last_name);
        $this->assertSame('deleted-'.$user->id, $row->username);
        $this->assertNotSame('09171234567', $row->contact_number);
        $this->assertNull($row->profile_photo_path);

/*
         * And the original values survive nowhere in the row.
         *
         * Read through the query builder rather than off the model, so this is the
         * stored data rather than whatever an accessor might still be able to
         * reconstruct — and the whole row, so a copy of the name in some other
         * column would be caught too.
         */
        $stored = json_encode(DB::table('users')->find($user->id));

        foreach (['Ana', 'Reyes', 'anareyes', '09171234567'] as $gone) {
            $this->assertStringNotContainsString($gone, (string) $stored, "{$gone} should not survive the deletion.");
        }
    }

    /**
     * The anonymised address can never resolve, and can never collide.
     *
     * `.invalid` is reserved by RFC 2606 precisely for names that must never
     * resolve, and the id makes it unique so a later registration cannot take it.
     */
    public function test_the_anonymised_email_can_never_be_registered_or_delivered_to(): void
    {
        $user = $this->makeUser();

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.users.destroy', $user));

        $row = User::withTrashed()->findOrFail($user->id);

        $this->assertStringEndsWith('@anonymized.invalid', $row->email);
        $this->assertTrue(UserAnonymizer::isAnonymized($row));

        // A second customer with the same id is impossible, so the address cannot
        // be reused: it is derived from the primary key, which is now taken.
        $other = $this->makeUser();

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.users.destroy', $other));

        $this->assertNotSame(
            $row->email,
            User::withTrashed()->findOrFail($other->id)->email,
        );
    }

    /** Anonymised and deactivated, so even a restored row cannot sign in. */
    public function test_a_deleted_account_cannot_sign_in_again(): void
    {
        $original = $this->makeUser(['password' => 'password']);

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.users.destroy', $original));

        $this->assertFalse(User::withTrashed()->findOrFail($original->id)->is_active);

        /*
         * Stand the customer guard back up before the login attempts.
         *
         * `actingAs($admin, 'admin')` left `admin` as the *default* guard for the
         * rest of the test, so the `guest` middleware on `/login` saw a signed-in
         * user and redirected to the dashboard before the form was ever validated.
         * Resetting it is what makes these two requests look like a signed-out
         * browser, which is the state being tested.
         */
        Auth::shouldUse('web');

        /*
         * Neither identity works.
         *
         * The original email is refused because the soft-deleted row is invisible
         * to the auth provider. The anonymised one is refused because it matches
         * no account at all — a deactivated row would be refused with a different
         * message, which is the point of checking that too.
         */
        $this->post('/login', ['login' => $original->email, 'password' => 'password'])
            ->assertSessionHasErrors('login');

        $this->post('/login', [
            'login' => User::withTrashed()->findOrFail($original->id)->email,
            'password' => 'password',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    /** Deleting twice is a 404, not a second "deleted" toast about a different name. */
    public function test_deleting_an_already_deleted_user_is_a_404(): void
    {
        $user = $this->makeUser();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->delete(route('admin.users.destroy', $user));

        // Reached through the route rather than through the model, so it needs the
        // id and the trashed row to resolve by hand.
        $trashed = User::withTrashed()->findOrFail($user->id);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.users.destroy', $trashed))
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /* 2. What survives                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * The appointments are the salon's records, and they stay whole.
     *
     * This is the assertion that distinguishes soft-delete-and-anonymise from a
     * cascade: the booking still exists, still totals the same, and still names a
     * technician. A cascade would have taken all of it.
     */
    public function test_deleting_a_user_keeps_their_appointments(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService(['price' => 500]);

        $appointment = $this->makeAppointment($user, $service, [
            'status' => \App\Enums\AppointmentStatus::Completed,
            'total_amount' => 500,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.users.destroy', $user));

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id]);
        $this->assertSame(1, $appointment->serviceLines()->count());
        $this->assertSame('500.00', $appointment->fresh()->total_amount);
    }

    /**
     * …and the revenue reports still count them.
     *
     * The practical reason for keeping them. A cascade would have silently
     * rewritten the salon's income history, which is the worst possible outcome
     * from pressing one button on a user list.
     */
    public function test_deleting_a_user_does_not_change_the_revenue_reports(): void
    {
        $admin = $this->admin();

        $this->makeAppointment($this->makeUser(), $this->makeService(['price' => 750]), [
            'status' => \App\Enums\AppointmentStatus::Completed,
            'preferred_date' => today()->subDay(),
            'total_amount' => 750,
        ]);

        $before = $this->actingAs($admin, 'admin')
            ->get(route('admin.reports.index', [
                'from' => today()->subDays(6)->toDateString(),
                'to' => today()->toDateString(),
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('750.00', $before);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.users.destroy', User::query()->first()));

        $after = $this->actingAs($admin, 'admin')
            ->get(route('admin.reports.index', [
                'from' => today()->subDays(6)->toDateString(),
                'to' => today()->toDateString(),
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('750.00', $after, 'The booking is still revenue.');
    }

    /**
     * The booking row itself is not rewritten.
     *
     * It keeps the name and contact it was made with. Those are the salon's
     * record of who they served, and scrubbing them would break the reports and
     * the appointment detail for no privacy gain — the account, which is the
     * customer's own data, is what has been anonymised.
     */
    public function test_the_appointments_themselves_are_left_alone(): void
    {
        $user = $this->makeUser(['first_name' => 'Ana', 'last_name' => 'Reyes']);

        $appointment = $this->makeAppointment($user, null, [
            'status' => \App\Enums\AppointmentStatus::Completed,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.users.destroy', $user));

        $fresh = $appointment->fresh();

        $this->assertSame('Ana Reyes', $fresh->customer_name);
        $this->assertSame($user->id, $fresh->user_id);
    }

    /* ------------------------------------------------------------------ */
    /* 3. The confirmation                                                 */
    /* ------------------------------------------------------------------ */

    /** The list offers a delete per row. */
    public function test_the_user_list_offers_a_delete_per_row(): void
    {
        $this->makeUser(['first_name' => 'Ana', 'last_name' => 'Reyes']);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.users.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('confirm-delete-user', $html);
        $this->assertStringContainsString('Delete Ana Reyes', $html);
        $this->assertStringContainsString('aria-label="Delete Ana Reyes\'s account"', $html);
    }

    /**
     * The house pattern, not the browser's `confirm()`.
     *
     * Asserted structurally rather than on a string: the dialog has to be a real
     * form carrying a CSRF token and a `_method` spoof, and it has to say Yes/No
     * rather than OK/Cancel. A `confirm()` would render the prompt text into an
     * `onsubmit` attribute and none of this would match.
     */
    public function test_the_confirmation_is_a_styled_dialog_with_a_real_form(): void
    {
        $this->makeUser();

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.users.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('name="_method" value="DELETE"', $html);
        $this->assertStringContainsString('x-bind:action="action"', $html);

        // Yes/No, and not the browser's wording.
        $this->assertStringContainsString('>Yes<', $html);
        $this->assertStringContainsString('>No<', $html);
        $this->assertStringNotContainsString('onsubmit="return confirm(', $html);
    }

    /**
     * The dialog explains what survives, which is the thing an admin cannot know
     * without being told.
     */
    public function test_the_confirmation_says_what_happens_to_the_bookings(): void
    {
        $this->makeUser();

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.users.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('What this does', $html);
        $this->assertStringContainsString('stay on the salon', $html);
        $this->assertStringContainsString('reversible', $html);

        // And it asks the server what the row is, rather than having the markup
        // carry a name that could go stale. `USER_ID` is the placeholder the
        // dialog fills in when a row is clicked.
        $this->assertStringContainsString(
            'data-confirmation-template="'.e(route('admin.users.delete-confirmation', ['user' => 'USER_ID'])).'"',
            $html,
        );
    }

    /** The payload the dialog reads, and what it contains. */
    public function test_the_confirmation_endpoint_describes_the_row(): void
    {
        $admin = $this->admin();
        $user = $this->makeUser(['first_name' => 'Ana', 'last_name' => 'Reyes']);

        $this->makeAppointment($user, null, []);
        $this->makeAppointment($user, null, []);

        $payload = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.users.delete-confirmation', $user))
            ->assertOk()
            ->json();

        $this->assertSame('Ana Reyes', $payload['name']);
        $this->assertSame($user->email, $payload['email']);
        $this->assertSame(2, $payload['appointments']);
        $this->assertSame(route('admin.users.destroy', $user), $payload['action']);
    }

    /** A deleted user has no confirmation to fetch — the dialog would be a lie. */
    public function test_the_confirmation_endpoint_404s_for_a_deleted_user(): void
    {
        $admin = $this->admin();
        $user = $this->makeUser();

        $this->actingAs($admin, 'admin')->delete(route('admin.users.destroy', $user));

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.users.delete-confirmation', User::withTrashed()->findOrFail($user->id)))
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /* 4. The list reflects it                                             */
    /* ------------------------------------------------------------------ */

    public function test_a_deleted_user_leaves_the_register(): void
    {
        $admin = $this->admin();
        $stays = $this->makeUser(['first_name' => 'Stays', 'last_name' => 'Listed']);
        $leaves = $this->makeUser(['first_name' => 'Leaves', 'last_name' => 'Listing']);

        $this->actingAs($admin, 'admin')->delete(route('admin.users.destroy', $leaves));

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.users.index'))
            ->assertOk()
            ->getContent();

        /*
         * Scoped to the table body.
         *
         * The delete flashed a toast naming the account it removed, and the toast
         * renders on this same page load — so asserting on the whole document
         * would be satisfied by the confirmation of the deletion rather than by the
         * register having actually dropped the row.
         */
        $body = substr($html, (int) strpos($html, '<tbody'), (int) strpos($html, '</tbody>') - (int) strpos($html, '<tbody'));

        $this->assertStringContainsString('Stays Listed', $body);
        $this->assertStringNotContainsString('Leaves Listing', $body);
        $this->assertStringNotContainsString($leaves->email, $body);
    }

    /** The toast says what survived, so the count is not a surprise later. */
    public function test_the_toast_reports_the_bookings_that_stay(): void
    {
        $admin = $this->admin();
        $user = $this->makeUser(['first_name' => 'Ana', 'last_name' => 'Reyes']);

        $this->makeAppointment($user, null, []);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.users.destroy', $user))
            ->assertSessionHas('toast');

        $toast = session('toast');

        $this->assertSame('success', $toast['type']);
        $this->assertStringContainsString('Ana Reyes deleted', $toast['message']);
        $this->assertStringContainsString('1 booking kept on record', $toast['message']);
    }

    /** With no bookings, the toast does not mention any. */
    public function test_the_toast_stays_short_when_there_were_no_bookings(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.users.destroy', $this->makeUser(['first_name' => 'Solo', 'last_name' => 'Customer'])));

        $message = session('toast')['message'];

        $this->assertSame('Solo Customer deleted.', $message);
    }

    /* ------------------------------------------------------------------ */
    /* 5. Access control                                                   */
    /* ------------------------------------------------------------------ */

    public function test_a_customer_cannot_delete_an_account(): void
    {
        $user = $this->makeUser();

        $this->actingAs($this->makeUser())
            ->delete(route('admin.users.destroy', $user))
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'deleted_at' => null]);
        $this->assertNotSame('deleted-user-'.$user->id.'@anonymized.invalid', $user->fresh()->email);
    }

    public function test_a_guest_cannot_delete_an_account(): void
    {
        $user = $this->makeUser();

        $this->delete(route('admin.users.destroy', $user))
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'deleted_at' => null]);
    }

    /** Deleting one user does not touch the admins. */
    public function test_the_admin_accounts_are_out_of_reach_of_this_route(): void
    {
        $admin = $this->admin();

        // The route's model binding resolves `User`, so an admin id matches no row
        // — there is no path from here to the admins table.
        $this->actingAs($admin, 'admin')
            ->delete(route('admin.users.destroy', $admin->id))
            ->assertNotFound();

        $this->assertDatabaseHas('admins', ['id' => $admin->id, 'deleted_at' => null]);
    }

    /* ------------------------------------------------------------------ */
    /* 6. The anonymiser itself                                            */
    /* ------------------------------------------------------------------ */

    /**
     * It reports what it removed, so a caller can say so.
     *
     * This is what the toast reads. Returning the previous values rather than
     * reconstructing them afterwards is what stops the message naming an
     * already-overwritten row.
     */
    public function test_the_anonymiser_reports_the_previous_values(): void
    {
        $user = $this->makeUser([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'contact_number' => '09171234567',
        ]);

        // The email is captured before the call: `getOriginal()` after a save returns
        // the *new* value, so asserting on it here would compare the anonymised
        // address with the anonymised address.
        $email = $user->email;

        $previous = app(UserAnonymizer::class)->anonymize($user);

        $this->assertSame('Ana Reyes', $previous['name']);
        $this->assertSame($email, $previous['email']);
        $this->assertSame('09171234567', $previous['phone']);
    }

    /** The description falls back to the email for an account with no name. */
    public function test_the_anonymiser_describes_a_nameless_account_by_email(): void
    {
        $user = $this->makeUser(['first_name' => '', 'last_name' => '']);

        $this->assertSame($user->email, UserAnonymizer::describe($user));
    }

    /**
     * `withTrashed()`, because the count is about what stays on record.
     *
     * A customer may have cleared finished visits off their own list, which
     * soft-deleted those rows. The salon still performed them, so the dialog has
     * to say they are kept.
     */
    public function test_the_appointment_count_includes_soft_deleted_bookings(): void
    {
        $user = $this->makeUser();

        $visible = $this->makeAppointment($user, null, []);
        $cleared = $this->makeAppointment($user, null, []);
        $cleared->delete();

        $this->assertSame(1, $visible->user->appointments()->count());
        $this->assertSame(2, UserAnonymizer::appointmentCount($user->fresh()));
    }
}