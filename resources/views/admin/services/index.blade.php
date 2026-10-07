@extends('layouts.admin')

@section('title', 'Services')
@section('heading', 'Service Management')

@section('content')
    @php
        // The Actions column is capability-gated, so the empty rows have to
        // span whatever the admin who is looking actually gets.
        $columns = auth('admin')->user()?->can('admin.catalog.manage') ? 5 : 4;
    @endphp

    <x-ui.admin-table
        add-label="Add Service"
        :add-href="route('admin.services.create')"
        :search="$search"
    >
        <table class="bta-table">
            <thead>
                <tr>
                    <x-ui.sortable-th column="name" label="Name" :sort="$sort" :direction="$direction" :action="route('admin.services.index')" :params="['search' => $search]" />
                    <x-ui.sortable-th column="category" label="Category" :sort="$sort" :direction="$direction" :action="route('admin.services.index')" :params="['search' => $search]" />
                    <x-ui.sortable-th column="base_price" label="Price" :sort="$sort" :direction="$direction" :action="route('admin.services.index')" :params="['search' => $search]" align="right" />
                    <x-ui.sortable-th column="is_active" label="Available" :sort="$sort" :direction="$direction" :action="route('admin.services.index')" :params="['search' => $search]" align="center" />
                    @can('admin.catalog.manage')
                        <th class="text-right">Actions</th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @forelse ($services as $service)
                    <tr data-row x-show="isShown({{ $loop->index }})">
                        <td class="font-medium text-primary">{{ $service->name }}</td>
                        <td>
                            <span class="badge badge-gold">{{ $service->category }}</span>
                        </td>
                        <td class="whitespace-nowrap text-right font-medium text-primary">
                            {{ \App\Support\PriceFormatter::display($service->price) }}
                        </td>
                        <td class="text-center">
                            @can('admin.catalog.manage')
                                <x-ui.table-toggle
                                    :action="route('admin.services.toggle', $service)"
                                    :checked="$service->is_active"
                                    on-label="Available"
                                    off-label="Hidden"
                                    :label="$service->is_active ? 'Mark “'.$service->name.'” as hidden' : 'Mark “'.$service->name.'” as available'"
                                />
                            @else
                                <x-ui.badge :status="$service->is_active ? 'confirmed' : 'cancelled'" :label="$service->is_active ? 'Available' : 'Hidden'" />
                            @endcan
                        </td>
                        @can('admin.catalog.manage')
                            <td>
                                <div class="flex items-center justify-end gap-1.5">
                                    <x-ui.icon-action
                                        label="Edit {{ $service->name }}"
                                        icon="heroicon-o-pencil-square"
                                        tone="primary"
                                        :href="route('admin.services.edit', $service)"
                                    />
                                    {{-- The concrete delete URL rides along in the event rather
                                         than the row announcing an id for a shared template
                                         to be filled in. One dialog serves the whole table
                                         either way, but this way the URL is never guessed:
                                         Blade renders it from the same route the form posts
                                         to, so there is no placeholder left to resolve wrong.

                                         It used to pass `id` against a `{id}` template on the
                                         dialog, and the first row to open it consumed that
                                         placeholder — so every row after it posted the first
                                         service's DELETE, which 404s once that service is
                                         soft-deleted. See `confirmDialog.ask()`. --}}
                                    <x-ui.icon-action
                                        label="Delete {{ $service->name }}"
                                        icon="heroicon-o-trash"
                                        tone="danger"
                                        :action="route('admin.services.destroy', $service)"
                                        :confirm="$service->name"
                                        confirm-event="confirm-delete-service"
                                    />
                                </div>
                            </td>
                        @endcan
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $columns }}" class="py-12 text-center text-sm text-ink-muted">
                            No services yet. Use “Add Service” to create the first one.
                        </td>
                    </tr>
                @endforelse

                {{-- Shown when the search box has filtered every row away. --}}
                @if ($services->isNotEmpty())
                    <tr x-show="total === 0" x-cloak>
                        <td colspan="{{ $columns }}" class="py-12 text-center text-sm text-ink-muted">No data available</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </x-ui.admin-table>
@endsection

@push('modals')
    {{-- The shared `x-ui.confirm-dialog`, replacing the native `confirm()` the
         trash action used to raise.

         One dialog for the whole table rather than a form per row: the trash
         button dispatches the row's delete URL and `confirmDialog.ask()` puts it
         in the form's action when the event fires. That keeps this to a single
         CSRF token and a single `_method` spoof instead of one pair per service.

         No `action` prop, because no caller can be assumed to supply one: every
         row on this page is per-row, so the URL comes from the row. --}}
    <x-ui.confirm-dialog
        title="Delete service"
        event="confirm-delete-service"
        subject="service"
        warning="This removes the service from the catalogue. Bookings that already used it keep their saved details."
        title-id="confirm-delete-service-title"
        confirm-label="Yes"
        cancel-label="No"
    />
@endpush
