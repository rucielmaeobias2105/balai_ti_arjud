<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\InquiryTopic;
use App\Models\ContactMessage;
use App\Notifications\AppointmentConfirmedNotification;
use App\Support\TabTitle;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The unread count in the browser tab title: "(3) NOTIFICATIONS | Balai ti Arjud",
 * the way Messenger and Facebook do it.
 *
 * Two properties matter and they are tested separately.
 *
 * The first is that the count is *right*. Rendered server-side, so it is correct
 * before the bundle has loaded — that is the window in which a user reads the
 * title to decide whether to switch to a background tab. A guest's tab carries no
 * prefix at all, because "(0) HOME" is noise.
 *
 * The second is that it is *live*, driven by the same poll as the bell badge.
 * Asserted against the JavaScript source rather than in a browser: what has to be
 * true is that one fetched count feeds both the badge and `document.title`, and
 * that there is no second polling mechanism for the title that could drift.
 */
class TabTitleUnreadCountTest extends TestCase
{
    use RefreshDatabase;

    private function unescaped(string $html): string
    {
        return str_replace('\\', '', str_replace('\u0022', '"', $html));
    }

    /** The `<title>` the page actually rendered. */
    private function title(string $html): string
    {
        preg_match('/<title>(.*?)<\/title>/s', $html, $m);

        return trim($m[1] ?? '');
    }

    /* ------------------------------------------------------------------ */
    /* 1. Rendered server-side                                             */
    /* ------------------------------------------------------------------ */

    public function test_a_guest_tab_title_carries_no_count(): void
    {
        $this->makeSalonSettings();

        $title = $this->title($this->get('/')->assertOk()->getContent());

        $this->assertSame('HOME | Balai ti Arjud', $title);
    }

    public function test_a_customer_with_no_unread_gets_a_plain_title(): void
    {
        $user = $this->makeUser();

        $title = $this->title($this->actingAs($user)->get('/appointments')->assertOk()->getContent());

        $this->assertSame('MY APPOINTMENTS | Balai ti Arjud', $title);
    }

    public function test_a_customers_unread_notifications_prefix_the_tab_title(): void
    {
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user);

        $user->notify(new AppointmentConfirmedNotification($appointment));
        $user->notify(new WelcomeNotification());

        $title = $this->title($this->actingAs($user)->get('/appointments')->assertOk()->getContent());

        $this->assertSame('(2) MY APPOINTMENTS | Balai ti Arjud', $title);
    }

    /**
     * One unread, exactly as Messenger renders it.
     *
     * The single-count case is the one that proves the format is right rather
     * than merely plausible: "(1) HOME | Balai ti Arjud" is what a user would see
     * if a booking status changed while they were on another tab.
     */
    public function test_a_single_unread_notification_is_shown_the_way_messenger_shows_it(): void
    {
        $user = $this->makeUser();

        $user->notify(new WelcomeNotification());

        $title = $this->title($this->actingAs($user)->get('/')->assertOk()->getContent());

        $this->assertSame('(1) HOME | Balai ti Arjud', $title);
    }

    /** Read notifications do not count. */
    public function test_read_notifications_do_not_prefix_the_title(): void
    {
        $user = $this->makeUser();

        $user->notify(new WelcomeNotification());
        $user->unreadNotifications->markAsRead();

        $title = $this->title($this->actingAs($user)->get('/appointments')->assertOk()->getContent());

        $this->assertSame('MY APPOINTMENTS | Balai ti Arjud', $title);
    }

    /* ------------------------------------------------------------------ */
    /* 2. The admin side                                                   */
    /* ------------------------------------------------------------------ */

    public function test_the_admin_title_counts_unseen_bookings_but_not_unread_enquiries(): void
    {
        $admin = $this->makeAdmin();

        $this->makeAppointment($this->makeUser(), null, ['status' => AppointmentStatus::Pending]);
        $this->makeAppointment($this->makeUser(), null, ['status' => AppointmentStatus::Pending]);
        ContactMessage::create([
            'name' => 'Ana',
            'email' => 'ana@example.test',
            'topic' => InquiryTopic::Others,
            'message' => 'Do you have a slot on Friday?',
        ]);

        $title = $this->title($this->actingAs($admin, 'admin')->get('/admin')->assertOk()->getContent());

        // The enquiry is unread but has no Messages row or notification list to
        // appear in, so it must not add a number the admin cannot account for.
        $this->assertSame('(2) DASHBOARD | Balai ti Arjud Admin', $title);
    }

    public function test_a_quiet_admin_panel_gets_a_plain_title(): void
    {
        $title = $this->title(
            $this->actingAs($this->makeAdmin(), 'admin')->get('/admin')->assertOk()->getContent()
        );

        $this->assertSame('DASHBOARD | Balai ti Arjud Admin', $title);
    }

    /**
     * The count must match the bell's, or the tab and the badge disagree.
     */
    public function test_the_admin_title_count_is_the_same_number_the_bell_badges(): void
    {
        $admin = $this->makeAdmin();

        $this->makeAppointment($this->makeUser(), null, ['status' => AppointmentStatus::Pending]);
        ContactMessage::create([
            'name' => 'Ana',
            'email' => 'ana@example.test',
            'topic' => InquiryTopic::Others,
            'message' => 'Do you have a slot on Friday?',
        ]);

        $html = $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->getContent();
        $unescaped = $this->unescaped($html);

        // One pending booking, one enquiry. The enquiry still reaches the poller
        // for the Messages screen's own pill, but it does not feed the title.
        $this->assertStringContainsString('"pending":1', $unescaped);
        $this->assertStringContainsString('"unread":1', $unescaped);
        $this->assertSame('(1) DASHBOARD | Balai ti Arjud Admin', $this->title($html));
    }

    /* ------------------------------------------------------------------ */
    /* 3. It follows the user across pages                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Not just the notifications page — any page carrying the navbar.
     *
     * That is the entire point of putting the count in the tab: a user with six
     * tabs open needs to know which one to switch to, and they are not on the
     * notifications page when they need to know.
     */
    public function test_the_prefix_is_present_on_every_customer_page_that_carries_the_navbar(): void
    {
        $user = $this->makeUser();

        $user->notify(new WelcomeNotification());

        foreach (['/', '/appointments', '/services', '/book', '/profile', '/notifications'] as $path) {
            $url = $path === '/book' ? $this->bookingUrl() : $path;
            $title = $this->title($this->actingAs($user)->get($url)->assertOk()->getContent());

            $this->assertStringStartsWith(
                '(1) ',
                $title,
                "{$path} carries the navbar bell, so its tab title carries the count.",
            );
        }
    }

    /**
     * The prefix appears on admin pages that do not clear it — and not on the one
     * that does.
     *
     * This used to assert the prefix on `/admin/appointments` too, which is the
     * bug in one line: the tab said "(1) APPOINTMENTS" while the admin was looking
     * at the appointments, telling them the page in front of them was unread.
     *
     * Visiting that screen marks what it shows as seen, so its title is plain —
     * and the other pages, which mark nothing, keep their prefix. Order matters:
     * `/admin/appointments` is visited last, after the other three have shown the
     * prefix, so nothing it clears is needed by them.
     */
    public function test_the_prefix_is_present_on_admin_pages_that_do_not_clear_it(): void
    {
        $admin = $this->makeAdmin();

        $this->makeAppointment($this->makeUser(), null, ['status' => AppointmentStatus::Pending]);

        foreach (['/admin', '/admin/users', '/admin/reports'] as $path) {
            $title = $this->title($this->actingAs($admin, 'admin')->get($path)->assertOk()->getContent());

            $this->assertStringStartsWith('(1) ', $title, "{$path} carries the unseen count.");
        }

        // And the screen that marks them seen has already cleared its own title.
        $title = $this->title(
            $this->actingAs($admin, 'admin')->get('/admin/appointments')->assertOk()->getContent()
        );

        $this->assertSame('APPOINTMENTS | Balai ti Arjud Admin', $title);
    }

    /* ------------------------------------------------------------------ */
    /* 4. Live, from the same poll as the badge                             */
    /* ------------------------------------------------------------------ */

    /**
     * Both bells feed the title from the count they already fetched. Asserted on
     * the source because the property is about there being *no second request*,
     * which no rendered page can show.
     *
     * Neither bell writes the title itself. Each writes its own store —
     * `window.btaUnread` for the customer, `window.btaAdminUnread` for the admin
     * — and those stores write the title. That is what lets a read that happens
     * somewhere other than the bell (a row in the admin's dropdown, a row on the
     * customer's notifications page) move the title too, and it is why the
     * stores are separate: the two counts mean different things and a customer
     * session and an admin session never share a tab.
     */
    public function test_both_bells_feed_the_title_from_the_count_they_already_fetched(): void
    {
        $source = file_get_contents(base_path('resources/js/app.js'));

        // The customer bell, in `apply()` — from the poll it already made.
        $this->assertStringContainsString('window.btaUnread?.set(payload.unread)', $source);

        // The admin poller, from the total of its two halves. It used to write
        // the title directly; it now goes through the admin store, which is what
        // makes an optimistic mark-as-read move the title.
        $this->assertStringContainsString('window.btaAdminUnread?.set(this.total)', $source);

        // …and each store's render is the only place the title is written, so
        // nothing else can put the badge and the title out of step.
        $this->assertSame(
            2,
            substr_count($source, 'window.btaTabTitle?.setCount(count);'),
            'Exactly the two unread stores may write the title.',
        );
    }

    /**
     * Marking all as read has to clear the prefix, or the tab lies.
     *
     * The action moved from the bell to the notifications page, so this now
     * asserts on the page component doing it — and on the store clearing, since
     * the optimistic step happens before the request is even sent.
     */
    public function test_clearing_the_badge_clears_the_title_prefix(): void
    {
        $source = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString(
            'window.btaUnread?.set(0)',
            $source,
            'Marking all as read must remove the "(n)" prefix.',
        );

        // And a single row, optimistically, before the request is answered.
        $this->assertStringContainsString('window.btaUnread?.set(before - 1)', $source);

        // `normalise()` clamps at zero, so marking one read while the count
        // already reads zero cannot produce a negative badge.
        $this->assertStringContainsString('Number.isFinite(next) && next > 0', $source);
    }

    /** One updater, not one per component, so the two cannot fight over the title. */
    public function test_the_title_has_a_single_owner(): void
    {
        $source = file_get_contents(base_path('resources/js/app.js'));

        // The anchor retitler goes through the same object rather than assigning
        // `document.title` itself, or following a `/#offers` link would drop the
        // count for as long as the poller took to notice.
        $this->assertStringNotContainsString('document.title = title;', $source);
        $this->assertStringContainsString('tabTitle.setBase(', $source);
    }

    /**
     * The count is capped on both sides of the wire.
     *
     * A four-digit inbox count would push the page name off a narrow tab, which
     * is the one thing the prefix is for. Tested on the helper directly rather
     * than through a rendered page, because creating 150 notifications to observe
     * a `min()` would be a slow way to assert arithmetic.
     */
    public function test_a_very_large_unread_count_is_capped(): void
    {
        $this->assertSame(
            '(99) HOME | Balai ti Arjud',
            TabTitle::withCount('HOME | Balai ti Arjud', 150),
        );

        $this->assertSame(
            '(99) HOME | Balai ti Arjud',
            TabTitle::withCount('HOME | Balai ti Arjud', 99),
        );

        // …and the client applies the same ceiling, or the two disagree.
        $this->assertStringContainsString(
            'Math.min(next, 99)',
            file_get_contents(base_path('resources/js/app.js')),
        );
    }

    /** Zero is a value, not an absence: it removes the prefix rather than adding "(0)". */
    public function test_a_zero_count_removes_the_prefix_entirely(): void
    {
        $this->assertSame('HOME | Balai ti Arjud', TabTitle::withCount('HOME | Balai ti Arjud', 0));
        $this->assertSame('HOME | Balai ti Arjud', TabTitle::withCount('HOME | Balai ti Arjud', -3));
    }

    /**
     * The title it is handed may already carry a prefix — the server renders one.
     *
     * Stripping before prefixing is what stops the tab reading "(3) (3) HOME" the
     * first time the poller fires on a page that was already prefixed.
     */
    public function test_an_already_prefixed_title_does_not_gain_a_second_prefix(): void
    {
        $this->assertSame(
            'HOME | Balai ti Arjud',
            TabTitle::strip('(3) HOME | Balai ti Arjud'),
        );

        $this->assertSame(
            '(1) HOME | Balai ti Arjud',
            TabTitle::withCount(TabTitle::strip('(3) HOME | Balai ti Arjud'), 1),
        );
    }
}