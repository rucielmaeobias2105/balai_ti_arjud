<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#6E211B">

    {{-- Who this page believes it is for: `admin:3`, `web:12` or `guest`.
         Read by `sessionSync`, which writes it to `localStorage` and watches for
         another tab writing a different one — the mechanism that stops Tab A
         from sitting on a customer dashboard after Tab B has signed in as an
         admin. The server half is `App\Support\SessionIdentity`. --}}
    <meta name="bta-session" content="{{ \App\Support\SessionIdentity::key() }}">

    {{-- Where `sessionSync` sends a tab when the browser's identity changes to
         something this page is not. Read from the document rather than hardcoded
         in the script, so the routing lives in routes/web.php and the two
         layouts can each contribute their own home. A tab that finds none stays
         put rather than looping. --}}
    <meta name="bta-home-admin" content="{{ route('admin.dashboard') }}">
    <meta name="bta-home-web" content="{{ route('home') }}">
    <meta name="bta-login-admin" content="{{ route('admin.login') }}">
    <meta name="bta-login-web" content="{{ route('login') }}">

    {{-- Where the liveness probe lives. Published rather than hardcoded in
         `sessionSync`, because this application is served out of a subdirectory
         (`htdocs/BTA/public`) and a root-absolute `/session-status` 404s there,
         which silently disables the back/forward-cache check. --}}
    <meta name="bta-session-status" content="{{ route('session-status') }}">

    {{-- Tab title: "HOME | Balai ti Arjud". The section is upper-cased here so
         every view keeps writing natural-cased titles.

         Signed in, it carries the unread count in front, the way Messenger does:
         "(3) HOME | Balai ti Arjud". Rendered server-side as well as updated by
         the poller, because the moment a user reads the title to decide whether
         to switch to a background tab is before the bundle has finished
         loading. A guest gets the plain title — `customerUnread()` is zero when
         nobody is signed in, so the prefix is simply absent.

         The count is the same number the navbar bell badges (see
         `App\Support\TabTitle`), and `notificationBell` keeps both in step from
         one poll. --}}
    @php
        $pageTitle = \App\Support\TabTitle::withCount(
            Str::upper(trim($__env->yieldContent('title', 'Home'))).' | '.config('app.name'),
            \App\Support\TabTitle::customerUnread(),
        );
    @endphp
    <title>{{ $pageTitle }}</title>

    <link rel="icon" href="{{ asset('images/tab_logo.png') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body
    {{--
        The same reason the auth layout has one: `Alpine.start()` initialises
        `[x-data]` elements and their subtrees, and never walks from `<body>` on
        its own. A directive sitting outside every `x-data` on the page is dead
        code — no error, just an attribute that does nothing — so the root goes
        here rather than depending on each page to remember its own.
    --}}
    x-data
    class="flex min-h-full flex-col bg-linen"
>
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-primary focus:px-4 focus:py-2 focus:text-cream">
        Skip to content
    </a>

    @include('partials.customer.nav')

    <main id="main" class="flex-1">
        @yield('content')
    </main>

    @include('partials.customer.footer')

    {{-- The app's single success surface: the same component the admin layout
         mounts, reading the same two flash keys. The customer booking form
         raises it for the date rules (minimum notice, blocked date), so a
         warning from the server and a success from a redirect are worded and
         timed identically on both sides of the app. --}}
    <x-ui.toast-stack />

    @stack('modals')

    {{-- The Terms & Conditions dialog, mounted here rather than per page. The
         triggers are scattered — a checkbox label on registration, links in the
         booking form, inside the cancel and reschedule dialogs — and a dialog
         added page by page is a dialog one of them ends up without. --}}
    <x-terms.modal />

    @stack('scripts')
</body>
</html>
