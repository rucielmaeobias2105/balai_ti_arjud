<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\InquiryTopic;
use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Guards the shared admin shell.
 *
 * Every admin screen has to render `partials.admin.sidebar` — the logo, the
 * grouped nav links and the profile/logout footer — with only the
 * `@section('content')` body changing between pages. A page that quietly renders
 * on its own looks like a different application, so the assertion is made per
 * page rather than once for the dashboard.
 */
class AdminLayoutTest extends TestCase
{
    use RefreshDatabase;

    /** Admin screens reachable without any fixture rows. */
    private const PATHS = [
        '',
        'appointments',
        'catalog',
        'services',
        'services/create',
        'categories',
        'categories/create',
        'technicians',
        'technicians/create',
        'inventory',
        'inventory/create',
        'promos',
        'promos/create',
        'tags',
        'users',
        'terms',
        'terms/create',
        'reports',
        'messages',
        'profile',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeSalonSettings();
    }

    /**
     * `@js()` escapes its payload for embedding in an HTML attribute: quotes come
     * out as `&quot;` and slashes are backslash-escaped, which is why the bell's
     * endpoints and initial counts have to be read out of the unescaped form.
     *
     * Order matters — the unicode escapes have to go first, because stripping the
     * backslashes first would leave a bare `u0022` behind.
     */
    private function unescaped(string $html): string
    {
        return str_replace('\\', '', str_replace('\u0022', '"', $html));
    }

    /**
     * The sidebar's `<nav>` and nothing else.
     *
     * Narrowing to the nav matters for the same reason it does in the other
     * sidebar tests: the page body links to most of these URLs too, so an
     * assertion over the whole document would pass even if the nav rendered
     * nothing at all.
     */
    private function sidebarNav(string $html): string
    {
        preg_match('/<nav[^>]*aria-label="Admin">(.*?)<\/nav>/s', $html, $matches);

        return $matches[1] ?? '';
    }

    public function test_every_admin_page_renders_the_shared_sidebar(): void
    {
        $admin = $this->makeAdmin();

        foreach (self::PATHS as $path) {
            $html = $this->actingAs($admin, 'admin')
                ->get('/admin/'.$path)
                ->assertOk()
                ->getContent();

            $context = 'The /admin/'.$path.' page';

            // The nav landmark, the branding and the full set of links.
            $this->assertStringContainsString('aria-label="Admin navigation"', $html, $context.' should render the shared sidebar.');
            $this->assertStringContainsString('Balai ti Arjud', $html, $context.' should render the salon wordmark.');
            $this->assertStringContainsString('aria-label="Admin"', $html, $context.' should render the grouped nav.');
            $this->assertStringContainsString('Inventory', $html, $context.' should render the navigation links.');

            // The footer half: the signed-in admin and the way out.
            $this->assertStringContainsString(route('admin.profile.edit'), $html, $context.' should link to the admin profile.');
            $this->assertStringContainsString(route('admin.logout'), $html, $context.' should offer the logout form.');
        }
    }

    /**
     * The panel must be visible at desktop width, where it is part of the page
     * flow rather than a drawer.
     *
     * An `x-show` here is a trap: Alpine writes an inline `display: none` when
     * the expression is false, and an inline style outranks every `lg:`
     * utility — so `x-show="sidebar"` on a `sidebar: false` wrapper hides the
     * panel at 1024px and above, and the only trigger (the topbar hamburger) is
     * `lg:hidden` itself, so it can never be reopened.
     */
    public function test_the_sidebar_is_never_hidden_by_an_alpine_x_show(): void
    {
        $admin = $this->makeAdmin();

        $html = $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->getContent();

        // The attribute values contain `>` (the resize handler tests
        // `window.innerWidth >= 1024`), so a plain `[^>]*` would cut the tag in
        // half. This pattern walks quoted attribute values as units.
        preg_match_all('/<aside\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/s', $html, $all);

        $navigation = array_values(array_filter(
            $all[0],
            fn (string $tag) => str_contains($tag, 'aria-label="Admin navigation"'),
        ));

        $this->assertCount(1, $navigation, 'The admin panel should render exactly one navigation <aside>.');
        $tag = $navigation[0];

        $this->assertStringNotContainsString('x-show', $tag, 'The navigation panel must not be toggled with x-show; use the mobile translate instead.');
        $this->assertStringContainsString('lg:!translate-x-0', $tag, 'The navigation panel should stay visible from the lg breakpoint up.');
    }

    /**
     * The panel has to render for an admin who is also signed in as a customer.     *
     * Gate passes the *default* guard's user to an `admin.*` callback, and the
     * default guard is the customer `web` guard. A `?Admin` parameter on the
     * gate therefore received a `User` and raised a TypeError that blanked
     * every admin screen, for anyone signed in on both guards at once — the
     * usual case for someone who books an appointment and then checks the
     * panel. `setUser()` is used rather than `actingAs()` because `actingAs()`
     * also calls `shouldUse()`, which would move the default guard to `admin`
     * and hide the collision this test exists to catch.
     */
    public function test_the_panel_renders_for_an_admin_who_is_also_signed_in_as_a_customer(): void
    {
        $customer = $this->makeUser();
        $admin = $this->makeAdmin();

        $this->actingAs($customer);
        Auth::guard('admin')->setUser($admin);

        $html = $this->get('/admin')->assertOk()->getContent();

        // The nav is gated on `admin.*` abilities, so a collapsed nav would
        // mean the gates denied the admin rather than merely rendering blank.
        $this->assertStringContainsString('aria-label="Admin navigation"', $html);
        $this->assertStringContainsString('Inventory', $html);
    }

    /**
     * The trimmed sidebar and dashboard stay trimmed.
     *
     * These cards and the "New Booking" button were removed deliberately, and
     * each one has a data source behind it, so a copy-paste from git history is
     * the obvious way to bring one back by accident.
     */
    public function test_the_removed_sidebar_pill_and_dashboard_cards_stay_removed(): void
    {
        $admin = $this->makeAdmin();

        $dashboard = $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->getContent();

        // The "Admin Panel" pill below the logo, and the "New Booking" button
        // that opened the public booking form in a new tab.
        $this->assertStringNotContainsString('>Admin Panel<', $dashboard);
        $this->assertStringNotContainsString('New Booking', $dashboard);

        // The page header: the "Overview" eyebrow, the "Welcome back, …"
        // greeting and the Pending Requests button.
        $this->assertStringNotContainsString('Overview', $dashboard);
        $this->assertStringNotContainsString('Welcome back', $dashboard);
        $this->assertStringNotContainsString('Pending Requests', $dashboard);

        $this->assertStringNotContainsString('Live snapshot of', $dashboard);
        $this->assertStringNotContainsString('Appointments by Status', $dashboard);
        $this->assertStringNotContainsString('Revenue Chart', $dashboard);
        $this->assertStringNotContainsString('Upcoming Appointments', $dashboard);
        $this->assertStringNotContainsString('Recent Reviews', $dashboard);
        $this->assertStringNotContainsString('Items at or below their reorder threshold', $dashboard);

        // The Low Stock Alerts tile is gone from this row too — it duplicated
        // the sidebar's Inventory badge.
        $this->assertStringNotContainsString('Low Stock Alerts', $dashboard);

        // What was kept: the remaining stat cards and today's schedule.
        $this->assertStringContainsString('Today&#039;s Appointments', $dashboard);
        $this->assertStringContainsString('Completed (Month)', $dashboard);
        $this->assertStringContainsString('Revenue (Month)', $dashboard);
    }

    /**
     * The trimmed Appointments and Catalogue pages stay trimmed, and the shared
     * admin footer is gone.
     *
     * The Calendar page is not asserted here — it exists again, with its own month
     * grid and header, and `BlockedSlotsTest` covers it. What is worth keeping in
     * this file is the one thing below: the Appointments list is still trimmed.
     */
    public function test_the_removed_page_headers_cards_and_footer_stay_removed(): void
    {
        $admin = $this->makeAdmin();

        // The "<app> Admin · Signed in as <name>" footer was shared, so it came
        // off every admin page rather than one.
        $dashboard = $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->getContent();
        $this->assertStringNotContainsString('Signed in as', $dashboard);

        $appointments = $this->actingAs($admin, 'admin')->get('/admin/appointments')->assertOk()->getContent();
        $this->assertStringNotContainsString('Booking Management', $appointments);
        $this->assertStringNotContainsString('Review, approve, decline', $appointments);
        // The search/date filter card.
        $this->assertStringNotContainsString('Reference, name, phone or email', $appointments);
        $this->assertStringNotContainsString('name="from"', $appointments);
        // The status tabs are the remaining navigation.
        $this->assertStringContainsString('aria-label="Admin navigation"', $appointments);

        $catalog = $this->actingAs($admin, 'admin')->get('/admin/catalog')->assertOk()->getContent();
        $this->assertStringNotContainsString('Catalogue Overview', $catalog);
        $this->assertStringNotContainsString('One combined view', $catalog);
        $this->assertStringNotContainsString('Inventory Items', $catalog);
        $this->assertStringNotContainsString('Add Item', $catalog);
        $this->assertStringNotContainsString('Search services and items at once', $catalog);
        // "Add Service" is kept, now living on the services card itself.
        $this->assertStringContainsString('Add Service', $catalog);
    }

    /**
     * The panel chrome, matched to the MCA Café admin layout.
     *
     * A static, auto-height sidebar grows with its nav and pushes the page down
     * instead of scrolling, which is what `lg:h-screen` on a sticky sidebar
     * fixes — the nav's own `overflow-y-auto` can only engage once the panel is
     * exactly one viewport tall.
     */
    public function test_the_sidebar_scrolls_its_links_inside_a_viewport_height_panel(): void
    {
        $admin = $this->makeAdmin();

        $html = $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->getContent();

        preg_match('/<aside\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/s', $html, $aside);
        $this->assertNotEmpty($aside, 'The navigation panel should render.');
        $this->assertStringContainsString('lg:h-screen', $aside[0], 'The sidebar should be exactly one viewport tall on desktop.');
        $this->assertStringContainsString('lg:sticky', $aside[0], 'The sidebar should stay pinned while the content scrolls.');

        // The links are the only part that scrolls; the brand header and the
        // profile/logout footer are outside this element.
        preg_match('/<nav\b[^>]*aria-label="Admin"[^>]*>/s', $html, $nav);
        $this->assertNotEmpty($nav, 'The nav element should render.');
        $this->assertStringContainsString('overflow-y-auto', $nav[0]);
        $this->assertStringContainsString('flex-1', $nav[0]);
    }

    public function test_the_sidebar_has_no_view_site_link(): void
    {
        $admin = $this->makeAdmin();

        $html = $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->getContent();

        // MCA Café's sidebar has no such link, and the logout form now follows
        // the profile block directly.
        $this->assertStringNotContainsString('View Site', $html);
        $this->assertStringContainsString(route('admin.logout'), $html);
    }

    /**
     * The sidebar is brand plus links and nothing else.
     *
     * The footer that used to sit below the nav is gone entirely: the identity
     * block (avatar initials, name, role badge, email), the "View Site" link and
     * the Logout button. Logging out and editing the profile both live in the
     * top bar dropdown, so the name and email still appear on the page — hence
     * the assertions are scoped to the sidebar element.
     */
    public function test_the_sidebar_is_brand_and_links_only(): void
    {
        $admin = $this->makeAdmin();

        $html = $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match('/<aside\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*aria-label="Admin navigation".*?<\/aside>/s', $html, $matches),
            'The navigation panel should render exactly once.',
        );

        $sidebar = $matches[0];

        $this->assertStringNotContainsString($admin->email, $sidebar, 'The sidebar should not repeat the admin email.');
        $this->assertStringNotContainsString($admin->full_name, $sidebar, 'The sidebar should not repeat the admin name.');
        $this->assertStringNotContainsString('Administrator', $sidebar, 'The sidebar should not show the role badge.');
        $this->assertStringNotContainsString('View Site', $sidebar, 'The sidebar should not offer a View Site link.');
        $this->assertStringNotContainsString(route('admin.logout'), $sidebar, 'The sidebar should not carry a logout form.');

        // The real logo asset, the same one the customer nav, the footer and the
        // sign-in panel use — and not the "BtA" monogram they fall back to
        // without it, which is what the panel used to show.
        $this->assertStringContainsString(asset('images/logo.png'), $sidebar);
        $this->assertStringNotContainsString('>Bt<span class="text-gold">A</span><', $sidebar);
        $this->assertFileExists(public_path('images/logo.png'));

        // The links are still there, and they now own the full panel height.
        $this->assertStringContainsString('Inventory', $sidebar);
        $this->assertStringContainsString('aria-label="Admin"', $sidebar);

        // The Low-Stock Tags screen is no longer a destination: the tagging
        // flow was folded away, so the link and the low-stock icon that used to
        // sit beside the notification bell must not quietly come back.
        $this->assertStringNotContainsString('Low-Stock Tags', $sidebar);
        $this->assertStringNotContainsString(route('admin.tags.index'), $sidebar);
        $this->assertStringNotContainsString(route('admin.tags.index'), $html);

        // Logout and Profile remain reachable from the top bar.
        $this->assertStringContainsString($admin->full_name, $html);
        $this->assertStringContainsString(route('admin.profile.edit'), $html);
        $this->assertStringContainsString(route('admin.logout'), $html);
    }

    /**
     * The topbar bell shows the salon's work queue, and keeps it current.
     *
     * This inverts an earlier decision on purpose. The bell was removed, then the
     * badge that outlived it, on the grounds that "the app has no admin-facing
     * notifications" — which was not quite true: it had two kinds of work
     * waiting, bookings sitting in Pending and contact enquiries nobody had
     * opened, and nothing in the panel surfaced either. The enquiry inbox in
     * particular was reachable by URL alone.
     *
     * The count came back, and then it moved again. The bell was reinstated as a
     * badged icon in the top bar, and has now been removed once more — not
     * because the count was wrong, but because the bar is the wrong place for it.
     * Two different kinds of work shared one number on one icon, so neither the
     * icon nor the number could say which it was; and the number sat nowhere near
     * the rows that act on it.
     *
     * So the count is on the sidebar now, split across the two rows that own it:
     * Appointments badges the bookings awaiting a decision and Messages badges the
     * enquiries nobody has opened. What is left of the bell is its poll, which
     * `adminLiveNotifications` in the layout still runs.
     */
    public function test_the_work_queue_count_lives_on_the_sidebar_not_the_topbar(): void
    {
        $admin = $this->makeAdmin();

        // One of each kind of thing waiting.
        $this->makeAppointment($this->makeUser(), null, ['status' => AppointmentStatus::Pending]);

        ContactMessage::create([
            'name' => 'Ana Reyes',
            'email' => 'ana@example.test',
            'topic' => InquiryTopic::GeneralInquiry,
            'message' => 'Do you take walk-ins on Saturdays?',
        ]);

        $html = $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->getContent();

        // The bell is gone from the bar, badge and all.
        $topbar = substr($html, (int) strpos($html, 'admin-topbar'), (int) strpos($html, '</header>') - (int) strpos($html, 'admin-topbar'));

        $this->assertStringNotContainsString(
            'aria-label="Notifications"',
            $topbar,
            'The topbar must carry no notification icon.',
        );
        $this->assertStringNotContainsString(
            'adminNotificationBell(',
            $html,
            'The bell component is gone; its poll is now `adminLiveNotifications`.',
        );
        $this->assertStringNotContainsString(
            'Waiting on you',
            $html,
            'Its dropdown is gone with it.',
        );

        // And the count is on the sidebar row that owns it, badged and bound to its own
        // broadcast. Messages used to be the second such row; it is gone from the
        // nav now, so Appointments and Inventory are the only badged rows left.
        $nav = $this->sidebarNav($html);

        $this->assertStringContainsString(route('admin.appointments.index'), $nav);
        $this->assertStringContainsString('admin-pending', $nav);

        $this->assertStringNotContainsString(route('admin.messages.index'), $nav, 'Messages is no longer on the admin nav.');
        $this->assertStringNotContainsString('admin-messages', $nav, 'The unread-enquiry broadcast has no row to drive.');

        // The poll survives, and the endpoint it reads answers inside the panel's
        // own guard rather than being publicly reachable.
        $this->assertStringContainsString('adminLiveNotifications(', $html);
        $this->assertStringContainsString(route('admin.notifications.feed'), $this->unescaped($html));

        $this->actingAs($admin, 'admin')->get(route('admin.notifications.feed'))->assertOk();
    }

    /**
     * Removing a nav row must not take the page with it.
     *
     * The Messages screen is deliberately left reachable by URL — only the link
     * was asked for. This pins that distinction, so a later "tidy up the dead
     * route" pass cannot quietly delete a working inbox along with the nav row.
     */
    public function test_messages_is_off_the_nav_but_the_page_still_works(): void
    {
        $admin = $this->makeAdmin();

        $html = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('admin.messages.index'), $this->sidebarNav($html));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.messages.index'))
            ->assertOk();
    }

    /** The bell is seeded from the same counts it will poll, so it cannot disagree with itself. */
    public function test_the_bell_is_seeded_with_the_counts_the_feed_returns(): void
    {
        $admin = $this->makeAdmin();

        $this->makeAppointment($this->makeUser(), null, ['status' => AppointmentStatus::Pending]);
        $this->makeAppointment($this->makeUser(), null, ['status' => AppointmentStatus::Pending]);

        ContactMessage::create([
            'name' => 'Ana Reyes',
            'email' => 'ana@example.test',
            'topic' => InquiryTopic::GeneralInquiry,
            'message' => 'Do you take walk-ins?',
        ]);

        $html = $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->getContent();

        // The payload the component starts from carries both halves, unescaped
        // out of `@js()`.
        $this->assertStringContainsString('"pending":2', $this->unescaped($html));
        $this->assertStringContainsString('"unread":1', $this->unescaped($html));

        // And the endpoint agrees with it, since both count the same rows.
        $payload = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.notifications.feed'))
            ->assertOk()
            ->json();

        $this->assertSame(2, $payload['pending']);
        $this->assertSame(1, $payload['messages']);

        // Both pending bookings and the one enquiry: the counts and the rows are
        // the same two queries, so a bell that badges 3 and lists 1 would be a
        // bug rather than a rounding difference.
        $this->assertCount(3, $payload['items']);
        $this->assertCount(2, array_filter($payload['items'], fn ($item) => $item['kind'] === 'appointment'));
    }

    /**
     * The feed only lists what the salon has not looked at.
     *
     * Was "pending bookings and unread enquiries only", filtered by status. The
     * status filter was doing two jobs — keeping settled bookings out of the
     * queue, which is still worth doing — and standing in for "unread", which it
     * cannot do, because a Pending booking does not become un-Pending by being
     * read. So the feed counts `admin_seen_at IS NULL`, and this creates a mix of
     * seen and unseen across several statuses to prove the filter is the right
     * one: a seen Pending booking is out, and an unseen Confirmed one is in.
     */
    public function test_the_feed_lists_unseen_bookings_and_unread_enquiries_only(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser();

        // Unseen: in, whatever its status.
        $unseenPending = $this->makeAppointment($user, null, ['status' => AppointmentStatus::Pending]);
        $unseenConfirmed = $this->makeAppointment($user, null, ['status' => AppointmentStatus::Confirmed]);

        // Seen: out, whatever its status.
        $seenPending = $this->makeAppointment($user, null, [
            'status' => AppointmentStatus::Pending,
            'admin_seen_at' => now(),
        ]);

        $read = ContactMessage::create([
            'name' => 'Ana Reyes',
            'email' => 'ana@example.test',
            'topic' => InquiryTopic::GeneralInquiry,
            'message' => 'Already answered.',
            'is_read' => true,
        ]);

        $unread = ContactMessage::create([
            'name' => 'Ben Cruz',
            'email' => 'ben@example.test',
            'topic' => InquiryTopic::GeneralInquiry,
            'message' => 'Do you take walk-ins on Saturdays?',
        ]);

        $payload = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.notifications.feed'))
            ->assertOk()
            ->json();

        $this->assertSame(2, $payload['pending']);
        $this->assertSame(1, $payload['messages']);

        $keys = array_map(
            fn (array $item) => $item['kind'].':'.$item['id'],
            $payload['items']
        );

        $this->assertContains('appointment:'.$unseenPending->id, $keys);
        $this->assertContains('appointment:'.$unseenConfirmed->id, $keys);
        $this->assertNotContains('appointment:'.$seenPending->id, $keys);
        $this->assertContains('message:'.$unread->id, $keys);
        $this->assertNotContains('message:'.$read->id, $keys);
    }

    /** The sidebar's pending badge follows the layout's poll, so the two cannot drift. */
    public function test_the_sidebar_pending_badge_follows_the_poll(): void
    {
        $admin = $this->makeAdmin();
        $this->makeAppointment($this->makeUser(), null, ['status' => AppointmentStatus::Pending]);

        $html = $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->getContent();

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        $row = (new \DOMXPath($doc))->query('//aside//a[@href="'.route('admin.appointments.index').'"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $row);

        $badge = (new \DOMXPath($doc))->query('.//span[contains(@class, "text-[10px]")]', $row)->item(0);
        $this->assertInstanceOf(\DOMElement::class, $badge);

        // Bound to the same broadcast the layout's poller dispatches, and seeded
        // with the count the page was rendered with.
        $markup = $badge->ownerDocument->saveHTML($badge);

        $this->assertStringContainsString('x-on:admin-pending.window', $markup);
        $this->assertStringContainsString('x-show="count &gt; 0"', $markup);
        $this->assertStringContainsString('x-text="count"', $markup);
    }

    /**
     * The badge component the sidebar's counters use.
     *
     * It was extracted when the topbar bell wanted the same badge, and the two
     * then drifted apart — a class string in the sidebar, a different one in the
     * topbar — which is how the bell came to look unlike the sidebar it was meant
     * to match. Both of its callers have since gone, the bell first and then the
     * count that outlived it, so the sidebar is the only one left.
     *
     * Which means the corner `overlay` placement has no caller at all now, and
     * has been taken out rather than left as a branch nothing exercises. The
     * customer-facing bell in `partials.customer.nav` still draws an equivalent
     * badge by hand; folding that into this component is the obvious way to give
     * `overlay` a caller again, and would unify the two badges while it is at it.
     *
     * Asserted on the sources rather than the rendered pages, because the failure
     * being guarded against is a second copy being pasted back in.
     */
    public function test_the_sidebar_counts_use_the_shared_badge_component(): void
    {
        $component = resource_path('views/components/ui/count-badge.blade.php');
        $this->assertFileExists($component);

        $sidebar = (string) file_get_contents(resource_path('views/partials/admin/sidebar.blade.php'));
        $topbar = (string) file_get_contents(resource_path('views/partials/admin/topbar.blade.php'));

        // The sidebar goes through the component…
        $this->assertStringContainsString('x-ui.count-badge', $sidebar);

        // …and does not carry its own copy of the styling.
        foreach (['text-[10px] font-semibold', 'bg-status-low-stock-bg', 'bg-primary text-cream'] as $token) {
            $this->assertStringNotContainsString($token, $sidebar, 'The sidebar should not restyle its own counters.');
        }

        $source = (string) file_get_contents($component);

        // The tokens, in exactly one place. The tone is chosen in a ternary
        // rather than an `@class` array, because the badge assembles its
        // attributes as a string — a Blade directive written between an
        // opening tag's attributes compiles to something that renders nothing.
        $this->assertStringContainsString(
            'rounded-pill px-1.5 py-0.5 text-[10px] font-semibold',
            $source,
        );
        $this->assertStringContainsString(
            "\$tone === 'warning' ? 'bg-status-low-stock-bg text-status-low-stock' : 'bg-primary text-cream'",
            $source,
        );

        // The pill, which is all that is left, and the sidebar asks for it by
        // taking the default rather than naming a placement.
        $this->assertStringNotContainsString('placement=', $sidebar);
        $this->assertStringNotContainsString("\$placement === 'overlay'", $source, 'Dead placement should not linger.');
        $this->assertStringNotContainsString('rounded-full', $source);

        // Nothing else in the admin panel uses it either.
        $this->assertStringNotContainsString('x-ui.count-badge', $topbar);

        // "Hide at zero" is the component's call, not the call site's. A live
        // badge is the one exception: it has to stay in the DOM to receive the
        // broadcast, so it is emitted and hidden by `x-show` instead.
        $this->assertStringContainsString('@if ($live || ! empty($count))', $source);
        $this->assertStringContainsString('x-show="count > 0"', $source);
    }

    /**
     * The sidebar counter is still a pill in the nav row.
     *
     * Sharing a component with the bell must not have dragged the sidebar into
     * the bell's corner geometry: a badge absolutely positioned inside a flex row
     * would fly off to the top-right of the *row* and stop lining up with its
     * label.
     */
    public function test_the_sidebar_counter_renders_as_a_pill_after_its_label(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser();

        $this->makeAppointment($user, null, ['status' => AppointmentStatus::Pending]);

        $html = $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->getContent();

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        $row = (new \DOMXPath($doc))->query('//aside//a[@href="'.route('admin.appointments.index').'"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $row);

        // Matched on the shared type size, which is what makes it this badge and
        // not the row's label span.
        $badge = (new \DOMXPath($doc))->query('//aside//a[@href="'.route('admin.appointments.index').'"]//span[contains(@class, "text-[10px]")]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $badge, 'The Appointments row should carry its counter.');

        $classes = $badge->getAttribute('class');

        $this->assertStringContainsString('rounded-pill', $classes);
        $this->assertStringContainsString('px-1.5', $classes);
        $this->assertStringContainsString('bg-primary', $classes);

        // Nothing corner-positioned, and no ring: this one is not overhanging.
        $this->assertStringNotContainsString('absolute', $classes);
        $this->assertStringNotContainsString('ring-2', $classes);
        $this->assertStringNotContainsString('rounded-full', $classes);

        // The Appointments counter is live, so its number is bound rather than
        // printed: the count the page was rendered with lives in `x-data`, and
        // every later count comes from the bell's poll.
        $markup = $badge->ownerDocument->saveHTML($badge);

        $this->assertStringContainsString('x-data="{ count: 1 }"', $markup);
        $this->assertStringContainsString('x-text="count"', $markup);

        $this->assertDatabaseCount('appointments', 1);
    }

    /** With nothing pending, the sidebar row carries no badge at all. */
    public function test_a_zero_sidebar_count_renders_no_badge(): void
    {
        $html = $this->actingAs($this->makeAdmin(), 'admin')->get('/admin')->assertOk()->getContent();

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        $row = (new \DOMXPath($doc))->query('//aside//a[@href="'.route('admin.appointments.index').'"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $row);

        /*
         * The badge span is present but hidden, rather than absent.
         *
         * That is the one thing that changed, and it follows from the counter
         * going live: an element removed from the DOM has nothing to bind to, so
         * the live badge stays in the markup and `x-show` is what hides it at
         * zero. What must still hold is that no visitor ever SEES a zero — hence
         * `x-show="count > 0"` and `x-cloak`, with the `hidden` attribute the
         * component falls back to as the no-JavaScript backstop.
         */
        $badges = (new \DOMXPath($doc))->query('.//span[contains(@class, "text-[10px]")]', $row);
        $this->assertSame(1, $badges->length, 'The live badge is rendered so it can receive the broadcast.');

        $badge = $badges->item(0);
        $markup = $badge->ownerDocument->saveHTML($badge);

        $this->assertStringContainsString('x-show="count &gt; 0"', $markup);

        // The no-JavaScript backstop: a live badge is in the markup so it can
        // receive the broadcast, so at zero it is hidden rather than absent.
        $this->assertStringContainsString('hidden', $markup);
        $this->assertStringContainsString('Appointments', $row->textContent);
    }

    /**
     * The topbar's right-hand controls: a notification bell, then the admin
     * identity behind a Profile/Logout dropdown.
     *
     * The light/dark toggle that briefly sat here was removed along with the
     * theme it controlled. Leaving the mechanism in place would have let a
     * stale `admin-theme` value in localStorage pin the panel dark with no way
     * back out of it.
     */
    public function test_the_topbar_carries_the_title_and_profile_menu_only(): void
    {
        $admin = $this->makeAdmin();

        $html = $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->getContent();

        // Title on the left.
        $this->assertStringContainsString('admin-topbar', $html);
        $this->assertStringContainsString('>Dashboard<', $html, 'The topbar should show the current page title.');

        // Nothing but the identity on the right. The notification icon used to
        // lead, and the count it badged is on the sidebar now — see
        // test_the_work_queue_count_lives_on_the_sidebar_not_the_topbar.
        $topbar = substr($html, (int) strpos($html, 'admin-topbar'), (int) strpos($html, '</header>') - (int) strpos($html, 'admin-topbar'));

        $this->assertStringNotContainsString('aria-label="Notifications"', $topbar);
        $this->assertStringNotContainsString('admin-notification-panel', $html);

        // The light/dark toggle is gone, and so is the mechanism behind it —
        // a stale `admin-theme` value must not be able to pin the panel dark with
        // no way back.
        $this->assertStringNotContainsString('themeToggle', $html);
        $this->assertStringNotContainsString('admin-theme', $html);
        $this->assertStringNotContainsString('admin-dark', $html);
        $this->assertStringNotContainsString('Toggle dark mode', $html);

        // Admin name with a dropdown holding Profile and Logout.
        $this->assertStringContainsString($admin->full_name, $html);
        $this->assertStringContainsString('x-data="dropdown"', $html);
        $this->assertStringContainsString(route('admin.profile.edit'), $html);

        // The labels sit on their own line next to an icon, so the surrounding
        // whitespace is part of the markup.
        foreach (['Profile', 'Logout'] as $label) {
            $this->assertMatchesRegularExpression(
                '/>\s*'.preg_quote($label, '/').'\s*</',
                $html,
                'The profile dropdown should offer a "'.$label.'" item.',
            );
        }
    }
}
