<?php

namespace App\Http\Requests\Customer;

use App\Models\Appointment;
use App\Services\BookingAvailability;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class RescheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment
            && auth()->id() !== null
            && $appointment->user_id === auth()->id();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preferred_date' => ['required', 'date'],
            'preferred_time' => ['required', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Re-validate the new slot against availability.
     *
     * The blocked-date check that used to be here went with the Calendar &
     * Blocked Dates feature. Nothing else about rescheduling depends on which
     * service the booking is for, so `$serviceId` is no longer resolved from the
     * appointment's service lines either.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Appointment $appointment */
            $appointment = $this->route('appointment');

            if (! $appointment->canBeRescheduled()) {
                throw ValidationException::withMessages([
                    'preferred_date' => 'This appointment can no longer be rescheduled.',
                ]);
            }

            $availability = BookingAvailability::make();

            $problems = $availability->timeProblems(
                $this->input('preferred_date'),
                $this->input('preferred_time'),
                $appointment->id,
            );

            foreach ($problems as $problem) {
                $validator->errors()->add('preferred_date', $problem);
            }
        });
    }

    /**
     * The minimum-notice rule raises an amber toast, same as the booking form.
     *
     * Reschedule reaches it through `timeProblems()` rather than
     * `dateProblems()`, so the message arrives on a different path but is the same
     * constant.
     */
    /**
     * The date rules raise a toast as well as inline errors, in the same colours
     * the booking form uses.
     *
     * Reschedule reaches these rules through `timeProblems()` rather than
     * `dateProblems()`, and reports every one of them against `preferred_date`
     * because the dialog has one date field rather than a date and a time. The
     * wording and the severity still have to match the booking form's, which is why
     * both ask `BookingAvailability::toastFor()` rather than each deciding.
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($toast = BookingAvailability::toastFor($validator->errors()->all())) {
            session()->flash('toast', $toast);
        }

        parent::failedValidation($validator);
    }
}
