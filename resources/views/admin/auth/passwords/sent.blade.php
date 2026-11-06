@extends('layouts.auth')

@section('title', 'Check Your Inbox')

@section('content')
    {{--
        The "we sent you a link" page.

        Its own screen rather than a redirect back to the form, so the person who
        asked for a reset is told what to do next instead of being dropped on an
        empty form with a "sent" toast. It is deliberately not conditional on the
        address existing: the controller returns the same page either way, so this
        screen cannot be used to find out which addresses are registered.
    --}}
    <div class="text-center">
        <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-primary/10 text-primary">
            <x-heroicon-o-envelope class="h-8 w-8" />
        </span>

        <h1 class="admin-auth-card-title mt-6 mb-1.5">Check Your Inbox</h1>

        <p class="text-[17px] text-[#765b50]">
            If that address belongs to a staff account, a reset link is on its way.
        </p>

        <p class="mx-auto mt-4 max-w-md text-sm leading-relaxed text-ink-muted">
            The link is valid for 30 minutes. If it does not arrive, check the spam
            folder before asking for another &mdash; it may be filtered.
        </p>
    </div>

    <div class="mt-8 flex flex-wrap justify-center gap-2">
        <a href="{{ route('login') }}" class="btn-primary">Back to Log In</a>
        <a href="{{ route('admin.password.request') }}" class="btn-ghost">Send it again</a>
    </div>
@endsection