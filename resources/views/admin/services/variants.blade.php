@extends('layouts.admin')

@section('title', 'Variants · '.$service->name)
@section('heading', 'Service Variants')

@section('content')
    <a href="{{ route('admin.services.index') }}" class="mb-5 inline-flex items-center gap-1.5 text-sm text-ink-muted transition hover:text-primary">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
        Back to services
    </a>

    <x-ui.page-header
        eyebrow="{{ $service->category }}"
        :title="'Variants · '.$service->name"
        description="Style-based pricing such as Short Hair / Long Hair. Exactly one variant can be the default."
    >
        <x-slot:actions>
            <a href="{{ route('admin.services.edit', $service) }}" class="btn-secondary btn-sm">Edit Service</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="xl:col-span-2">
            <x-ui.card title="Existing Variants">
                @if ($service->variants->isEmpty())
                    <x-ui.empty title="No variants yet" description="Add one on the right, e.g. Short Hair / Long Hair." />
                @else
                    <ul class="space-y-3">
                        @foreach ($service->variants as $variant)
                            <li class="rounded-xl border border-primary/12 bg-linen/50 p-4">
                                <form method="POST" action="{{ route('admin.variants.update', $variant) }}" class="flex flex-wrap items-end gap-3">
                                    @csrf
                                    @method('PATCH')

                                    <div class="min-w-40 flex-1">
                                        <label class="label" for="variant-name-{{ $variant->id }}">Name</label>
                                        <input id="variant-name-{{ $variant->id }}" name="name" type="text" value="{{ $variant->name }}"
                                               maxlength="100" class="input" required>
                                    </div>

                                    {{-- Text, so "249/499" and "100+" survive. --}}
                                    <div class="w-40">
                                        <label class="label" for="variant-price-{{ $variant->id }}">Price (₱)</label>
                                        <input id="variant-price-{{ $variant->id }}" name="price" type="text" inputmode="text"
                                               maxlength="50" value="{{ $variant->price }}" class="input" required>
                                    </div>

                                    <div class="w-32">
                                        <label class="label" for="variant-duration-{{ $variant->id }}">Duration</label>
                                        <input id="variant-duration-{{ $variant->id }}" name="duration_minutes" type="number" min="5" max="1440"
                                               value="{{ $variant->duration_minutes }}" class="input" placeholder="{{ $service->duration_minutes }}">
                                    </div>

                                    <label class="flex cursor-pointer items-center gap-2 pb-2.5 text-xs font-medium text-ink">
                                        <input type="checkbox" name="is_default" value="1" class="checkbox" @checked($variant->is_default)>
                                        Default
                                    </label>

                                    <button type="submit" class="btn-primary btn-sm mb-1">Save</button>
                                </form>

{{-- One dialog for the whole page, so a row carries no form and no
                                     native prompt. `name` is what makes the
                                     sentence read "Remove Long Hair?" rather than
                                     "Remove 1 variant?", which is the part that
                                     stops the wrong row being removed. --}}
                                <div class="mt-2.5 flex justify-end">
                                    <button
                                        type="button"
                                        class="toggle-link text-status-cancelled"
                                        x-on:click="$dispatch('confirm-remove-variant', { name: @js($variant->name), action: @js(route('admin.variants.destroy', $variant)) })"
                                    >Remove variant</button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        <aside>
            <x-ui.card title="Add Variant">
                <form method="POST" action="{{ route('admin.services.variants.store', $service) }}" class="space-y-4" novalidate>
                    @csrf

                    <x-ui.form.input name="name" label="Variant Name" required placeholder="Long Hair" />
                    <x-ui.form.input name="price" type="text" inputmode="text" label="Price (₱)" required prefix="₱"
                                     hint='100+ for "starting at", or 249/499 for two options.' />
                    <x-ui.form.input name="duration_minutes" type="number" min="5" max="1440" label="Duration (minutes)" hint="Leave blank to inherit the service duration." />
                    <x-ui.form.checkbox name="is_default" value="1" label="Make this the default variant" />

                    <button type="submit" class="btn-primary w-full">Add Variant</button>
                </form>
</x-ui.card>
            </aside>
    </div>

    {{-- Replaces the per-variant `onsubmit="return confirm(…)"`. --}}
    <x-ui.confirm-dialog
        title="Remove this variant?"
        event="confirm-remove-variant"
        verb="Remove"
        subject="variant"
        confirm-label="Yes, remove it"
        cancel-label="Cancel"
    />
@endsection
