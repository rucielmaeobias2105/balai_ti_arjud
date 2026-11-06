<?php

namespace Tests;

use App\Enums\AdminRole;
use App\Enums\AppointmentStatus;
use App\Enums\DownPaymentStatus;
use App\Enums\ItemTag;
use App\Models\Admin;
use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\InventoryItem;
use App\Models\SalonSetting;
use App\Models\Service;
use App\Models\User;
use App\Support\PriceFormatter;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /* ------------------------------------------------------------------ */
    /* Factories */
    /* ------------------------------------------------------------------ */

    protected function makeUser(array $attributes = []): User
    {
        static::$sequence++;

        return User::create(array_merge([
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'email' => 'customer'.static::$sequence.'@example.test',
            'username' => 'customer'.static::$sequence,
            'contact_number' => '09171234567',
            'password' => 'password',
            'is_active' => true,
        ], $attributes));
    }

    protected function makeAdmin(array $attributes = []): Admin
    {
        static::$sequence++;

        return Admin::create(array_merge([
            'first_name' => 'Admin',
            'last_name' => 'Member',
            'username' => 'admin'.static::$sequence,
            'email' => 'admin'.static::$sequence.'@example.test',
            'password' => 'password',
            'role' => AdminRole::Admin,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * A service that can actually be booked.
     *
     * `base_price` mirrors `price` unless a test says otherwise, so a test that
     * says `['price' => 600]` still books at 600. A test that wants the
     * advertised price and the charge to differ passes both; a test that wants
     * an unbookable service passes `'base_price' => null`.
     */
    /**
     * A `/book` URL with a bookable service in the query string.
     *
     * Services are chosen on the Browse Services page now. A bare `/book` opens
     * no form and redirects back to the catalogue, so tests that need the wizard
     * to render pass a basket the same way the real "Proceed to Checkout" button
     * does.
     */
    protected function bookingUrl(?Service $service = null): string
    {
        $service ??= Service::query()
            ->where('is_active', true)
            ->whereNotNull('base_price')
            ->first() ?? $this->makeService();

        return route('appointments.create', ['services' => [$service->slug]]);
    }

    protected function makeService(array $attributes = []): Service
    {
        static::$sequence++;

        $defaults = [
            'name' => 'Test Service '.static::$sequence,
            'slug' => 'test-service-'.static::$sequence,
            'category' => 'Nail Care',
            'price' => 500,
            'duration_minutes' => 60,
            'description' => 'A service used in tests.',
            'is_active' => true,
        ];

        $merged = array_merge($defaults, $attributes);

        // Taken from the merged price, not the default one: a test that says
        // ['price' => 900] has to book at 900, and a list sorted by price has to
        // have three different amounts to sort.
        if (! array_key_exists('base_price', $attributes)) {
            $merged['base_price'] = PriceFormatter::firstFigure($merged['price']);
        }

        return Service::create($merged);
    }

    protected function makeItem(array $attributes = []): InventoryItem
    {
        static::$sequence++;

        return InventoryItem::create(array_merge([
            'name' => 'Test Item '.static::$sequence,
            'sku' => 'TEST-'.static::$sequence,
            'category' => 'Nail Care',
            'quantity' => 20,
            'unit' => 'pcs',
            'reorder_threshold' => 5,
            'status_tag' => ItemTag::Available,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * A confirmed, bookable appointment with one service line.
     */
    protected function makeAppointment(
        ?User $user = null,
        ?Service $service = null,
        array $attributes = [],
    ): Appointment {
        $service ??= $this->makeService();

        $appointment = Appointment::create(array_merge([
            'reference_number' => Appointment::generateReferenceNumber(),
            'user_id' => $user?->id,
            'customer_name' => $user?->full_name ?? 'Walk In',
            'customer_phone' => '09171234567',
            'customer_email' => $user?->email,
            'preferred_date' => $this->bookableDate(1),
            'preferred_time' => '10:00',
            'total_amount' => (float) $service->price,
            'down_payment_status' => DownPaymentStatus::Unverified,
            'status' => AppointmentStatus::Pending,
        ], $attributes));

        AppointmentService::create([
            'appointment_id' => $appointment->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'price' => (float) $service->price,
            'duration_minutes' => (int) $service->duration_minutes,
            'quantity' => 1,
        ]);

        return $appointment->fresh();
    }

    /**
     * Operating config with a predictable window: open 09:00–18:00 daily.
     */
    protected function makeSalonSettings(): SalonSetting
    {
        $hours = [];

        foreach (array_keys(SalonSetting::dayNames()) as $day) {
            $hours[$day] = ['09:00', '18:00'];
        }

        // `down_payment_required` is false here to match
        // `SalonSetting::current()`'s default. It was forced true while the
        // booking form still asked for a GCash reference; leaving it set would
        // have run every booking test against a configuration the app no longer
        // ships, and `BookingService` would compute a deposit amount for bookings
        // that never took one. A test that needs the old behaviour turns it on
        // itself, explicitly.
        $settings = SalonSetting::first();

        if ($settings) {
            $settings->update([
                'operating_hours' => $hours,
                'slot_interval_minutes' => 60,
                'booking_lead_days' => 30,
                'down_payment_required' => false,
                'down_payment_percentage' => 50,
            ]);

            return $settings->fresh();
        }

        return SalonSetting::create([
            'operating_hours' => $hours,
            'slot_interval_minutes' => 60,
            'booking_lead_days' => 30,
            'down_payment_required' => false,
            'down_payment_percentage' => 50,
        ]);
    }

    /**
     * First open date at least `offset` days out, so tests never collide with
     * a blocked range left behind by a previous case.
     */
    protected function bookableDate(int $offset = 3): string
    {
        $settings = $this->makeSalonSettings();

        $date = today()->addDays($offset);

        while (! $settings->isOpenOn($date)) {
            $date->addDay();
        }

        return $date->toDateString();
    }

    protected function linkItemToService(Service $service, InventoryItem $item, float $perService = 1): void
    {
        $service->inventoryItems()->syncWithoutDetaching([
            $item->id => ['quantity_per_service' => $perService],
        ]);
    }

    protected static int $sequence = 0;
}
