
<?php

use App\Http\Controllers\Admin\AppointmentController as AdminAppointmentController;
use App\Http\Controllers\Admin\Auth\AdminPasswordResetLinkController;
use App\Http\Controllers\Admin\Auth\AdminSessionController;
use App\Http\Controllers\Admin\CalendarController;
use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\ContactMessageController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\NotificationFeedController;
use App\Http\Controllers\Admin\NotificationReadController;
use App\Http\Controllers\Admin\ItemTagController;
use App\Http\Controllers\Admin\ProfileController as AdminProfileController;
use App\Http\Controllers\Admin\PromoController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ServiceCategoryController as AdminServiceCategoryController;
use App\Http\Controllers\Admin\ServiceController as AdminServiceController;
use App\Http\Controllers\Admin\TermsController;
use App\Http\Controllers\Admin\TechnicianController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\Customer\AppointmentController;
use App\Http\Controllers\Customer\CancelAppointmentController;
use App\Http\Controllers\Customer\NotificationController;
use App\Http\Controllers\Customer\ProfileController;
use App\Http\Controllers\Customer\RescheduleAppointmentController;
use App\Http\Controllers\Customer\TermsController as CustomerTermsController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PromoController as CustomerPromoController;
use App\Http\Controllers\SessionStatusController;
use App\Http\Controllers\ServiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public / customer routes
|--------------------------------------------------------------------------
*/

Route::get('/', HomeController::class)->name('home');
Route::get('/about', [HomeController::class, 'about'])->name('about');

// Customer Flow 18 — Promo / Special Offers (public, read-only).
// Promos have their own page; the home page no longer embeds this section.
Route::get('/promos', [CustomerPromoController::class, 'index'])->name('promos.index');

// Customer Flow 4 — Browse Services (public, read-only)
Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
Route::get('/services/{service}', [ServiceController::class, 'show'])->name('services.show');
// Customer Flow 15 — Refined Grid View (category-filtered)
Route::get('/services-grid', [ServiceController::class, 'refined'])->name('services.refined');

// Customer Flow 12 — Contact Us
Route::get('/contact', [ContactController::class, 'create'])->name('contact.create');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:12,1')->name('contact.store');

// Published T&C, linked from the booking / cancellation / reschedule checkboxes
Route::get('/terms/{category}', CustomerTermsController::class)->name('terms.show');

/*
|--------------------------------------------------------------------------
| Customer auth
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    // Customer Flow 1 — Register
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->name('register.store');

    // Customer Flow 2 — Login
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('login.store');

    // Customer Flow 3 — Forgot Password (4-step wizard)
    Route::prefix('password')->name('password.')->group(function () {
        Route::get('/', [PasswordResetLinkController::class, 'create'])->name('request');
        Route::post('/', [PasswordResetLinkController::class, 'store'])->middleware('throttle:6,1')->name('email');
        Route::get('/code', [PasswordResetLinkController::class, 'code'])->name('code');
        Route::post('/code', [PasswordResetLinkController::class, 'verifyCode'])->middleware('throttle:12,1')->name('verify');
        Route::get('/reset', [PasswordResetLinkController::class, 'reset'])->name('reset');
        Route::post('/reset', [PasswordResetLinkController::class, 'update'])->name('update');
    });
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Session liveness probe
|--------------------------------------------------------------------------
|
| One tiny JSON route, used by `sessionSync` in resources/js/app.js.
|
| It exists for the back/forward-cache case: a page restored from the bfcache was
| never re-requested, so it cannot know the session was invalidated by a logout
| in another tab. The client asks here on `pageshow`, and is told whether this
| browser still holds a session.
|
| Deliberately unauthenticated and deliberately unguarded, because its whole job
| is to answer for a browser that may no longer be signed in. It reports which
| guard is active and nothing else — no ids, no names, no session data — so it is
| not worth protecting, and a redirect here would defeat the check.
|
| No-store headers, for the same reason the authenticated middleware sets them:
| a cached answer to "are you still signed in?" is the one answer that must
| never be stale.
*/

Route::get('/session-status', SessionStatusController::class)
    ->middleware('throttle:120,1')
    ->name('session-status');

/*
|--------------------------------------------------------------------------
| Customer — authenticated
|--------------------------------------------------------------------------
*/

/*
 * `no.store` sits inside the authenticated group rather than beside it in every
 * route, so a new protected page cannot be added without it. See
 * App\Http\Middleware\PreventAuthenticatedCaching for what it is for.
 *
 * `auth.customer` is the framework's `auth` with one addition: it remembers where
 * a guest was heading, under the customer guard's own key. It cannot simply be an
 * extra entry in front of `auth` — route middleware is sorted against a fixed
 * priority list that `auth` is on and ordinary middleware is not, so anything
 * listed before it here is sorted behind it and never runs. See
 * App\Http\Middleware\AuthenticateCustomer.
 */
Route::middleware(['auth.customer', 'user.active', 'no.store'])->group(function () {
    // Customer Flow 5 — Book Appointment.
    // Guests may browse the catalogue but must register before booking, so the
    // booking is always tied to an account.
    Route::get('/book', [AppointmentController::class, 'create'])->name('appointments.create');

    /*
     * The one write route in the app that carried no rate limit.
     *
     * Contact is capped at 12/min, login at 10/min, password reset at 6/min, and
     * this one — the most expensive write in the product, since it validates a
     * basket, opens a transaction, writes an appointment and its lines, decrements
     * linked stock and sends a notification — was uncapped.
     *
     * Twelve a minute, keyed by account, defined in AppServiceProvider. Paired with
     * the idempotency key below, which is the part that stops a *double-tap*: the
     * limiter would only slow the second request down, not stop it.
     */
    Route::post('/book', [AppointmentController::class, 'store'])
        ->middleware('throttle:booking')
        ->name('appointments.store');

    // Customer Flow 13 — the customer Dashboard is gone.
    //
    // It duplicated the landing page's own "Book an Appointment" hero call to
    // action behind a second URL, and being a *protected* page that meant two
    // authenticated surfaces to keep in step rather than one. A customer signing
    // in is sent to `home` now, which is where the marketing copy and the booking
    // button already lived.
    //
    // Its "Your Next Appointment" idea is not lost: `appointments.index` is the
    // one place a customer goes to see their bookings, and it already lists them.

    // Customer Flow 5 — My Appointments / Transactions.
    // The View action is a dialog on the list, not a page of its own. This route
    // is kept only so an old bookmark or a notification link still lands
    // somewhere useful: it 302s to the list with `?view=ID`, which opens the
    // dialog on that booking. See `AppointmentController::show`.
    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
    Route::get('/appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show');

    // Customer Flow 5 — Book Again.
    //
    // Declared before the `{appointment}/cancel` and `{appointment}/reschedule`
    // literals below only in the sense that it shares the parameter; it has its own
    // third segment, so nothing shadows it.
    //
    // A redirect into `appointments.create?from=…` rather than its own form, so
    // there is one booking screen with one set of rules. Refused for anything that
    // is not this customer's own Completed booking — see the controller.
    Route::get('/appointments/{appointment}/book-again', [AppointmentController::class, 'bookAgain'])
        ->name('appointments.book-again');

    // Customer Flow 7 — Cancel Appointment
    Route::get('/appointments/{appointment}/cancel', [CancelAppointmentController::class, 'edit'])
        ->name('appointments.cancel');
    Route::patch('/appointments/{appointment}/cancel', [CancelAppointmentController::class, 'update'])
        ->name('appointments.cancel.update');

    // Customer Flow 8 — Reschedule Appointment
    Route::get('/appointments/{appointment}/reschedule', [RescheduleAppointmentController::class, 'edit'])
        ->name('appointments.reschedule');
    Route::patch('/appointments/{appointment}/reschedule', [RescheduleAppointmentController::class, 'update'])
        ->name('appointments.reschedule.update');

    // Customer Flow 5 — clearing finished bookings off the list.
    // Two routes for the same idea, exactly as the notifications list has them:
    // the bulk route is `/appointments` itself (ids arrive in the body) so it
    // cannot be shadowed by the `{appointment}` parameter below.
    //
    // Both refuse anything that is not Completed or Cancelled, in the controller
    // rather than only in the view — a crafted DELETE must not be able to
    // remove a booking the salon still has to act on.
    Route::delete('/appointments', [AppointmentController::class, 'destroyMany'])
        ->name('appointments.destroy-many');
    Route::delete('/appointments/{appointment}', [AppointmentController::class, 'destroy'])
        ->name('appointments.destroy');

    // Customer Flow 9 — Notifications.
    //
    // The navbar bell is a plain link to this list, not a dropdown: opening the
    // bell used to be the read receipt, which meant the page itself carried no
    // read controls and every "unread" was settled by a click somewhere else.
    // Reading now happens here, one row or all of them, and both write endpoints
    // answer JSON with the fresh unread count so the badge in the header and the
    // "(2)" in the tab title can be corrected without a re-render.
    //
    // `read-all` is declared before `{notification}/read` so the literal path is
    // not swallowed by the parameter below it.
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    // Polled by the navbar bell so its badge stays current without a reload.
    // `since` is the newest id the page already has, so an idle tab costs one
    // indexed lookup rather than a re-read of the inbox.
    Route::get('/notifications/feed', [NotificationController::class, 'feed'])
        ->middleware('throttle:120,1')
        ->name('notifications.feed');
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('notifications.read-all');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->whereUuid('notification')
        ->name('notifications.read');
    Route::delete('/notifications', [NotificationController::class, 'destroyMany'])
        ->name('notifications.destroy-many');
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])
        ->whereUuid('notification')
        ->name('notifications.destroy');

    // Customer Flow 11 — Profile Management
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile/photo', [ProfileController::class, 'destroyPhoto'])->name('profile.photo.destroy');
});

/*
|--------------------------------------------------------------------------
| Live slot lookup for the booking form's date picker
|--------------------------------------------------------------------------
|
| Left public so service pages can show availability to visitors before they
| commit to logging in.
*/

Route::get('/book/slots', [AppointmentController::class, 'slots'])
    ->middleware('throttle:60,1')
    ->name('appointments.slots');

/*
|--------------------------------------------------------------------------
| Admin auth (separate guard — never shares a session with customers)
|--------------------------------------------------------------------------
*/

Route::prefix('admin')->name('admin.')->group(function () {
    // Admin Flow 1 — Admin Login
    Route::middleware('admin.guest')->group(function () {
        Route::get('/login', [AdminSessionController::class, 'create'])->name('login');
        Route::post('/login', [AdminSessionController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('login.store');

        /*
         * Password recovery, on Laravel's broker rather than the customer's
         * six-digit wizard.
         *
         * The `admin.guest` middleware is on these too, for the same reason it is
         * on the login form: someone already signed in has no business resetting
         * a password, and the controller would only redirect them afterwards.
         *
         * Throttled tighter than the login itself. A login attempt is checked
         * against one account; a reset request sends mail, so an unbounded one is
         * both a way to spam an address and a way to fill the inbox of a
         * compromised account.
         *
         * `admin.password.sent` is declared before `admin.password.reset` so the
         * literal path is not swallowed by the `{token}` parameter below it.
         */
        Route::prefix('password')->name('password.')->group(function () {
            Route::get('/', [AdminPasswordResetLinkController::class, 'create'])->name('request');
            Route::post('/', [AdminPasswordResetLinkController::class, 'store'])
                ->middleware('throttle:3,1')
                ->name('email');
            Route::get('/sent', [AdminPasswordResetLinkController::class, 'sent'])->name('sent');
            Route::get('/reset/{token}', [AdminPasswordResetLinkController::class, 'edit'])->name('reset');
            Route::post('/reset', [AdminPasswordResetLinkController::class, 'update'])->name('update');
        });
    });

    Route::post('/logout', [AdminSessionController::class, 'destroy'])
        ->middleware('auth.admin')
        ->name('logout');

    /*
    |----------------------------------------------------------------------
    | Admin panel
    |----------------------------------------------------------------------
    |
    | `auth.admin` keeps customers out. The `admin.role:*` middleware then narrows
    | each screen to the roles listed in App\Enums\AdminRole: Super Admin
    | holds everything, Manager runs the salon but cannot edit legal text or
    | destroy customer records, and Staff get the appointment queue plus
    | read-only reference screens.
    |
    */

    /*
     | `no.store` is here for the same reason as the customer group above: an
     | authenticated page must never be handed to the browser's back/forward
     | cache, or Back after a logout redraws the panel.
     |
     | `auth.admin` records its own guest destination for the same reason
     | `auth.customer` does on the other side. The two share one session cookie,
     | so an unscoped destination is whichever guard bounced last — and an admin
     | who was sent to the customer dashboard bounced straight back out again,
     | which made a successful sign-in look like it had done nothing.
     */
    Route::middleware(['auth.admin', 'no.store'])->group(function () {
        // Admin Flow 2 — Dashboard
        Route::get('/', AdminDashboardController::class)
            ->middleware('admin.role:admin.dashboard.view')
            ->name('dashboard');

        // Admin Flow 3 — Appointment Management
        //
        // `destroy` is excluded from the resource and re-declared below as an
        // explicit DELETE, so the "settled statuses only" rule it enforces is
        // visible on the route rather than implied by a resource default.
        //
        // `store` is excluded for the same kind of reason, and it was a live 500.
        // Bookings are customer-initiated — there is no admin create form, and
        // `create` was already excluded because of it — but leaving `store`
        // registered put `POST /admin/appointments` on a controller with no
        // `store()` method, so the route answered 500 rather than 404. Nothing
        // linked to it, which is exactly why it survived: a route nothing links
        // to is only ever exercised by the tests, and no test posted to it.
        Route::resource('appointments', AdminAppointmentController::class)
            ->except(['create', 'store', 'destroy'])
            ->middleware('admin.role:admin.appointments.manage');

        // Archive and permanent delete. Both re-check the status server-side in
        // AppointmentArchiver — see the note there about why an in-progress
        // booking can be neither.
        Route::delete('appointments/{appointment}', [AdminAppointmentController::class, 'destroy'])
            ->middleware('admin.role:admin.appointments.manage')
            ->name('appointments.destroy');
        Route::post('appointments/{appointment}/archive', [AdminAppointmentController::class, 'archive'])
            ->middleware('admin.role:admin.appointments.manage')
            ->name('appointments.archive');
        Route::post('appointments/{appointment}/restore', [AdminAppointmentController::class, 'restore'])
            ->middleware('admin.role:admin.appointments.manage')
            ->name('appointments.restore');

        // The Archived view: settled rows that have left the working list.
        // Declared before the resource's `show` so the literal `archived` path is
        // not swallowed by the `{appointment}` parameter.
        Route::get('/appointments-archived', [AdminAppointmentController::class, 'archived'])
            ->middleware('admin.role:admin.appointments.manage')
            ->name('appointments.archived');

        // The working list's poller: the current state of the rows it is showing,
        // so a status change made anywhere — this tab, another tab, another admin
        // — redraws the row that changed instead of waiting for a page load.
        //
        // Declared here for the same reason `archived` is: a literal
        // `appointments/row-state` would otherwise be swallowed by the resource's
        // `{appointment}` parameter, which is declared above it.
        Route::get('/appointments-row-state', [AdminAppointmentController::class, 'rowStates'])
            ->middleware('admin.role:admin.appointments.manage')
            ->name('appointments.row-state');

        // Bulk restore from the archive screen's select-all. Declared alongside
        // the literal `archived` path above because it is the same kind of
        // route — a fixed segment that a `{appointment}` parameter would
        // otherwise swallow.
        Route::post('/appointments-restore-many', [AdminAppointmentController::class, 'restoreMany'])
            ->middleware('admin.role:admin.appointments.manage')
            ->name('appointments.restore-many');

        Route::patch('appointments/{appointment}/status', [AdminAppointmentController::class, 'updateStatus'])
            ->middleware('admin.role:admin.appointments.manage')
            ->name('appointments.status');
        Route::patch('appointments/{appointment}/notes', [AdminAppointmentController::class, 'updateNotes'])
            ->middleware('admin.role:admin.appointments.manage')
            ->name('appointments.notes');
        Route::patch('appointments/{appointment}/down-payment', [AdminAppointmentController::class, 'updateDownPayment'])
            ->middleware('admin.role:admin.appointments.manage')
            ->name('appointments.down-payment');

        // Admin Flow 4 — Service & Item overview (combined)
        Route::get('/catalog', [CatalogController::class, 'index'])
            ->middleware('admin.role:admin.catalog.view')
            ->name('catalog.index');
        Route::get('/catalog/{type}/{id}/edit', [CatalogController::class, 'edit'])
            ->middleware('admin.role:admin.catalog.manage')
            ->name('catalog.edit');

        // Admin Flow 5 — Service Management.
        // Index is split out so a read-only role can browse the catalogue
        // while `admin.role:admin.catalog.manage` guards every write route.
        Route::get('/services', [AdminServiceController::class, 'index'])
            ->middleware('admin.role:admin.catalog.view')
            ->name('services.index');
        Route::resource('services', AdminServiceController::class)
            ->except(['show', 'index'])
            ->middleware('admin.role:admin.catalog.manage');
        // The Available column in the list is a switch, not a checkbox post, so
        // flipping a row is one request with no optimistic UI to roll back.
        Route::patch('services/{service}/toggle', [AdminServiceController::class, 'toggleAvailability'])
            ->middleware('admin.role:admin.catalog.manage')
            ->name('services.toggle');
        Route::get('services/{service}/variants', [AdminServiceController::class, 'variants'])
            ->middleware('admin.role:admin.catalog.manage')
            ->name('services.variants');
        Route::post('services/{service}/variants', [AdminServiceController::class, 'storeVariant'])
            ->middleware('admin.role:admin.catalog.manage')
            ->name('services.variants.store');

        // Categories still carry the colour the admin calendar paints their
        // chips, so they are guarded by the same abilities as the rest of the
        // catalogue.
        Route::get('/categories', [AdminServiceCategoryController::class, 'index'])
            ->middleware('admin.role:admin.catalog.view')
            ->name('categories.index');
        Route::resource('categories', AdminServiceCategoryController::class)
            ->except(['show', 'index'])
            ->middleware('admin.role:admin.catalog.manage');
        Route::patch('categories/{category}/toggle', [AdminServiceCategoryController::class, 'toggleActive'])
            ->middleware('admin.role:admin.catalog.manage')
            ->name('categories.toggle');
        Route::patch('variants/{variant}', [AdminServiceController::class, 'updateVariant'])
            ->middleware('admin.role:admin.catalog.manage')
            ->name('variants.update');
        Route::delete('variants/{variant}', [AdminServiceController::class, 'destroyVariant'])
            ->middleware('admin.role:admin.catalog.manage')
            ->name('variants.destroy');

        // Technicians — the people a customer picks when booking.
        // Index is split out so a read-only role can browse the roster while
        // `admin.role:admin.technicians.manage` guards every write route.
        Route::get('/technicians', [TechnicianController::class, 'index'])
            ->middleware('admin.role:admin.technicians.view')
            ->name('technicians.index');
        Route::resource('technicians', TechnicianController::class)
            ->except(['show', 'index'])
            ->middleware('admin.role:admin.technicians.manage');
        // The Active column in the list is a switch, not a checkbox post, so
        // flipping a row is one request with no optimistic UI to roll back.
        Route::patch('technicians/{technician}/toggle', [TechnicianController::class, 'toggleActive'])
            ->middleware('admin.role:admin.technicians.manage')
            ->name('technicians.toggle');

        // Admin Flow 6 — Inventory / Item Management
        Route::get('/inventory', [InventoryController::class, 'index'])
            ->middleware('admin.role:admin.inventory.view')
            ->name('inventory.index');
        // The placeholder is named for the model so the controller's
        // `$inventory_item` is bound by name rather than by a type fallback.
        // `route('admin.inventory.update')` with no argument then fails at the
        // route() call that made the mistake, not later.
        Route::resource('inventory', InventoryController::class)
            ->except(['show', 'index'])
            ->parameters(['inventory' => 'inventory_item'])
            ->middleware('admin.role:admin.inventory.manage');

        // Admin Flow 7 — Low-Stock / Not-Available Tagging.
        // NOTE: /tags/bulk must be declared before /tags/{item} or the
        // literal path is swallowed by the {item} parameter.
        Route::get('/tags', [ItemTagController::class, 'index'])
            ->middleware('admin.role:admin.inventory.view')
            ->name('tags.index');
        Route::patch('/tags/bulk', [ItemTagController::class, 'bulkUpdate'])
            ->middleware('admin.role:admin.tags.manage')
            ->name('tags.bulk');
        Route::patch('/tags/{item}', [ItemTagController::class, 'update'])
            ->middleware('admin.role:admin.tags.manage')
            ->name('tags.update');

        // Admin Flow 8 — Calendar & Blocked Slots.
        //
        // The specific-date half of "when is the salon open": a month grid, and
        // the ranges behind each day. The recurring half — the weekly schedule,
        // the slot length, the booking window — is configuration rather than a
        // record, and lives in `salon_settings`, which `BookingAvailability` reads
        // on every request. It has no admin screen: see the note in
        // `SalonSetting::current()`.
        //
        // `blocks` is declared before `blocks/{block}` for the same reason
        // `read-all` sits above `{notification}/read` — a literal path declared
        // after a parameter route is swallowed by it.
        Route::get('/calendar', [CalendarController::class, 'index'])
            ->middleware('admin.role:admin.calendar.manage')
            ->name('calendar.index');
        Route::post('/calendar/blocks', [CalendarController::class, 'store'])
            ->middleware('admin.role:admin.calendar.manage')
            ->name('calendar.store');
        Route::patch('/calendar/blocks/{block}', [CalendarController::class, 'update'])
            ->middleware('admin.role:admin.calendar.manage')
            ->name('calendar.update');
        Route::delete('/calendar/blocks/{block}', [CalendarController::class, 'destroy'])
            ->middleware('admin.role:admin.calendar.manage')
            ->name('calendar.destroy');

        // Admin Flow 9 — Registered Users Management.
        // The list is a register: customers register themselves, so there is no
        // create. There is a delete, and it is deliberately not a cascade — it
        // anonymises the account and soft-deletes it, keeping the booking history
        // the revenue reports read. See App\Services\UserAnonymizer.
        //
        // There is still no status route: the panel does not switch a live account
        // off, only removes it. And the delete is gated on `users.view` rather
        // than a `users.manage` twin, because the ability table has one entry for
        // this screen and inventing a second would mean the panel could show a
        // button it did not check.
        Route::get('/users', [UserController::class, 'index'])
            ->middleware('admin.role:admin.users.view')
            ->name('users.index');
        Route::get('/users/{user}', [UserController::class, 'show'])
            ->middleware('admin.role:admin.users.view')
            ->name('users.show');

        // What the confirmation dialog says about this row. A GET rather than
        // inline JSON in the markup, so the wording is the server's and cannot
        // drift from the action — see UserController::destroyConfirmation().
        Route::get('/users/{user}/delete-confirmation', [UserController::class, 'destroyConfirmation'])
            ->middleware('admin.role:admin.users.view')
            ->name('users.delete-confirmation');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])
            ->middleware('admin.role:admin.users.view')
            ->name('users.destroy');

        // Admin Flow 10 — Terms & Conditions Editor
        Route::get('/terms', [TermsController::class, 'index'])
            ->middleware('admin.role:admin.terms.view')
            ->name('terms.index');
        Route::get('/terms/create', [TermsController::class, 'create'])
            ->middleware('admin.role:admin.terms.manage')
            ->name('terms.create');
        Route::post('/terms', [TermsController::class, 'store'])
            ->middleware('admin.role:admin.terms.manage')
            ->name('terms.store');
        Route::get('/terms/{term}/edit', [TermsController::class, 'edit'])
            ->middleware('admin.role:admin.terms.manage')
            ->name('terms.edit');
        Route::put('/terms/{term}', [TermsController::class, 'update'])
            ->middleware('admin.role:admin.terms.manage')
            ->name('terms.update');
        Route::post('/terms/{term}/publish', [TermsController::class, 'publish'])
            ->middleware('admin.role:admin.terms.manage')
            ->name('terms.publish');
        Route::delete('/terms/{term}', [TermsController::class, 'destroy'])
            ->middleware('admin.role:admin.terms.manage')
            ->name('terms.destroy');

        // Admin Flow 12 — Sales / Usage Reports
        Route::get('/reports', [ReportController::class, 'index'])
            ->middleware('admin.role:admin.reports.view')
            ->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])
            ->middleware('admin.role:admin.reports.view')
            ->name('reports.export');

        // The Inventory report's CSV. Sits here rather than under /inventory so
        // both exports are reachable from the Reports screen, which is where an
        // admin goes to export anything. Guarded by the inventory ability because
        // it reads the stock table, not by the reports one.
        Route::get('/inventory/export', [InventoryController::class, 'export'])
            ->middleware('admin.role:admin.inventory.view')
            ->name('inventory.export');

        // Admin Flow 13 — Promo / Announcements
        Route::resource('promos', PromoController::class)
            ->except(['show'])
            ->middleware('admin.role:admin.promos.manage');
        Route::post('/promos/{promo}/announce', [PromoController::class, 'announce'])
            ->middleware('admin.role:admin.promos.manage')
            ->name('promos.announce');

        // Contact messages from Customer Flow 12
        Route::get('/messages', [ContactMessageController::class, 'index'])
            ->middleware('admin.role:admin.messages.manage')
            ->name('messages.index');
        Route::patch('/messages/{message}', [ContactMessageController::class, 'update'])
            ->middleware('admin.role:admin.messages.manage')
            ->name('messages.update');
        Route::delete('/messages/{message}', [ContactMessageController::class, 'destroy'])
            ->middleware('admin.role:admin.messages.manage')
            ->name('messages.destroy');

        // Admin profile — every role may edit its own account
        Route::get('/profile', [AdminProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [AdminProfileController::class, 'update'])->name('profile.update');

        // The top bar's live notification bell. Polled rather than pushed, for
        // the same reason the customer bell is: there is no WebSocket server in
        // this project. Throttled generously because every open admin tab polls
        // it, and it is a couple of counts and a handful of rows.
        Route::get('/notifications/feed', NotificationFeedController::class)
            ->middleware('throttle:120,1')
            ->name('notifications.feed');

        // Marking the bell's enquiries read without leaving the page, so the
        // badge and the tab title can settle the moment the row is clicked
        // rather than whenever the next page load happens to re-render them.
        //
        // `read-all` is declared before `{message}/read` so the literal segment
        // is not captured as a message id.
        Route::post('/notifications/read-all', [NotificationReadController::class, 'readAll'])
            ->name('notifications.read-all');
        Route::patch('/notifications/{message}/read', [NotificationReadController::class, 'read'])
            ->name('notifications.read');
    });
});
