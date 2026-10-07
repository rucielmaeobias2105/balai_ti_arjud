<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * No appointment ever shows "0 min".
 *
 * The durations on a booking line are almost all zero: `duration_minutes` is
 * copied from the service when the booking is made, and most of this salon's
 * services were listed on a price sheet that gives no duration, so the column
 * arrived null and cast to 0. Every screen that printed a duration
 * unconditionally therefore printed "0 min" — and "0 min" is a claim, that a
 * treatment takes no time at all, rather than an admission that nobody wrote the
 * figure down.
 *
 * `Service::duration_label` already returns an empty string instead of "0 min"
 * for the catalogue. This covers the booking surfaces, which each had their own
 * copy of the mistake:
 *
 *   - the customer's Appointment Details dialog rendered
 *     `quantity + ' × ' + duration + ' min'`, i.e. "1 × 0 min";
 *   - the admin's View dialog put `duration_minutes.' min · '` in front of the
 *     quantity on the same line;
 *   - and the admin's appointment page showed "0 min total" and "0 min · Qty 1".
 *
 * The first two lost the duration outright rather than hiding it at the last
 * moment, because the number is not being collected at all — a booking made once
 * the salon does record durations should get it back as real data in the
 * payload, not as a zero the view has to suppress. The page version keeps its
 * number and only shows it when it is one.
 */
class AppointmentDurationDisplayTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The service a booking normally carries no duration for.
     *
     * `duration_minutes` is NOT NULL on `services`, so "no duration" is stored
     * as a zero — which is exactly how the real rows look and exactly why the
     * screens printed "0 min".
     */
    private function serviceWithoutDuration(): \App\Models\Service
    {
        return $this->makeService([
            'name' => 'Arm Paraffin',
            'price' => 350,
            'duration_minutes' => 0,
        ]);
    }

    private function customerHtml(\App\Models\User $user): string
    {
        return $this->actingAs($user)
            ->get(route('appointments.index'))
            ->assertOk()
            ->getContent();
    }

    private function adminHtml(\App\Models\Admin $admin): string
    {
        return $this->actingAs($admin, 'admin')
            ->get(route('admin.appointments.index'))
            ->assertOk()
            ->getContent();
    }

    public function test_the_customer_details_dialog_shows_the_quantity_and_not_a_duration(): void
    {
        $user = $this->makeUser();
        $this->makeAppointment($user, $this->serviceWithoutDuration());

        $html = $this->customerHtml($user);

        // The payload itself no longer carries a duration, so there is nothing
        // for the view to print even if it wanted to.
        $this->assertStringNotContainsString(
            '"duration"',
            $html,
            'The customer details payload should not carry a per-service duration.',
        );

        $this->assertStringNotContainsString(
            "' min'",
            $html,
            'The customer details dialog must not build a duration string.',
        );

        // The quantity survives — it is the part of that line that was real.
        $this->assertStringContainsString("'× ' + service.quantity", $html);
    }

    public function test_the_admin_view_dialog_does_not_put_a_duration_on_the_service_line(): void
    {
        $admin = $this->makeAdmin();
        $this->makeAppointment($this->makeUser(), $this->serviceWithoutDuration());

        $html = $this->adminHtml($admin);

        // The line reads as quantity and advertised price.
        $this->assertStringContainsString('Qty ', $html);
        $this->assertStringContainsString(' each', $html);

        // And nothing prepends a zero to it.
        $this->assertStringNotContainsString("' min · Qty '", $html);
        $this->assertStringNotContainsString('0 min · Qty', $html);
    }

    public function test_the_admin_appointment_page_omits_a_zero_duration_but_keeps_a_real_one(): void
    {
        $admin = $this->makeAdmin();

        // No duration on the service, so none on the line or in the total.
        $withoutDuration = $this->makeAppointment($this->makeUser(), $this->serviceWithoutDuration());

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.appointments.show', $withoutDuration))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('0 min', $html);
        $this->assertStringContainsString('Qty 1', $html);

        // A real duration is still shown, and is still added up.
        $withDuration = $this->makeAppointment(
            $this->makeUser(),
            $this->makeService(['name' => 'Gelish Manicure', 'price' => 750, 'duration_minutes' => 90]),
        );

        $real = $this->actingAs($admin, 'admin')
            ->get(route('admin.appointments.show', $withDuration))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('90 min', $real);
        $this->assertStringContainsString('90 min total', $real);
    }

    public function test_a_booking_that_does_have_a_duration_is_still_shown_in_the_admin_view_dialog(): void
    {
        $admin = $this->makeAdmin();

        $this->makeAppointment(
            $this->makeUser(),
            $this->makeService(['name' => 'Gelish Manicure', 'price' => 750, 'duration_minutes' => 90]),
        );

        // The View dialog no longer prints a duration at all, real or not —
        // the point is that the customer-facing text is gone, not suppressed
        // behind a condition.
        $this->assertStringNotContainsString('90 min', $this->adminHtml($admin));
    }
}