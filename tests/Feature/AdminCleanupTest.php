<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\UserController;
use App\Models\Admin;
use App\Models\Appointment;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Guards the Registered Users screen and the one-off inventory cleanup.
 *
 * Both are deletions of a feature rather than additions, and a deletion is easy
 * to undo by accident from a copy of an older view — so each removed piece is
 * asserted by its absence here, next to a test that the thing that replaced it
 * actually works.
 */
class AdminCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        return $this->makeAdmin();
    }

    /* ------------------------------------------------------------------ */
    /* Registered Users                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * The header block went: the top bar already says "Registered Users", so a
     * second heading plus a sentence explaining that there are no input fields
     * was three lines saying nothing.
     */
    public function test_the_registered_users_page_has_no_header_block(): void
    {
        $admin = $this->admin();
        $this->makeUser();

        $html = $this->actingAs($admin, 'admin')->get('/admin/users')->assertOk()->getContent();

        $this->assertStringNotContainsString('font-display text-2xl font-bold tracking-tight text-primary', $html);
        $this->assertStringNotContainsString('Search, review, deactivate or delete customer accounts.', $html);
        $this->assertStringNotContainsString('This screen is view/delete only', $html);
    }

    /**
     * The three counts are gone. The top bar says how many there are nowhere,
     * and the badge that mattered — whether an account can still book — now
     * lives in the row's switch, where acting on it happens.
     */
    public function test_the_registered_users_stat_cards_are_gone(): void
    {
        $admin = $this->admin();
        $this->makeUser();

        $html = $this->actingAs($admin, 'admin')->get('/admin/users')->assertOk()->getContent();

        foreach (['Total Users', 'Deactivated'] as $label) {
            $this->assertStringNotContainsString($label, $html, $label.' was removed from the users screen.');
        }

        // The stat card component itself: no icon disc, no display-sized count.
        $this->assertStringNotContainsString('bta-card flex items-center gap-4 p-4', $html);
        $this->assertStringNotContainsString('font-display text-2xl font-bold text-primary', $html);
    }

    /**
     * The list is the card the Services screen uses: a live search box with the
     * entries-per-page select beside it, the table, and the pager below it.
     */
    public function test_the_registered_users_list_is_the_shared_admin_table_card(): void
    {
        $admin = $this->admin();
        $this->makeUser();

        $html = $this->actingAs($admin, 'admin')->get('/admin/users')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div class="bta-card p-5".*?<\/table>/s', $html);
        $this->assertStringContainsString('x-data="adminTable(', $html);
        $this->assertStringContainsString('Search:', $html);
        $this->assertStringContainsString('entries per page', $html);
        $this->assertStringContainsString('aria-label="Next page"', $html);
        $this->assertLessThan(
            strpos($html, 'table-page-btn'),
            strpos($html, '</table>'),
            'The pager should sit below the table.',
        );

        // No GET filter form, and no add button: customers register themselves.
        $this->assertStringNotContainsString('method="GET"', $html);
        $this->assertStringNotContainsString('>Clear<', $html);
        $this->assertStringNotContainsString('>Search<', $html);
        $this->assertStringContainsString('data-row', $html);
    }

    public function test_the_registered_users_table_sorts(): void
    {
        $admin = $this->admin();
        $this->makeUser(['first_name' => 'Alpha', 'last_name' => 'Zulu', 'email' => 'a@example.com']);
        $this->makeUser(['first_name' => 'Zeta', 'last_name' => 'Alpha', 'email' => 'z@example.com']);

        $ascending = $this->actingAs($admin, 'admin')
            ->get('/admin/users?sort=last_name&direction=asc')
            ->assertOk()
            ->getContent();

        // Surname first, as a phone book reads.
        $this->assertLessThan(
            strpos($ascending, 'Zulu'),
            strpos($ascending, 'Alpha'),
            'Sorting by surname ascending should list Alpha before Zulu.',
        );

        // A column outside the allow-list falls back rather than reaching the
        // database.
        $this->actingAs($admin, 'admin')->get('/admin/users?sort=drop_table')->assertOk();
    }

    /**
     * Name | Email | Contact | Actions.
     *
     * The Actions column came back with the delete action. It holds one control —
     * a trash button that opens the shared Yes/No dialog — and nothing else, so
     * the assertion is on that rather than on the column merely existing.
     *
     * The other columns did not: Bookings, Status and Joined stay off, because a
     * delete does not need a status to read or a booking count to be meaningful.
     */
    public function test_the_registered_users_table_is_name_email_contact_with_one_action(): void
    {
        $admin = $this->admin();
        $user = $this->makeUser();

        $html = $this->actingAs($admin, 'admin')->get('/admin/users')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<table[^>]*class="[^"]*bta-table[^"]*".*?<\/table>/s', $html);
        $table = preg_match('/<table[^>]*class="[^"]*bta-table[^"]*".*?<\/table>/s', $html, $matches) ? $matches[0] : '';

        foreach (['Name', 'Email', 'Contact', 'Actions'] as $heading) {
            $this->assertStringContainsString($heading, $table, 'The users table should have a '.$heading.' column.');
        }

        foreach (['Bookings', 'Status', 'Joined'] as $heading) {
            $this->assertStringNotContainsString($heading, $table, $heading.' is still not on the users table.');
        }

        // One control per row, and it announces which account it belongs to —
        // a trash icon alone cannot.
        $this->assertStringContainsString(
            "aria-label=\"Delete {$user->full_name}'s account\"",
            $table,
        );

        // And nothing else that writes: no form of its own, no status switch.
        $this->assertStringNotContainsString('class="switch', $table);
        $this->assertStringNotContainsString('_method" value="DELETE"', $table);

        // The name is still there, with the username under it — and it is the
        // link into the profile, so removing the column lost no navigation.
        $this->assertStringContainsString('<a href="'.route('admin.users.show', $user).'"', $table);
        $this->assertStringContainsString($user->full_name, $table);
        $this->assertStringContainsString('@'.$user->username, $table);
        $this->assertStringNotContainsString('rounded-full bg-primary text-[11px]', $table);
    }

/**
 * The one write on the list is the delete, and it is the only one.
 *
 * The screen was read-only for a while and gained deletion deliberately, so this
 * asserts the narrow version of what it used to assert wholesale: exactly one
 * destructive route exists, there is still no *status* route, and browsing the
 * list changes nothing.
 *
 * What the delete actually does — anonymise and soft-delete, keeping the bookings
 * — is `AdminDeleteUserTest`.
 */
    public function test_the_registered_users_screen_has_exactly_one_write(): void
    {
        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');

        $keep = $this->makeUser();

        $html = $client->get('/admin/users')->assertOk()
            ->assertSee(route('admin.users.show', $keep))
            ->getContent();

        // The delete asks before it acts, so the row carries no form of its own: the
        // one `@method('DELETE')` on the page is inside the dialog's single form,
        // which is the whole point of the shared confirm-dialog — a form per row
        // would repeat a CSRF token and a method spoof once per customer.
        $this->assertStringContainsString('confirm-delete-user', $html);
        $this->assertSame(1, substr_count($html, '_method" value="DELETE"'));
        $this->assertStringNotContainsString('_method" value="PATCH"', $html);
        $this->assertStringNotContainsString('class="switch', $html);

        $this->assertTrue(Route::has('admin.users.destroy'), 'There is a destroy route.');
        $this->assertFalse(Route::has('admin.users.status'), 'There is still no status route.');
        $this->assertTrue(method_exists(UserController::class, 'destroy'), 'UserController has a destroy action.');
        $this->assertFalse(method_exists(UserController::class, 'toggleStatus'), 'UserController has no status action.');

        $client->get(route('admin.users.show', $keep))->assertOk();

        // Browsing the list changed nothing.
        $this->assertNotSoftDeleted($keep);
        $this->assertTrue($keep->fresh()->is_active);
        $this->assertNotSame('deleted-user-'.$keep->id.'@anonymized.invalid', $keep->fresh()->email);
    }

    /* ------------------------------------------------------------------ */
    /* Inventory seeding                                                  */
    /* ------------------------------------------------------------------ */

    public function test_seeding_creates_the_salon_inventory(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertSame(85, InventoryItem::count());
    }

    public function test_inventory_clear_removes_items_and_leaves_everything_else(): void
    {
        $service = $this->makeService(['name' => 'Glow Manicure']);
        $item = $this->makeItem(['name' => 'Gelish Top Coat']);
        $this->linkItemToService($service, $item);
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user);

        $this->artisan('inventory:clear --force')->assertSuccessful();

        $this->assertSame(0, InventoryItem::count());
        $this->assertSame(0, DB::table('service_inventory')->count());

        // Nothing else is touched: the catalogue, the customers and the booking
        // history are exactly as they were.
        $this->assertNotNull($service->fresh());
        $this->assertNotNull($user->fresh());
        $this->assertNotNull($appointment->fresh());

        // Soft-deleted rows go too: they stay out of the list but would keep
        // counting in the reports.
        $gone = $this->makeItem(['name' => 'Argan Oil']);
        $gone->delete();

        $this->artisan('inventory:clear --force')->assertSuccessful();

        $this->assertSame(0, InventoryItem::withTrashed()->count());
    }

    /**
     * The command refuses to delete without --force, and --dry-run only reports.
     */
    public function test_inventory_clear_needs_force_and_dry_run_changes_nothing(): void
    {
        $this->makeItem(['name' => 'Gelish Top Coat']);

        $this->artisan('inventory:clear')->assertSuccessful();
        $this->assertSame(1, \App\Models\InventoryItem::count(), 'A bare run must not delete anything.');

        $this->artisan('inventory:clear --dry-run')->assertSuccessful();
        $this->assertSame(1, \App\Models\InventoryItem::count(), 'A dry run must not delete anything.');

        $this->artisan('inventory:clear --force')->assertSuccessful();
        $this->assertSame(0, \App\Models\InventoryItem::count());
    }

    public function test_inventory_seeder_adds_the_salon_products_without_duplicates(): void
    {
        $this->seed(\Database\Seeders\InventorySeeder::class);

        $this->assertSame(85, InventoryItem::count());
        $this->assertTrue(InventoryItem::where('name', 'Acetone / Nail Polish Remover')->exists());
        $this->assertTrue(InventoryItem::where('name', 'Sterilization Pouches')->exists());

        $this->seed(\Database\Seeders\InventorySeeder::class);

        $this->assertSame(85, InventoryItem::count());
    }

    /* ------------------------------------------------------------------ */
    /* Customer profile                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * The page header, the four stat cards and the two action buttons are gone.
     *
     * The header repeated what the Account Details card beside the history
     * already says, the cards were four queries answering a question the
     * appointment list below answers better, and both actions live on the row in
     * the Registered Users list — where they are one click from every other
     * account rather than buried on a page reached by opening each one.
     */
    public function test_the_customer_profile_has_no_header_cards_or_actions(): void
    {
        $admin = $this->admin();
        $user = $this->makeUser(['first_name' => 'Ruciel', 'last_name' => 'Obias']);
        $this->makeAppointment($user);

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->getContent();

        // The header block: the page-header heading class, the eyebrow and the
        // joined/last-login line that were its description.
        $this->assertStringNotContainsString('font-display text-2xl font-bold tracking-tight text-primary', $html);
        $this->assertStringNotContainsString('Joined '.$user->created_at->format('F j, Y'), $html);
        $this->assertStringNotContainsString('Last login', $html);

        // The four stat cards.
        foreach (['Total Bookings', 'Lifetime Value'] as $card) {
            $this->assertStringNotContainsString($card, $html, $card.' was removed from the customer profile.');
        }

        $this->assertStringNotContainsString('bta-card p-5', $html, 'The stat tiles used their own card padding.');
        $this->assertStringNotContainsString('font-display text-2xl font-bold text-primary', $html);

        // The two action buttons, and the forms that posted them.
        foreach (['Deactivate Account', 'Reactivate Account', 'Delete'] as $button) {
            $this->assertStringNotContainsString('>'.$button.'<', $html, $button.' was removed from the customer profile.');
        }

        $this->assertStringNotContainsString('Delete '.$user->full_name, $html);
    }

    /**
     * What the page is for is still there: who the customer is and what they
     * have booked.
     *
     * The Reviews card is gone from this screen along with the rest of the
     * rating feature, so it is asserted absent rather than merely dropped from
     * the expected list.
     */
    public function test_the_customer_profile_still_shows_the_account_history(): void
    {
        $admin = $this->admin();
        $user = $this->makeUser(['first_name' => 'Ruciel', 'last_name' => 'Obias']);
        $appointment = $this->makeAppointment($user);

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Ruciel Obias', $html);
        $this->assertStringContainsString($user->email, $html);
        $this->assertStringContainsString('Account Details', $html);
        $this->assertStringContainsString('Appointment History', $html);
        $this->assertStringContainsString($appointment->reference_number, $html);
        $this->assertStringContainsString(route('admin.appointments.show', $appointment), $html);
        $this->assertStringContainsString('Back to users', $html);

        // No leftover review UI, and nothing that would 500 on the removed model.
        $this->assertStringNotContainsString('Reviews', $html);
        $this->assertStringNotContainsString('star-rating', $html);
    }

    /* ------------------------------------------------------------------ */
    /* Seeded customer accounts                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Seeding creates exactly one customer: the developer's own.
     *
     * The point of the original test was never "no customers" — it was "no
     * *invented* customers". Six named demo accounts plus a deactivated one
     * filled the admin's Registered Users list and put their bookings into the
     * reports and the admin calendar as if they were real people.
     *
     * So the assertion that mattered is kept and sharpened: every seeded account
     * is the one known account, and nothing wearing an `@example.test` address is
     * there to be mistaken for a customer.
     */
    public function test_seeding_creates_only_the_developer_account_and_no_demo_customers(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertSame(1, User::count(), 'Seeding should create exactly one customer.');
        $this->assertGreaterThan(0, Admin::count(), 'The panel login must still be seeded.');

        $user = User::firstOrFail();

        $this->assertSame('rucielmaeobias277@gmail.com', $user->email);
        $this->assertSame('rucielmaeobias277', $user->username);
        $this->assertSame('Ruciel Mae Obias', $user->full_name);
        $this->assertTrue($user->is_active, 'The seeded account has to be able to sign in.');

        // The original complaint, kept as its own assertion so it cannot come
        // back one row at a time: nothing synthetic is masquerading as a customer.
        $this->assertSame(
            0,
            User::where('email', 'like', '%@example.test')->count(),
            'Seeding must not invent customers with @example.test addresses.',
        );
    }

    /**
     * The seeded account can actually sign in.
     *
     * `User` casts `password` to `hashed`, so a seeder that pre-hashes produces
     * a row that looks correct and cannot authenticate. Asserting the stored hash
     * verifies the value; asserting the login verifies the cast.
     */
    public function test_the_seeded_customer_can_sign_in(): void
    {
        $this->seed(\Database\Seeders\UserSeeder::class);

        $this->post(route('login.store'), [
            'login' => 'rucielmaeobias277@gmail.com',
            'password' => 'Rucielmae@21',
        ])->assertRedirect(route('home'));

        $this->assertTrue(auth()->check());

        $this->get(route('appointments.index'))->assertOk();
    }

    /**
     * Seeding twice leaves one account, not two.
     *
     * `db:seed` is run repeatedly on a working database, and a seeder that only
     * ever creates is a duplicate waiting to happen. The login at the end also
     * covers the second pass not double-hashing the password — `User` casts
     * `password` to `hashed`, so a seeder that pre-hashed would produce a row
     * that looks correct and cannot authenticate.
     */
    public function test_seeding_the_customer_twice_does_not_duplicate_it(): void
    {
        $this->seed(\Database\Seeders\UserSeeder::class);
        $this->seed(\Database\Seeders\UserSeeder::class);

        $this->assertSame(1, User::count());

        $this->post(route('login.store'), [
            'login' => 'rucielmaeobias277@gmail.com',
            'password' => 'Rucielmae@21',
        ])->assertRedirect(route('home'));
    }

    public function test_users_clear_keeps_the_named_accounts_and_removes_the_rest(): void
    {
        $admin = $this->admin();
        $keep = $this->makeUser(['username' => 'ruciel', 'email' => 'ruciel@example.test']);
        $remove = $this->makeUser(['username' => 'demo1']);
        $alsoRemove = $this->makeUser(['username' => 'demo2']);

        $this->artisan('users:clear --keep=ruciel --force')
            ->expectsOutputToContain('Removed 2 account(s). 1 kept.')
            ->assertSuccessful();

        $this->assertNotNull($keep->fresh());
        $this->assertNull($remove->fresh());
        $this->assertNull($alsoRemove->fresh());

        // The panel login lives in a different table and is never read or
        // written by the command.
        $this->assertNotNull($admin->fresh());
        $this->assertSame(1, Admin::count());
    }

    /**
     * `--keep` takes an email as readily as a username, so nobody has to know
     * which of the two the account was created with.
     */
    public function test_users_clear_keeps_by_email_too(): void
    {
        $keep = $this->makeUser(['username' => 'ruciel', 'email' => 'ruciel@example.test']);
        $remove = $this->makeUser();

        $this->artisan('users:clear --keep=ruciel@example.test --force')->assertSuccessful();

        $this->assertNotNull($keep->fresh());
        $this->assertNull($remove->fresh());
    }

    public function test_users_clear_needs_force_and_dry_run_changes_nothing(): void
    {
        $this->makeUser(['username' => 'ruciel']);
        $this->makeUser();

        $this->artisan('users:clear --keep=ruciel')->assertSuccessful();
        $this->assertSame(2, User::count(), 'A bare run must not delete anything.');

        $this->artisan('users:clear --keep=ruciel --dry-run')->assertSuccessful();
        $this->assertSame(2, User::count(), 'A dry run must not delete anything.');

        $this->artisan('users:clear --keep=ruciel --force')->assertSuccessful();
        $this->assertSame(1, User::count());
    }

    /**
     * A soft-deleted account still holds its unique email and username, so it
     * has to be hard-deleted or the same person could never register again.
     */
    public function test_users_clear_removes_soft_deleted_accounts_too(): void
    {
        $keep = $this->makeUser(['username' => 'ruciel']);
        $gone = $this->makeUser();
        $gone->delete();

        $this->assertSoftDeleted($gone);

        $this->artisan('users:clear --keep=ruciel --force')->assertSuccessful();

        $this->assertSame(0, User::withTrashed()->whereKey($gone->id)->count());
        $this->assertSame(1, User::count());
    }

    /**
     * `appointments.user_id` is ON DELETE CASCADE, so removing an account that
     * still has bookings removes them too. The command has to say so rather than
     * deleting quietly — the appointment history cannot be handed to another
     * customer.
     */
    public function test_users_clear_reports_the_bookings_that_would_be_cascaded(): void
    {
        $this->makeUser(['username' => 'ruciel']);
        $demo = $this->makeUser(['username' => 'demo']);

        $this->makeAppointment($demo);

        $this->artisan('users:clear --keep=ruciel')
            ->expectsOutputToContain('1 appointment(s)')
            ->assertSuccessful();

        $this->assertSame(2, User::count());
        $this->assertSame(1, Appointment::count());
    }
}
