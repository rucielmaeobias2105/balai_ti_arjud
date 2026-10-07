@props([
    'label',
    'icon',
    'href' => null,
    'action' => null,
    'method' => 'DELETE',
    'tone' => 'secondary',
    // The NAME of the thing being acted on — "Glow Foot Spa", not a sentence.
    // Was a whole sentence handed to the browser's `confirm()`.
    'confirm' => null,
    // Which `x-ui.confirm-dialog` on this page should open. Required alongside
    // `confirm`, because the dialog owns the real form and supplies the verb,
    // the consequence and the confirm button's own wording.
    'confirmEvent' => null,
])

{{--
    A single icon action for an admin table row — edit, delete, announce.

    A link renders as an anchor. Everything else is a POST form carrying
    `@method`, so a destructive row is a real form submission rather than a
    `javascript:` handler, and works with JavaScript disabled.

    WITH a `confirm`, there is no longer a form here at all.

    This used to render the form and hang `onsubmit="return confirm(...)"` on it,
    which produced the browser's own grey dialog: unstyleable, it names the page
    it came from rather than the salon, and its buttons say OK/Cancel for a
    question phrased as a sentence. `x-ui.confirm-dialog` is the replacement, and
    it carries the real form itself — so a row action that asks first renders one
    button, and the page supplies one dialog for all of its rows.

    That is why `confirm` is now a name and not a sentence. The dialog composes
    "Remove Closed all day on Oct 9, 2026? That time becomes bookable again."
    from a verb, the name, and a consequence, which means one dialog on a page of
    forty rows reads correctly for every one of them.

    Without a `confirm` the form is still rendered inline, so the non-destructive
    half of this component keeps working with scripting off.
--}}
@php $classes = 'icon-action icon-action-'.(in_array($tone, ['primary', 'danger', 'ghost'], true) ? $tone : 'secondary'); @endphp

@if ($href)
    <a href="{{ $href }}" title="{{ $label }}" aria-label="{{ $label }}" {{ $attributes->merge(['class' => $classes]) }}>
        <x-dynamic-component :component="$icon" class="h-4 w-4" />
    </a>
@elseif ($confirm)
    {{-- A `type="button"` that asks, rather than a submit that asks. The dialog
         holds the CSRF token and the `_method` spoof, which is the other reason
         there is no form per row here. --}}
    <button
        type="button"
        title="{{ $label }}"
        aria-label="{{ $label }}"
        x-on:click="$dispatch(@js($confirmEvent ?? 'bta-confirm'), { action: @js($action), name: @js($confirm) })"
        {{ $attributes->merge(['class' => $classes]) }}
    >
        <x-dynamic-component :component="$icon" class="h-4 w-4" />
    </button>
@else
    <form method="POST" action="{{ $action }}" class="inline-flex">
        @csrf
        @method($method)

        <button type="submit" title="{{ $label }}" aria-label="{{ $label }}" {{ $attributes->merge(['class' => $classes]) }}>
            <x-dynamic-component :component="$icon" class="h-4 w-4" />
        </button>
    </form>
@endif