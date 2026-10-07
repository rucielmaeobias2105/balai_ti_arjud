@props([
    'service',

    /*
     * The parent scope's key for the category this service belongs to, or null for
     * a row that is never hidden.
     *
     * The overflow rows need an `isCategoryExpanded('<key>')` test on `x-show`, and
     * the caller cannot write it. An attribute on a component tag is read as a
     * literal string rather than compiled as Blade, so `@js($key)` written there
     * reaches the browser as the literal text `@js($key)`, Alpine throws "Invalid
     * or unexpected token", and the row is never hidden — while the See More button
     * beside it, which is plain markup in the parent view, compiles correctly and
     * goes on behaving perfectly. All the cards visible next to a working-looking
     * button is what that mismatch looks like.
     *
     * So the key is passed as data and this view writes the binding itself, which
     * is ordinary Blade and compiles as expected.
     *
     * Keep this note free of a literal component tag: Blade's component-tag
     * compiler can pick one out of a comment and swallow the rest of the file.
     */
    'expandedKey' => null,
])

{{--
    One bookable service in step 1 of the booking form: a tick box, the name, what
    it costs, and whether it can be booked at all.

    Lives here rather than inline in `customer/appointments/create.blade.php`
    for the same reason `x-salon.service-row` does. This view renders every
    service twice — the four a category shows, and the rest behind its See More —
    and the two copies are identical apart from the Alpine directives that hide
    the overflow. Written out twice, the copies would drift, and the drift would
    show up as one row behaving differently from its neighbours.

* `x-show="isCategoryExpanded('<key>')"` in `$reveal` below, rather than
     * being accepted as an attribute from the caller — see the prop's note.
     * Everything else is passed through `{{ $attributes }}` on the `<label>`,
     * so the classes here are the only classes on the element.

    Alpine state it reads, all from the enclosing `bookingForm` scope:
    `selectedIds`, `isSelected(id)`, `toggle(id, checked)`.
--}}
@php
    /**
     * The cheapest variant that can actually be charged. `variants` is ordered by
     * base_price, so the first priceable one is also the lowest.
     */
    $from = $service->variants->firstWhere(fn ($v) => $v->base_price !== null);
    $bookable = $from !== null || $service->base_price !== null;
    $fromPrice = $from?->price ?? $service->price;

    /*
     * `Js::from()` emits `'manicure-pedicure'` — single-quoted, so the result is
     * safe to drop inside the double-quoted `x-show` attribute.
     *
     * Assembled before the tag is opened and echoed into it, because Blade does
     * not compile `@if`/`@endif` inside a tag's attribute list.
     */
    $reveal = $expandedKey === null
        ? ''
        : 'x-show="isCategoryExpanded(' . \Illuminate\Support\Js::from($expandedKey) . ')" x-cloak';
@endphp
{{-- An overflow row carries `x-show` + `x-cloak` via `$reveal`, so it cannot paint
     before Alpine has read the category as collapsed. Both are inert without Alpine,
     so leaving them off a visible row costs nothing. --}}
<label
    class="flex items-center gap-3 rounded-xl border border-primary/15 bg-white/60 px-4 py-3 transition {{ $bookable ? 'cursor-pointer hover:border-gold hover:bg-linen/50' : 'opacity-60' }}"
    :class="isSelected({{ $service->id }}) && '!border-primary !bg-primary/5'"
    {!! $reveal !!}
    {{ $attributes }}
>
    <input
        type="checkbox"
        class="checkbox"
        value="{{ $service->id }}"
        x-model="selectedIds"
        @disabled(! $bookable)
        x-on:change="toggle({{ $service->id }}, $event.target.checked)"
    >
    <span class="min-w-0 flex-1">
        <span class="block truncate text-sm font-medium text-ink">{{ $service->name }}</span>
        <span class="block text-xs text-ink-muted">
            {{-- No duration, and so no separator either. The row reads the price
                 and nothing else; `duration_minutes` stays in the Alpine payload
                 above because the slot arithmetic still needs it. --}}
            {{ $bookable
                ? App\Support\PriceFormatter::display($fromPrice)
                : 'price on request' }}
        </span>
        @unless ($bookable)
            <span class="mt-0.5 block text-[11px] text-gold-dark">
                Confirm the price with the salon
            </span>
        @endunless
    </span>
</label>
