<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One blocked range on one date.
 *
 * The `whole_day` flag is what makes a single form able to express both halves of
 * the feature. Ticked, the two time inputs are cleared and the row is saved with
 * no times at all, which is how "closed that day" is stored. Unticked, both times
 * are required and the row is saved as a window.
 *
 * The rules use `required_unless` rather than `nullable` + a manual check, so the
 * refusal happens during validation and lands on the time fields themselves —
 * which is where the person who has to fix it is looking.
 */
class StoreBlockedSlotRequest extends FormRequest
{
    /**
 * The admin guard, not the default one.
 *
 * `$this->user()` resolves against `config('auth.defaults.guard')`, which is `web`
 * — the customer guard. An admin on the panel has nobody on `web`, so the bare
 * call is null and every block came back 403.
 *
 * A test calling `actingAs($admin, 'admin')` cannot see this: Laravel's test
 * helper also runs `Auth::shouldUse('admin')`, so the default guard moves with it
 * and the bare call appears to work. Hence the explicit guard, matching every other
 * request in `Http/Requests/Admin`.
 */
public function authorize(): bool
    {
        return $this->user('admin')?->can('admin.calendar.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date', 'after_or_equal:today'],
            'whole_day' => ['nullable', 'boolean'],
            'start_time' => ['nullable', 'required_unless:whole_day,1', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_unless:whole_day,1', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * The rows this submission is asking for.
     *
     * An array rather than a single row, because the admin dialog lets an admin add
     * several ranges to one date in a single save — "block 9–10am and 2–4pm" is one
     * decision about one date, and making them save twice would let the first be
     * committed and the second rejected, leaving the day half-blocked after the
     * dialog said it had failed.
     *
     * A ticked `whole_day` short-circuits all of that into one row with no times,
     * because there is exactly one way to express it and it is not "a window from
     * null to null". Handling that here rather than in the controller keeps every
     * consumer of this request working off a fully-shaped row — the controller
     * never has to know which form the admin filled in.
     *
     * @return array<int, array{date: string, start_time: ?string, end_time: ?string, reason: ?string}>
     */
    public function ranges(): array
    {
        $date = $this->blockedDate();
        $reason = $this->cleanReason();

        if ($this->isWholeDay()) {
            return [['date' => $date, 'start_time' => null, 'end_time' => null, 'reason' => $reason]];
        }

        return $this->rangeRows()->map(fn (array $row) => [
            'date' => $date,
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'reason' => $reason,
        ])->all();
    }

    /**
     * Normalised rows, one per range.
     *
     * A single-range submit (the common case — one range typed into the primary
     * fields) is read as a one-row array, so both shapes below run through the same
     * validation and the same save.
     *
     * @return \Illuminate\Support\Collection<int, array{start_time: ?string, end_time: ?string}>
     */
    protected function rangeRows()
    {
        $rows = $this->input('ranges');

        if (! is_array($rows) || $rows === []) {
            $rows = [[
                'start_time' => $this->input('start_time'),
                'end_time' => $this->input('end_time'),
            ]];
        }

        return collect($rows)
            ->map(fn ($row) => [
                'start_time' => $this->timeOrNull(is_array($row) ? ($row['start_time'] ?? null) : null),
                'end_time' => $this->timeOrNull(is_array($row) ? ($row['end_time'] ?? null) : null),
            ])
            ->values();
    }

    public function blockedDate(): string
    {
        return substr((string) $this->input('date'), 0, 10);
    }

    /**
     * The first range in this submission.
     *
     * An update edits exactly one existing row, so it only ever reads one range.
     * A submission carrying several is treated as its first — the admin dialog
     * edits a range in place, and "add another" is a separate save.
     */
    public function rangeFor(): array
    {
        return $this->rangeRows()->first() ?? ['start_time' => null, 'end_time' => null];
    }

    public function cleanReasonFor(): ?string
    {
        return $this->cleanReason();
    }

    /** True when the submission is closing the whole day rather than a window. */
    public function isWholeDay(): bool
    {
        return $this->boolean('whole_day');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_time.required_unless' => 'Enter a start time, or switch on “Closed all day”.',
            'end_time.required_unless' => 'Enter an end time, or switch on “Closed all day”.',
            'date.after_or_equal' => 'You cannot block a date in the past.',
        ];
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Contracts\Validation\Validator $validator) {
            if ($this->isWholeDay()) {
                return;
            }

            foreach ($this->rangeRows() as $index => $row) {
                /*
                 * A window that ends before it starts.
                 *
                 * Refused rather than swapped. Silently normalising 4pm–2pm into
                 * 2pm–4pm would be guessing at which half the admin meant, and on a
                 * closure the cost of guessing wrong is a day the salon is shut for
                 * no stated reason — or, worse, a window they believed was blocked
                 * and was not.
                 */
                if ($row['start_time'] !== null
                    && $row['end_time'] !== null
                    && $row['end_time'] <= $row['start_time']) {
                    $field = $index === 0 ? 'end_time' : "ranges.{$index}.end_time";

                    $validator->errors()->add($field, 'The end time has to be later than the start time.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        /*
         * Fold the extra-range rows into the primary field names when there is only
         * one, so validation messages for the ordinary single-range case point at
         * `start_time`/`end_time` — the inputs actually on screen — instead of at a
         * `ranges.0.*` key that has no visible field.
         */
        if ($this->isWholeDay()) {
            $this->merge(['start_time' => null, 'end_time' => null]);
        }
    }

    protected function cleanReason(): ?string
    {
        $reason = trim((string) $this->input('reason'));

        return $reason === '' ? null : $reason;
    }

    protected function timeOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : substr($value, 0, 5);
    }
}