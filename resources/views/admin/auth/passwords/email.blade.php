@extends('layouts.auth')

@section('title', 'Reset Staff Password')

@section('content')
    {{--
        Step 1 of the staff password reset: ask for the address.

        The customer flow asks the same question in the same words, which is
        deliberate — whoever is doing the recovery should not have to work out
        which of two recovery screens they are on before typing anything.
    --}}
    <h1 class="admin-auth-card-title mb-1.5 mt-4">Forgot Password</h1>
    <p class="mb-8 text-[17px] text-[#765b50]">Enter your email and we'll send you a reset link.</p>


    <form method="POST" action="{{ route('admin.password.email') }}" class="space-y-[23px]" novalidate>
        @csrf

        <x-ui.form.input
            name="email"
            type="email"
            label="Email Address"
            placeholder="you@example.com"
            icon="heroicon-o-envelope"
            required
            autocomplete="email"
        />

        <button type="submit" class="admin-auth-submit">
            <span class="inline-flex items-center justify-center gap-2">
                <x-heroicon-o-paper-airplane class="h-5 w-5" />
                Send Reset Link
            </span>
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-ink-muted">
        <a href="{{ route('login') }}" class="font-medium text-primary underline underline-offset-2 hover:text-primary-dark">
            Back to log in
        </a>
    </p>
@endsection
