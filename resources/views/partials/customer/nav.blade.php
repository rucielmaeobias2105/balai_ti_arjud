@php
    use App\Support\Nav;

    $current = Nav::customerCurrent();
    $unread = auth()->check() ? auth()->user()->unreadNotifications()->count() : 0;

    /*
     * What the bell's poller starts from, so the first tick is already a
     * diff rather than a first render of a number the server rendered into the
     * page anyway.
     *
     * Only the endpoint and the count now. The bell used to be handed the last
     * eight rows to list in its dropdown; it is a link to the notifications page
     * and the page renders its own list, so carrying them here would mean
     * fetching every notification on every page load to show a badge.
     *
     * `readAll` is here because clicking the bell clears the badge. It is the
     * same endpoint the notifications page's own "Mark all as read" button posts
     * to, handed over rather than rebuilt in the header: one write, one place
     * that knows what it means.
     */
    $bellContext = auth()->check()
        ? [
            'endpoint' => route('notifications.feed'),
            'unread' => $unread,
            'readAll' => route('notifications.read-all'),
        ]
        : null;
@endphp

{{--
    One `notificationBell` on the header rather than one per badge.

    The desktop bell and the mobile nav row both show the unread count, and
    giving each its own scope would start two pollers hitting the same endpoint
    every few seconds for the same number. Spreading the component's state into
    this header's existing object keeps one timer, one request per tick, and both
    badges bound to the same `unread`.

    `bellContext` is null for a guest, and the component treats a null config as
    "no endpoint, do nothing" — so a signed-out visitor pays nothing.
--}}
<header
    x-data="{ mobileOpen: false, profileOpen: false, ...notificationBell(@js($bellContext)) }"
    class="sticky top-0 z-40 border-b border-primary/10 bg-cream/95 backdrop-blur supports-[backdrop-filter]:bg-cream/80"
>
    <div class="mx-auto flex h-20 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
        <x-brand.logo href="{{ route('home') }}" size="md" image="images/logo.png" />

        {{-- The nav list and the bell/profile group share one `customer-nav-cluster`
             wrapper. They used to sit as two of three children of the
             `justify-between` row above, so the space between the last link and
             the bell was whatever free space the container had left over — a
             gap that grew with the viewport and left "My Appointments" stranded
             away from the bell. Inside the cluster it is a fixed `gap-5`.

             "My Appointments" is a top-level item here rather than a row in the
             profile menu, mirroring the reference: icon + label, last in the
             list, immediately before the bell. `Nav::CUSTOMER` already maps
             `appointments.*`, so it picks up its active state. --}}
        <div class="customer-nav-cluster">
            <nav class="hidden items-center gap-7 lg:flex" aria-label="Main">
                <a href="{{ route('home') }}" class="customer-nav-link {{ $current === 'home' ? 'customer-nav-link-active' : '' }}">
                    <i class="fas fa-house customer-nav-icon" aria-hidden="true"></i>
                    Home
                </a>
                <a href="{{ route('services.index') }}" class="customer-nav-link {{ $current === 'services' ? 'customer-nav-link-active' : '' }}">
                    <i class="fas fa-spa customer-nav-icon" aria-hidden="true"></i>
                    Services
                </a>
                <a href="{{ route('promos.index') }}" class="customer-nav-link {{ $current === 'promos' ? 'customer-nav-link-active' : '' }}">
                    <i class="fas fa-tag customer-nav-icon" aria-hidden="true"></i>
                    Promo
                </a>
                <a href="{{ route('about') }}" class="customer-nav-link {{ $current === 'about' ? 'customer-nav-link-active' : '' }}">
                    <i class="fas fa-circle-info customer-nav-icon" aria-hidden="true"></i>
                    About Us
                </a>
                <a href="{{ route('contact.create') }}" class="customer-nav-link {{ $current === 'contact' ? 'customer-nav-link-active' : '' }}">
                    <i class="fas fa-phone customer-nav-icon" aria-hidden="true"></i>
                    Contact
                </a>

                @auth
                    <a
                        href="{{ route('appointments.index') }}"
                        class="customer-nav-link {{ $current === 'appointments' ? 'customer-nav-link-active' : '' }}"
                    >
                        <i class="fas fa-calendar-check customer-nav-icon" aria-hidden="true"></i>
                        My Appointments
                    </a>
                @endauth
            </nav>

            <div class="flex items-center gap-2.5">
                @auth
                    {{--
                        The notification bell: a live badge on a link to the
                        notifications page.

                        It was a dropdown once, and opening it was the read
                        receipt. Two things were wrong with that. Nothing on screen
                        said that opening the bell had marked eight things read, so
                        checking the bell had the side effect of clearing it. And
                        the count only lived here, in the header — so marking
                        something read anywhere else could not move the badge, and
                        the bell and the tab title could sit there disagreeing.

                        So the bell is an ordinary link now. Reading happens on the
                        page it goes to, where there is a visible row and a
                        visible button, and `window.btaUnread` keeps the badge,
                        the mobile row and the tab title on one number.

                        `notificationBell` still polls `notifications.feed`, so a
                        status change updates the badge while the customer is on
                        the page rather than when they happen to reload.

                        And clicking it clears the badge on the way out.
                        `markAllRead()` — not `.prevent`, so the link still
                        navigates — zeroes the shared count in the same tick the
                        click is handled and posts the read-all write behind the
                        navigation with `keepalive`. The badge and the write are
                        separate on purpose: the badge is the customer's evidence
                        that they have seen something, so it must not wait on a
                        round trip, and the write must not be lost to the very
                        navigation it was triggered by. The write is idempotent,
                        so landing on the notifications page a moment before it
                        arrives costs nothing — the page renders from the database
                        either way, and the next poll reconciles.
                    --}}
                    <a
                        href="{{ route('notifications.index') }}"
                        class="relative flex h-10 w-10 items-center justify-center rounded-full text-primary transition hover:bg-primary/5"
                        aria-label="Notifications"
                        x-on:click="markAllRead()"
                    >
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/>
                        </svg>

                        {{-- The count is bound, not baked in: the poller and the
                             read actions both rewrite it in place, so it must not
                             carry a server-rendered number that JavaScript would
                             have to hunt for. --}}
                        <x-ui.icon-count-badge for="unread" />
                    </a>

                    {{-- `x-on:` rather than the `@click` shorthand. Both are valid — Alpine
                         registers `@` as a prefix for `x-on:` — so this is the
                         spelled-out form used everywhere in this file, chosen so
                         that a reader (and a grep) finds one spelling of the same
                         directive on the page rather than two. --}}
                    <div class="relative hidden sm:block" x-data="dropdown" x-on:click.outside="close()">
                        <button type="button" x-on:click="toggle()" class="flex items-center gap-2 rounded-pill border border-primary/15 py-1 pl-1 pr-3 transition hover:border-gold hover:bg-linen/60" aria-haspopup="true" :aria-expanded="open">
                            @if (auth()->user()->profile_photo_path)
                                <img src="{{ auth()->user()->profile_photo_url }}" alt="" class="h-7 w-7 rounded-full object-cover">
                            @else
                                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-[11px] font-semibold text-cream">
                                    {{ Str::upper(Str::substr(auth()->user()->first_name, 0, 1).Str::substr(auth()->user()->last_name, 0, 1)) }}
                                </span>
                            @endif
                            <span class="text-sm font-medium text-ink">{{ Str::before(auth()->user()->full_name, ' ') }}</span>
                            <svg class="h-3.5 w-3.5 text-ink-muted" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/></svg>
                        </button>

                        <div x-show="open" x-transition.origin.top.right class="absolute right-0 mt-2 w-56 overflow-hidden rounded-card border border-primary/10 bg-cream shadow-card-hover">
                            <div class="border-b border-primary/10 px-4 py-3">
                                <p class="truncate text-sm font-semibold text-primary">{{ auth()->user()->full_name }}</p>
                                <p class="truncate text-xs text-ink-muted">{{ auth()->user()->email }}</p>
                            </div>
                            <div class="p-1.5">
                                {{-- Just the two account actions. "My Appointments" moved up
                                     into the nav itself, and the Dashboard went away
                                     entirely, so this is now the short list it should
                                     have been. --}}
                                <a href="{{ route('profile.edit') }}" class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-ink transition hover:bg-linen">Profile</a>
                                <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-primary/10 pt-1">
                                    @csrf
                                    <button type="submit" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm text-status-cancelled transition hover:bg-status-cancelled-bg/50">Log Out</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @else
                    {{-- Register is not offered here: the login screen already
                         links to it twice, so a second entry point in the navbar
                         just competes with the page's own calls to action. --}}
                    <a href="{{ route('login') }}" class="btn-secondary btn-sm hidden sm:inline-flex">
                        <i class="fas fa-sign-in-alt" aria-hidden="true"></i>
                        Log In
                    </a>
                @endauth

                <button type="button" x-on:click="mobileOpen = ! mobileOpen" class="flex h-10 w-10 items-center justify-center rounded-lg text-primary lg:hidden" aria-label="Toggle menu" :aria-expanded="mobileOpen">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                </button>
            </div>
        </div>
    </div>

    {{-- Mobile nav --}}
    <div x-show="mobileOpen" x-transition class="border-t border-primary/10 bg-cream lg:hidden">
        <nav class="mx-auto grid max-w-7xl gap-1 px-4 py-4 sm:px-6" aria-label="Mobile">
            <a href="{{ route('home') }}" class="customer-nav-mobile-link">
                <i class="fas fa-house customer-nav-icon" aria-hidden="true"></i>
                Home
            </a>
            <a href="{{ route('services.index') }}" class="customer-nav-mobile-link">
                <i class="fas fa-spa customer-nav-icon" aria-hidden="true"></i>
                Services
            </a>
            <a href="{{ route('promos.index') }}" class="customer-nav-mobile-link">
                <i class="fas fa-tag customer-nav-icon" aria-hidden="true"></i>
                Promo
            </a>
            <a href="{{ route('about') }}" class="customer-nav-mobile-link">
                <i class="fas fa-circle-info customer-nav-icon" aria-hidden="true"></i>
                About Us
            </a>
            <a href="{{ route('contact.create') }}" class="customer-nav-mobile-link">
                <i class="fas fa-phone customer-nav-icon" aria-hidden="true"></i>
                Contact
            </a>

            {{-- Mirrors the desktop order: a top-level nav item collapses into
                 the mobile list with the other top-level items, not down with
                 the account actions. --}}
            @auth
                <a href="{{ route('appointments.index') }}" class="customer-nav-mobile-link">
                    <i class="fas fa-calendar-check customer-nav-icon" aria-hidden="true"></i>
                    My Appointments
                </a>
            @endauth

            @auth
                <div class="my-2 bta-divider"></div>
                {{-- The same destination as the bell, with the same live count —
                     both read `unread`, which both get from `window.btaUnread`, so
                     they cannot show different numbers. A link rather than the
                     read-receipt form this used to be, for the reason the bell is
                     a link too.

                     Same handler as the bell, because it is the same action on the
                     same number: this row is where a customer on a phone opens
                     their notifications, so a badge that survived the trip would be
                     a badge they had to open twice to get rid of. --}}
                <a
                    href="{{ route('notifications.index') }}"
                    class="customer-nav-mobile-link"
                    x-on:click="markAllRead()"
                >
                    <i class="fas fa-bell customer-nav-icon" aria-hidden="true"></i>
                    Notifications
                    <span
                        x-show="unread > 0"
                        x-cloak
                        class="ml-1 rounded-pill bg-primary px-1.5 py-0.5 text-[10px] font-semibold text-cream"
                    ><span x-text="unread > 9 ? '9+' : unread"></span></span>
                </a>
                <a href="{{ route('profile.edit') }}" class="customer-nav-mobile-link">
                    <i class="fas fa-user customer-nav-icon" aria-hidden="true"></i>
                    Profile
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium text-status-cancelled hover:bg-status-cancelled-bg/50">
                        <i class="fas fa-right-from-bracket customer-nav-icon" aria-hidden="true"></i>
                        Log Out
                    </button>
                </form>
            @else
                {{-- One column now that Register is gone; the login screen
                     carries the link to registration. --}}
                <div class="mt-3">
                    <a href="{{ route('login') }}" class="btn-secondary btn-sm w-full">
                        <i class="fas fa-sign-in-alt" aria-hidden="true"></i>
                        Log In
                    </a>
                </div>
            @endauth
        </nav>
    </div>
</header>
