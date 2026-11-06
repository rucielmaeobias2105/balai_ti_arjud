<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Support\PriceFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A service price is an advertised string, not a number.
 *
 * The two halves are `price` ("249/499", "100+") and `base_price`, the figure
 * booking totals are calculated from. These tests pin the split: the display
 * half never loses a character, and the arithmetic half never sees a string.
 */
class ServicePriceFormatsTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ */
    /* The advertised string                                               */
    /* ------------------------------------------------------------------ */

    public function test_the_price_column_stores_a_string_and_base_price_stays_numeric(): void
    {
        $service = $this->makeService(['price' => '249/499']);

        $fresh = $service->fresh();

        // A DECIMAL column would have truncated this to 249.00 on the way in.
        $this->assertSame('249/499', $fresh->price);
        $this->assertIsString($fresh->price);

        // The arithmetic half stays a number.
        $this->assertSame('249.00', $fresh->base_price);

        $this->assertSame(
            '249/499',
            Service::query()->find($service->id)->getRawOriginal('price'),
            'The stored value must be the advertised string, not a parsed number.',
        );
    }

    /**
     * @dataProvider acceptedPrices
     */
    public function test_the_admin_form_accepts_the_price_formats_a_salon_actually_uses(string $price): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.services.store'), [
                'name' => 'Price Format Probe',
                'category' => 'Nail Care',
                'price' => $price,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($price, Service::where('name', 'Price Format Probe')->sole()->price);
    }

    public static function acceptedPrices(): array
    {
        return [
            'plain number' => ['799'],
            'two decimal places' => ['799.50'],
            'starting price' => ['100+'],
            'short starting price' => ['50+'],
            'half and full' => ['249/499'],
            'two options' => ['299/399'],
            'thousands separator' => ['1,200'],
            'peso sign already typed' => ["\u{20B1}499"],
            'spaced range' => ['100 - 250'],
            'peso with decimals' => ["\u{20B1}1,250.00"],
        ];
    }

    /**
     * @dataProvider rejectedPrices
     */
    public function test_the_admin_form_refuses_prices_that_are_not_prices(string $price): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->from(route('admin.services.create'))
            ->post(route('admin.services.store'), [
                'name' => 'Junk Price Probe',
                'category' => 'Nail Care',
                'price' => $price,
            ])
            ->assertRedirect(route('admin.services.create'))
            ->assertSessionHasErrors('price');

        $this->assertSame(0, Service::where('name', 'Junk Price Probe')->count());
    }

    public static function rejectedPrices(): array
    {
        return [
            'letters' => ['expensive'],
            'a bare plus' => ['+'],
            'a bare peso' => ["\u{20B1}"],
            'a bare slash' => ['/'],
            'no digits at all' => ['P onwards'],
            'a script tag' => ['<script>alert(1)</script>1'],
            'sql-ish junk' => ['100; DROP TABLE services'],
            'too long' => [str_repeat('9', 51)],
        ];
    }

    public function test_the_price_field_is_a_text_input_not_a_number_input(): void
    {
        $html = $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.services.create'))
            ->assertOk()
            ->getContent();

        // A number input silently discards "249/499" before the form is even
        // submitted, which is the bug this change is about.
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="price"[^>]*type="text"/s',
            $html,
            'The advertised price must be a text input.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*name="price"[^>]*type="number"/s',
            $html,
        );
    }

    public function test_the_form_has_one_price_field_and_no_second_amount_field(): void
    {
        $html = $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.services.create'))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            preg_match_all('/<input[^>]*name="price"/s', $html),
            'There must be exactly one price field.',
        );

        $this->assertStringNotContainsString(
            'name="base_price"',
            $html,
            'The amount to charge is derived from the price, so it is not asked for.',
        );
        $this->assertStringNotContainsString('Amount To Charge', $html);
    }

    /**
     * The amount a booking is calculated from is the first figure in the price,
     * which is the amount the customer is told the service starts at.
     *
     * @dataProvider derivedAmounts
     */
    public function test_the_amount_is_derived_from_the_price(string $price, ?float $expected): void
    {
        $service = $this->makeService(['price' => $price]);

        $this->assertSame($expected, $service->fresh()->base_price === null
            ? null
            : (float) $service->fresh()->base_price);
    }

    public static function derivedAmounts(): array
    {
        return [
            'plain number' => ['799', 799.0],
            'two decimal places' => ['799.50', 799.5],
            'starting price' => ['100+', 100.0],
            'short starting price' => ['50+', 50.0],
            'half and full' => ['249/499', 249.0],
            'two options' => ['299/399', 299.0],
            'thousands separator' => ['1,200', 1200.0],
            'peso sign' => ["\u{20B1}499", 499.0],
            // No digits at all: nothing to derive, and the booking form then
            // refuses the service rather than charging zero.
            'no digits' => ['ask in store', null],
        ];
    }

    public function test_editing_the_price_re_derives_the_amount(): void
    {
        $service = $this->makeService(['price' => '100+']);
        $this->assertSame(100.0, (float) $service->fresh()->base_price);

        $service->update(['price' => '150+']);
        $this->assertSame(150.0, (float) $service->fresh()->base_price);

        $service->update(['price' => '249/499']);
        $this->assertSame(249.0, (float) $service->fresh()->base_price);
    }

    public function test_a_variant_derives_its_amount_from_its_price(): void
    {
        $service = $this->makeService(['price' => '500+']);

        $variant = ServiceVariant::create([
            'service_id' => $service->id,
            'name' => 'Long Hair',
            'price' => '799/1,299',
        ]);

        $this->assertSame('799/1,299', $variant->fresh()->price);
        $this->assertSame(799.0, (float) $variant->fresh()->base_price);
    }

    /* ------------------------------------------------------------------ */
    /* Display                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * @dataProvider displayCases
     */
    public function test_a_price_is_shown_exactly_as_typed(mixed $price, string $expected): void
    {
        $this->assertSame($expected, PriceFormatter::display($price));
    }

    public static function displayCases(): array
    {
        return [
            ['799', "\u{20B1}799"],
            ['100+', "\u{20B1}100+"],
            ['249/499', "\u{20B1}249/499"],
            ['1,200', "\u{20B1}1,200"],
            // An admin who typed the peso sign must not get two of them.
            ["\u{20B1}499", "\u{20B1}499"],
            ['', 'Price on request'],
            [null, 'Price on request'],
        ];
    }

    public function test_the_services_page_shows_a_range_verbatim(): void
    {
        $this->makeSalonSettings();
        $this->makeService([
            'name' => 'Half or Full',
            'slug' => 'half-or-full',
            'price' => '249/499',
        ]);

        $html = $this->get(route('services.index'))->assertOk()->getContent();

        $this->assertStringContainsString("\u{20B1}249/499", $html);
        $this->assertStringNotContainsString('249.00', $html, 'The advertised string must not be reformatted as a number.');
    }

    public function test_the_service_detail_page_shows_a_starting_price_verbatim(): void
    {
        $this->makeSalonSettings();
        $this->makeService([
            'name' => 'From Price',
            'slug' => 'from-price',
            'price' => '100+',
        ]);

        $this->get(route('services.show', 'from-price'))
            ->assertOk()
            ->assertSee('100+');
    }

    public function test_the_admin_list_shows_the_price_string(): void
    {
        $this->makeService(['price' => '299/399']);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.services.index'))
            ->assertOk()
            ->assertSee('299/399');
    }

    /* ------------------------------------------------------------------ */
    /* Variants                                                             */
    /* ------------------------------------------------------------------ */

    public function test_a_variant_takes_a_price_string_and_an_amount(): void
    {
        $service = $this->makeService(['price' => '500+']);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.services.variants.store', $service), [
                'name' => 'Long Hair',
                'price' => '799/1,299',
            ])
            ->assertSessionHasNoErrors();

        $variant = $service->variants()->sole();

        $this->assertSame('799/1,299', $variant->price);
        $this->assertSame('799.00', $variant->base_price);
    }

    public function test_a_variant_price_rejects_junk(): void
    {
        $service = $this->makeService();

        $this->actingAs($this->makeAdmin(), 'admin')
            ->from(route('admin.services.variants', $service))
            ->post(route('admin.services.variants.store', $service), [
                'name' => 'Long Hair',
                'price' => 'ask in store',
            ])
            ->assertSessionHasErrors('price');

        $this->assertSame(0, $service->variants()->count());
    }

    public function test_variants_are_ordered_by_their_amount_not_their_text(): void
    {
        $service = $this->makeService(['price' => '500']);

        // Inserted out of order, and with text that would sort differently:
        // "1000+" sorts before "99+" lexically, but 1000 is the larger amount.
        ServiceVariant::create(['service_id' => $service->id, 'name' => 'Expensive', 'price' => '1000+']);
        ServiceVariant::create(['service_id' => $service->id, 'name' => 'Cheap', 'price' => '99+']);

        $this->assertSame(
            ['Cheap', 'Expensive'],
            $service->variants()->pluck('name')->all(),
            'Variants must sort by the amount charged, not lexically by the price text.',
        );
    }

    /* ------------------------------------------------------------------ */
    /* Booking totals still use real numbers                                */
    /* ------------------------------------------------------------------ */

    public function test_a_booking_total_comes_from_the_amount_not_the_price_text(): void
    {
        $this->actingAs($this->makeUser());
        $this->makeSalonSettings();

        $service = $this->makeService(['price' => '249/499']);

        $this->post('/book', $this->payload($service, quantity: 2))->assertRedirect();

        $appointment = Appointment::sole();

        // 249 x 2. If the total had been read off "249/499" it would be NaN,
        // and PHP would have turned that into 0.
        $this->assertSame('498.00', (string) $appointment->total_amount);

        $line = $appointment->serviceLines->sole();
        $this->assertSame('249.00', (string) $line->price);
        $this->assertEquals(498.0, $line->line_total);
    }

    public function test_the_price_the_customer_saw_is_snapshotted_onto_the_line(): void
    {
        $this->actingAs($this->makeUser());
        $this->makeSalonSettings();

        $service = $this->makeService(['price' => '249/499']);

        $this->post('/book', $this->payload($service, quantity: 1))->assertRedirect();

        $line = AppointmentService::sole();

        $this->assertSame('249/499', $line->display_price);
        $this->assertSame('249/499', $line->display_price, 'The accessor must return the advertised string.');
    }

    public function test_a_snapshotted_price_survives_a_later_edit_to_the_service(): void
    {
        $this->actingAs($this->makeUser());
        $this->makeSalonSettings();

        $service = $this->makeService(['price' => '249/499']);

        $this->post('/book', $this->payload($service, quantity: 1))->assertRedirect();

        $service->update(['price' => '999']);

        $this->assertSame('249/499', AppointmentService::sole()->fresh()->display_price);
    }

    public function test_a_line_total_uses_the_amount_when_the_advertised_price_is_a_range(): void
    {
        $line = new AppointmentService(['price' => 249, 'display_price' => '249/499', 'quantity' => 3]);

        $this->assertSame(747.0, $line->line_total);
    }

    public function test_a_down_payment_is_a_percentage_of_the_amount(): void
    {
        $this->actingAs($this->makeUser());

        $service = $this->makeService(['price' => '400+']);
        $payload = $this->payload($service, quantity: 2, reference: null);

        // After building the payload: it reaches bookableDate() ->
        // makeSalonSettings(), which resets the flag to the shipping default.
        $this->makeSalonSettings()->update(['down_payment_required' => true, 'down_payment_percentage' => 50]);

        $this->post('/book', $payload)->assertRedirect();

        $appointment = Appointment::sole();

        $this->assertSame('800.00', (string) $appointment->total_amount);
        $this->assertEquals(400.0, (float) $appointment->down_payment_amount);
    }

    /* ------------------------------------------------------------------ */
    /* A price with nothing to derive an amount from is not bookable        */
    /* ------------------------------------------------------------------ */

    public function test_a_service_with_nothing_to_charge_cannot_be_booked(): void
    {
        $this->actingAs($this->makeUser());
        $this->makeSalonSettings();

        // The admin form cannot produce this: the price regex demands a digit.
        // It is still reachable by a direct write or a future import, and the
        // booking form must not quietly turn it into a zero-peso appointment.
        $service = $this->makeService(['price' => 'ask in store']);

        $this->assertNull($service->fresh()->base_price);

        $this->post('/book', $this->payload($service))
            ->assertSessionHasErrors('services');

        $this->assertSame(0, Appointment::count(), 'No appointment may be created without an amount to charge.');
    }

    public function test_a_variant_with_nothing_to_charge_falls_back_to_the_service_amount(): void
    {
        $this->actingAs($this->makeUser());
        $this->makeSalonSettings();

        $service = $this->makeService(['price' => '500']);
        $variant = ServiceVariant::create([
            'service_id' => $service->id,
            'name' => 'Pending',
            'price' => 'ask in store',
        ]);

        $this->assertNull($variant->fresh()->base_price);

        $this->post('/book', $this->payload($service, variantId: $variant->id))->assertRedirect();

        // The variant has no amount of its own, so the parent service's is used
        // rather than booking the line at zero.
        $this->assertSame('500.00', (string) AppointmentService::sole()->price);
    }

    public function test_a_service_with_nothing_to_charge_cannot_enter_the_booking_form(): void
    {
        $this->actingAs($this->makeUser());
        $this->makeSalonSettings();

        $this->makeService(['name' => 'Priced', 'slug' => 'priced', 'price' => '799']);
        $this->makeService(['name' => 'Unpriced', 'slug' => 'unpriced', 'price' => 'ask in store']);

        // Services are chosen on the Browse Services page now, so an unpriced
        // slug in the query string is refused at the door rather than rendered as
        // a row the customer can tick and then cannot book.
        $this->get('/book?services[]=unpriced')
            ->assertRedirect(route('services.index').'#services')
            ->assertSessionHas('toast');

        $html = $this->get('/book?services[]=priced')->assertOk()->getContent();

        $this->assertStringContainsString('Priced', $html);
        $this->assertStringNotContainsString('price on request', $html);
    }

    public function test_a_variant_with_nothing_to_charge_is_left_out_of_the_booking_form(): void
    {
        $this->actingAs($this->makeUser());
        $this->makeSalonSettings();

        $service = $this->makeService(['price' => '500']);
        $service->variants()->create(['name' => 'Short Hair', 'price' => '500']);
        $service->variants()->create(['name' => 'Unpriced Length', 'price' => 'ask in store']);

        $html = $this->get('/book?services[]='.$service->slug)->assertOk()->getContent();

        $this->assertStringContainsString('Short Hair', $html);
        $this->assertStringNotContainsString('Unpriced Length', $html);
    }

    /* ------------------------------------------------------------------ */
    /* Filtering and sorting use the amount                                */
    /* ------------------------------------------------------------------ */

    public function test_the_minimum_price_filter_uses_the_amount(): void
    {
        $this->makeSalonSettings();
        $this->makeService(['name' => 'Cheap', 'category' => 'Nail Care', 'price' => '300+']);
        $this->makeService(['name' => 'Pricey', 'category' => 'Nail Care', 'price' => '5,000']);

        $this->get('/services?min_price=1000')
            ->assertOk()
            ->assertSee('Pricey')
            ->assertDontSee('Cheap');
    }

    public function test_the_admin_price_column_sorts_numerically(): void
    {
        $admin = $this->makeAdmin();
        $this->makeService(['name' => 'Alpha', 'price' => '1000+']);
        $this->makeService(['name' => 'Bravo', 'price' => '99+']);

        $html = $this->actingAs($admin, 'admin')
            ->get('/admin/services?sort=base_price&direction=asc')
            ->assertOk()
            ->getContent();

        // Lexically "1000+" sorts before "99+"; by amount it must not.
        $this->assertLessThan(strpos($html, 'Alpha'), strpos($html, 'Bravo'));
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                              */
    /* ------------------------------------------------------------------ */

    private function payload(
        Service $service,
        int $quantity = 1,
        ?int $variantId = null,
        ?string $reference = 'GCASH1234567890',
    ): array {
        return [
            'services' => [
                ['service_id' => $service->id, 'service_variant_id' => $variantId, 'quantity' => $quantity],
            ],
            'customer_name' => 'Juan Dela Cruz',
            'customer_phone' => '09171234567',
            'preferred_date' => $this->bookableDate(),
            'preferred_time' => '10:00',
            'allergies_other' => 'Latex',
            'last_services_availed_note' => 'Glow Manicure',
            'down_payment_reference' => $reference,
            'agree_terms' => '1',
        ];
    }
}
