@props(['service'])

{{--
    One line of a category's service list: what it is, what it costs, and a way
    to book it.

    Lives here rather than inline in `services/index.blade.php` because that view
    renders every service row twice — the four it shows, and the rest it hides
    behind "See More" — and the two copies had drifted: only the visible copy lost
    its `first:pt-0`, so revealing the overflow rows left a double gap above the
    fifth service. One component cannot drift from itself.

    `{{ $attributes }}` is on the `<li>` so the overflow rows can carry the
    Alpine directives that hide them (`x-show`, `x-cloak`) without this view
    having to render a second, near-identical block. Nothing else is passed in,
    so the classes here are the only ones on the element.

    Sized for the two-up category grid the list now sits in. Each row is half a
    card wide, so the type steps down one notch from the full-width version and
    the row's vertical padding is halved: the point of the grid is to fit two
    categories in the space one used to take, and a row that still pads like a
    full-width page undoes it.

    THE NAME IS NOT A LINK

    It used to wrap the name in an `<a>` to the single-service page. That link is
    gone: the only link in a row is "Book Now".

    Two reasons, one practical and one about what the row is. Practically, it
    was a link to a page with a heading, a price and a Book button on it — so
    clicking the name and clicking Book Now led to the same place by two routes,
    and the name link was a slower way to reach the thing the button already did.
    And in substance, this list is a price list: what a customer is reading it
    for is what each treatment costs and whether they want it, and the only
    action those two facts produce is to book it.

    The name keeps its `font-medium text-primary` so it still reads as the row's
    heading rather than as loose text. The link's `hover:underline` went with the
    link, since a hover affordance on something that is not clickable is worse
    than no affordance at all.
--}}
<li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-2 first:pt-0" {{ $attributes }}>
    <div class="min-w-0 flex-1">
        <p class="text-sm font-medium text-primary">{{ $service->name }}</p>

        {{-- The duration line ("1 hr", "1 hr 15 min", "30 min") is gone: this
             list is a price list, and the only two facts a row carried beyond
             the name were how long it takes and what it costs. Only the price
             stays, so the secondary line now exists solely for the Sold Out
             badge and is emitted only when there is one — an always-present
             `<p>` holding nothing renders an empty band under the name.

             `duration_minutes` itself is untouched: it is what the slot
             generator and the availability rules read, so removing the label
             hides a fact from the list without changing any booking. --}}
        @if ($service->isUnavailable())
            <p class="mt-0.5">
                <x-ui.badge status="sold_out" label="Sold Out" />
            </p>
        @endif
    </div>

    <div class="flex shrink-0 items-center gap-2">
        <p class="font-display text-sm font-semibold text-primary">
            {{ \App\Support\PriceFormatter::display($service->price) }}
        </p>

        <a
            href="{{ route('appointments.create', ['services' => $service->slug]) }}"
            class="btn-primary btn-sm"
        >Book Now</a>
    </div>
</li>
