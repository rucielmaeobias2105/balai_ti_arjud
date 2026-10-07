@extends('layouts.admin')

@section('title', 'Promo & Announcements')
@section('heading', 'Promo Management')

@section('content')
    <x-ui.admin-table
        add-label="Add Promo"
        :add-href="route('admin.promos.create')"
        :search="$search"
    >
        <table class="bta-table">
            <thead>
                <tr>
                    <x-ui.sortable-th column="title" label="Title" :sort="$sort" :direction="$direction" :action="route('admin.promos.index')" :params="['search' => $search]" />
                    <th class="w-20">Image</th>
                    <x-ui.sortable-th column="starts_at" label="Validity" :sort="$sort" :direction="$direction" :action="route('admin.promos.index')" :params="['search' => $search]" />
                    <x-ui.sortable-th column="is_active" label="Active" :sort="$sort" :direction="$direction" :action="route('admin.promos.index')" :params="['search' => $search]" align="center" />
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($promos as $promo)
                    @php $isRunning = $promo->isCurrentlyValid(); @endphp

                    <tr data-row x-show="isShown({{ $loop->index }})">
                        <td>
                            <p class="font-medium text-primary">{{ $promo->title }}</p>
                            @if ($promo->notified)
                                <span class="mt-0.5 inline-block text-xs text-gold-dark">Announced</span>
                            @endif
                        </td>

                        {{-- The same shape the customer card leads with, so an admin
                             picking which promo to feature is looking at what the
                             customer will see. Not sortable: `image_path` is not
                             one of the columns the table offers, and a picture is
                             not a useful thing to order by anyway. --}}
                        <td>
                            @if ($promo->hasImage())
                                <img
                                    src="{{ $promo->image_url }}"
                                    alt=""
                                    class="h-12 w-16 rounded-md object-cover"
                                >
                            @else
                                <span class="flex h-12 w-16 items-center justify-center rounded-md border border-dashed border-primary/20 bg-linen/40 text-lg text-gold/60" aria-hidden="true">&#10048;</span>
                                <span class="sr-only">No image</span>
                            @endif
                        </td>

                        <td class="whitespace-nowrap text-ink">{{ $promo->validity_label }}</td>
                        <td class="text-center">
                            <x-ui.badge
                                :status="$promo->is_active ? 'confirmed' : 'cancelled'"
                                :label="$promo->is_active ? 'Active' : 'Inactive'"
                            />
                            @if ($promo->is_active && ! $isRunning)
                                <p class="mt-1 text-[11px] text-ink-muted">
                                    {{ $promo->starts_at->isFuture() ? 'Upcoming' : 'Expired' }}
                                </p>
                            @endif
                        </td>
                        <td>
                            <div class="flex items-center justify-end gap-1.5">
                                <x-ui.icon-action
                                    label="Edit {{ $promo->title }}"
                                    icon="heroicon-o-pencil-square"
                                    tone="primary"
                                    :href="route('admin.promos.edit', $promo)"
                                />

                                <x-ui.icon-action
                                    label="Delete {{ $promo->title }}"
                                    icon="heroicon-o-trash"
                                    tone="danger"
                                    :action="route('admin.promos.destroy', $promo)"
                                    :confirm="$promo->title"
                                    confirm-event="confirm-delete-promo"
                                />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-12 text-center text-sm text-ink-muted">
                            No promos yet. Use “Add Promo” to create the first one.
                        </td>
                    </tr>
                @endforelse

                {{-- Shown when the search box has filtered every row away. --}}
                @if ($promos->isNotEmpty())
                    <tr x-show="total === 0" x-cloak>
                        <td colspan="5" class="py-12 text-center text-sm text-ink-muted">No data available</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </x-ui.admin-table>

    <x-ui.confirm-dialog
        title="Delete this promo?"
        event="confirm-delete-promo"
        verb="Delete"
        subject="promo"
        confirm-label="Yes, delete it"
        cancel-label="Cancel"
    />
@endsection
