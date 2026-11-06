<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\InquiryTopic;
use App\Models\Appointment;
use App\Models\ContactMessage;
use App\Support\TabTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The admin work-queue count: where it is shown, and what reading does to it.
 *
 * Three separate things are under test here and it is worth keeping them apart,
 * because they are separate decisions:
 *
 *   - the *placement* of the count — on the sidebar rows that own it, and not on
 *     an icon in the top bar, which is a regression guard on a decision that has
 *     already been made twice in this codebase in both directions; and
 *   - what the count *does* when an enquiry is read, which is a behaviour
 *     question about the endpoints and the numbers they return.
 *
 * The second is deliberately only about enquiries. A booking sitting in Pending
 * is work, not a message, and no amount of looking at the count should clear it —
 * see NotificationReadController. There is a test here pinning that, because
 * "mark as read" quietly growing to cover bookings would be the easy way to make
 * a count reach zero and the most damaging thing that could happen to it.
 */
class AdminNotificationCountTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ */
    /* Where the count is shown                                            */
    /* ------------------------------------------------------------------ */

    public function test_the_topbar_carries_no_bell_or_badge(): void
    {
        $admin = $this->makeAdmin();
        $this->makeMessage();

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        // The bar on its own, so a badge elsewhere on the page cannot satisfy or
        // trip the assertion.
        $bar = Str::between($html, '<header class="admin-topbar', '</header>');

        $this->assertNotSame('', $bar, 'The admin top bar must render.');

        $this->assertStringNotContainsString(
            'aria-label="Notifications"',
            $bar,
            'The notification icon must not sit beside the admin name.',
        );
        $this->assertStringNotContainsString('admin-notification-panel', $html);
        $this->assertStringNotContainsString('Waiting on you', $html);
    }

    public function test_no_bare_number_sits_beside_the_admin_name(): void
    {
        $admin = $this->makeAdmin();
        $this->makeMessage();

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $bar = Str::between($html, '<header class="admin-topbar', '</header>');

        // The bug being guarded against: the count used to render as a plain
        // sibling of the avatar, reading as "2  MA  Maia Arjud" — a stray total
        // in the bar rather than a count attached to anything.
        $this->assertSame(
            [],
            preg_grep('/^\s*\d+\s*$/', $this->spanContents($bar)),
            'The top bar must carry no number of its own; the count lives in the sidebar.',
        );
    }

    public function test_the_two_halves_badge_the_two_sidebar_rows_that_own_them(): void
    {
        $admin = $this->makeAdmin();
        $this->makeAppointment($this->makeUser(), null, ['status' => AppointmentStatus::Pending]);
        $this->makeMessage();

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        // Appointments badges the bookings awaiting a decision. It used to have a
        // Messages row beside it badging the unread enquiries; that row is off
        // the nav now, so Appointments and Inventory are the badged rows left.
        $this->assertStringContainsString(
            'x-on:admin-pending.window',
            $this->navRow($html, route('admin.appointments.index')),
            'Appointments must badge the pending-booking count.',
        );

        // `navRow` is gone with the row it was reaching for: it asserts the row exists,
        // which is exactly the thing that must stop being true. The whole admin
        // page is checked instead, because nothing else links to the inbox.
        $this->assertStringNotContainsString(
            route('admin.messages.index'),
            str_replace('\\', '', $html),
            'Messages is no longer on the admin nav.',
        );
    }

    public function test_the_sidebar_badges_start_on_the_counts_the_server_counted(): void
    {
        $admin = $this->makeAdmin();
        $this->makeAppointment($this->makeUser(), null, ['status' => AppointmentStatus::Pending]);
        $this->makeMessage();
        $this->makeMessage();

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        // The counts the poller starts on are the server's, so the sidebar and the
        // tab title cannot disagree before JavaScript has run at all.
        //
        // The config travels through `@js()`, so the quotes are `\u0022` and the
        // slashes are backslash-escaped. The unicode escapes have to go first —
        // stripping backslashes first would leave a bare `u0022` behind.
        $plain = str_replace('\\', '', str_replace('\u0022', '"', $html));

        $this->assertStringContainsString('"pending":1', $plain);
        $this->assertStringContainsString('"unread":2', $plain);

        // The tab title counts only the unseen booking. The two enquiries still
        // travel in the poller's payload for the Messages screen's unread pill,
        // but the Messages row is not in the admin nav, so they must not put a
        // number in the title with no visible notification behind it.
        $this->assertMatchesRegularExpression(
            '/<title>\(1\) DASHBOARD \| Balai ti Arjud Admin<\/title>/',
            $html,
        );
    }

    public function test_the_count_is_polled_from_the_layout_rather_than_from_an_icon(): void
    {
        $html = $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        // The bell is gone but its polling is not, or the badges and the tab
        // title would go stale between visits.
        $this->assertStringContainsString('adminLiveNotifications(', $html);
        $this->assertStringContainsString('hidden', $html, 'The poller renders nothing.');
        $this->assertStringNotContainsString('adminNotificationBell(', $html);
    }

    /* ------------------------------------------------------------------ */
    /* Tab title                                                           */
    /* ------------------------------------------------------------------ */

    public function test_the_tab_title_carries_the_unseen_booking_count(): void
    {
        $admin = $this->makeAdmin();

        // Enquiries no longer contribute: there is no Messages row to badge and
        // no notification list to open, so they must not leave a count behind.
        $this->makeMessage();
        $this->makeMessage();
        $this->makeAppointment($this->makeUser(), null, ['status' => AppointmentStatus::Pending]);

        // Asserted through an authenticated request rather than by calling the
        // helper directly: `adminUnread()` reads the guard, so calling it on the
        // test's own would always answer zero and prove nothing.
        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('<title>(1) DASHBOARD | Balai ti Arjud Admin</title>', false);
    }

    public function test_unread_enquiries_do_not_put_a_count_in_the_admin_title(): void
    {
        $admin = $this->makeAdmin();
        $this->makeMessage();
        $this->makeMessage();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('<title>DASHBOARD | Balai ti Arjud Admin</title>', false);
    }

    public function test_the_tab_title_has_no_count_when_there_is_nothing_unread(): void
    {
        $admin = $this->makeAdmin();

        $this->assertSame(0, TabTitle::adminUnread());

        $this->actingAs($admin, 'admin')
            ->get(route('admin.appointments.index'))
            ->assertOk()
            ->assertSee('<title>APPOINTMENTS | Balai ti Arjud Admin</title>', false);
    }

    /* ------------------------------------------------------------------ */
    /* Reading                                                             */
    /* ------------------------------------------------------------------ */

    public function test_opening_an_enquiry_marks_it_read_and_returns_the_new_counts(): void
    {
        $admin = $this->makeAdmin();
        $message = $this->makeMessage();

        $response = $this->actingAs($admin, 'admin')
            ->patchJson(route('admin.notifications.read', $message));

        $response->assertOk()
            ->assertJson([
                'messages' => 0,
                'pending' => 0,
            ]);

        $this->assertTrue($message->fresh()->is_read);
        $this->assertSame(0, TabTitle::adminUnread());
    }

    public function test_marking_all_enquiries_read_clears_them_in_one_request(): void
    {
        $admin = $this->makeAdmin();
        $this->makeMessage();
        $this->makeMessage();
        $this->makeMessage();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.notifications.read-all'))
            ->assertOk()
            ->assertJson([
                'messages' => 0,
                'pending' => 0,
            ]);

        $this->assertSame(0, ContactMessage::query()->unread()->count());
    }

    public function test_reading_an_enquiry_is_idempotent(): void
    {
        $admin = $this->makeAdmin();
        $message = $this->makeMessage();

        $this->actingAs($admin, 'admin')
            ->patchJson(route('admin.notifications.read', $message))
            ->assertOk();

        $stamp = $message->fresh()->updated_at;

        // A double click must not re-stamp the message, or it jumps to the top of
        // an inbox the admin is looking at.
        $this->travel(2)->minutes();

        $this->actingAs($admin, 'admin')
            ->patchJson(route('admin.notifications.read', $message))
            ->assertOk()
            ->assertJson(['messages' => 0]);

        $this->assertEquals($stamp, $message->fresh()->updated_at);
    }

    public function test_a_pending_booking_is_work_and_cannot_be_marked_read(): void
    {
        $admin = $this->makeAdmin();
        $this->makeMessage();
        $appointment = $this->makeAppointment($this->makeUser());

        $this->assertSame(1, Appointment::query()->active()->count());

        // Reading the enquiries must leave the booking half of the count alone.
        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.notifications.read-all'))
            ->assertOk()
            ->assertJson([
                'messages' => 0,
                'pending' => 1,
            ]);

        $this->assertSame(1, TabTitle::adminUnread());
        $this->assertSame(
            AppointmentStatus::Pending,
            $appointment->fresh()->status,
            'A booking is cleared by deciding on it, not by looking at the count.',
        );
    }

    public function test_the_read_endpoints_are_closed_to_a_guest_and_to_a_customer(): void
    {
        $message = $this->makeMessage();

        // Both are refused by redirect: a guest is sent to the admin login, and a
        // customer is not in the `admin` guard at all. The part worth pinning is
        // that neither request reaches the write.
        $this->post(route('admin.notifications.read-all'))->assertRedirect();
        $this->patch(route('admin.notifications.read', $message))->assertRedirect();

        $this->actingAs($this->makeUser(), 'web')
            ->post(route('admin.notifications.read-all'))
            ->assertRedirect();

        $this->assertFalse($message->fresh()->is_read);
    }

    /* ------------------------------------------------------------------ */
    /* Where reading is offered, now the bell is gone                       */
    /* ------------------------------------------------------------------ */

    public function test_the_messages_screen_offers_marking_read(): void
    {
        $this->makeMessage();

        $html = $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.messages.index'))
            ->assertOk()
            ->getContent();

        // The bell's dropdown used to be the only place either control lived.
        // Both are here now, as dispatches to the one poller in the layout.
        $this->assertStringContainsString("\$dispatch('admin-read-all')", $html);
        $this->assertStringContainsString("\$dispatch('admin-read-message'", $html);

        // …and the layout is what answers them.
        $this->assertStringContainsString('x-on:admin-read-all.window', $html);
        $this->assertStringContainsString('x-on:admin-read-message.window', $html);
    }

    public function test_the_messages_screen_keeps_a_read_receipt_without_scripting(): void
    {
        $message = $this->makeMessage();

        // The scripted buttons degrade to nothing, so the form that has always
        // been here is what guarantees an enquiry can be read either way.
        $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.messages.index'))
            ->assertOk()
            ->assertSee('Save &amp; Mark Read', false);

        // And it really does mark it read, with no JavaScript involved.
        $this->actingAs($this->makeAdmin(), 'admin')
            ->patch(route('admin.messages.update', $message), ['admin_reply' => 'Noted.'])
            ->assertRedirect();

        $this->assertTrue($message->fresh()->is_read);
    }

    /* ------------------------------------------------------------------ */
    /* The nav no longer clears anything                                  */
    /* ------------------------------------------------------------------ */

    /**
     * The Messages row used to clear the badge it carried.
     *
     * The topbar bell had been the read receipt, and it was removed precisely
     * because opening it marked every enquiry read with nothing on screen saying
     * so. That was a fair thing to stop doing by accident and an odd thing to
     * keep doing invisibly — so the badge and the action were bound to the same
     * click, on the row that carried the badge.
     *
     * That row is off the nav now, so the behaviour is gone with it, and this is
     * what keeps it gone: an admin can no longer mark every enquiry read by
     * opening a page. The inbox itself still offers the action, on its own screen,
     * where it is a labelled button rather than a side effect of navigation.
     */
    public function test_no_nav_row_clears_the_unread_badge_as_a_side_effect(): void
    {
        $admin = $this->makeAdmin();
        $this->makeMessage();

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $nav = preg_match('/<nav[^>]*aria-label="Admin">(.*?)<\/nav>/s', str_replace('\\', '', $html), $m) ? $m[1] : '';

        $this->assertNotSame('', $nav, 'The admin nav should have rendered.');
        $this->assertStringNotContainsString(
            "\$dispatch('admin-read-all')",
            $nav,
            'No nav row should mark every enquiry read just because it was clicked.',
        );
        $this->assertStringNotContainsString(route('admin.messages.index'), $nav, 'Messages is off the nav.');

        // The inbox is still a working screen, and still offers the action
        // explicitly. Only the silent side effect is gone.
        $inbox = $this->actingAs($admin, 'admin')
            ->get(route('admin.messages.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("\$dispatch('admin-read-all')", $inbox);
    }

    /**
     * Appointments does not, because its badge is not a read receipt.
     *
     * The two rows sit side by side in the same sidebar and are otherwise written
     * the same way, so "Messages clears on click" is only meaningful if the other
     * row deliberately does not. A pending booking is work: it leaves the queue
     * because somebody confirms or declines it, and a badge that vanished on a
     * click would hide work rather than acknowledge it.
     */
    public function test_the_appointments_row_does_not_clear_the_pending_badge(): void
    {
        $admin = $this->makeAdmin();
        $this->makeAppointment($this->makeUser(), null, ['status' => AppointmentStatus::Pending]);
        $this->makeMessage();

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            "\$dispatch('admin-read-all')",
            $this->navRow($html, route('admin.appointments.index')),
        );
    }

    /**
     * The badge goes on the click, and the write survives the navigation.
     *
     * Same two requirements as the customer bell, and for the same reasons. The
     * badge is dispatched to zero before the request goes out, so it does not
     * wait on a round trip; and the request is `keepalive`, because the row that
     * triggers it is a link and an ordinary in-flight request is cancelled when
     * the document that made it starts unloading. Without the second, the badge
     * would clear and the enquiries would stay unread — the precise state this
     * was added to end.
     *
     * Asserted on the source because both are runtime properties; what can be
     * pinned is the mechanism that delivers them.
     */
    public function test_the_read_all_write_settles_optimistically_and_keeps_alive_through_the_click(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/app.js'));

        $block = Str::between($source, 'async markAllRead() {', 'async poll() {');

        $this->assertStringContainsString('keepalive: true', $block);

        $optimisticAt = strpos($block, "this.dispatch('admin-messages', 0)");
        $fetchedAt = strpos($block, 'const response = await fetch(this.readAllUrl');

        $this->assertIsInt($optimisticAt);
        $this->assertIsInt($fetchedAt);
        $this->assertLessThan(
            $fetchedAt,
            $optimisticAt,
            'The badge should be zeroed on the click, not after the request answers.',
        );

        // A poll already on the wire must not resurrect it: that tick was answered
        // before the write landed, so it is the stale half of a race.
        $this->assertStringContainsString('this.messages = this.clearing ? 0 : messages;', $source);

        // And its own guard is not the poller's `busy` flag. Sharing them meant a
        // click during a poll was silently refused, so the badge an admin had just
        // clicked to remove stayed put.
        $this->assertStringContainsString('if (!this.readAllUrl || this.clearing) return;', $block);
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * One sidebar `<a>`, from its opening tag to its closing tag.
     *
     * Matched with a regex rather than a literal `<a href="…"` because the tag
     * is written across several lines in the partial — so the attribute is never
     * contiguous with the tag name — and `Str::between()` answers a needle it
     * cannot find with the whole document. Every "this row does X" assertion in
     * this file was therefore running against the entire page, which passes for
     * anything present anywhere in it and would not catch a row losing its own
     * badge. Now a row that is not there fails, and a row that does not carry
     * the badge fails.
     *
     * Backslashes are stripped first, because the hrefs travel into the page
     * through `@js()` and arrive slash-escaped — so an unstripped `href` would not
     * match anything.
     */
    private function navRow(string $html, string $path): string
    {
        preg_match(
            '/<a\b[^>]*href="'.preg_quote($path, '/').'"[^>]*>.*?<\/a>/s',
            str_replace('\\', '', $html),
            $matches,
        );

        $this->assertNotEmpty($matches, 'The sidebar should have a row linking to '.$path.'.');

        return $matches[0];
    }

    /**
     * The text inside every `<span>` in a fragment, without the tags.
     *
     * Used rather than a regex over the raw HTML because a bare number in the bar
     * is the thing being guarded against, and "some element whose entire content
     * is a number" is a much more precise statement to make about it than "the
     * string 2 appears somewhere" — which would trip over a year, a version, or a
     * price in an unrelated part of the same fragment.
     */
    private function spanContents(string $html): array
    {
        preg_match_all('/<span\b[^>]*>(.*?)<\/span>/s', $html, $matches);

        return array_map(
            fn (string $inner): string => trim(html_entity_decode(strip_tags($inner), ENT_QUOTES)),
            $matches[1] ?? [],
        );
    }

    private function makeMessage(array $attributes = []): ContactMessage
    {
        static::$messages++;

        return ContactMessage::create(array_merge([
            'name' => 'Enquirer '.static::$messages,
            'email' => 'enquirer'.static::$messages.'@example.test',
            'topic' => InquiryTopic::GeneralInquiry,
            'message' => 'A message used in tests.',
            'is_read' => false,
        ], $attributes));
    }

    private static int $messages = 0;
}
