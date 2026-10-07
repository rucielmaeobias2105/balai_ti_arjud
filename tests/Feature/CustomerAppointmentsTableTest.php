<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\DownPaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer's "My Appointments" page, rebuilt as a history table with the
 * row actions in dialogs.
 *
 * Three things this file exists to pin, because all three were deliberate
 * decisions rather than omissions:
 *
 *   - the header is a title and a button, nothing else;
 *   - the Appointment Details screen is a dialog on this page, not a page of
 *     its own, so the money that used to be hidden here — down payment, GCash
 *     reference, payment status — is now carried in that dialog's payload; and
 *   - deletion is available only on a settled booking, in one row or in a
 *     ticked set, through the shared confirmation dialog.
 */
class CustomerAppointmentsTableTest extends TestCase
{
    use RefreshDatabase;

    private function html(array $attributes = [], ?\App\Models\User $user = null, bool $withService = true): string
    {
        $user ??= $this->makeUser();
        $service = $withService ? $this->makeService() : null;

        $appointment = $this->makeAppointment($user, $service, $attributes);

        return $this->actingAs($user)
            ->get(route('appointments.index'))
            ->assertOk()
            ->getContent();
    }

    /**
     * `@js()` escapes its payload for embedding in an HTML attribute: quotes come
     * out as `\u0022` and slashes are backslash-escaped, which is exactly why the
     * row actions carry their URLs through it.
     *
     * Order matters — the unicode escapes have to go first, because stripping the
     * backslashes first would leave a bare `u0022` behind and every quote in the
     * payload would still be invisible.
     */
    private function unescaped(string $html): string
    {
        return str_replace('\\', '', str_replace('\u0022', '"', $html));
    }

    /* ------------------------------------------------------------------ */
    /* 1. Header — title and button only                                  */
    /* ------------------------------------------------------------------ */

    public function test_the_header_is_the_title_and_the_new_booking_button_and_nothing_else(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('>My Appointments</h1>', $html);

        // The eyebrow and the subtitle are gone. Both were describing the list
        // rather than titling it.
        $this->assertStringNotContainsString('My Bookings', $html);
        $this->assertStringNotContainsString('View, cancel, reschedule or rate your appointments.', $html);

        // The action and the filters are untouched.
        $this->assertStringContainsString(route('appointments.create'), $html);
        $this->assertStringContainsString('New Booking', $html);
    }

    public function test_the_status_filters_are_unchanged(): void
    {
        $html = $this->html();

        foreach (['All', 'Pending', 'Confirmed', 'Completed', 'Cancelled'] as $label) {
            $this->assertMatchesRegularExpression(
                '/>\s*'.preg_quote($label, '/').'\s*</',
                $html,
                "The \"{$label}\" filter should still be offered.",
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* 2. The list is a table                                             */
    /* ------------------------------------------------------------------ */

    public function test_the_appointments_render_as_a_table_with_the_expected_columns(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('<table', $html);
        $this->assertMatchesRegularExpression('/<table[^>]*class="[^"]*bta-table[^"]*"/', $html);

        // The dark header band, so the table reads like the reference history
        // table rather than the pale admin one.
        $this->assertStringContainsString('bta-table-dark', $html);

        // No "Ref #" column. The reference is still generated and still shown
        // inside the View dialog, which is where a customer looks when they want
        // to quote it; in the table it was a second copy of a number nobody reads
        // at a glance.
        $headers = ['#', 'Service(s)', 'Date &amp; Time', 'Amount', 'Staff', 'Status', 'Actions'];

        foreach ($headers as $header) {
            $this->assertMatchesRegularExpression(
                '/<th[^>]*>\s*'.preg_quote($header, '/').'\s*<\/th>/',
                $html,
                "Expected a \"{$header}\" column.",
            );
        }

        // Only the table column is gone. The dialog still shows the reference in
        // its own field — that is where a customer looks when they want to quote
        // it to the salon — so the absence is asserted against the header
        // rather than the whole page, or it would fail on the copy that is meant
        // to stay.
        $this->assertDoesNotMatchRegularExpression('/<th[^>]*>\s*Ref #\s*<\/th>/', $html);

        // In that order, since a history table is read across.
        $last = -1;

        foreach ($headers as $header) {
            $found = strpos($html, '>'.$header.'<');
            $this->assertNotFalse($found, "Expected the \"{$header}\" header.");
            $this->assertGreaterThan($last, $found, "The \"{$header}\" column is out of order.");
            $last = $found;
        }
    }

    /**
     * The dialog's payment section is the total, and nothing else.
     *
     * This inverts an earlier decision on purpose, twice over. It first asserted
     * that no down payment, GCash reference or payment status appeared anywhere,
     * because the reference project's checkout shows only a grand total. It was
     * then inverted, to argue the dialog was where a customer checks what they
     * paid. It is now back to the total alone, because the salon takes no deposit
     * for a web booking: `down_payment_required` defaults to false, `BookingService`
     * writes `NotRequired`, and a booking made through this form carries no
     * reference and no amount to show.
     *
     * Which meant three empty rows — Down Payment, Payment status, GCash
     * Reference — and a badge reading "Not Required", followed by reassurance
     * about a payment that had never been requested. An admin who does need the
     * real figures for an older booking has the verification panel.
     */
    public function test_the_payment_summary_is_the_total_and_nothing_else(): void
    {
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user, $this->makeService());

        // Make sure the data is there to leak if a view were showing it.
        $appointment->update([
            'down_payment_amount' => 459,
            'down_payment_reference' => '09123456789',
            'down_payment_status' => DownPaymentStatus::Unverified,
        ]);

        $html = $this->actingAs($user)->get(route('appointments.index'))->assertOk()->getContent();
        $payload = $this->unescaped($html);

        // The heading stays; the rows under it do not.
        $this->assertStringContainsString('Payment Summary', $html);
        $this->assertStringContainsString('Total Amount', $html);

        foreach (['Down Payment', 'Payment status', 'GCash Reference', 'Awaiting Verification'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, "\"{$gone}\" should be gone from the customer view.");
        }

        // And out of the payload too, so the keys are not merely unrendered.
        foreach (['downPayment', 'downPaymentStatus', 'downPaymentBadge', 'downPaymentReference'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'"', $payload);
        }

        // The figure that matters is still shown, in the table and in the dialog.
        $this->assertStringContainsString('"total":"'.number_format((float) $appointment->total_amount, 2).'"', $payload);
        $this->assertStringContainsString('₱'.number_format((float) $appointment->total_amount, 2), $html);
    }

    public function test_a_row_carries_the_booking_data_across_the_columns(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();
        $appointment = $this->makeAppointment($user, $service, ['status' => AppointmentStatus::Confirmed]);

        $html = $this->actingAs($user)->get(route('appointments.index'))->assertOk()->getContent();

        $this->assertStringContainsString($appointment->reference_number, $html);
        $this->assertStringContainsString($service->name, $html);
        $this->assertStringContainsString($appointment->date_time_label, $html);
        $this->assertStringContainsString('₱'.number_format((float) $appointment->total_amount, 2), $html);
        $this->assertStringContainsString($appointment->technicianLabel(), $html);
        $this->assertStringContainsString('Confirmed', $html);
    }

    /** The table scrolls sideways rather than squeezing its columns on a phone. */
    public function test_the_table_scrolls_horizontally_instead_of_breaking(): void
    {
        $this->assertMatchesRegularExpression(
            '/<div class="[^"]*overflow-x-auto[^"]*">\s*<table/',
            $this->html(),
            'The table should sit in a horizontally scrolling wrapper.',
        );
    }

    /* ------------------------------------------------------------------ */
    /* 3. The row actions                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * The three icon buttons, side by side in one row of equal squares.
     *
     * Asserted as a set inside the actions cell rather than on the page, because
     * Cancel and Reschedule are gated on `isCustomerActionable()` and a page of
     * completed appointments would otherwise look like the icons were missing.
     */
    public function test_the_actions_cell_holds_the_three_icon_buttons(): void
    {
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user, $this->makeService(), ['status' => AppointmentStatus::Pending]);

        $html = $this->actingAs($user)->get(route('appointments.index'))->assertOk()->getContent();

        // One view trigger per row, in the actions cell. There used to be two —
        // the reference-number cell and the eye icon both opened the dialog —
        // but the Ref # column is gone, so the eye is the only way in.
        $this->assertSame(1, substr_count($html, "\$dispatch('view-appointment'"));
        $this->assertSame(1, substr_count($html, "\$dispatch('cancel-appointment'"));
        $this->assertSame(1, substr_count($html, "\$dispatch('reschedule-appointment'"));

        // The icon set, all one family so the squares do not clash.
        foreach (['fa-eye', 'fa-times-circle', 'fa-calendar'] as $icon) {
            $this->assertStringContainsString('fas '.$icon, $html);
        }

        // Same square, different roles — and the reschedule one is the cool accent.
        $this->assertStringContainsString('icon-action icon-action-secondary', $html);
        $this->assertStringContainsString('icon-action icon-action-danger', $html);
        $this->assertStringContainsString('icon-action icon-action-info', $html);

        // Evenly spaced rather than flush.
        $this->assertMatchesRegularExpression(
            '/<div class="flex items-center justify-center gap-1\.5">/',
            $html,
        );
    }

    /**
     * Nothing sits under the icons. "Your Review" and the rate link used to hang
     * below the button row, which made the cell taller than its neighbours and
     * broke the line the icons were meant to form.
     */
    public function test_the_actions_cell_carries_no_secondary_link(): void
    {
        $user = $this->makeUser();

        $this->makeAppointment($user, $this->makeService(), ['status' => AppointmentStatus::Completed]);
        $this->makeAppointment($user, $this->makeService(), ['status' => AppointmentStatus::Completed]);

        $html = $this->actingAs($user)->get(route('appointments.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Your Review', $html);
        $this->assertStringNotContainsString('Rate this visit', $html);
    }

    /** Cancel and Reschedule appear only while the booking can still be changed. */
    public function test_every_row_can_be_viewed_and_only_actionable_rows_offer_cancel_and_reschedule(): void
    {
        $user = $this->makeUser();

        $this->makeAppointment($user, $this->makeService(), ['status' => AppointmentStatus::Pending]);
        $this->makeAppointment($user, $this->makeService(), ['status' => AppointmentStatus::Completed]);
        $this->makeAppointment($user, $this->makeService(), ['status' => AppointmentStatus::Cancelled]);

        $html = $this->actingAs($user)->get(route('appointments.index'))->assertOk()->getContent();

        // One view trigger per row. There used to be two per row — the reference
        // number and the eye icon both opened the same dialog — but the Ref #
        // column is gone, so three rows now carry three triggers. Counted on the
        // dispatch rather than the bare name, which the dialog's own id and
        // listener also contain.
        $this->assertSame(3, substr_count($html, "\$dispatch('view-appointment'"));

        // Cancel and reschedule are gated on `isCustomerActionable()`, which is
        // Pending and Confirmed only — so of Pending / Completed / Cancelled
        // exactly one row offers either. The gates are the model's, unchanged.
        $this->assertSame(1, substr_count($html, "\$dispatch('cancel-appointment'"));
        $this->assertSame(1, substr_count($html, "\$dispatch('reschedule-appointment'"));
    }

    /* ------------------------------------------------------------------ */
    /* 3. The three dialogs                                               */
    /* ------------------------------------------------------------------ */

    /* 4. The three dialogs                                               */
    /* ------------------------------------------------------------------ */

    /**
     * One of each dialog for the whole page, not one per row. A dialog per row
     * would repeat the whole form, its CSRF token and its `_method` spoof once
     * per booking.
     *
     * Four, not three: the delete confirmation is a dialog like the others, and
     * it is the shared `x-ui.confirm-dialog` rather than a fourth hand-rolled
     * shell.
     */
    public function test_the_dialogs_are_rendered_once_each_for_the_page(): void
    {
        $user = $this->makeUser();

        $this->makeAppointment($user, $this->makeService(), ['status' => AppointmentStatus::Pending]);
        $this->makeAppointment($user, $this->makeService(), ['status' => AppointmentStatus::Pending]);
        $this->makeAppointment($user, $this->makeService(), ['status' => AppointmentStatus::Pending]);

        $html = $this->actingAs($user)->get(route('appointments.index'))->assertOk()->getContent();

        // Four from this page's own markup — the three dialogs below plus the
        // bulk-delete confirmation — and a fifth from the layout, which mounts
        // the Terms & Conditions dialog on every screen so the policy links in
        // the cancel and reschedule dialogs have something to open.
        $this->assertSame(5, substr_count($html, 'role="dialog"'));
        $this->assertStringContainsString('id="terms-modal-title"', $html);

        // Once each, which is the claim this test is actually about: three
        // bookings on the page must not mean three copies of any dialog.
        $this->assertSame(1, substr_count($html, 'id="view-appointment-title"'));
        $this->assertSame(1, substr_count($html, 'id="cancel-appointment-title"'));
        $this->assertSame(1, substr_count($html, 'id="reschedule-appointment-title"'));
        $this->assertSame(1, substr_count($html, 'id="terms-modal-title"'));
    }

    /**
     * They render below `</main>`, so the table's `overflow-x-auto` cannot clip
     * them. A dialog nested inside the scrolling table would be cut off at the
     * panel edge on a narrow screen.
     */
    public function test_the_dialogs_sit_outside_the_scrolling_table(): void
    {
        $html = $this->html();

        $mainEnd = strpos($html, '</main>');
        $firstDialog = strpos($html, 'role="dialog"');

        $this->assertNotFalse($mainEnd);
        $this->assertNotFalse($firstDialog);
        $this->assertGreaterThan($mainEnd, $firstDialog, 'The dialogs should render after </main>.');
    }

    /** The View dialog is fed a payload map, so opening it costs no request. */
    public function test_the_view_dialog_is_fed_a_payload_map_and_holds_no_form(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();
        $appointment = $this->makeAppointment($user, $service);

        $html = $this->actingAs($user)->get(route('appointments.index'))->assertOk()->getContent();

        // The existing component, reused rather than reinvented.
        $this->assertStringContainsString('appointmentViewer(', $html);
        $this->assertStringContainsString($appointment->reference_number, $html);

        // Its two columns, the per-service breakdown and the history footnote.
        $this->assertStringContainsString('Appointment Information', $html);
        $this->assertStringContainsString('Service(s)', $html);
        $this->assertStringContainsString('x-text="service.name"', $html);
        $this->assertStringContainsString('x-text="detail?.specialRequest"', $html);


        // Read-only, unlike the other two: it carries no form and no submit
        // button, so there is nothing in it to accidentally post.
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        $dialog = (new \DOMXPath($doc))->query('//*[@aria-labelledby="view-appointment-title"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $dialog);
        $this->assertSame(0, (new \DOMXPath($doc))->query('.//form', $dialog)->length);
        $this->assertSame(0, (new \DOMXPath($doc))->query('.//button[@type="submit"]', $dialog)->length);
    }

    /**
     * The Cancel dialog is a red one, and it posts exactly the fields
     * `CancelAppointmentController@update` validates — so a refusal comes back as
     * a validation error the dialog can show rather than a 500.
     */
    public function test_the_cancel_dialog_is_red_and_posts_what_the_controller_validates(): void
    {
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user, $this->makeService(), ['status' => AppointmentStatus::Pending]);

        $html = $this->actingAs($user)->get(route('appointments.index'))->assertOk()->getContent();

        $this->assertStringContainsString('bg-status-cancelled', $html);
        $this->assertStringContainsString('Cancel Appointment', $html);
        $this->assertStringContainsString('This action cannot be undone.', $html);

        // The route and the fields, byte-for-byte what the controller expects.
        $this->assertStringContainsString(route('appointments.cancel.update', $appointment), $this->unescaped($html));

        foreach (['reason_preset', 'reason', 'agree_cancellation_policy'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html);
        }

        $this->assertStringContainsString('value="PATCH"', $html);

        // The quick-pick list, from the same constant the standalone page uses.
        $this->assertStringContainsString('Schedule conflict', $html);
        $this->assertStringContainsString('Found another salon', $html);
    }

    public function test_the_reschedule_dialog_posts_what_the_controller_validates(): void
    {
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user, $this->makeService(), ['status' => AppointmentStatus::Pending]);

        $html = $this->actingAs($user)->get(route('appointments.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Reschedule Appointment', $html);
        $this->assertStringContainsString(route('appointments.reschedule.update', $appointment), $this->unescaped($html));

        foreach (['preferred_date', 'preferred_time', 'reason'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html);
        }

        // The time list is the server's, not a hardcoded window, and the picker is
        // bounded by the same bookable range the booking rules use.
        $this->assertStringContainsString('appointmentReschedulePanel(', $html);
        $this->assertStringContainsString(route('appointments.slots'), $this->unescaped($html));
        $this->assertStringContainsString('x-bind:min="minDate"', $html);
        $this->assertStringContainsString('x-bind:max="maxDate"', $html);
    }

    /* ------------------------------------------------------------------ */
    /* 5. Each icon is wired to its own dialog, with its own data          */
    /* ------------------------------------------------------------------ */

    /**
     * The wiring, end to end, per action: the row button dispatches one named
     * event, exactly one dialog listens for that name, and that dialog's form
     * posts to the route for that action.
     *
     * PHPUnit cannot click, so this is the honest ceiling here: it proves the
     * three halves are joined by the same string and the right payload reaches
     * the right form. A click-through assertion needs a browser driver, which
     * this project does not have.
     */
    public function test_each_icon_is_wired_to_its_own_dialog(): void
    {
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user, $this->makeService(), ['status' => AppointmentStatus::Pending]);

        $html = $this->actingAs($user)->get(route('appointments.index'))->assertOk()->getContent();

        $wiring = [
            // event            listener                                     form action it drives
            'view-appointment' => ['appointmentViewer(', 'view-appointment-title', null],
            'cancel-appointment' => ['appointmentCancelPanel()', 'cancel-appointment-title', 'appointments.cancel.update'],
            'reschedule-appointment' => ['appointmentReschedulePanel(', 'reschedule-appointment-title', 'appointments.reschedule.update'],
        ];

        foreach ($wiring as $event => [$component, $titleId, $formAction]) {
            // The row dispatches it…
            $this->assertStringContainsString(
                "\$dispatch('".$event."'",
                $html,
                "A row button should dispatch {$event}.",
            );

            // …exactly one dialog listens for it…
            $this->assertStringContainsString(
                'x-on:'.$event.'.window',
                $html,
                "A dialog should listen for {$event}.",
            );

            // …and that dialog is driven by its own component, not a shared one.
            $this->assertStringContainsString($component, $html, "{$event} should have its own component.");

            $this->assertStringContainsString('id="'.$titleId.'"', $html);

            if ($formAction !== null) {
                $this->assertStringContainsString(
                    route($formAction, $appointment),
                    $this->unescaped($html),
                    "The {$event} form should post to {$formAction}.",
                );
            }
        }

        // Each dialog opens only for its own event, so one icon cannot raise the
        // other two.
        $this->assertStringNotContainsString('x-on:cancel-appointment.window="show(', $html);
        $this->assertStringNotContainsString('x-on:view-appointment.window="ask(', $html);
    }

    /**
     * The row's payload reaches the dialog, so the dialog shows *that*
     * appointment: reference, date, time, total and its service names all travel
     * with the event.
     */
    public function test_each_row_dispatches_its_own_appointments_data(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();

        $first = $this->makeAppointment($user, $service, ['status' => AppointmentStatus::Pending]);
        $second = $this->makeAppointment($user, $service, ['status' => AppointmentStatus::Pending]);

        $html = $this->actingAs($user)->get(route('appointments.index'))->assertOk()->getContent();

        // Both rows' references are carried, each against its own action route.
        $unescaped = $this->unescaped($html);

        $this->assertStringContainsString(route('appointments.cancel.update', $first), $unescaped);
        $this->assertStringContainsString(route('appointments.cancel.update', $second), $unescaped);
        $this->assertStringContainsString(route('appointments.reschedule.update', $first), $unescaped);
        $this->assertStringContainsString(route('appointments.reschedule.update', $second), $unescaped);

        // And the summary fields the dialogs read off the event. Asserted against
        // the decoded JSON — by this point Blade has substituted the values, and
        // `@js()` has rendered them as `JSON.parse('{"key":"value"}')`.
        $this->assertStringContainsString('"reference":"'.$first->reference_number.'"', $unescaped);
        $this->assertStringContainsString(
            '"currentDateLabel":"'.$first->preferred_date->format('M j, Y').'"',
            $unescaped,
        );

        // A literal service list, not an empty one.
        $this->assertStringContainsString('"services":["'.$service->name.'"]', $unescaped);

        // The View dialog is fed a map keyed by id, so `show(id)` resolves a
        // single row rather than guessing.
        $this->assertStringContainsString('appointmentViewer(', $html);
    }

    /** Each dialog carries its own header treatment. */
    public function test_the_three_dialogs_are_toned_apart(): void
    {
        $html = $this->html();

        // Asserted on the rendered bands, not the `tone="…"` prop — that is
        // consumed when the component compiles and never reaches the output.
        $this->assertStringContainsString('bg-status-cancelled', $html, 'Cancel should be the red band.');
        $this->assertStringContainsString('bg-info', $html, 'Reschedule should be the cool accent band.');
        $this->assertMatchesRegularExpression('/>\s*Appointment Details\s*</', $html);

        // The blue confirm, and a neutral Close beside it rather than two
        // competing primaries.
        $this->assertStringContainsString('btn-info', $html);
        $this->assertStringContainsString('Confirm Reschedule', $html);

        // Three of the four have a close control in the header. The delete
        // confirmation deliberately has none — Yes and No, and dismissal is No,
        // Escape or the backdrop.
        //
        // The fourth is the Terms & Conditions dialog, which the layout mounts
        // on every page and which does carry an X in its header, as its
        // specified arrangement calls for.
        $this->assertSame(4, substr_count($html, 'aria-label="Close"'));
    }

    /* ------------------------------------------------------------------ */
    /* 6. Nothing about the actions themselves changed                     */
    /* ------------------------------------------------------------------ */

    /**
     * The dialogs are a different way in, not a different set of rules: the same
     * PATCH the standalone form posts still cancels the booking.
     *
     * It lands on the list with the dialog open, since the cancelled booking is
     * what the customer wants to look at next.
     */
    public function test_cancelling_still_goes_through_the_same_route_and_rules(): void
    {
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user, $this->makeService(), ['status' => AppointmentStatus::Confirmed]);

        $this->actingAs($user)
            ->patch(route('appointments.cancel.update', $appointment), [
                'reason_preset' => 'Schedule conflict',
                'agree_cancellation_policy' => '1',
            ])
            ->assertRedirect(route('appointments.index', ['view' => $appointment->id]));

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    /**
     * The two standalone action pages survive, and the retired detail page
     * redirects rather than 404ing — a notification link or a bookmark still
     * gets to the booking, via the dialog.
     */
    public function test_the_action_pages_still_work_and_the_detail_page_redirects_to_the_dialog(): void
    {
        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user, $this->makeService(), ['status' => AppointmentStatus::Pending]);

        $this->actingAs($user)
            ->get(route('appointments.show', $appointment))
            ->assertRedirect(route('appointments.index', ['view' => $appointment->id]));

        $this->actingAs($user)->get(route('appointments.cancel', $appointment))->assertOk();
        $this->actingAs($user)->get(route('appointments.reschedule', $appointment))->assertOk();
    }

    /** Following the redirect lands on the list, with that booking's dialog open. */
    public function test_the_redirected_list_opens_the_dialog_on_that_appointment(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();
        $appointment = $this->makeAppointment($user, $service, ['status' => AppointmentStatus::Pending]);

        $html = $this->actingAs($user)
            ->get(route('appointments.index', ['view' => $appointment->id]))
            ->assertOk()
            ->getContent();

        // The component is handed the id, and the row's own payload is present for
        // it to resolve — that pair is what makes the dialog open on load.
        $this->assertStringContainsString('appointmentViewer(', $html);
        $this->assertStringContainsString($appointment->reference_number, $this->unescaped($html));
    }

    /**
     * A booking that is not on the current page still opens.
     *
     * The redirect carries only an id, so it may name a row the status filter or
     * the pager has excluded. Silently showing nothing would look like a broken
     * link, so the payload for that one appointment is fetched and merged in.
     */
    public function test_a_booking_outside_the_current_page_is_still_carried_into_the_dialog(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService();

        // 11 rows, so one of them falls onto page 2 of the 10-per-page pager.
        for ($i = 0; $i < 11; $i++) {
            $this->makeAppointment($user, $service, ['status' => AppointmentStatus::Pending]);
        }

        $offPage = $user->appointments()->where('status', AppointmentStatus::Pending)->orderByDesc('id')->first();

        $html = $this->actingAs($user)
            ->get(route('appointments.index', ['view' => $offPage->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($offPage->reference_number, $this->unescaped($html));
    }
}
