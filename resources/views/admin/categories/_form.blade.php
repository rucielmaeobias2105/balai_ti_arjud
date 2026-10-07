@props([
    'category',
    'action',
    'method' => 'POST',
    'submitLabel' => 'Add Category',
])

{{--
    Category create/edit fields: a name, an optional photo and an Active switch.

    The colour picker is gone, and so is every mention of the palette. What stays
    behind the scenes is what matters here: `service_categories.color` is still
    written and still read, because the admin calendar legend and its chips
    cannot work without it. A new category is handed the next unused salon
    palette colour by `ServiceCategoryController::store()`, and an existing one
    keeps whatever it already has — the admin just no longer picks one by hand.

    The photo is the picture the Services page shows beside this category's price
    list, so the field says plainly what it is for: an admin choosing a picture
    for a price list is not the same act as choosing a picture for a service.
    Written inline rather than extracted to a component, as the technician form's
    equivalent is — two fields that say almost the same thing are not yet a
    pattern, and the technician one is a circle while this one is a panel.

    `enctype` is what makes the file input reach the server at all; without it
    the browser posts the filename as `photo` and validation cannot tell.
--}}
<form method="POST" action="{{ $action }}" enctype="multipart/form-data" novalidate>
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif


    <div class="max-w-2xl space-y-6">
        <x-ui.form.input
            name="name"
            label="Name"
            required
            :value="old('name', $category->name)"
            placeholder="e.g. Manicure & Pedicure"
        />

        {{-- The current photo, in the shape the Services page shows it, so what
             "replace this" means is visible before anything is uploaded. Only on
             edit: a category being created has nothing to replace yet. --}}
        @if ($category->exists && $category->photo)
            <div class="flex items-center gap-4">
                <img
                    src="{{ $category->photo_url }}"
                    alt=""
                    class="h-20 w-24 shrink-0 rounded-lg object-cover"
                >
                <p class="text-sm text-ink-muted">
                    This is the photo customers see beside {{ $category->name }} on the Services page.
                </p>
            </div>
        @endif

        <div>
            <label for="photo" class="label">
                Photo <span class="font-normal text-ink-muted">(optional)</span>
            </label>

            <input
                id="photo"
                name="photo"
                type="file"
                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                class="input py-2 file:mr-3 file:rounded-lg file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-cream hover:file:bg-primary-dark"
            >

            @if ($category->exists && $category->photo)
                <label class="mt-3 flex items-center gap-2 text-sm text-ink">
                    <input type="checkbox" name="remove_photo" value="1" class="checkbox">
                    Remove the current photo
                </label>
            @endif

            @error('photo')
                <p class="input-error-text">{{ $message }}</p>
            @enderror
        </div>

        <x-ui.form.switch
            name="is_active"
            label="Active"
            :checked="$category->exists ? $category->is_active : true"
        />
    </div>

    <div class="mt-7 border-t border-primary/10 pt-6">
        <button type="submit" class="btn-primary">
            <x-heroicon-o-check class="h-4 w-4" />
            {{ $submitLabel }}
        </button>
    </div>
</form>
