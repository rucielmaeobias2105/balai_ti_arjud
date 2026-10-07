@extends('layouts.admin')

@section('title', 'Technicians')
@section('heading', 'Technicians')

@section('content')
    @php
        // The Actions column is capability-gated, so the empty rows have to
        // span whatever the admin who is looking actually gets.
        $canManage = auth('admin')->user()?->can('admin.technicians.manage');
        $columns = $canManage ? 3 : 2;
    @endphp

    <x-ui.admin-table
        add-label="Add Technician"
        :add-href="$canManage ? route('admin.technicians.create') : null"
        :search="$search"
    >
        <table class="bta-table">
            <thead>
                <tr>
                    <x-ui.sortable-th column="name" label="Name" :sort="$sort" :direction="$direction" :action="route('admin.technicians.index')" :params="['search' => $search]" />
                    <x-ui.sortable-th column="is_active" label="Active" :sort="$sort" :direction="$direction" :action="route('admin.technicians.index')" :params="['search' => $search]" align="center" />
                    @can('admin.technicians.manage')
                        <th class="text-right">Actions</th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @forelse ($technicians as $technician)
                    <tr data-row x-show="isShown({{ $loop->index }})">
                        <td>
                            <div class="flex items-center gap-3">
                                {{-- The same avatar treatment the customer booking
                                     picker uses, so an admin recognises the row
                                     they will see on the site. --}}
                                @if ($technician->photo_path)
                                    <img src="{{ $technician->photo_url }}" alt="" class="h-10 w-10 shrink-0 rounded-full object-cover">
                                @else
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-cream">{{ $technician->initials }}</span>
                                @endif

                                <p class="min-w-0 truncate font-medium text-primary">{{ $technician->name }}</p>
                            </div>
                        </td>
                        <td class="text-center">
                            @can('admin.technicians.manage')
                                <x-ui.table-toggle
                                    :action="route('admin.technicians.toggle', $technician)"
                                    :checked="$technician->is_active"
                                    on-label="Bookable"
                                    off-label="Hidden"
                                    :label="$technician->is_active ? 'Stop offering '.$technician->name.' for new bookings' : 'Offer '.$technician->name.' for new bookings'"
                                />
                            @else
                                <x-ui.badge :status="$technician->is_active ? 'confirmed' : 'cancelled'" :label="$technician->is_active ? 'Bookable' : 'Hidden'" />
                            @endcan
                        </td>
                        @can('admin.technicians.manage')
                            <td>
                                <div class="flex items-center justify-end gap-1.5">
                                    <x-ui.icon-action
                                        label="Edit {{ $technician->name }}"
                                        icon="heroicon-o-pencil-square"
                                        tone="primary"
                                        :href="route('admin.technicians.edit', $technician)"
                                    />
                                    <x-ui.icon-action
                                        label="Delete {{ $technician->name }}"
                                        icon="heroicon-o-trash"
                                        tone="danger"
                                        :action="route('admin.technicians.destroy', $technician)"
                                        :confirm="$technician->name"
                                        confirm-event="confirm-delete-technician"
                                    />
                                </div>
                            </td>
                        @endcan
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $columns }}" class="py-12 text-center text-sm text-ink-muted">
                            No technicians yet. Use “Add Technician” to add the first one.
                        </td>
                    </tr>
                @endforelse

                {{-- Shown when the search box has filtered every row away. --}}
                @if ($technicians->isNotEmpty())
                    <tr x-show="total === 0" x-cloak>
                        <td colspan="{{ $columns }}" class="py-12 text-center text-sm text-ink-muted">No data available</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </x-ui.admin-table>

    {{-- The consequence is stated because it is the question an admin is actually
         asking: "will deleting them cancel their bookings?" It does not. --}}
    <x-ui.confirm-dialog
        title="Delete this technician?"
        event="confirm-delete-technician"
        verb="Delete"
        subject="technician"
        warning="Their existing bookings are kept."
        confirm-label="Yes, delete it"
        cancel-label="Cancel"
    />
@endsection
