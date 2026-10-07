<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The admin's unread-appointment badge, and the tab title built from it.
 *
 * The badge used to count bookings in Pending, which is a status rather than an
 * acknowledgement. Nothing about *looking* at the Appointments page changed it, so
 * the number stayed on the sidebar and in the tab title for as long as a booking
 * sat undecided — including while the admin was reading the very list it was
 * counting.
 *
 * `admin_seen_at` separates the two ideas, and this file pins the behaviour that
 * comes out of that:
 *
 *   - opening the screen clears the badge and the title prefix, server-side, on the
 *     first render;
 *   - "unseen" is not "Pending" — either can be true without the other;
 *   - a booking that arrives afterwards brings the badge back, counting only itself;
 *   - the sidebar badge and the tab title are the same number, read from one place;
 *   - the poll agrees with the server-rendered halves.
 */
class AdminUnseenAppointmentsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): \App\Models\Admin
    {
        return $this->makeAdmin();
    }

    private function book(array $attributes = []): Appointment
    {
        return $this->makeAppointment($this->makeUser(), null, $attributes);
    }

    /** The `<title>` of a rendered admin page, unescaped. */
    private function title(string $html): string
    {
        $start = strpos($html, '<title>');
        $end = strpos($html, '</title>');

        return trim(html_entity_decode(substr($html, $start + 7, $end - $start - 7)));
    }

    /**
     * The page with its `\u0022` JSON quoting turned back into `"`.
     *
     * Blade's `@js()` hex-escapes quotes so the payload is safe inside an HTML
     * attribute, so the literal text `"pending":0` does not appear in the source —
     * only its escaped form does.
     */
    private function unescaped(string $html): string
    {
        return str_replace('\\', '', str_replace('\u0022', '"', $html));
    }

    /**
     * The seeded payload of the one component that owns the badges.
     *
     * `adminLiveNotifications` is mounted once per admin page and is the only
     * thing that reads the unseen count, so this is the markup that has to be
     * right. Extracting it keeps an assertion about the badge from being decided
     * by an unrelated number somewhere else on the page.
     */
    private function notificationsPayload(string $html): string
    {
        $start = strpos($html, 'adminLiveNotifications(');

        $this->assertNotFalse($start, 'The live notifications component was not mounted.');

        // The payload is the value of the component's own `x-data`, and that
        // attribute is the first one after the call. Searching forward from just
        // past its opening quote to the last quote before the next attribute
        // avoids depending on how `@js()` chooses to escape anything inside the
        // object — a literal quote would otherwise be read as the end.
        $open = strpos($html, '"', strpos($html, 'x-data', $start));
        $next = strpos($html, 'x-on:', $start);

        $this->assertNotFalse($open, 'The notifications component has no x-data attribute.');
        $this->assertNotFalse($next, 'The notifications component has no following attribute.');

        return substr($html, $start, strrpos(substr($html, 0, $next), '"') - $start);
    }

    /** The markup of the count badge inside the Appointments sidebar row. */
    private function sidebarBadgeMarkup(string $html): string
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        $row = (new \DOMXPath($doc))
            ->query('//aside//a[@href="'.route('admin.appointments.index').'"]')
            ->item(0);

        $this->assertInstanceOf(\DOMElement::class, $row, 'The Appointments sidebar row should exist.');

        $badge = (new \DOMXPath($doc))
            ->query('.//span[contains(@class, "text-[10px]")]', $row)
            ->item(0);

        $this->assertInstanceOf(\DOMElement::class, $badge, 'The Appointments row should carry a count badge.');

        return $badge->ownerDocument->saveHTML($badge);
    }

    /**
     * The number the sidebar's Appointments badge was rendered with.
     *
     * Read from the badge's own Alpine seed, `x-data="{ count: N }"`, which is
     * the only place the rendered number lives: a live badge emits no text node
     * at all, because the count is bound and is replaced by the first poll.
     *
     * Null when the row has no badge element — which is the "rendered nothing"
     * case, though in practice the live badge keeps an element in the DOM so it
     * can be shown again by a later poll.
     */
    private function sidebarBadge(string $html): ?int
    {
        $markup = $this->sidebarBadgeMarkup($html);

        return preg_match('/x-data="\{ count: (\d+) \}"/', $markup, $m)
            ? (int) $m[1]
            : null;
    }

    /**
     * The badge paints nothing at zero.
     *
     * A live badge cannot remove itself from the DOM — it has to stay there for
     * the next poll to write into — so "renders nothing" means two things at
     * once: the count is zero, and the element carries both the Alpine hide and
     * the no-JavaScript `hidden` backstop. Asserted as a pair, because a badge
     * showing a literal "0" passes the first check and fails the requirement.
     */
    private function assertBadgeIsHidden(string $html): void
    {
        $markup = $this->sidebarBadgeMarkup($html);

        $this->assertSame(0, $this->sidebarBadge($html), 'The badge should be seeded with zero.');
        $this->assertStringContainsString('x-show="count &gt; 0"', $markup, 'Alpine hides it at zero.');
        $this->assertStringContainsString('hidden', $markup, 'And it is hidden without JavaScript too.');
    }

    /* ------------------------------------------------------------------ */
    /* 1. The screen that clears the badge                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Opening the Appointments page clears the badge and the title prefix.
     *
     * The headline case, and the one the old behaviour got wrong in the most
     * visible way: an admin looking at the appointments had "(2) APPOINTMENTS"
     * in their tab.
     */
    public function test_opening_the_appointments_page_clears_the_badge_and_the_title(): void
    {
        $admin = $this->admin();

        $this->book();
        $this->book();

        $this->assertSame(2, Appointment::unseenForAdminCount());

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.appointments.index'))
            ->assertOk()
            ->getContent();

        // No prefix — exactly the plain title asked for.
        $this->assertSame('APPOINTMENTS | Balai ti Arjud Admin', $this->title($html));

        // And the badge paints nothing on the first render: not a "0", not a
        // number waiting for a script to fix it.
        $this->assertBadgeIsHidden($html);
        $this->assertSame(0, Appointment::unseenForAdminCount());
    }

    /**
     * The clearing is server-side, so it survives a reload and other pages.
     *
     * The point of doing it in the action rather than in JavaScript: a badge kept
     * in a storage key or patched into the DOM comes back on refresh, and the
     * admin would have to visit the page twice to make it stick.
     */
    public function test_the_clearing_persists_across_reloads_and_other_pages(): void
    {
        $admin = $this->admin();

        $this->book();

        $this->actingAs($admin, 'admin')->get(route('admin.appointments.index'))->assertOk();

        // Reload it.
        $this->actingAs($admin, 'admin')->get(route('admin.appointments.index'))->assertOk();
        $this->assertSame(0, Appointment::unseenForAdminCount());

        // Somewhere else entirely.
        $dashboard = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertSame('DASHBOARD | Balai ti Arjud Admin', $this->title($dashboard));

        // And in the database, which is the only place that really counts.
        $this->assertNotNull(Appointment::first()->admin_seen_at);
    }

    /**
     * A second visit does not rewrite the timestamp of a booking already seen.
     *
     * Otherwise `admin_seen_at` records when a page was last *loaded* rather than
     * when a booking was last *looked at*, and every visit churns `updated_at` on
     * rows nothing happened to.
     */
    public function test_a_second_visit_leaves_the_first_timestamp_alone(): void
    {
        $admin = $this->admin();

        $appointment = $this->book();

        $this->actingAs($admin, 'admin')->get(route('admin.appointments.index'))->assertOk();

        $first = $appointment->fresh()->admin_seen_at;
        $this->assertNotNull($first);

        $this->travel(5)->minutes();

        $this->actingAs($admin, 'admin')->get(route('admin.appointments.index'))->assertOk();

        $this->assertEquals($first, $appointment->fresh()->admin_seen_at);
        $this->assertSame(0, Appointment::markUnseenAsSeenForAdmin(), 'Nothing was left unseen to mark.');
    }

    /* ------------------------------------------------------------------ */
    /* 2. Unseen is not Pending                                             */
    /* ------------------------------------------------------------------ */

    /**
     * A Pending booking the salon has read does not badge.
     *
     * The other half of the separation, and the case that would otherwise keep the
     * badge stuck at a non-zero number for a salon that has done its job: the
     * booking still needs deciding, but nobody is waiting to hear about it.
     */
    public function test_a_pending_booking_that_has_been_seen_does_not_badge(): void
    {
        $admin = $this->admin();

        $this->book(['status' => AppointmentStatus::Pending, 'admin_seen_at' => now()]);

        $html = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertSame(0, Appointment::unseenForAdminCount());
        $this->assertSame('DASHBOARD | Balai ti Arjud Admin', $this->title($html));
    }

    /**
     * A booking approved without ever being read still badges.
     *
     * Filtering the badge by status — the shape the old query had — would silently
     * drop this one, and nobody would know the booking was there. Whether a booking
     * is Pending is the salon's decision; whether anyone has looked at it is not,
     * and the count is about the second.
     */
    public function test_a_confirmed_booking_that_was_never_seen_still_badges(): void
    {
        $admin = $this->admin();

        $this->book(['status' => AppointmentStatus::Confirmed, 'admin_seen_at' => null]);

        $html = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertSame(1, Appointment::unseenForAdminCount());
        $this->assertStringStartsWith('(1) ', $this->title($html));
    }

    /** An archived booking is off the working list, so it cannot badge it. */
    public function test_an_archived_booking_does_not_badge(): void
    {
        $admin = $this->admin();

        $this->book(['archived_at' => now()]);

        $html = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertSame(0, Appointment::unseenForAdminCount());
        $this->assertStringNotContainsString('(1) ', $this->title($html));
    }

    /** Nor can a soft-deleted one. */
    public function test_a_soft_deleted_booking_does_not_badge(): void
    {
        $admin = $this->admin();

        $booking = $this->book();
        $booking->delete();

        $this->assertSame(0, Appointment::unseenForAdminCount());

        $html = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('(1) ', $this->title($html));
    }

    /* ------------------------------------------------------------------ */
    /* 3. A new booking brings it back                                      */
    /* ------------------------------------------------------------------ */

    /**
     * A booking made after the screen was opened badges again — on its own.
     *
     * The regression risk is a badge that comes back at the old number, because
     * the "seen" marking was applied as a blanket update rather than per row. One
     * new booking must mean exactly one.
     */
    public function test_a_new_booking_brings_the_badge_back_counting_only_itself(): void
    {
        $admin = $this->admin();

        $this->book();
        $this->book();

        $this->actingAs($admin, 'admin')->get(route('admin.appointments.index'))->assertOk();

        $this->assertSame(0, Appointment::unseenForAdminCount());

        // One more arrives.
        $this->book();

        $html = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertSame(1, Appointment::unseenForAdminCount(), 'Only the new booking is unseen.');
        $this->assertStringStartsWith('(1) ', $this->title($html));
        $this->assertSame(1, $this->sidebarBadge($html));
    }

    /**
     * Two new bookings show as two.
     */
    public function test_two_new_bookings_show_as_two(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get(route('admin.appointments.index'))->assertOk();

        $this->book();
        $this->book();

        $this->assertSame(2, Appointment::unseenForAdminCount());

        $html = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringStartsWith('(2) ', $this->title($html));
    }

    /* ------------------------------------------------------------------ */
    /* 4. Server-side, not JavaScript                                       */
    /* ------------------------------------------------------------------ */

    /**
     * The marking is a database write, not a view of one.
     *
     * Asserted against the row rather than the page, because a badge that only
     * *looked* clear would pass every render assertion above and still come back
     * on the next one.
     */
    public function test_the_marking_is_a_database_write(): void
    {
        $admin = $this->admin();

        $appointment = $this->book();
        $this->assertNull($appointment->fresh()->admin_seen_at);

        $this->actingAs($admin, 'admin')->get(route('admin.appointments.index'))->assertOk();

        $this->assertNotNull(
            DB::table('appointments')->where('id', $appointment->id)->value('admin_seen_at'),
            'The column is written directly, so it survives independently of this page.'
        );
    }

    /**
     * A request with no JavaScript gets the same cleared state.
     *
     * The badge is server-rendered in the sidebar partial, which runs while the
     * layout renders — after the action. So the first response is already correct
     * and there is nothing for a script to fix afterwards.
     */
    public function test_the_first_response_is_already_clear(): void
    {
        $admin = $this->admin();

        $this->book();

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.appointments.index'))
            ->assertOk()
            ->getContent();

        // No count in the seeded poll payload either — the layout reads the same
        // helper, after the action has run.
        //
        // Scoped to the notifications component's own payload rather than the
        // whole page. A page-wide search for `"pending":1` used to be a safe
        // proxy for "the badge is stale", but it is not: the Appointments screen
        // also seeds per-status tab totals, which are keyed by status name and so
        // legitimately contain `"pending":1` for a list with one pending booking.
        // Two payloads, one key, and only one of them is about unseen bookings.
        $payload = $this->unescaped($this->notificationsPayload($html));

        $this->assertStringContainsString('"pending":0', $payload);
        $this->assertStringNotContainsString('"pending":1', $payload);
    }

    /* ------------------------------------------------------------------ */
    /* 5. One number, three places                                         */
    /* ------------------------------------------------------------------ */

    /**
     * The sidebar badge, the tab title and the poll are the same number.
     *
     * Three readouts of one fact. The poll is included because it is the one that
     * would be stale: it runs fifteen seconds later and repaints the badge and the
     * title from its own answer, so if it asked a different question the badge
     * would jump back to a number the server had already retired.
     */
    public function test_the_badge_the_title_and_the_poll_agree(): void
    {
        $admin = $this->admin();

        $this->book();
        $this->book();
        $this->book(['status' => AppointmentStatus::Confirmed, 'admin_seen_at' => now()]);

        $unseen = Appointment::unseenForAdminCount();
        $this->assertSame(2, $unseen);

        $html = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk()->getContent();

        // Sidebar.
        $this->assertSame(2, $this->sidebarBadge($html));

        // Title.
        $this->assertStringStartsWith('(2) ', $this->title($html));

        // Poll.
        $feed = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.notifications.feed'))
            ->assertOk()
            ->json();

        $this->assertSame($unseen, $feed['pending']);
    }

    /**
     * The read-all endpoint reports the same count.
     *
     * It is the other writer's return value, and the client sets the badge and the
     * title from it — so a stale number here would put both back.
     */
    public function test_the_read_endpoint_reports_the_same_count(): void
    {
        $admin = $this->admin();

        $this->book();

        $payload = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.notifications.read-all'))
            ->assertOk()
            ->json();

        $this->assertSame(Appointment::unseenForAdminCount(), $payload['pending']);
    }

    /**
     * The title is the sidebar badge, with the enquiries added.
     *
     * Both halves still count, and they still add up: the Appointments badge is
     * the booking half and the Messages badge is the enquiry half, so the title
     * agreeing with the sum of the two badges beside it is the property.
     */
    public function test_the_title_is_the_sum_of_the_two_sidebar_badges(): void
    {
        $admin = $this->admin();

        $this->book();
        $this->book();
        $this->book(['admin_seen_at' => now()]);

        \App\Models\ContactMessage::create([
            'name' => 'Ana',
            'email' => 'ana@example.test',
            'topic' => \App\Enums\InquiryTopic::Others,
            'message' => 'Do you have a slot on Friday?',
        ]);

        $html = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk()->getContent();

        // Two unseen bookings plus one enquiry.
        $this->assertStringStartsWith('(3) ', $this->title($html));
    }

    /* ------------------------------------------------------------------ */
    /* 6. The column                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * The column exists, is nullable, and existing rows were left unseen.
     *
     * The decision not to backfill is asserted, because it is the one a later
     * reader would most likely second-guess. Backfilling would declare that the
     * salon had already seen every booking that predates the column — so the
     * badge would start at zero with nobody having looked at any of them, and
     * the outstanding work would be invisible. Leaving them null means the badge
     * starts at the real number and clears the first time the page is opened.
     */
    public function test_the_column_is_nullable_and_existing_rows_are_unseen(): void
    {
        $this->assertTrue(
            DB::getSchemaBuilder()->hasColumn('appointments', 'admin_seen_at'),
            'The column must exist.'
        );

        $this->book();

        $this->assertSame(
            1,
            Appointment::query()->whereNull('admin_seen_at')->count(),
            'Existing rows are unseen, not backdated to seen.'
        );
    }

    /** A booking made with no reference to this column is unseen, by default. */
    public function test_a_new_booking_is_unseen_without_anything_setting_it(): void
    {
        $this->book();

        $this->assertTrue(Appointment::first()->isUnseenForAdmin());
        $this->assertSame(1, Appointment::unseenForAdminCount());
    }

    /** The helper reports what it marked, and is safe to call on an empty set. */
    public function test_marking_reports_its_row_count(): void
    {
        $this->assertSame(0, Appointment::markUnseenAsSeenForAdmin(), 'Nothing unseen, nothing written.');

        $this->book();
        $this->book();

        $this->assertSame(2, Appointment::markUnseenAsSeenForAdmin());
        $this->assertSame(0, Appointment::markUnseenAsSeenForAdmin(), 'Idempotent.');
        $this->assertSame(0, Appointment::unseenForAdminCount());
    }
}
