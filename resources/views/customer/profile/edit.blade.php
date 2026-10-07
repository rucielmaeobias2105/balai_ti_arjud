@extends('layouts.customer')

@section('title', 'Profile')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        {{-- Title only. The eyebrow and the standfirst described the page rather
             than titling it, and the card's identity block already says who this
             account belongs to. --}}
        <x-ui.page-header title="Profile Management" />

        {{-- Above the card, not inside it: the summary reads as a page-level
             report on the submission, the same place the old three-card layout
             put it. --}}

        {{--
            One card, identity block on top and every field inside it.

            The reference this follows is a single surface rather than a stack of
            them: a centred avatar with the account's name and email under it,
            then the fields in a two-column grid, then one full-width save. The
            card carries the salon's own recipe — gold hairline, cream ground,
            soft card shadow — with the reference's 20px radius and a roomier
            body padding than the default card.

            The <form> wraps the card rather than sitting inside it. Visually
            that is the same card; it also keeps the photo input and the
            remove-picture control inside the form, where they have to be to
            submit, without the identity block having to be threaded through a
            nested form.

            `profilePhotoCrop` wraps the form so the crop dialog can live beside
            it. The dialog is `position: fixed`, so where it sits in the document
            does not affect where it appears — what matters is that it is inside
            the scope, because it reads `open`, `close()` and `busy` from it.

            Field names, method and the photo controls are unchanged, so the
            upload is still a plain `profile_photo` POST. The only difference is
            that the file in it is now the cropped one.
        --}}
        <div
            x-data="profilePhotoCrop(@js([
                'input' => '#profile-photo-input',
                'preview' => '#profile-photo-preview',
                'fallback' => '#profile-photo-fallback',
                'crop' => '#profile-photo-crop',
            ]))"
        >
        <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" novalidate>
            @csrf
            @method('PATCH')

            <div class="overflow-hidden rounded-[1.25rem] border border-gold/25 bg-cream shadow-card">
                <div class="p-5 sm:p-8">

                    {{-- Identity ------------------------------------------------------}}
                    <div class="text-center">
                        <div class="relative inline-block">
                            {{--
                                Preview and initials are both rendered, and CSS
                                decides which is showing. `profilePhotoCrop`
                                swaps them when a crop is applied — an account
                                with no photo has to be able to end this screen
                                with one, and re-rendering the server side would
                                mean an upload that has not been saved yet.
                            --}}
                            @if ($user->profile_photo_path)
                                <img
                                    id="profile-photo-preview"
                                    src="{{ $user->profile_photo_url }}"
                                    alt="Profile picture"
                                    class="h-28 w-28 rounded-full border-4 border-primary object-cover shadow-card sm:h-[7.5rem] sm:w-[7.5rem]"
                                >
                            @else
                                {{-- Kept in the DOM but hidden, so the cropper has
                                     an <img> to write the cropped image into. --}}
                                <img id="profile-photo-preview" alt="" class="hidden">

                                <span
                                    id="profile-photo-fallback"
                                    class="flex h-28 w-28 items-center justify-center rounded-full border-4 border-primary bg-primary font-display text-3xl font-semibold text-cream shadow-card sm:h-[7.5rem] sm:w-[7.5rem]"
                                >
                                    {{ $user->initials }}
                                </span>
                            @endif

                            {{-- The camera badge is the only affordance for the
                                 upload, so the input itself is kept for keyboards
                                 and screen readers and the badge is its label. --}}
                            <label
                                for="profile-photo-input"
                                class="absolute bottom-0 right-0 flex h-9 w-9 cursor-pointer items-center justify-center rounded-full border-[3px] border-cream bg-primary text-cream transition hover:bg-primary-dark focus-within:ring-2 focus-within:ring-gold focus-within:ring-offset-2 focus-within:ring-offset-cream"
                                title="Change profile picture"
                            >
                                <span class="sr-only">Change profile picture</span>
                                <x-heroicon-o-camera class="h-4 w-4" aria-hidden="true" />
                            </label>

                            <x-ui.form.input
                                name="profile_photo"
                                id="profile-photo-input"
                                type="file"
                                class="sr-only"
                                accept="image/jpeg,image/png,image/webp"
                            />
                        </div>

                        <h2 class="mt-5 font-display text-xl font-semibold text-primary">{{ $user->full_name }}</h2>
                        <p class="mt-1 text-sm text-ink-muted">{{ $user->email }}</p>

                        {{--
                            The flag rides on the button that is meant to send it.

                            It used to be a permanent hidden `remove_photo=1`
                            input plus a button whose handler set `.checked = true`
                            on it — a no-op, because a hidden input has no checked
                            state. The flag therefore went out with *every* save,
                            and `ProfileController::update()` deletes the photo
                            whenever it sees it and no new file was chosen, so
                            editing a name quietly unlinked your picture.

                            A submit button only contributes its name and value
                            when it is the button that submitted the form, which is
                            exactly the semantics wanted, and it needs no
                            JavaScript.
                        --}}
                        @if ($user->profile_photo_path)
                            <button type="submit" name="remove_photo" value="1" class="toggle-link mt-2">
                                Remove current picture
                            </button>
                        @endif
                    </div>

                    {{-- Personal details ----------------------------------------------}}
                    <div class="mt-8 border-t border-primary/10 pt-6 sm:mt-10 sm:pt-8">
                        <h3 class="mb-4 flex items-center gap-2 font-display text-base font-semibold text-primary">
                            <x-heroicon-o-user class="h-5 w-5 shrink-0 text-gold-dark" />
                            Personal Information
                        </h3>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-ui.form.input name="first_name" label="First Name" required :value="$user->first_name" autocomplete="given-name" />
                            <x-ui.form.input name="last_name" label="Last Name" required :value="$user->last_name" autocomplete="family-name" />
                            <x-ui.form.input name="email" type="email" label="Email Address" required :value="$user->email" autocomplete="email" />
                            <x-ui.form.input name="contact_number" label="Contact Number" required :value="$user->contact_number" autocomplete="tel" />
                        </div>
                    </div>

                    {{-- Password --------------------------------------------------------}}
                    <div class="mt-8 border-t border-primary/10 pt-6 sm:mt-10 sm:pt-8">
                        <h3 class="mb-4 flex items-center gap-2 font-display text-base font-semibold text-primary">
                            <x-heroicon-o-lock-closed class="h-5 w-5 shrink-0 text-gold-dark" />
                            Change Password
                        </h3>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-ui.form.password
                                name="password"
                                label="New Password"
                                icon="heroicon-o-lock-closed"
                                :toggle-icon="'heroicon-o-eye'"
                                autocomplete="new-password"
                                hint="Minimum of 8 characters."
                            />
                            <x-ui.form.password
                                name="password_confirmation"
                                label="Confirm New Password"
                                icon="heroicon-o-lock-closed"
                                :toggle-icon="'heroicon-o-eye'"
                                autocomplete="new-password"
                            />
                        </div>
                    </div>

                    {{-- One full-width action, as in the reference ------------------------}}
                    <div class="mt-8 border-t border-primary/10 pt-6 sm:mt-10 sm:pt-8">
                        <button type="submit" class="btn-primary w-full justify-center">
                            <x-heroicon-o-check-circle class="h-4 w-4" />
                            Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </form>

        {{--
            The crop dialog. Inside `profilePhotoCrop`'s scope, which is what
            supplies the `open` it shows itself from and the `close()` its ✕,
            Escape and Cancel all call.

            Rendered outside the <form> on purpose: it holds no fields, and a
            <button> inside a form defaults to type="submit", which would post
            the whole profile to save a picture the user then had to cancel.
            Both buttons below set `type="button"` for the same reason.
        --}}
        <x-ui.photo-crop-dialog />
        </div>
    </div>
@endsection

{{--
    Cropper.js, vendored rather than bundled.

    Same library and version as the reference implementation this was ported
    from (Cropper.js v1.6.2), loaded from `public/vendor/` rather than npm so the
    version is pinned by a file in the repository instead of a range in
    package.json — a cropper that changes its defaults between versions would
    change the output size without anything here saying so.

    Pushed onto `head`/`scripts` rather than added to the layout: this is the only
    screen that needs it, and 37 KB on every page to serve one dialog is not worth
    it. `x-on:click` on this page's Alpine directives is a house convention rather
    than a necessity — Alpine does accept `@click` as a shorthand for `x-on:click`
    (it registers `@` as a prefix), so both work — but the spelled-out form is
    what the rest of this page uses and what a reader can grep for.
--}}
@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/cropper/cropper.min.css') }}">
@endpush

@push('scripts')
    {{-- A classic script, so it runs while the document parses and before the
         deferred module bundle that defines `profilePhotoCrop`. --}}
    <script src="{{ asset('vendor/cropper/cropper.min.js') }}"></script>
@endpush

