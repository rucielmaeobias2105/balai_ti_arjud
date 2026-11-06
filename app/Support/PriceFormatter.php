<?php

namespace App\Support;

/**
 * Renders a service price for display.
 *
 * `price` is stored as the admin typed it, so "249/499" stays "249/499" and
 * "100+" stays "100+". Nothing here parses or reformats a number — the whole
 * point of the string column is that the price list is not always a number.
 *
 * The peso sign is added only when the value does not already carry one, so an
 * admin who types "₱499" does not end up with "₱₱499".
 */
final class PriceFormatter
{
    /**
     * The peso sign, as typed by an admin.
     */
    public const PESO = "\u{20B1}";

    /**
     * What an advertised price may contain: figures, a trailing "+", a "/"
     * between two prices, a dash, thousands separators, decimals, the peso
     * sign and spaces. The comma is written as \x2C because a literal one in a
     * `regex:` rule is read as a rule separator.
     *
     * The lookahead insists on at least one digit, so "+", "₱" and "/" are
     * rejected while "100+", "249/499" and "1,200" pass.
     */
    public const PATTERN = '/^(?=.*\d)[\d+\/\x{20B1}\x2C.\-\s]+$/u';

    /**
     * The same set, as validation rules, for the service and variant forms.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'max:50', 'regex:'.self::PATTERN];
    }

    /**
     * Show a price, or an honest stand-in when there is not one to show.
     *
     * Most prices are advertised strings and are shown as typed, with a peso
     * sign added only when the value does not already carry one. The exception
     * is a price phrased as "starts @ 500": prefixing that with a peso sign
     * produced "₱starts @ 500", which reads as broken text rather than a price.
     * It is normalised to "Starts @ ₱500" instead, with the number formatted so
     * "2300" becomes "2,300".
     */
    public static function display(mixed $price, string $empty = 'Price on request'): string
    {
        $value = trim((string) $price);

        if ($value === '') {
            return $empty;
        }

        if (preg_match('/\bstarts\s*@\s*([0-9][0-9,]*(?:\.[0-9]+)?)/iu', $value, $matches)) {
            return 'Starts @ '.self::PESO.self::formatFigure($matches[1]);
        }

        return str_contains($value, self::PESO) ? $value : self::PESO.$value;
    }

    /**
     * A figure with thousands separators, keeping any decimals that were typed.
     */
    private static function formatFigure(string $figure): string
    {
        $plain = str_replace(',', '', $figure);
        $decimals = str_contains($plain, '.') ? strlen(substr(strrchr($plain, '.'), 1)) : 0;

        return number_format((float) $plain, $decimals, '.', ',');
    }

    /**
     * True when the value is a bare number, i.e. a price that needs no
     * explaining to a customer.
     */
    public static function isPlainNumber(mixed $price): bool
    {
        return is_numeric(trim((string) $price));
    }

    /**
     * The number a range starts at, for "from" copy. "249/499" starts at 249.
     */
    public static function firstFigure(mixed $price): ?float
    {
        $value = trim((string) $price);

        if ($value === '') {
            return null;
        }

        if (is_numeric(str_replace(',', '', $value))) {
            return (float) str_replace(',', '', $value);
        }

        return preg_match('/[0-9][0-9,]*(?:\.[0-9]+)?/', $value, $matches)
            ? (float) str_replace(',', '', $matches[0])
            : null;
    }
}
