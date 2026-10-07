@props([
    'action',
    'checked' => false,
    'label' => 'Toggle',
    'onLabel' => null,
    'offLabel' => null,
])

{{--
    An inline on/off switch for an admin table row.

    The whole track is the submit button of a PATCH form, so flipping a row is
    one request with no JavaScript and no optimistic UI to roll back. `role` and
    `aria-checked` carry the state for assistive technology, and the state text
    beside the track is what a sighted admin reads.

    There is deliberately no `confirm` prop any more. It existed to hang
    `onsubmit="return confirm(…)"` on this form, which is how the toggle reached
    the browser's own grey dialog — and nothing ever passed it, so the branch was
    a live native `confirm()` waiting for a caller. Hiding a service or
    un-booking a technician is reversible from this same switch, which is the
    reason it never warranted a prompt. A row action that *is* irreversible
    takes `confirm` + `confirm-event` on `x-ui.icon-action` and is answered by
    `x-ui.confirm-dialog`.
--}}
<form method="POST" action="{{ $action }}" class="inline-flex">
    @csrf
    @method('PATCH')

    <button
        type="submit"
        role="switch"
        aria-checked="{{ $checked ? 'true' : 'false' }}"
        title="{{ $label }}"
        @class(['switch', 'switch-on' => $checked])
    >
        <span class="sr-only">{{ $label }}</span>
    </button>

    @if ($onLabel || $offLabel)
        <span @class(['ml-2.5 text-xs font-medium', 'text-status-confirmed' => $checked, 'text-ink-muted' => ! $checked])>
            {{ $checked ? $onLabel : $offLabel }}
        </span>
    @endif
</form>
