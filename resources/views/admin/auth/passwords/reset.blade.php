@extends('layouts.auth')

@section('title', 'Choose a New Password')

@section('content')
    {{--
        Step 2: the form the emailed link opens.

        The token and the address are both carried in the URL and posted back
        hidden. They are not kept in the session — the link has to survive a page
        refresh and work in a tab that was opened before the email arrived, and
        either would break a flow that stashed them.
    --}}
    <h1 class="admin-auth-card-title mb-1.5 mt-4">New Password</h1>
    <p class="mb-8 text-[17px] text-[#765b50]">Choose a strong password you haven't used before.</p>


    <form method="POST" action="{{ route('admin.password.update') }}" class="space-y-[23px]" novalidate>
        @csrf

        {{-- The token the broker issued, and the address it was issued for. Both
             round-trip through the form rather than the session; see the note
             above. `email` is readonly rather than hidden so the person can see
             which account they are resetting — a mistyped address is the most
             likely reason a link "does not work". --}}
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ $email }}">

        <x-ui.form.password
            name="password"
            label="New Password"
            icon="heroicon-o-lock-closed"
            :toggle-icon="'heroicon-o-eye'"
            required
            autocomplete="new-password"
            hint="Minimum of 8 characters."
        />

        <x-ui.form.password
            name="password_confirmation"
            label="Confirm New Password"
            icon="heroicon-o-lock-closed"
            :toggle-icon="'heroicon-o-eye'"
            required
            autocomplete="new-password"
        />

        <button type="submit" class="admin-auth-submit">
            <span class="inline-flex items-center justify-center gap-2">
                <x-heroicon-o-check class="h-5 w-5" />
                Reset Password
            </span>
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-ink-muted">
        <a href="{{ route('login') }}" class="font-medium text-primary underline underline-offset-2 hover:text-primary-dark">
            Back to log in
        </a>
    </p>
@endsection
