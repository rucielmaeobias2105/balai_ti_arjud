{{--
    Toasts.

    One component for every short confirmation in the app. Bottom-right, solid
    fill, white icon and white text, auto-dismiss after ~4s.

    Why it is its own component rather than the existing `x-ui.alert`:
    `alert` is an inline page banner that sits in the flow, while a toast is
    fixed-position and must never shift or replace page content. They are
    different jobs, so they are different components.

    Markup contract:
      session('toast')   => ['type' => 'success|warning|error', 'message' => '…']
      <x-ui.toast-stack />
      <x-ui.toast type="warning" message="…" />   (ad-hoc, e.g. in a dialog)

    `dismissible` adds a close button; the plain form is for a flash that has
    already served its purpose.
--}}
@props([
    'type' => 'info',
    'message' => null,
    'dismissible' => false,
])

@php
    $icons = [
        'success' => 'heroicon-o-check-circle',
        'warning' => 'heroicon-o-exclamation-triangle',
        'error' => 'heroicon-o-x-circle',
        'info' => 'heroicon-o-information-circle',
    ];

    $fills = [
        'success' => 'bg-status-completed',
        'warning' => 'bg-status-low-stock',
        'error' => 'bg-status-cancelled',
        'info' => 'bg-primary',
    ];

    $icon = $icons[$type] ?? $icons['info'];
    $fill = $fills[$type] ?? $fills['info'];
@endphp

<div
    role="status"
    aria-live="polite"
    {{ $attributes->merge([
        'class' => "pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-card px-4 py-3 text-cream shadow-card-hover {$fill}",
    ]) }}
>
    <span class="mt-0.5 shrink-0">
        <x-dynamic-component :component="$icon" class="h-5 w-5" />
    </span>

    <p class="min-w-0 flex-1 text-sm font-medium leading-snug">{{ $message ?? $slot }}</p>

    @if ($dismissible)
        <button
            type="button"
            {{-- `x-on:click` rather than the `@click` shorthand, for consistency with the
             rest of the components. Both work — Alpine registers `@` as a prefix
             for `x-on:` — but one spelling of the same directive across the
             design system is easier to grep for than two. --}}
            x-on:click="window.btaToast.dismiss($el.closest('[data-toast]'))"
            class="-mr-1 shrink-0 rounded p-1 text-cream/70 transition hover:bg-cream/10 hover:text-cream"
            aria-label="Dismiss notification"
        >&times;</button>
    @endif
</div>
