<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#7A241B">
    <meta name="robots" content="noindex, nofollow">

    {{-- Who this page believes it is for: `admin:3`, `web:12` or `guest`.
         Read by `sessionSync`, which writes it to `localStorage` and watches for
         another tab writing a different one — the mechanism that stops a customer
         tab from sitting on a stale session after the staff portal has been
         signed into. The server half is `App\Support\SessionIdentity`. --}}
    <meta name="bta-session" content="{{ \App\Support\SessionIdentity::key() }}">

    {{-- Where `sessionSync` sends a tab when the browser's identity changes.
         See the note on the customer layout. --}}
    <meta name="bta-home-admin" content="{{ route('admin.dashboard') }}">
    <meta name="bta-home-web" content="{{ route('home') }}">
    <meta name="bta-login-admin" content="{{ route('login') }}">
    <meta name="bta-login-web" content="{{ route('login') }}">

    {{-- Where the liveness probe lives. Published rather than hardcoded in
         `sessionSync`, because this application is served out of a subdirectory
         (`htdocs/BTA/public`) and a root-absolute `/session-status` 404s there,
         which silently disables the back/forward-cache check. --}}
    <meta name="bta-session-status" content="{{ route('session-status') }}">

    {{-- "(2) APPOINTMENTS | Balai ti Arjud Admin" while something has arrived the
         salon has not looked at, the plain title when there is nothing. Server-
         rendered rather than left entirely to JavaScript so the count is right
         before the bundle loads, and kept live afterwards by
         `adminLiveNotifications` from the same poll that drives the Appointments
         badge — see App\Support\TabTitle.

         Both this and the Appointments badge ask
         `Appointment::unseenForAdminCount()`, so they cannot report different
         numbers for the same rows. Unread contact enquiries are deliberately not
         included: the Messages row is not in the admin nav, so that number had
         no visible surface and only left a count the admin could not account
         for. The count is computed while the layout renders, which is after the
         index action has marked what it showed as seen — so landing on the
         Appointments page produces a title with no prefix at all, with nothing
         to correct on the client. --}}
    @php
        $pageTitle = \App\Support\TabTitle::withCount(
            Str::upper(trim($__env->yieldContent('title', 'Dashboard'))).' | '.config('app.name').' Admin',
            \App\Support\TabTitle::adminUnread(),
        );
    @endphp
    <title>{{ $pageTitle }}</title>

    <link rel="icon" href="{{ asset('images/tab_logo.png') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body
    {{--
        The root `x-data` is the reason the sidebar's own `x-data` below is a
        child rather than the root. `Alpine.start()` initialises `[x-data]`
        elements and their subtrees, and does not walk from `<body>` itself, so
        anything outside every `x-data` would never be initialised — silently,
        with no error. Without a root here, that is anything a page puts in the
        layout before the sidebar wrapper.
    --}}
    x-data
    class="min-h-full bg-linen"
>
    <div x-data="{ sidebar: false }" class="min-h-screen lg:flex">
        {{-- Mobile sidebar backdrop --}}
        <div
            x-show="sidebar"
            x-transition.opacity
            x-cloak
            @click="sidebar = false"
            class="modal-backdrop fixed inset-0 z-40 lg:hidden"
            aria-hidden="true"
        ></div>

        @include('partials.admin.sidebar')

        <div class="flex min-w-0 flex-1 flex-col">
            @include('partials.admin.topbar')

            <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                @yield('content')
            </main>
        </div>
    </div>

    {{-- The app's single success surface. It reads the flash itself, so every
         confirmation in the admin — including the plain `status` strings most
         controllers still flash — floats here instead of as a page banner.
         Outside `@stack('modals')` on purpose: a toast raised from a dialog must
         outlive the dialog. --}}
    <x-ui.toast-stack />

    @stack('modals')

    {{--
        The Terms & Conditions dialog used to be mounted here, for the "Preview"
        link on the admin's Terms screen. That link is gone — the page is the
        editing surface, and the textarea beside it is the text being edited.

        Nothing in the panel opens the dialog any more, so it was pure cost: the
        component reads all three published policies and renders each one through
        `TermsRenderer` on *every* admin page, not only the Terms screen. That is
        three extra queries and a few hundred words of HTML on the dashboard, the
        appointments list and the rest, for markup nothing on those pages can
        reach.

        The customer and auth layouts still mount it, and there the triggers are
        real — the checkbox labels on registration and on the booking form.
    --}}

    {{--
        The live work queue, with no widget of its own.

        This used to be the topbar's notification bell, badge and all, which meant
        the number sat in the bar while the row that acted on it sat in the
        sidebar. The count is now on that row, and all that was left of the bell
        was the polling — so that is what lives here: one component, mounted once
        per page, that keeps the Appointments badge and the tab title's "(n)" in
        step from a single request.

        It still receives the unread-enquiry count in the same payload, because
        the Messages screen's own unread pill listens for `admin-messages`. That
        count deliberately does not feed the tab title or the Appointments badge:
        the Messages row is no longer in the admin nav, so counting it there left
        a number with no visible notification behind it.

        It renders nothing, so where it is mounted does not matter visually. It
        listens on the window for `admin-read-all` and `admin-read-message` so the
        Messages screen can ask it to mark things read without a second poller and
        without the screen having to know anything about the feed.
    --}}
    <div
        x-data="adminLiveNotifications(@js([
            'endpoint' => route('admin.notifications.feed'),
            'pending' => \App\Models\Appointment::unseenForAdminCount(),
            'unread' => \App\Models\ContactMessage::query()->unread()->count(),
            'readUrl' => route('admin.notifications.read', ['message' => '__ID__']),
            'readAllUrl' => route('admin.notifications.read-all'),
        ]))"
        x-on:admin-read-all.window="markAllRead()"
        x-on:admin-read-message.window="read($event.detail)"
        hidden
    ></div>

    @stack('scripts')
</body>
</html>
