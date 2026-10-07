@extends('layouts.auth')

@section('title', 'Register')

@section('content')
    <div class="mb-4">
        <x-ui.button :href="route('home')" variant="ghost" size="sm" icon="heroicon-o-arrow-left">
            Back to Home
        </x-ui.button>
    </div>

    <nav class="auth-tabs" aria-label="Authentication">
        <a href="{{ route('login') }}" class="auth-tab">Login</a>
        <a href="{{ route('register') }}" class="auth-tab auth-tab-active" aria-current="page">Register</a>
    </nav>

    <h1 class="font-display text-2xl font-bold tracking-tight text-primary">Create Account</h1>

    {{--
        No `x-on:submit`, no `x-data`, and no submit button outside this form.

        The account is created by exactly one thing: a POST from the button at
        the foot of this form. Nothing here submits on blur, on change, or on
        Enter-from-a-field, and there is no draft account written before that
        click — so a customer who fills the form in and walks away has left
        nothing behind, and a customer who mistypes their password is not signed
        in to an account that already exists.
    --}}
    <form method="POST" action="{{ route('register') }}" class="mt-5 space-y-3" novalidate>
        @csrf

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.form.input name="first_name" label="First Name" placeholder="Juan" required autocomplete="given-name" />
            <x-ui.form.input name="last_name" label="Last Name" placeholder="Dela Cruz" required autocomplete="family-name" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.form.input name="email" type="email" label="Email" placeholder="you@example.com" required autocomplete="email" />

            {{--
                Digits only, in one shape.

                `inputmode="numeric"` and `pattern="[0-9]*"` are what tell a phone
                to bring up the number pad instead of the keyboard;
                `autocomplete="tel-numeric"` is what lets a browser fill it from
                the one number already saved on the device.

                `maxlength` is 15 rather than 11 on purpose. A customer pasting
                "+639171234567" is 13 characters, and an 11-character cap would
                silently truncate the paste into a different number — a customer
                who then cannot be reached and does not know why. The field is
                allowed to *hold* the long form; `digitsOnly()` below converts it
                to the 11 digits we store, and the field then holds that.

                None of the three attributes stop a letter being typed, so
                `digitsOnly()` does. The authoritative check is still
                `RegisterRequest`: this only shapes the input.
            --}}
            <x-ui.form.input
                x-data="digitsOnly"
                name="contact_number"
                label="Contact Number"
                placeholder="09*********"
                required
                autocomplete="tel-numeric"
                inputmode="numeric"
                pattern="[0-9]*"
                maxlength="15"
                x-on:input="digitsOnly($event)"
            />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            {{-- Both fields get an eye. Confirmation is where a mistyped character
                 actually bites, and "the two do not match" with no way to see
                 either is a frustrating thing to debug. --}}
            <x-ui.form.password
                name="password"
                label="Password"
                icon="heroicon-o-lock-closed"
                :toggle-icon="'heroicon-o-eye'"
                required
                autocomplete="new-password"
            />
            <x-ui.form.password
                name="password_confirmation"
                label="Confirm Password"
                icon="heroicon-o-lock-closed"
                :toggle-icon="'heroicon-o-eye'"
                required
                autocomplete="new-password"
            />
        </div>

        {{--
            No `value`, and that is load-bearing.

            `x-ui.form.checkbox` seeds its checked state from
            `old($name, (bool) $value)`, so passing `value="1"` pre-ticked this
            box on a fresh form — and agreeing to the terms and the cancellation
            policy is not something to tick on the customer's behalf. Without
            `value` the input still posts `1` (the component falls back to that)
            but renders unchecked, and `old('terms')` still re-ticks it after a
            validation failure so a corrected form does not have to be re-read.

            The link opens the shared terms dialog: `x-terms.link` carries a real
            `href` to `/terms/{category}` and is intercepted by Alpine, so the
            customer never loses what they have typed. See
            `resources/views/layouts/auth.blade.php` for the dialog itself.
        --}}
        <x-ui.form.checkbox name="terms" required hint="I agree to the Terms and Conditions and Cancellation Policy.">
            <x-terms.link :category="'booking'">Terms and Conditions</x-terms.link>
        </x-ui.form.checkbox>

        <button type="submit" class="btn-primary w-full">Register</button>
    </form>

    <p class="mt-6 text-center text-sm text-ink-muted">
        Already have an account?
        <a href="{{ route('login') }}" class="font-medium text-primary underline underline-offset-2 hover:text-primary-dark">Log in</a>
    </p>
@endsection

@push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            /**
             * Keep a phone field to digits, in the one shape we store.
             *
             * Progressive: it rewrites the input as the customer types rather
             * than refusing what they typed, so a letter is simply never
             * accepted. The caret is put back at the end, which is the only
             * position that is safe while the field is being rewound — a
             * customer typing into the middle of a number is not a case this
             * handles better than the browser would.
             *
             * The international form is converted rather than dropped. "+63" and
             * a bare "63" are how a number arrives when it is copied out of a
             * contact card, and it is the same subscriber as "09", so
             * `+639171234567` becomes `09171234567` and is judged as an
             * 11-digit local number. Capping at 11 digits only afterwards is
             * what makes that safe: truncating first would cut a 13-character
             * paste down to 11 and leave a different number in the box.
             *
             * The authoritative check is `RegisterRequest`, which applies the
             * same conversion server-side.
             */
            Alpine.data('digitsOnly', () => ({
                digitsOnly(event) {
                    const input = event.target;
                    const raw = input.value.trim();
                    const digits = raw.replace(/\D/g, '');

                    const local = /^(\+)?63\d/.test(raw.replace(/\s/g, ''))
                        ? '0' + digits.replace(/^63/, '')
                        : digits;

                    input.value = local.slice(0, 11);
                },
            }));
        });
    </script>
@endpush