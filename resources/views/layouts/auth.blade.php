<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#5A1F18">
    <meta name="robots" content="noindex, nofollow">

    {{-- The session-sync contract, repeated here because this layout is the one
         a stale tab is most likely to be sitting on: a login screen is exactly
         where somebody lands after the browser's identity changed underneath
         them. See `App\Support\SessionIdentity` and the notes on the customer
         layout. --}}
    <meta name="bta-session" content="{{ \App\Support\SessionIdentity::key() }}">
    <meta name="bta-home-admin" content="{{ route('admin.dashboard') }}">
    <meta name="bta-home-web" content="{{ route('home') }}">
    <meta name="bta-login-admin" content="{{ route('admin.login') }}">
    <meta name="bta-login-web" content="{{ route('login') }}">

    {{-- Where the liveness probe lives. Published rather than hardcoded in
         `sessionSync`, because this application is served out of a subdirectory
         (`htdocs/BTA/public`) and a root-absolute `/session-status` 404s there,
         which silently disables the back/forward-cache check. --}}
    <meta name="bta-session-status" content="{{ route('session-status') }}">

    {{-- Tab title follows the house format: "LOG IN | Balai ti Arjud" for the
         customer surfaces, "ADMIN SIGN IN | ..." for staff. The section is
         upper-cased here so views write natural case. --}}
    @php
        $pageTitle = Str::upper(trim($__env->yieldContent('title', 'Log In')));
    @endphp
    <title>{{ $pageTitle }} | {{ config('app.name') }}</title>

    <link rel="icon" href="{{ asset('images/tab_logo.png') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body
    {{--
        `x-data` on the body is load-bearing, and it is here because Alpine does
        not do what everyone assumes it does.

        `Alpine.start()` initialises the elements that match `[x-data]` and walks
        their subtrees. It does NOT walk from `<body>`. A directive that is not
        inside some `x-data` element is therefore never initialised at all — no
        error, no warning, the attribute simply sits there doing nothing.

        That is why the registration form's "Terms and Conditions" link was
        inert. It carried `x-on:click.prevent` to open the terms dialog, but the
        link sits inside `<form>`, which has no `x-data`, and neither does any
        ancestor up to the body — the only roots on this page were the phone
        field's own `x-data` and the dialog's. So `preventDefault` was never
        bound, the click followed the real `href`, and the customer was dropped
        on the standalone `/terms/booking` page instead of getting a dialog.

        A root here means every directive on the page is inside a tree, whether
        or not the page that owns it remembers to add its own.
    --}}
    x-data
    class="min-h-full"
>
    {{--
        One two-panel auth shell for every sign-in surface: customer login,
        register and password recovery, plus the staff portal login.

        The brand panel is deliberately identical everywhere so the left side
        reads as one brand moment. Only the headline and the feature row vary,
        and both arrive as data — `$features` defaults to the customer-facing
        row, and the admin controller overrides it with its own labels.

        The old per-surface layouts (layouts.guest and layouts.admin-auth) were
        near-duplicates of this markup with divergent styling; they are gone.
    --}}
    @php
        $features = $features ?? [
            ['Book Appointments', 'heroicon-o-calendar-days'],
            ['Browse Services', 'heroicon-o-sparkles'],
            ['Track Your Bookings', 'heroicon-o-clipboard-document-check'],
            ['Enjoy Exclusive Offers', 'heroicon-o-gift'],
        ];
    @endphp

    <div
        class="admin-auth"
        style="--admin-auth-image: url('{{ asset('images/'.($authImage ?? 'hero.jpg')) }}')"
    >
        {{-- Brand panel: photo, cropped corner disc and the salon voice. --}}
        <section class="admin-auth-brand" aria-label="Balai ti Arjud branding">
            <span class="admin-auth-ring" aria-hidden="true"></span>

            <div class="admin-auth-brand-inner">
                {{-- The navbar's own emblem, scaled up: same logo.png asset,
                     same gold ring. The mark already carries the flower
                     illustration, the arched wordmark, "Glow & Co." and
                     "BEAUTY LOUNGE", so the maroon face and the ring come
                     from the artwork rather than a text-only rebuild. --}}
                <x-brand.emblem
                    image="images/logo.png"
                    size="xl"
                    name-label="Balai ti Arjud logo"
                    class="admin-auth-mark"
                />

                <h2 class="admin-auth-name">Balai ti Arjud</h2>
                <p class="admin-auth-sub">Glow & Co. Beauty Lounge</p>

                <span class="admin-auth-divider" aria-hidden="true"></span>

                <h3 class="admin-auth-title">{!! $panelTitle ?? 'Beauty, Relaxation,<br>and Hospitality' !!}</h3>
                <p class="admin-auth-copy">
                    {!! $panelScript ?? 'in one elegant experience.' !!}
                </p>

                <ul class="admin-auth-features">
                    @foreach ($features as [$label, $icon])
                        <li class="admin-auth-feature">
                            <span class="admin-auth-feature-icon">
                                <x-dynamic-component :component="$icon" class="h-[22px] w-[22px]" />
                            </span>
                            <span>{{ $label }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <span class="admin-auth-floral admin-auth-floral-left" aria-hidden="true">&#10047;</span>
            <span class="admin-auth-floral admin-auth-floral-right" aria-hidden="true">&#10048;</span>
        </section>

        {{-- Form panel. The brand panel is hidden below `lg`, so the compact
             wordmark stands in for it on small screens. --}}
        <main class="admin-auth-panel">
            {{-- Customer forms are short and single-column, so they take the
                 narrow card; the staff portal passes $wideCard for the full
                 680px one. --}}
            <div @class(['admin-auth-card', 'admin-auth-card-narrow' => ! ($wideCard ?? false)])>
                <div class="mb-8 text-center lg:hidden">
                    <x-brand.logo href="{{ route('home') }}" size="md" image="images/logo.png" />
                </div>

                @yield('content')
            </div>
        </main>
    </div>

    {{--
        The Terms & Conditions dialog, for the same reason the customer and admin
        layouts mount it: the triggers are scattered and a dialog added page by
        page is a dialog one of them ends up without.

        It was missing here, and that made the registration page's terms link
        inert. `x-terms.link` is a real `<a>` whose `href` is intercepted by
        Alpine to open this dialog — so with no dialog mounted, the
        `preventDefault` fired, nothing opened, and the only way to read the
        terms from the sign-up form was gone. Mounting it here is what makes
        "I agree to the Terms and Conditions" a statement the customer can
        actually check.
    --}}
    <x-terms.modal />

    @stack('scripts')
</body>
</html>
