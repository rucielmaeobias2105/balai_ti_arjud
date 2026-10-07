@extends('layouts.admin')

@section('title', 'Contact Messages')
@section('heading', 'Contact Messages')

@section('content')
    <x-ui.page-header
        eyebrow="Inbox"
        title="Contact Messages"
        description="Enquiries submitted through the customer Contact Us form."
    />

    {{--
        `unread` is seeded from the server's own count and then follows the
        `admin-messages` broadcast, so the pill and the button below it move the
        instant an enquiry is marked read rather than at the next page load. A
        mirror of the sidebar's badge, from the same event — not a second poll.
    --}}
    <div
        class="mb-5 flex flex-wrap items-center gap-2"
        x-data="{ unread: {{ (int) $unreadCount }} }"
        x-on:admin-messages.window="unread = $event.detail"
    >
        <a href="{{ route('admin.messages.index') }}"
           class="rounded-pill px-3.5 py-1.5 text-sm font-medium transition {{ empty($filters['filter']) ? 'bg-primary text-cream' : 'bg-cream text-ink hover:bg-linen' }}">
            All
        </a>
        <a href="{{ route('admin.messages.index', ['filter' => 'unread']) }}"
           class="rounded-pill px-3.5 py-1.5 text-sm font-medium transition {{ ($filters['filter'] ?? '') === 'unread' ? 'bg-primary text-cream' : 'bg-cream text-ink hover:bg-linen' }}">
            Unread (<span x-text="unread">{{ $unreadCount }}</span>)
        </a>

        {{--
            Reading an enquiry clears the sidebar's Messages badge, and this
            screen is the only place that can happen now that the topbar bell
            and its dropdown are gone.

            A dispatch rather than a component of its own, because
            `adminLiveNotifications` in the layout already owns the count and the
            request — a second implementation here would be a second place for the
            badge and the tab title to come apart. With scripting off the button
            does nothing, which is the same degradation every other scripted
            affordance in this project has; the per-row "Save & Mark Read" form
            always works.
        --}}
        <button
            type="button"
            x-show="unread > 0"
            x-cloak
            x-on:click="$dispatch('admin-read-all')"
            class="btn-secondary ml-auto"
        >Mark all as read</button>
    </div>

    <form method="GET" action="{{ route('admin.messages.index') }}" class="bta-card mb-6 p-5">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-64 flex-1">
                <x-ui.form.input name="search" label="Search" placeholder="Name, email or message" :value="$filters['search'] ?? null" />
            </div>
            <button type="submit" class="btn-primary">Search</button>
            @if (array_filter($filters))
                <a href="{{ route('admin.messages.index') }}" class="btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    @if ($messages->isEmpty())
        <x-ui.empty title="No messages" description="Customer enquiries will appear here." />
    @else
        <div class="space-y-4">
            @foreach ($messages as $message)
                <article @class([
                    'bta-card p-5',
                    'border-l-4 !border-l-primary' => ! $message->is_read,
                ])>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-semibold text-primary">{{ $message->name }}</p>
                                <x-ui.badge status="gold" :label="$message->topic->label()" />
                                @unless ($message->is_read)
                                    <x-ui.badge status="pending" label="New" />
                                @endunless
                            </div>

                            <p class="mt-0.5 text-xs text-ink-muted">
                                <a href="mailto:{{ $message->email }}" class="hover:text-primary">{{ $message->email }}</a>
                                &middot; {{ $message->created_at->format('M j, Y g:i A') }}
                            </p>

                            <p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-ink">{{ $message->message }}</p>
                        </div>

                        <div class="flex shrink-0 flex-col items-stretch gap-2 sm:items-end">
                            {{-- Only on an unread row, and the same dispatch as the
                                 button above: the layout's poller owns the write
                                 and the count. --}}
                            @unless ($message->is_read)
                                <button
                                    type="button"
                                    x-on:click="$dispatch('admin-read-message', @js($message->id))"
                                    class="btn-secondary btn-sm"
                                >Mark read</button>
                            @endunless

                            {{-- A button that asks rather than a form that asks.
                                 The one dialog below owns the real form, the CSRF
                                 token and the DELETE spoof, so this row carries no
                                 form of its own — which is also why the sentence
                                 names the count instead of the sender: "Delete 1
                                 message?" does not put a customer's name in a
                                 prompt that can be misread. --}}
                            <button
                                type="button"
                                class="btn-danger btn-sm"
                                x-on:click="$dispatch('confirm-delete-message', { id: {{ $message->id }}, action: @js(route('admin.messages.destroy', $message)) })"
                            >Delete</button>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('admin.messages.update', $message) }}" class="mt-4 border-t border-primary/10 pt-4">
                        @csrf
                        @method('PATCH')

                        <x-ui.form.textarea
                            name="admin_reply"
                            label="Internal Reply / Note"
                            rows="3"
                            :value="old('admin_reply', $message->admin_reply)"
                            placeholder="Record your response here and mark the message as read."
                        />

                        <div class="mt-3 flex items-center justify-end">
                            <button type="submit" class="btn-primary btn-sm">Save &amp; Mark Read</button>
                        </div>
                    </form>
                </article>
            @endforeach
        </div>

        <div class="mt-6">{{ $messages->links() }}</div>
    @endif

    {{-- One dialog for every message. Replaces the per-row
         `onsubmit="return confirm('Delete this message?')"`, which put the
         browser's own unstyleable dialog in the middle of the inbox. --}}
    <x-ui.confirm-dialog
        title="Delete this message?"
        event="confirm-delete-message"
        verb="Delete"
        subject="message"
        warning="It will be removed from the inbox for good."
        confirm-label="Yes, delete it"
        cancel-label="Cancel"
    />
@endsection
