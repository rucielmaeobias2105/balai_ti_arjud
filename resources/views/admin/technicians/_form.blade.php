@props([
    'technician',
    'action',
    'method' => 'POST',
    'submitLabel' => 'Add Technician',
])

{{--
    Technician create/edit fields: a name, a photo and an Active switch.

    The same lean shape as the Add Category and Add Service forms — one card, a
    small title row with the way back, the fields stacked in a single column,
    and one submit button below a divider.

    The photo is optional: with none, both this list and the customer booking
    picker fall back to the initials stand-in, so a technician is never a blank
    space in either place.
--}}
<form method="POST" action="{{ $action }}" enctype="multipart/form-data" novalidate>
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif


    <div class="max-w-2xl space-y-6">
        @if ($technician->exists)
            {{-- What the customer will see next to the name in the booking picker. --}}
            <div class="flex items-center gap-4">
                @if ($technician->photo_path)
                    <img src="{{ $technician->photo_url }}" alt="" class="h-16 w-16 shrink-0 rounded-full object-cover">
                @else
                    <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-primary text-lg font-semibold text-cream">{{ $technician->initials }}</span>
                @endif

                <p class="text-sm text-ink-muted">This is the photo customers see when they choose a technician.</p>
            </div>
        @endif

        <x-ui.form.input
            name="name"
            label="Name"
            required
            :value="old('name', $technician->name)"
            placeholder="e.g. Maria Santos"
        />

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

            @if ($technician->exists && $technician->photo_path)
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
            :checked="$technician->exists ? $technician->is_active : true"
        />
    </div>

    <div class="mt-7 border-t border-primary/10 pt-6">
        <button type="submit" class="btn-primary">
            <x-heroicon-o-check class="h-4 w-4" />
            {{ $submitLabel }}
        </button>
    </div>
</form>
