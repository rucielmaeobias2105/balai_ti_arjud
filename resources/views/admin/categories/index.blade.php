@extends('layouts.admin')

@section('title', 'Categories')
@section('heading', 'Categories')

@section('content')
    <x-ui.admin-table
        add-label="Add Category"
        :add-href="route('admin.categories.create')"
        :search="$search"
    >
        <table class="bta-table">
            <thead>
                <tr>
                    <x-ui.sortable-th column="name" label="Name" :sort="$sort" :direction="$direction" :action="route('admin.categories.index')" :params="['search' => $search]" />
                    <x-ui.sortable-th column="is_active" label="Active" :sort="$sort" :direction="$direction" :action="route('admin.categories.index')" :params="['search' => $search]" align="center" />
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($categories as $category)
                    <tr data-row x-show="isShown({{ $loop->index }})">
                        {{-- The thumbnail is inside the Name cell rather than a column
                             of its own: it is a second view of the same row, not a
                             thing the list sorts or filters by, and a fourth column
                             would push Actions closer to the edge on the narrow
                             screens this table is read at.

                             `imageUrl()` rather than `photo_url`, deliberately — this
                             is the picture the Services page actually resolves for
                             this category, including a service's uploaded photo or
                             the configured default. An admin comparing this list
                             against the public page needs to be looking at the same
                             image the customer is, not at a gap where a photo might
                             have been. --}}
                        <td class="font-medium text-primary">
                            <div class="flex items-center gap-3">
                                <img
                                    src="{{ $category->imageUrl() }}"
                                    alt=""
                                    class="h-9 w-9 shrink-0 rounded-lg object-cover"
                                    loading="lazy"
                                >
                                <span class="truncate">{{ $category->name }}</span>
                            </div>
                        </td>
                        <td class="text-center">
                            @can('admin.catalog.manage')
                                <x-ui.table-toggle
                                    :action="route('admin.categories.toggle', $category)"
                                    :checked="$category->is_active"
                                    on-label="Active"
                                    off-label="Inactive"
                                    :label="$category->is_active ? 'Deactivate '.$category->name : 'Activate '.$category->name"
                                />
                            @else
                                <x-ui.badge :status="$category->is_active ? 'confirmed' : 'cancelled'" :label="$category->is_active ? 'Active' : 'Inactive'" />
                            @endcan
                        </td>
                        <td>
                            <div class="flex items-center justify-end gap-1.5">
                                @can('admin.catalog.manage')
                                    <x-ui.icon-action
                                        label="Edit {{ $category->name }}"
                                        icon="heroicon-o-pencil-square"
                                        tone="primary"
                                        :href="route('admin.categories.edit', $category)"
                                    />
                                    <x-ui.icon-action
                                        label="Delete {{ $category->name }}"
                                        icon="heroicon-o-trash"
                                        tone="danger"
                                        :action="route('admin.categories.destroy', $category)"
:confirm="$category->name"
                                    confirm-event="confirm-delete-category"
                                />
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="py-12 text-center text-sm text-ink-muted">
                            No categories yet. Use “Add Category” to create the first one.
                        </td>
                    </tr>
                @endforelse

                {{-- Shown when the search box has filtered every row away. --}}
                @if ($categories->isNotEmpty())
                    <tr x-show="total === 0" x-cloak>
                        <td colspan="3" class="py-12 text-center text-sm text-ink-muted">No data available</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </x-ui.admin-table>

    {{-- One dialog for every row.

         The old prompt counted the row's services — "Its 3 service(s) are
         kept." One dialog serves every row, so the sentence is composed here
         and the count is dropped rather than faked: "Its services are kept" is
         the part that decides whether an admin clicks, and a stale number would
         be worse than none. --}}
    <x-ui.confirm-dialog
        title="Delete this category?"
        event="confirm-delete-category"
        verb="Delete"
        subject="category"
        warning="Its services are kept."
        confirm-label="Yes, delete it"
        cancel-label="Cancel"
    />
@endsection
