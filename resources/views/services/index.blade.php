@extends('layouts.customer')

@section('title', 'Services')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <x-ui.page-header
            eyebrow=""
            title="Browse Services"
            description=""
        />

        @if ($categories->isEmpty())
            {{-- No "match your filters" copy: the filters are not on this page
                 any more, so there is nothing to reset and nothing to widen. --}}
            <x-ui.empty title="No services yet" />
        @else
            {{--
                One card per category, two to a row.

                Each card is split into two columns: the category's name and its
                price list on the left, one photo of that category on the right.
                That is the arrangement the salon price list itself uses, and it
                is the one a customer comparing two categories can scan — a photo
                beside the list it belongs to, rather than a banner above a stack
                of text they have to read past to find out what they are looking
                at.

                The image is the category's own, not a service's. A customer
                scanning this page is asking "what do you do here?", and eleven
                near-identical photos of individual treatments would answer a
                question they are not asking. Where a category has no photo of
                its own, `ServiceCategory::imagePath()` falls through a service's
                uploaded photo and the configured file to a neutral default, so
                there is always an image and never a broken one.

                Cards are equal height within a row, so the price lists of two
                categories line up instead of leaving a ragged edge.

                A category shows at most four services. Past that a "See All"
                button reveals the rest in place, rather than linking somewhere
                else: the whole point of the layout is that a category and its
                prices read together, and a link out would split the two.
            --}}
            <div class="grid scroll-mt-24 items-stretch gap-5 md:grid-cols-2 lg:gap-6" id="services">
                @foreach ($categories as $row)
                    @php
                        $services = $row['services'];
                        $preview = $services->take(4);
                        $hidden = $services->count() - $preview->count();
                        $bookableCount = $services->filter(function ($s) {
                            $hasVariantPrice = $s->variants->contains(fn ($v) => $v->base_price !== null);

                            return ! $s->isUnavailable() && ($s->base_price !== null || $hasVariantPrice);
                        })->count();
                    @endphp

                    {{--
                        The Alpine scope rides on the card for disclosure (`serviceCategory()`)
                        or multi-select (`categoryMultiSelect()`).

                        `sm:flex-row` is what turns the two columns into two
                        columns; below `sm` it is a single column and the photo
                        (`order-first`) sits above the list, which is the only
                        reading that works at that width.
                    --}}
                    <section
                        class="bta-card flex overflow-hidden"
                        @if ($hidden > 0) x-data="serviceCategory()" @else x-data="categoryMultiSelect()" @endif
                    >
                        <div class="flex w-full flex-col sm:flex-row">
                            {{-- Left column: what the category is, and what it costs. --}}
                            <div class="flex flex-1 flex-col p-4 sm:p-5">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5">
                                        <h2 class="font-display text-lg font-bold tracking-tight text-primary">
                                            {{ $row['category']->name }}
                                        </h2>
                                        <p class="text-xs text-ink-muted">
                                            {{ $row['count'] }} {{ Str::plural('service', $row['count']) }}
                                        </p>
                                    </div>

                                    @if ($bookableCount > 0)
                                        <label class="inline-flex cursor-pointer select-none items-center gap-1.5 text-xs font-medium text-ink-muted hover:text-primary">
                                            <input
                                                type="checkbox"
                                                class="checkbox"
                                                data-category-select-all
                                                x-bind:checked="allSelected"
                                                x-effect="$el.indeterminate = someSelected"
                                                x-on:change="toggleAll($event.target.checked)"
                                                aria-label="Select all available in {{ $row['category']->name }}"
                                            >
                                            <span>Select All</span>
                                        </label>
                                    @endif
                                </div>

                                <ul class="mt-3 divide-y divide-primary/8">
                                    @foreach ($preview as $service)
                                        <x-salon.service-row :service="$service" />
                                    @endforeach

                                    {{-- The rest of the category, hidden until
                                         "See All". `x-cloak` so they are not
                                         briefly visible before Alpine boots.

                                         The same component as the visible rows, with
                                         the two Alpine directives that hide these —
                                         which is the whole reason the row is a
                                         component. --}}
                                    @forelse ($services->slice(4) as $service)
                                        <x-salon.service-row
                                            :service="$service"
                                            x-show="expanded"
                                            x-cloak
                                        />
                                    @empty
                                        {{-- No overflow: nothing to reveal. --}}
                                    @endforelse
                                </ul>

                                {{-- `mt-auto` keeps the disclosure on the bottom edge of
                                     every card, so a row of two categories reads as one
                                     band however different their heights. Right-aligned,
                                     because it belongs to the list it discloses rather
                                     than to the card. --}}
                                @if ($hidden > 0)
                                    <div class="mt-auto flex justify-end border-t border-primary/8 pt-3">
                                        <button
                                            type="button"
                                            class="btn-ghost btn-sm"
                                            x-on:click="toggle()"
                                            :aria-expanded="expanded"
                                        >
                                            {{-- The count is in the visible label, not
                                                 hidden from sight. "See All" on a ten-row
                                                 category says there is more without saying
                                                 how much, and a customer weighing two
                                                 categories has no way to guess.

                                                 `x-cloak` on *both* spans: without it on
                                                 the first, both labels paint before
                                                 Alpine boots and the button briefly reads
                                                 "See More (6 more)See Less". --}}
                                            <span x-show="! expanded" x-cloak>See More ({{ $hidden }} more)</span>
                                            <span x-show="expanded" x-cloak>See Less</span>

                                            {{-- For a screen reader the control is named by
                                                 what it discloses, not just by its verb —
                                                 seven identical "See More" buttons on a
                                                 long page are all the same button as far
                                                 as a list of links is concerned. --}}
                                            <span class="sr-only">{{ $row['category']->name }} services</span>
                                        </button>
                                    </div>
                                @endif

                                {{-- Multi-select checkout action bar: sends the selected services in this
                                     category to /book in one step. One service is booked from its own row's
                                     Book Now button, so this action only unlocks at two or more. The literal
                                     `disabled` is the no-JavaScript fallback; Alpine maintains the live state. --}}
                                <div
                                    class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-primary/15 bg-linen/60 px-3 py-2.5"
                                    x-bind:class="selected.length <= 1 ? 'opacity-60' : ''"
                                >
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button
                                            type="button"
                                            class="btn-primary btn-sm"
                                            data-proceed-selected
                                            disabled
                                            x-bind:disabled="selected.length <= 1"
                                            x-on:click="proceedToBooking(@js(route('appointments.create')))"
                                        >
                                            <i class="fa-solid fa-cart-shopping" aria-hidden="true"></i>
                                            Proceed to Checkout
                                        </button>
                                        <button
                                            type="button"
                                            class="btn-ghost btn-sm"
                                            x-bind:disabled="selected.length === 0"
                                            x-on:click="clearSelection()"
                                        >
                                            Clear
                                        </button>
                                    </div>
                                    <p class="text-xs font-medium text-ink">
                                        <span x-text="selected.length"></span> selected
                                        <span x-show="selectedTotal > 0" x-cloak>
                                            &middot; <span x-text="selectedTotalLabel"></span>
                                        </span>
                                    </p>
                                </div>
                            </div>

                            {{-- Right column: the one picture that stands for this
                                 category. `order-first` above `sm` puts it back on
                                 top, where a full-width photo has room to be a photo
                                 and the card still says what it is at a glance.

                                 `self-center` IS THE FIX, and the reason is worth
                                 recording so nobody tidies it away.

                                 The card is a flex row from `sm` up, and a flex item
                                 is `align-self: stretch` by default — which means
                                 its height is set to the height of the tallest item
                                 beside it. That silently overrode the aspect ratio:
                                 the browser reported `aspect-ratio` and then ignored
                                 it, because a definite cross-size from the flex line
                                 wins over a ratio.

                                 So the photo was not 5:7 at all. It was "as tall as
                                 the price list happens to be" — measured on the same
                                 card at 292px collapsed and 562px expanded, and
                                 292px against 206px between the two cards. Nothing
                                 was distorted; `object-cover` was doing its job. But
                                 the picture changed size every time the list did,
                                 which is what makes it read as broken.

                                 Centring instead of stretching takes the height back
                                 from the ratio: now only the width decides it, so
                                 expanding the list makes the card taller and the
                                 photo sits in the middle of it, unchanged, over the
                                 card's own background.

                                 `w-full` is kept alongside `self-center`
                                 deliberately. Below `sm` this is a flex *column*,
                                 where `align-self` acts on the horizontal axis —
                                 without `w-full` the photo would shrink to its
                                 content width instead of filling the phone.

                                 16:9 below `sm`, because a full-width photo on a
                                 phone at 5:7 is a wall of image; 5:7 from `sm` up,
                                 which is the portrait crop the price list wants. Both
                                 cards take their width from the same steps, so the
                                 two photos are the same size on any given screen.
                             --}}
                            <div class="order-first flex aspect-[16/9] w-full shrink-0 items-center justify-center self-center overflow-hidden bg-linen/40 sm:order-none sm:aspect-[5/7] sm:w-40 md:w-32 lg:w-48 xl:w-56">
                                <img
                                    src="{{ $row['image'] }}"
                                    alt="{{ $row['category']->name }} at {{ config('app.name') }}"
                                    class="h-full w-full object-cover object-center"
                                    loading="lazy"
                                >
                            </div>
                        </div>
                    </section>
                @endforeach
            </div>
        @endif
    </div>
@endsection