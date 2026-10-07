@extends('layouts.admin')

@section('title', 'Terms & Conditions')
@section('heading', 'Terms & Conditions')

@section('content')

    {{--
        One card per policy, each with a textarea and a Save button, saved
        straight from this page.

        A category owns exactly one row, and there is no version history: this
        page edits the text, and a separate "Publish to customers" action decides
        whether customers see it.

        WHAT IS NOT ON THIS PAGE

          - No Preview pill. It was a link to the shared Terms dialog, offered
            only when something was published, so the two halves of the page
            disagreed: with nothing published there was no way to see what a
            customer would get, and with something published the only way to
            check it sat next to a status badge saying so. Reading the text in
            the textarea is the same text; `/terms/{category}` is the page a
            customer lands on, and both are one click away from anywhere else in
            the application.

          - No Live / Not published pill. A badge that restates what the
            Publish button already implies — the button reads "Publish to
            customers" on an unpublished policy and is replaced by the date the
            policy went live on a published one. Two controls saying the same
            fact is two things to keep in step when one of them changes.

          - No version counter. There is no version to count: the `version`
            column was dropped when saving stopped appending rows, and the
            de-duplicating migration collapsed the superseded rows that had
            accumulated. What is true instead is stated as a date, because "live
            since 4 Oct" is the question an admin is actually asking.

        WHY PUBLISH IS A BUTTON AND NOT A TICK BOX

        The checkbox was on the Save form, so "I have edited the text" and "I
        have decided the public should now read this" were one gesture. They are
        not the same gesture, and on a page that overwrites in place the
        difference is the whole point: saving an edit to a live policy used to
        post `is_published` as false and quietly take it down. Save now writes
        the text and leaves publication alone; Publish is pressed on purpose.
    --}}
    <div class="grid gap-6 xl:grid-cols-2">
        @foreach (\App\Enums\TermsCategory::cases() as $category)
            @php
                $row = ($grouped[$category->value] ?? collect())->first();
                $live = $published[$category->value] ?? null;
            @endphp

            <x-ui.card :title="$category->label().' Terms'">
                <x-slot:actions>
                    {{-- The date the customer-facing text last went live, as
                         plain text rather than a status pill. On an unpublished
                         policy the slot is empty and the action below says what
                         can be done instead. --}}
                    @if ($live?->published_at)
                        <p class="text-right text-xs leading-tight text-ink-muted">
                            Live to customers<br>
                            <span class="font-medium text-primary">{{ $live->published_at->format('M j, Y g:i A') }}</span>
                        </p>
                    @endif
                </x-slot:actions>

                @can('admin.terms.manage')
                    <form method="POST" action="{{ route('admin.terms.store') }}">
                        @csrf
                        <input type="hidden" name="category" value="{{ $category->value }}">

                        <x-ui.form.textarea
                            name="content"
                            label="Content"
                            rows="10"
                            required
                            :value="old('content', $row?->content ?? '')"
                        />

                        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                            <button type="submit" class="btn-secondary">Save</button>

                            {{-- Publish to customers.

                                 A real CSRF-protected POST to
                                 `admin.terms.publish`, not a link and not a
                                 script call: publishing changes what the public
                                 reads, so it goes through the same route as
                                 everything else that changes data, and there is
                                 exactly one of them.

                                 The button carries `form="…"` pointing at the
                                 hidden form below rather than nesting one form
                                 inside the Save form, which is not valid HTML —
                                 a browser silently drops the inner `<form>` and
                                 its CSRF token with it, and the publish either
                                 does nothing or posts without one.

                                 Absent for a policy with no row, because there
                                 is nothing to publish: the note in its place
                                 says what to do first. On a live policy the
                                 button is inert and says so, and the date in the
                                 card header is the record — publishing again
                                 after an edit is deliberately how an admin says
                                 "this revised text is now the live text". --}}
                            @if ($row)
                                <button
                                    type="submit"
                                    class="{{ $live ? 'btn-ghost' : 'btn-primary' }}"
                                    form="publish-terms-{{ $row->id }}"
                                    @disabled((bool) $live)
                                >
                                    {{ $live ? 'Already published' : 'Publish to customers' }}
                                </button>
                            @else
                                <p class="text-xs text-ink-muted">
                                    Save the text above first, then publish it to customers.
                                </p>
                            @endif
                        </div>
                    </form>

                    @if ($row)
                        <form
                            method="POST"
                            action="{{ route('admin.terms.publish', $row) }}"
                            id="publish-terms-{{ $row->id }}"
                            class="hidden"
                        >
                            @csrf
                        </form>
                    @endif
                @else
                    <div class="prose-bta max-w-none text-sm leading-relaxed text-ink">
                        @if ($row)
                            {!! $row->content !!}
                        @else
                            <p>No content yet.</p>
                        @endif
                    </div>
                @endcan
            </x-ui.card>
        @endforeach
    </div>
@endsection