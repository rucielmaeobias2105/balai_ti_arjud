@php
    use App\Models\Appointment;
    use App\Support\Nav;

    $current = Nav::adminCurrent();

    /*
     * Unseen bookings, which is not the same set as Pending ones.
     *
     * This was `where('status', 'pending')`, and that made the badge impossible to
     * clear: it counted a status, so the only thing that could change the number
     * was approving or declining each booking. An admin who opened the page, read
     * every row and closed the tab saw the count unchanged, because reading is not
     * deciding — so it sat there all day telling them something they already knew.
     *
     * The Appointments screen marks what it shows as seen, so opening it clears
     * the badge, and a booking that arrives afterwards brings it back. Pending
     * still means Pending; it just no longer doubles as "unread".
     *
     * Counted through the model so this, the tab title and the poll all ask the
     * same question of the same rows.
     */
    $unseenAppointments = Appointment::unseenForAdminCount();

    $lowStockCount = \App\Models\InventoryItem::lowStock()->count();

    // Contact messages are no longer on this nav. The unread-enquiry count
    // lived only on that row, so with the row gone there is nothing here to
    // count: `ContactMessage::query()->unread()->count()` was asked on every
    // admin page render to badge a row that is no longer rendered. The Messages
    // screen itself, its routes and its permission are untouched — it is still
    // reachable by URL, it just is not advertised here.
    $groups = [
        'Main' => [
            ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'icon' => 'heroicon-o-squares-2x2', 'active' => 'dashboard', 'ability' => 'admin.dashboard.view'],
            ['route' => 'admin.appointments.index', 'label' => 'Appointments', 'icon' => 'heroicon-o-calendar-days', 'active' => 'appointments', 'ability' => 'admin.appointments.manage', 'badge' => $unseenAppointments, 'liveEvent' => 'admin-pending'],
            // Its own row rather than only the pill on the Appointments toolbar.
            // An archived booking is a different queue — settled, out of the
            // working list, restorable in bulk — and burying it under a button on
            // another screen is why the archive was easy to forget existed.
            // `heroicon-o-archive-box` is taken by Inventory, so this uses the
            // open box: filed away, but not locked.
            ['route' => 'admin.appointments.archived', 'label' => 'Archived', 'icon' => 'heroicon-o-folder-open', 'active' => 'archived-appointments', 'ability' => 'admin.appointments.manage'],
        ],
        'Catalog' => [
            ['route' => 'admin.services.index', 'label' => 'Services', 'icon' => 'heroicon-o-scissors', 'active' => 'services', 'ability' => 'admin.catalog.view'],
            ['route' => 'admin.categories.index', 'label' => 'Categories', 'icon' => 'heroicon-o-swatch', 'active' => 'categories', 'ability' => 'admin.catalog.view'],
            ['route' => 'admin.technicians.index', 'label' => 'Technicians', 'icon' => 'heroicon-o-user-group', 'active' => 'technicians', 'ability' => 'admin.technicians.view'],
            ['route' => 'admin.promos.index', 'label' => 'Promo', 'icon' => 'heroicon-o-megaphone', 'active' => 'promos', 'ability' => 'admin.promos.manage'],
        ],
        'Operations' => [
            // `liveEvent` is what keeps the count current:
            // `adminLiveNotifications` polls the feed and broadcasts the count to
            // the row that shows it, so the badge cannot disagree with itself or
            // with the tab title.
            //
            // This group used to hold a Messages row beside this one, badged with
            // unread enquiries and bound to the `admin-messages` broadcast. That
            // row is gone; what is left is a single badged count, and the two
            // "what does my number mean" comments it was carrying with it.
            ['route' => 'admin.inventory.index', 'label' => 'Inventory', 'icon' => 'heroicon-o-archive-box', 'active' => 'inventory', 'ability' => 'admin.inventory.view', 'badge' => $lowStockCount, 'badgeStyle' => 'warning'],
            ['route' => 'admin.users.index', 'label' => 'Registered Users', 'icon' => 'heroicon-o-users', 'active' => 'users', 'ability' => 'admin.users.view'],
        ],
        'System' => [
            ['route' => 'admin.terms.index', 'label' => 'Terms & Conditions', 'icon' => 'heroicon-o-document-text', 'active' => 'terms', 'ability' => 'admin.terms.view'],
            ['route' => 'admin.reports.index', 'label' => 'Reports', 'icon' => 'heroicon-o-chart-bar', 'active' => 'reports', 'ability' => 'admin.reports.view'],
            // Calendar only. The recurring schedule — opening hours, slot length,
            // booking window — is configuration in `salon_settings` rather than a
            // record, and has no screen here. One-off closures are records with
            // rows an admin adds and removes, which is exactly what a sidebar item
            // is for.
            ['route' => 'admin.calendar.index', 'label' => 'Calendar', 'icon' => 'heroicon-o-calendar', 'active' => 'calendar', 'ability' => 'admin.calendar.manage'],
        ],
    ];
@endphp

{{--
    Visibility is deliberately NOT driven by `x-show`. Alpine writes an inline
    `display: none` when the expression is false, and an inline style outranks
    every `lg:` utility, so a `x-show="sidebar"` on a `sidebar: false` wrapper
    hid the panel at desktop width too — with the only trigger (the topbar
    hamburger) itself `lg:hidden`, so it could never be reopened.

    Instead the drawer state is a translate: `-translate-x-full` parks it
    off-canvas below `lg`, and `lg:!translate-x-0` (important, so it beats the
    bound class) pins it in flow from `lg` up. The bound open state is
    `!translate-x-0` for the same reason, which keeps "closed" and "open"
    mutually exclusive instead of relying on Tailwind's rule order.

    From `lg` the panel is `sticky` at the top and exactly one viewport tall, so
    the nav's own `overflow-y-auto` engages and only the links scroll — the brand
    header and the profile/logout footer stay pinned. A static, auto-height
    sidebar grows with the nav instead, which pushes the whole page down.
--}}
<aside
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="-translate-x-full"
    x-transition:enter-end="translate-x-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="translate-x-0"
    x-transition:leave-end="-translate-x-full"
    :class="sidebar ? '!translate-x-0' : '-translate-x-full'"
    @resize.window="if (window.innerWidth >= 1024) sidebar = false"
    class="admin-sidebar fixed inset-y-0 left-0 z-50 flex w-64 shrink-0 -translate-x-full flex-col border-r border-line/70 bg-cream transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:!translate-x-0"
    aria-label="Admin navigation"
>
    <div class="shrink-0 border-b border-line/70 px-4 py-4">
        {{-- The real logo asset, as the customer nav, the footer and the
             sign-in panel all use. This was the one surface still falling back
             to the "BtA" monogram, so the panel did not match the site. --}}
        <x-brand.logo href="{{ route('admin.dashboard') }}" size="sm" image="images/logo.png" />

        <button type="button" @click="sidebar = false" class="absolute right-3 top-5 rounded-lg p-1.5 text-ink-muted hover:bg-linen lg:hidden" aria-label="Close navigation">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 18 18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4" aria-label="Admin">
        @foreach ($groups as $heading => $links)
            @php $visible = array_filter($links, fn ($link) => Gate::allows($link['ability'])); @endphp

            @if ($visible !== [])
                <p class="admin-nav-heading">{{ $heading }}</p>
            @endif

            @foreach ($visible as $link)
                @php
                    $isActive = $current === $link['active'];
                @endphp
                <a
                    href="{{ route($link['route']) }}"
                    @class(['admin-nav-link', 'admin-nav-link-active' => $isActive])
                    @if ($isActive) aria-current="page" @endif
                >{{-- The row's attributes stay contiguous, which is why the
                      icon is emitted after the `href` line. --}}
                    <x-dynamic-component :component="$link['icon']" class="h-5 w-5 shrink-0" />
                    <span class="flex-1 truncate">{{ $link['label'] }}</span>

                    {{-- The count styling itself lives in `x-ui.count-badge`,
                         shared with the topbar bell. Which tone is a decision
                         this row makes, not the badge: the warning fill is
                         dropped while the link is the active one, because an
                         amber badge on the row you are already on reads as a
                         second thing needing attention.

                         Appointments is the one live count. The topbar bell
                         polls the same pending bookings and broadcasts them, so
                         the sidebar follows from that one request rather than
                         sitting there claiming a number the bell beside it has
                         already watched change. The rest are rendered once, as
                         before — nothing polls for them. --}}
                    <x-ui.count-badge
                        :count="$link['badge'] ?? null"
                        :tone="! $isActive && ($link['badgeStyle'] ?? null) === 'warning' ? 'warning' : 'default'"
                        :live-event="$link['liveEvent'] ?? null"
                    />
                </a>
            @endforeach
        @endforeach
    </nav>

    {{--
        The footer that used to live here is gone entirely: the identity block
        (avatar initials, name, role badge, email), then the "View Site" link,
        then the Logout button. Nothing is left pinned below the nav, so the
        links own the full height of the panel. Logging out and editing the
        profile both live in the top bar dropdown.
    --}}
</aside>
