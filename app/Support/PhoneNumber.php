<?php

namespace App\Support;

/**
 * One format for a Philippine mobile number, in and out of every form.
 *
 * THE FORMAT
 *
 * A number is stored as exactly eleven digits: `09XXXXXXXXX` — `09`, then nine
 * more. That is the format every existing row is already in, so it is the one
 * kept: normalising *into* it means no migration, no second format to render,
 * and — the reason it matters most — the duplicate check in `RegisterRequest`
 * can compare strings.
 *
 * TWO WAYS IN, ONE WAY OUT
 *
 * Both of these are accepted on input:
 *
 *   09171234567      eleven digits, the mobile prefix 09
 *   +639171234567    the international form: `+`, `63`, `9`, then nine digits
 *
 * and both are stored as `09171234567`. `+63` followed by `9…` is the same
 * subscriber as `09…`, so keeping both would store one person two ways and make
 * "is this the same customer?" a question rather than a comparison.
 *
 * The two shapes are different lengths — `09…` is 11 characters, `+639…` is 13
 * — which is why this class exists at all. It accepts either and normalises,
 * rather than leaving each form to its own rule and hoping they agree.
 *
 * Anything else is refused: a landline (`(02) 8123 4567`), a mobile with too
 * few or too many digits, a number that does not start `09`, letters, or the
 * spaces and dashes people type for readability.
 *
 * Separators are stripped before the number is judged rather than rejected
 * outright — see `stripFormatting()`. So `0917 123 4567` becomes
 * `09171234567` and is then accepted, which is what somebody pasting a number
 * out of their phone's contacts expects. What is *not* repaired is a wrong
 * number: too few digits, a landline, or something starting `02` still fails,
 * because silently completing a phone number is how a customer ends up
 * unreachable and does not know why.
 */
final class PhoneNumber
{
    /**
     * The stored form: `09` plus nine digits.
     */
    public const PATTERN = '/^09\d{9}$/';

    /**
     * The international form, `+63` then `9` then nine digits.
     */
    public const INTERNATIONAL = '/^\+639\d{9}$/';

    /**
     * Exactly how long the stored form is, for the client-side counter.
     */
    public const LENGTH = 11;

    /**
     * Validation rules for a phone field that must hold a mobile number.
     *
     * `max:20` rather than a bare regex so an absurd paste is rejected by a
     * length rule with an obvious message instead of by a pattern.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'max:20', 'regex:'.self::PATTERN];
    }

    /**
     * The message used for every shape of wrong phone number.
     *
     * One sentence, because there is only one thing wrong with all of them: it
     * is not an 11-digit number starting 09. Naming the expected shape is more
     * use than echoing the offending value back.
     */
    public const MESSAGE = 'Enter an 11-digit mobile number starting with 09 (for example 09171234567).';

    /**
     * The error keys the message belongs to, for a Form Request's `messages()`.
     *
     * @return array<string, string>
     */
    public static function messages(string $field): array
    {
        return [
            $field => self::MESSAGE,
            $field.'.regex' => self::MESSAGE,
            $field.'.max' => self::MESSAGE,
        ];
    }

    /**
     * Turn whatever was typed or posted into the stored form, or null.
     *
     * This is the only place the conversion happens. `prepareForValidation()`
     * calls it before the rules run, so the rules above see the normalised
     * value and the controller stores the same string it validated.
     */
    public static function normalise(mixed $value): ?string
    {
        $value = self::stripFormatting((string) $value);

        if ($value === '') {
            return null;
        }

        // `+63 9…` and `639…` are the international form; `09…` is already ours.
        if (preg_match(self::INTERNATIONAL, $value) === 1) {
            return '0'.substr($value, 3);
        }

        if (preg_match('/^639\d{9}$/', $value) === 1) {
            return '0'.substr($value, 2);
        }

        return $value;
    }

    /**
     * True when the value is already a storable mobile number.
     */
    public static function isValid(mixed $value): bool
    {
        return preg_match(self::PATTERN, (string) $value) === 1;
    }

    /**
     * Remove the punctuation people add while reading a number aloud.
     *
     * Spaces, dashes, dots, parentheses and a leading `00` international
     * access code are removed; `+` is kept, because it is what distinguishes
     * the international form from the local one and `normalise()` needs it to
     * tell them apart.
     */
    private static function stripFormatting(string $value): string
    {
        $value = preg_replace('/[\s\-().]/', '', trim($value)) ?? '';

        if (str_starts_with($value, '00')) {
            $value = '+'.substr($value, 2);
        }

        return $value;
    }
}