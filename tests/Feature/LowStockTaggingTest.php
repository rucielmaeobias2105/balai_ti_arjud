<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\ItemTag;
use App\Models\InventoryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LowStockTaggingTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ */
    /* The rule itself                                                     */
    /* ------------------------------------------------------------------ */

    public function test_quantity_at_or_below_the_threshold_is_low_stock(): void
    {
        $equal = $this->makeItem(['quantity' => 5, 'reorder_threshold' => 5]);
        $below = $this->makeItem(['quantity' => 2, 'reorder_threshold' => 5]);
        $above = $this->makeItem(['quantity' => 6, 'reorder_threshold' => 5]);

        $this->assertTrue($equal->isLowOnStock());
        $this->assertTrue($below->isLowOnStock());
        $this->assertFalse($above->isLowOnStock());

        $this->assertSame(ItemTag::LowStock, $equal->suggestedTag());
        $this->assertSame(ItemTag::LowStock, $below->suggestedTag());
        $this->assertSame(ItemTag::Available, $above->suggestedTag());
    }

    public function test_zero_quantity_suggests_sold_out(): void
    {
        $item = $this->makeItem(['quantity' => 0, 'reorder_threshold' => 5]);

        $this->assertTrue($item->isSoldOut());
        $this->assertSame(ItemTag::SoldOut, $item->suggestedTag());
    }

    public function test_sync_status_tag_derives_the_tag_from_quantity(): void
    {
        $item = $this->makeItem(['quantity' => 20, 'reorder_threshold' => 5]);

        $item->syncStatusTag();
        $this->assertSame(ItemTag::Available, $item->fresh()->status_tag);

        $item->update(['quantity' => 3]);
        $item->syncStatusTag();
        $this->assertSame(ItemTag::LowStock, $item->fresh()->status_tag);

        $item->update(['quantity' => 0]);
        $item->syncStatusTag();
        $this->assertSame(ItemTag::SoldOut, $item->fresh()->status_tag);
    }

    public function test_sync_status_tag_preserves_a_best_seller_tag(): void
    {
        $item = $this->makeItem([
            'quantity' => 20,
            'reorder_threshold' => 5,
            'status_tag' => ItemTag::BestSeller,
        ]);

        $item->syncStatusTag();
        $this->assertSame(ItemTag::BestSeller, $item->fresh()->status_tag);

        // ...but not once it actually runs out.
        $item->update(['quantity' => 0]);
        $item->syncStatusTag();
        $this->assertSame(ItemTag::SoldOut, $item->fresh()->status_tag);
    }

    public function test_has_manual_override_detects_a_tag_that_contradicts_the_quantity(): void
    {
        $lowButTaggedAvailable = $this->makeItem([
            'quantity' => 2, 'reorder_threshold' => 5, 'status_tag' => ItemTag::Available,
        ]);
        $healthyButTaggedSoldOut = $this->makeItem([
            'quantity' => 50, 'reorder_threshold' => 5, 'status_tag' => ItemTag::SoldOut,
        ]);
        $matching = $this->makeItem([
            'quantity' => 2, 'reorder_threshold' => 5, 'status_tag' => ItemTag::LowStock,
        ]);

        $this->assertTrue($lowButTaggedAvailable->hasManualOverride());
        $this->assertTrue($healthyButTaggedSoldOut->hasManualOverride());
        $this->assertFalse($matching->hasManualOverride());
    }

    public function test_the_low_stock_scope_matches_the_threshold_rule(): void
    {
        $this->makeItem(['quantity' => 5, 'reorder_threshold' => 5]);
        $this->makeItem(['quantity' => 1, 'reorder_threshold' => 5]);
        $this->makeItem(['quantity' => 50, 'reorder_threshold' => 5]);

        $this->assertSame(2, InventoryItem::lowStock()->count());
    }

    /* ------------------------------------------------------------------ */
    /* Admin tagging screen                                                */
    /* ------------------------------------------------------------------ */

    public function test_the_tagging_screen_renders(): void
    {
        $this->makeItem(['quantity' => 1, 'reorder_threshold' => 5]);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.tags.index'))
            ->assertOk()
            ->assertSee('Low-Stock &amp; Availability Tags', false)
            ->assertSee('Suggested');
    }

    public function test_an_admin_can_assign_a_tag_to_an_item(): void
    {
        $admin = $this->makeAdmin();
        $item = $this->makeItem(['quantity' => 50, 'reorder_threshold' => 5]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.tags.index'))
            ->patch(route('admin.tags.update', $item), ['status_tag' => ItemTag::SoldOut->value])
            ->assertRedirect(route('admin.tags.index'))
            ->assertSessionHas('status');

        $this->assertSame(ItemTag::SoldOut, $item->fresh()->status_tag);
        $this->assertTrue($item->fresh()->hasManualOverride());
    }

    public function test_an_admin_can_assign_a_tag_in_bulk(): void
    {
        $admin = $this->makeAdmin();

        $items = collect(range(1, 3))->map(fn () => $this->makeItem([
            'quantity' => 50, 'reorder_threshold' => 5, 'status_tag' => ItemTag::Available,
        ]));

        $this->actingAs($admin, 'admin')
            ->from(route('admin.tags.index'))
            ->patch(route('admin.tags.bulk'), [
                'status_tag' => ItemTag::BestSeller->value,
                'items' => $items->pluck('id')->all(),
            ])
            ->assertSessionHas('status');

        $this->assertSame(3, InventoryItem::where('status_tag', ItemTag::BestSeller->value)->count());
    }

    public function test_bulk_tagging_requires_at_least_one_item(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->patch(route('admin.tags.bulk'), [
                'status_tag' => ItemTag::SoldOut->value,
                'items' => [],
            ])
            ->assertSessionHasErrors('items');
    }

    public function test_an_unknown_tag_is_rejected(): void
    {
        $item = $this->makeItem();

        $this->actingAs($this->makeAdmin(), 'admin')
            ->patch(route('admin.tags.update', $item), ['status_tag' => 'discontinued'])
            ->assertSessionHasErrors('status_tag');

        $this->assertSame(ItemTag::Available, $item->fresh()->status_tag);
    }

    public function test_guests_cannot_reach_the_tagging_screen(): void
    {
        $this->get(route('admin.tags.index'))->assertRedirect(route('login'));
    }

    /* ------------------------------------------------------------------ */
    /* Auto flagging when a service is booked                              */
    /* ------------------------------------------------------------------ */

    public function test_booking_a_service_decrements_linked_items_and_flags_low_stock(): void
    {
        $service = $this->makeService(['price' => 500]);
        $item = $this->makeItem(['quantity' => 3, 'reorder_threshold' => 5, 'status_tag' => ItemTag::Available]);

        $this->linkItemToService($service, $item, 1);

        $this->actingAs($this->makeUser())->post('/book', [
            'services' => [['service_id' => $service->id, 'quantity' => 1]],
            'customer_name' => 'Juan Dela Cruz',
            'customer_phone' => '09171234567',
            'preferred_date' => $this->bookableDate(),
            'preferred_time' => '10:00',
            // Step 4 asks a first-timer what they had done before.
            'last_services_availed_note' => 'Glow Manicure',
            'down_payment_reference' => 'GCASH1234567890',
            'agree_terms' => '1',
        ])->assertSessionHasNoErrors();

        $item->refresh();

        $this->assertEquals(2, (float) $item->quantity);
        $this->assertSame(ItemTag::LowStock, $item->status_tag);
    }

    public function test_booking_depletes_a_linked_item_to_sold_out(): void
    {
        $service = $this->makeService(['price' => 500]);
        $item = $this->makeItem(['quantity' => 1, 'reorder_threshold' => 0, 'status_tag' => ItemTag::Available]);

        $this->linkItemToService($service, $item, 1);

        $this->actingAs($this->makeUser())->post('/book', [
            'services' => [['service_id' => $service->id, 'quantity' => 1]],
            'customer_name' => 'Juan Dela Cruz',
            'customer_phone' => '09171234567',
            'preferred_date' => $this->bookableDate(),
            'preferred_time' => '10:00',
            // Step 4 asks a first-timer what they had done before.
            'last_services_availed_note' => 'Glow Manicure',
            'down_payment_reference' => 'GCASH1234567890',
            'agree_terms' => '1',
        ])->assertSessionHasNoErrors();

        $item->refresh();

        $this->assertEquals(0, (float) $item->quantity);
        $this->assertSame(ItemTag::SoldOut, $item->status_tag);
    }

    public function test_cancelling_an_appointment_returns_the_stock(): void
    {
        $service = $this->makeService(['price' => 500]);
        $item = $this->makeItem(['quantity' => 10, 'reorder_threshold' => 5, 'status_tag' => ItemTag::Available]);

        $this->linkItemToService($service, $item, 2);

        $user = $this->makeUser();
        $appointment = $this->makeAppointment($user, $service);

        // The booking consumed 2 units.
        $item->update(['quantity' => 8]);
        $item->syncStatusTag();

        $this->actingAs($user)
            ->patch(route('appointments.cancel.update', $appointment), [
                'agree_cancellation_policy' => '1',
            ]);

        $item->refresh();

        $this->assertEquals(10, (float) $item->quantity);
    }

    /**
 * The low-stock count is still surfaced — on the sidebar's Inventory badge — but
 * not as a dashboard stat card any more.
 *
     * The card was a second copy of a figure the sidebar already shows on every
     * page, so it went; the alert it represented did not. Asserted both ways so
     * neither half can drift: the dashboard must not carry the card, and the
     * badge must still carry the count.
     */
    public function test_the_low_stock_count_is_on_the_sidebar_badge_not_a_dashboard_card(): void
    {
        $this->makeItem(['quantity' => 1, 'reorder_threshold' => 5]);
        $this->makeItem(['quantity' => 2, 'reorder_threshold' => 5]);
        $this->makeItem(['quantity' => 99, 'reorder_threshold' => 5]);

        $admin = $this->makeAdmin();

        $dashboard = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Low Stock Alerts', $dashboard);

        // Gone from the service too, so the query is not merely unreferenced. Matched
        // on the assignment rather than the bare name, because the comment
        // explaining the removal still names the key.
        $this->assertDoesNotMatchRegularExpression(
            "/'low_stock_count'\s*=>/",
            (string) file_get_contents(app_path('Services/ReportService.php')),
            'The summary should no longer compute a count nothing reads.',
        );

        // And the badge still reports it.
        $this->actingAs($admin, 'admin')
            ->get(route('admin.inventory.index'))
            ->assertOk()
            ->assertSee('Inventory');
    }

    public function test_an_admin_can_clear_a_tag_by_choosing_available(): void
    {
        $admin = $this->makeAdmin();
        $item = $this->makeItem(['quantity' => 1, 'reorder_threshold' => 5, 'status_tag' => ItemTag::LowStock]);

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.tags.update', $item), ['status_tag' => ItemTag::Available->value]);

        $this->assertSame(ItemTag::Available, $item->fresh()->status_tag);
    }
}
