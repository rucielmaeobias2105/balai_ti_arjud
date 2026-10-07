<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => [
                'required', 'string', 'email:rfc', 'max:255',
                // Kept even though the identity check below also catches it, for
                // the case that is *only* an email collision: one customer
                // applying twice with a typo'd name or a different phone number
                // is a duplicate account, and `unique` is the rule that says so.
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            // One mobile number, normalised to `09XXXXXXXXX` before it is judged.
            // See App\Support\PhoneNumber for why both `09…` and `+639…` are
            // accepted and only the first is stored.
            'contact_number' => PhoneNumber::rules(),
            'password' => [
                'required', 'string', 'confirmed',
                // 8+, an upper and a lower case letter, a symbol and a digit.
                // `Password::min(8)` alone is what the hint on the form used to
                // promise, which is why a password like `password` was accepted
                // on a public sign-up form.
                Password::min(8)->mixedCase()->symbols()->numbers(),
            ],
            'terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...PhoneNumber::messages('contact_number'),
            'terms.accepted' => 'You must agree to the Terms and Conditions to register.',
            'password.confirmed' => 'The password confirmation does not match.',
            'password.min' => 'Your password must be at least 8 characters long.',
            'password.mixed' => 'Your password must contain at least one uppercase and one lowercase letter.',
            'password.symbols' => 'Your password must contain at least one special character (for example ! @ # $ %).',
            'password.numbers' => 'Your password must contain at least one number.',
        ];
    }

    /**
     * The message shown instead of "that email is already taken" when the whole
     * identity — name, email and phone number — is already on file.
     */
    public const DUPLICATE_IDENTITY = 'An account already exists with this name, email address and phone number. Please log in instead.';

    /**
     * A second account under one identity is refused, in its own words.
     *
     * `unique('users', 'email')` already stops a repeat signup, and it fires
     * first, so this check has to run anyway and has to replace that message
     * rather than sit behind it. Otherwise the person on the form — who
     * believes they are registering for the first time — is told only that
     * their address is registered, which answers a question they did not ask.
     * The useful thing to say is that this name, email and phone number are
     * already on file together, so they should log in instead.
     *
     * Only `email` errors are cleared, and only once every other field has
     * passed: if the form also has a short password or an unchecked terms box,
     * those are the problems worth fixing first, and the duplicate is
     * something they will meet on the next attempt anyway.
     *
     * Compared case-insensitively on the name and on the phone, because
     * "Juan dela Cruz" and a number typed with a leading `+63` are the same
     * person and a comparison that missed those would be a check that passes.
     * The phone is already normalised by `prepareForValidation()`.
     *
     * Soft-deleted rows are excluded: a deleted account's owner is allowed to
     * come back, and blocking them here would strand an address forever.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['first_name', 'last_name', 'contact_number', 'password', 'terms'])) {
                return;
            }

            $exists = User::query()
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(first_name) = ?', [mb_strtolower((string) $this->input('first_name'))])
                ->whereRaw('LOWER(last_name) = ?', [mb_strtolower((string) $this->input('last_name'))])
                ->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $this->input('email'))])
                ->where('contact_number', (string) $this->input('contact_number'))
                ->exists();

            if (! $exists) {
                return;
            }

            $validator->errors()->forget('email');
            $validator->errors()->add('email', self::DUPLICATE_IDENTITY);
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name' => trim((string) $this->input('first_name')),
            'last_name' => trim((string) $this->input('last_name')),
            'email' => strtolower(trim((string) $this->input('email'))),
            // Normalised before validation, so the rules, the duplicate check
            // and the stored value are all looking at the same 11 digits.
            'contact_number' => PhoneNumber::normalise($this->input('contact_number')) ?? '',
        ]);
    }
}