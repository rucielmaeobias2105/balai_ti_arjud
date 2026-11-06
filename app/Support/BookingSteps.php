<?php

namespace App\Support;

use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/**
 * The three steps of the booking wizard, and where a refused submission lands.
 *
 * The wizard is three panels inside one card, moved by Back and Continue, and it
 * is a server-rendered page: pressing Confirm Booking posts the form, and a
 * refused booking comes back as a fresh render. Which panel that fresh render
 * opens on is therefore not a detail the customer can lose track of — the page
 * genuinely comes back on step 1 unless something says otherwise.
 *
 * That was the bug: tick everything, reach the last step, press Confirm without
 * ticking the agreement, and "You must agree to the Terms and Conditions to
 * book." arrived in the toast while the panel holding the checkbox was steps
 * away and invisible. Nothing had gone wrong and nothing was lost — but the
 * customer was told about a box they could not see, on a form they had just
 * filled in, with no way back to it except Continue clicks that read as
 * navigation they had to do again.
 *
 * So the step is decided here, from the error bag, and sent to the wizard as the
 * step to open on. Three properties of the answer, all of them the point:
 *
 *   - it is the *earliest* step with an error, so the panel that opens is the
 *     first thing the customer has to put right. `MessageBag::all()` lists the
 *     errors in the order `StoreBookingRequest::rules()` declares them, which is
 *     step order, so this is also the panel the toast opens on;
 *   - it is a step, never a new step. Every error bag is mapped onto one of the
 *     three panels rather than inventing a place to put the message;
 *   - it is 1 when there is nothing to report, so a fresh, untouched form opens
 *     where it always has.
 *
 * Errors that fall on more than one step are listed once, in the toast, and only
 * the first one's panel is opened — there is one page and it can only show one
 * panel, and refusing to guess any further than that is honest about it.
 *
 * THE FIELD MAP IS A SECOND LIST, DELIBERATELY
 *
 * `STEP_FIELDS` and the `missingOnCurrentStep()` gate in `create.blade.php` both
 * have to know which fields belong to which step, and neither can be derived
 * from the other: the gate words each omission ("Enter your full name"), while
 * this one only has to know the panel. They are kept in step by hand, which is
 * the one thing that could let them drift, so `BookingWizardTest` asserts that
 * every field the server can reject is claimed by exactly one step — a field in
 * no step would be an error the customer is told about and then not shown.
 *
 * The server side of the same arrangement is `StoreBookingRequest`, which decides
 * *whether* each of these is an error at all.
 */
final class BookingSteps
{
    /**
     * The step titles, in order.
     *
     * Keyed by step number rather than a plain list because the number is what
     * the wizard's panels compare themselves against (`x-show="step === 3"`), and
     * what `startStep()` returns. A list would make every caller count to get it
     * back.
     *
     * `count()` is what the "Step X of N" counter, the Continue button's
     * visibility and the last step's submit button all read, so every place that
     * needs the length cannot disagree about it.
     *
     * The headings themselves are written into the panels, where they are read.
     * This is the list they are kept in step with.
     */
    public const TITLES = [
        1 => 'Your Details & Preferred Date & Time',
        2 => 'Preferences & Health Notes',
        3 => 'Last Service(s) Availed, Technician & Special Request',
    ];

    /**
     * Which step owns which field.
     *
     * `services` covers `services.0.service_id` and the rest. There is no
     * service-picker panel any more — services are chosen before `/book` — but a
     * refused line still belongs to the first panel, beside the Booking Summary
     * that shows the basket. Likewise `last_services_availed_note` is the visible
     * half of the question and `last_services_availed` the hidden one, and both
     * are answered on the same step.
     *
     * Step 2 is in here even though nothing on it can fail. `allergies_other` is
     * nullable and bounded only by `max`, and a string that is too long is still a
     * health note rather than a question about anything else — but listing it
     * means a future rule cannot fail on a step this map claims does not exist.
     */
    public const STEP_FIELDS = [
        1 => ['services', 'customer_name', 'customer_phone', 'preferred_date', 'preferred_time'],
        2 => ['allergies_other'],
        3 => [
            'last_services_availed',
            'last_services_availed_note',
            'technician_id',
            'special_request',
            'agree_terms',
        ],
    ];

    /** How many steps the wizard has. */
    public static function count(): int
    {
        return count(self::TITLES);
    }

    /**
     * The step a field is answered on, or null if no step claims it.
     *
     * Prefix match, because that is how a nested error key is written:
     * `services.0.quantity` is an error about `services`. An exact match is tried
     * first so a field named `special_request_notes` cannot be claimed by
     * `special_request`.
     */
    public static function stepForField(string $field): ?int
    {
        foreach (self::STEP_FIELDS as $step => $fields) {
            foreach ($fields as $claimed) {
                if ($field === $claimed || str_starts_with($field, $claimed.'.')) {
                    return $step;
                }
            }
        }

        return null;
    }

    /**
     * The step a refused submission should come back to.
     *
     * The earliest step carrying an error, or 1 for a clean bag. "Earliest" is
     * the point: a customer who filled in nothing is sent to the first thing they
     * left out, and one who filled in everything but the agreement is sent
     * straight to the agreement, which is the whole of this class.
     *
     * Taking the minimum rather than trusting the bag's order is deliberate.
     * `MessageBag::all()` happens to walk the errors in the order
     * `StoreBookingRequest::rules()` declares them, which is step order, so
     * "first key" and "earliest step" agree today — and the toast is read in that
     * order, so the panel that opens and the first line of the toast still
     * describe the same problem. But that is a property of the request class, not
     * of the bag, and a min() costs one line and cannot be broken by a rule being
     * added in a different place.
     *
     * A `ViewErrorBag` is unwrapped rather than read as a bag of bags, so a caller
     * can hand over whatever `$errors` is in a Blade view without knowing which of
     * the two it has.
     */
    public static function startStep(ViewErrorBag|MessageBag|null $errors): int
    {
        $bag = $errors instanceof ViewErrorBag ? $errors->getBag('default') : $errors;

        if (! $bag) {
            return 1;
        }

        $steps = [];

        foreach ($bag->keys() as $field) {
            if ($step = self::stepForField((string) $field)) {
                $steps[] = $step;
            }
        }

        return $steps ? min($steps) : 1;
    }
}
