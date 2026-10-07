@extends('layouts.auth')

@section('title', 'Log In')

@section('content')
    <div class="mb-6">
        <x-ui.button :href="route('home')" variant="ghost" size="sm" icon="heroicon-o-arrow-left">
            Back to Home
        </x-ui.button>
    </div>

    {{-- Tabs: the reference puts sign-in and registration on one surface. --}}
    <nav class="auth-tabs" aria-label="Authentication">
        <a href="{{ route('login') }}" class="auth-tab auth-tab-active" aria-current="page">Login</a>
        <a href="{{ route('register') }}" class="auth-tab">Register</a>
    </nav>

    <h1 class="font-display text-2xl font-bold tracking-tight text-primary">Welcome Back</h1>



    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4" novalidate>
        @csrf

        <x-ui.form.input
            name="login"
            label="Username or Email"
            placeholder="you@example.com"
            required
            autocomplete="username"
        />

        {{-- The eye toggle, not the text link: this is the form a customer is most
                     likely to mistype into, and seeing what was typed before
                     pressing Log In is the whole point. `data-password-toggle-for`
                     is what `initPasswordToggles()` binds on — see the note in
                     `resources/js/app.js` for why that name matters. --}}
        <x-ui.form.password
            name="password"
            label="Password"
            icon="heroicon-o-lock-closed"
            :toggle-icon="'heroicon-o-eye'"
            required
            autocomplete="current-password"
        />

        <div class="flex items-center justify-between gap-3 pt-1">
            <x-ui.form.checkbox name="remember" value="1" label="Remember me" class="mb-0" />
            <a href="{{ route('password.request') }}" class="toggle-link">Forgot your password?</a>
        </div>

        <button type="submit" class="btn-primary w-full">Log In</button>
    </form>

    {{-- The same two links the registration form carries, in the same muted
         style, because the same policies govern signing in as govern signing
         up. `x-terms.link` intercepts the click and opens the shared dialog,
         so a customer who wants to check the cancellation policy does not lose
         the half-typed username and password to a page load. Its `href` is the
         standalone policy page, which is what a customer with JavaScript
         disabled gets instead of a dialog that never appears. --}}
    <p class="mt-5 text-center text-xs text-ink-muted">
        By logging in you accept our
        <x-terms.link :category="'booking'">Terms and Conditions</x-terms.link>
        and
        <x-terms.link :category="'cancellation'">Cancellation Policy</x-terms.link>.
    </p>

    <p class="mt-6 text-center text-sm text-ink-muted">
        Don't have an account?
        <a href="{{ route('register') }}" class="font-medium text-primary underline underline-offset-2 hover:text-primary-dark">Register</a>
    </p>
@endsection
