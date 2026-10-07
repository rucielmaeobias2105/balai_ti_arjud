{{--
    Shared promo create/edit form: title, description, the validity window, an
    optional image and an Active switch.

    The image is the picture the customer promo card leads with, so the field
    says what it is for. It is optional on purpose — a promo with no picture
    renders the flourish instead, and `image_path` stays null.

    `enctype` is what makes the file input reach the server at all; without it
    the browser posts the filename as `image` and validation cannot tell.

    The preview is plain Alpine over a `FileReader`, so an admin sees the crop
    they just picked before committing to it. `URL.createObjectURL` rather than
    a data URL: it does not copy the whole file into a base64 string in memory
    for a 4 MB upload, and the object URL is revoked as soon as it is replaced so
    a long editing session does not leak one per attempt.
--}}
@extends('layouts.admin')

@section('title', $promo->exists ? 'Edit Promo' : 'Add Promo')
@section('heading', $promo->exists ? 'Edit Promo' : 'Add Promo')

@section('content')

    @php $action = $promo->exists ? route('admin.promos.update', $promo) : route('admin.promos.store'); @endphp

    <form
        method="POST"
        action="{{ $action }}"
        enctype="multipart/form-data"
        novalidate
        x-data="{
            /* What is on disk right now. Drives the remove box, so it has to be
               remembered separately from what is on screen: picking a new file
               replaces the saved one, and offering 'remove' as well would be
               asking the admin to contradict themselves. */
            saved: @js($promo->imagePath()),
            preview: @js($promo->image_url),
            picked: '',
            pick(event) {
                const file = event.target.files[0];
                if (! file) { return; }
                if (this.preview?.startsWith('blob:')) { URL.revokeObjectURL(this.preview); }
                this.preview = URL.createObjectURL(file);
                this.picked = file.name;
            },
        }"
    >
        @csrf
        @if ($promo->exists) @method('PUT') @endif

        <div class="bta-card p-6 sm:p-8">
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-display text-lg font-semibold text-primary">
                    {{ $promo->exists ? 'Edit Promo' : 'Add Promo' }}
                </h2>

                <a href="{{ route('admin.promos.index') }}" class="btn-secondary btn-sm">
                    <x-heroicon-o-arrow-left class="h-4 w-4" />
                    Back
                </a>
            </div>

            <div class="max-w-2xl space-y-6">
                <x-ui.form.input
                    name="title"
                    label="Title"
                    required
                    :value="old('title', $promo->title)"
                    placeholder="e.g. Glow Package Discount"
                />

                <x-ui.form.textarea
                    name="description"
                    label="Description"
                    required
                    rows="5"
                    :value="old('description', $promo->description)"
                    placeholder="What is the offer, and what does the customer get?"
                />

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.form.input
                        name="starts_at"
                        type="date"
                        label="Start Date"
                        required
                        :value="old('starts_at', $promo->starts_at?->toDateString())"
                    />
                    <x-ui.form.input
                        name="ends_at"
                        type="date"
                        label="End Date"
                        required
                        :value="old('ends_at', $promo->ends_at?->toDateString())"
                    />
                </div>

                {{-- The image block. `x-data` lives on the form, so `preview` is
                     shared by the preview, the filename and the remove toggle —
                     picking a file has to clear the remove box, and a remove has
                     to clear the preview, or the two contradict each other on
                     screen. --}}
                <div>
                    <label for="image" class="label">
                        Image <span class="font-normal text-ink-muted">(optional)</span>
                    </label>

                    <div class="flex flex-wrap items-start gap-4">
                        <div class="flex h-28 w-40 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-primary/15 bg-linen/50">
                            <template x-if="preview">
                                <img :src="preview" alt="" class="h-full w-full object-cover">
                            </template>
                            <template x-if="! preview">
                                <span class="px-3 text-center text-xs text-ink-muted">
                                    No image — the promo shows its flourish instead
                                </span>
                            </template>
                        </div>

                        <div class="min-w-[14rem] flex-1">
                            <input
                                id="image"
                                name="image"
                                type="file"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                class="input py-2 file:mr-3 file:rounded-lg file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-cream hover:file:bg-primary-dark"
                                x-on:change="pick($event)"
                            >

                            <p class="mt-1 truncate text-xs text-ink-muted" x-show="picked" x-cloak>
                                <span x-text="picked"></span>
                            </p>

                            {{-- Only offered when there is a saved picture to remove, and
                                 hidden the moment a replacement is chosen — a new file wins
                                 over `remove_image`, so showing both would promise something
                                 the controller does not do. Ticking it clears the preview, so
                                 the form shows the state it is about to save in rather than a
                                 picture that is about to disappear. --}}
                            <label
                                class="mt-3 flex items-center gap-2 text-sm text-ink"
                                x-show="saved && ! picked"
                            >
                                <input
                                    type="checkbox"
                                    name="remove_image"
                                    value="1"
                                    class="checkbox"
                                    x-on:change="if ($event.target.checked) { preview = null; }"
                                >
                                Remove the current image
                            </label>
                        </div>
                    </div>

                    @error('image')
                        <p class="input-error-text">{{ $message }}</p>
                    @enderror
                </div>

                <x-ui.form.switch
                    name="is_active"
                    label="Active"
                    :checked="$promo->exists ? $promo->is_active : true"
                />
            </div>

            <div class="mt-7 border-t border-primary/10 pt-6">
                <button type="submit" class="btn-primary">
                    <x-heroicon-o-check class="h-4 w-4" />
                    Save Promo
                </button>
            </div>
        </div>
    </form>
@endsection
