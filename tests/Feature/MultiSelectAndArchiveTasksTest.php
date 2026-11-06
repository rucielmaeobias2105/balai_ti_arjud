<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\ChangedBy;
use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\InventoryItem;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiSelectAndArchiveTasksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeSalonSettings();
    }

    /* ------------------------------------------------------------------ */
    /* Task 2: Category Card Multi-Select Checkout                         */
    /* ------------------------------------------------------------------ */

    public function test_services_category_card_renders_select_all_and_product_checkboxes_and_checkout_button(): void
    {
        $available1 = $this->makeService([
            'name' => 'Classic Manicure',
            'slug' => 'classic-manicure',
            'category' => 'Nail Care',
            'price' => 250,
            'base_price' => 250,
        ]);

        $available2 = $this->makeService([
            'name' => 'Gel Pedicure',
            'slug' => 'gel-pedicure',
            'category' => 'Nail Care',
            'price' => 450,
            'base_price' => 450,
        ]);

        // Unavailable service (linked to out-of-stock item)
        $unavailable = $this->makeService([
            'name' => 'Sold Out Nail Art',
            'slug' => 'sold-out-nail-art',
            'category' => 'Nail Care',
            'price' => 600,
            'base_price' => 600,
        ]);
        $item = $this->makeItem([
            'quantity' => 0,
            'manual_unavailable' => true,
        ]);
        $this->linkItemToService($unavailable, $item, 1);

        $html = $this->get(route('services.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-category-select-all', $html);
        $this->assertStringContainsString('Select All', $html);
        $this->assertStringContainsString('data-service-checkbox', $html);
        $this->assertStringContainsString('data-proceed-selected', $html);
        $this->assertStringContainsString('Proceed to Checkout', $html);
        $this->assertStringContainsString('x-bind:disabled="selected.length <= 1"', $html);
        $this->assertStringNotContainsString('Add Selected to Order', $html);

        // Available items have enabled checkboxes; sold out item has disabled checkbox
        $this->assertMatchesRegularExpression(
            '/<input[^>]*data-service-checkbox[^>]*value="'.$available1->slug.'"[^>]*>/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<input[^>]*data-service-checkbox[^>]*value="'.$unavailable->slug.'"[^>]*disabled/s',
            $html,
        );
    }

    public function test_proceeding_to_checkout_with_multiple_selected_services_preselects_only_available_ones(): void
    {
        $user = $this->makeUser();

        $available1 = $this->makeService([
            'name' => 'Classic Manicure',
            'slug' => 'classic-manicure',
            'category' => 'Nail Care',
            'price' => 250,
            'base_price' => 250,
        ]);

        $available2 = $this->makeService([
            'name' => 'Gel Pedicure',
            'slug' => 'gel-pedicure',
            'category' => 'Nail Care',
            'price' => 450,
            'base_price' => 450,
        ]);

        $unavailable = $this->makeService([
            'name' => 'Sold Out Nail Art',
            'slug' => 'sold-out-nail-art',
            'category' => 'Nail Care',
            'price' => 600,
            'base_price' => 600,
        ]);
        $item = $this->makeItem([
            'quantity' => 0,
            'manual_unavailable' => true,
        ]);
        $this->linkItemToService($unavailable, $item, 1);

        $response = $this->actingAs($user)->get(route('appointments.create', [
            'services' => [$available1->slug, $available2->slug, $unavailable->slug],
        ]))->assertOk();

        $selected = $response->viewData('selected');

        $this->assertArrayHasKey($available1->id, $selected);
        $this->assertArrayHasKey($available2->id, $selected);
        $this->assertArrayNotHasKey($unavailable->id, $selected);
        $this->assertSame(700.0, (float) $response->viewData('total'));
    }

    /* ------------------------------------------------------------------ */
    /* Task 3: Auto Archive/Delete Every 30 Days                           */
    /* ------------------------------------------------------------------ */

    public function test_archive_old_records_command_is_scheduled_daily(): void
    {
        $events = collect(Schedule::events())->filter(
            fn ($event) => str_contains($event->command ?? '', 'records:cleanup')
        );

        $this->assertCount(1, $events);
        $this->assertSame('0 2 * * *', $events->first()->expression);
    }

    public function test_archive_old_records_command_deletes_old_notifications_and_archives_appointments_and_logs_without_breaking_reports(): void
    {
        $user = $this->makeUser();
        $service = $this->makeService(['price' => 850, 'base_price' => 850]);

        // 1. Old notification (35 days old) vs Recent notification (5 days old)
        $oldNotifId = (string) Str::uuid();
        $recentNotifId = (string) Str::uuid();

        DatabaseNotification::query()->insert([
            [
                'id' => $oldNotifId,
                'type' => 'App\\Notifications\\AppointmentStatusNotification',
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->id,
                'data' => json_encode(['title' => 'Old']),
                'read_at' => null,
                'created_at' => now()->subDays(35),
                'updated_at' => now()->subDays(35),
            ],
            [
                'id' => $recentNotifId,
                'type' => 'App\\Notifications\\AppointmentStatusNotification',
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->id,
                'data' => json_encode(['title' => 'Recent']),
                'read_at' => null,
                'created_at' => now()->subDays(5),
                'updated_at' => now()->subDays(5),
            ],
        ]);

        // 2. Old completed appointment (40 days ago) vs Recent completed appointment (5 days ago)
        $oldAppointment = $this->makeAppointment($user, $service, [
            'status' => AppointmentStatus::Completed,
            'preferred_date' => today()->subDays(40),
            'completed_at' => now()->subDays(40),
            'total_amount' => 850,
        ]);

        $recentAppointment = $this->makeAppointment($user, $service, [
            'status' => AppointmentStatus::Completed,
            'preferred_date' => today()->subDays(5),
            'completed_at' => now()->subDays(5),
            'total_amount' => 850,
        ]);

        // 3. Old status history log (40 days old) vs Recent status history log (5 days old)
        $oldLog = AppointmentStatusHistory::query()->create([
            'appointment_id' => $oldAppointment->id,
            'from_status' => AppointmentStatus::Confirmed,
            'to_status' => AppointmentStatus::Completed,
            'changed_by' => ChangedBy::Admin,
            'note' => 'Completed 40 days ago',
        ]);
        AppointmentStatusHistory::query()->whereKey($oldLog->id)->update([
            'created_at' => now()->subDays(40),
            'updated_at' => now()->subDays(40),
        ]);

        $recentLog = AppointmentStatusHistory::query()->create([
            'appointment_id' => $recentAppointment->id,
            'from_status' => AppointmentStatus::Confirmed,
            'to_status' => AppointmentStatus::Completed,
            'changed_by' => ChangedBy::Admin,
            'note' => 'Completed 5 days ago',
        ]);

        // Verify reports total before running archive command
        $reportService = app(ReportService::class);
        $reportBefore = $reportService->totalsByPeriod('daily', today()->subDays(60), today());

        $this->artisan('records:cleanup')
            ->expectsOutputToContain('1 notifications deleted, 1 appointments archived, 1 activity logs archived')
            ->assertSuccessful();

        // Old notification deleted, recent notification kept
        $this->assertDatabaseMissing('notifications', ['id' => $oldNotifId]);
        $this->assertDatabaseHas('notifications', ['id' => $recentNotifId]);

        // Old appointment archived, recent appointment still active
        $this->assertNotNull($oldAppointment->fresh()->archived_at);
        $this->assertNull($recentAppointment->fresh()->archived_at);

        // Old activity log archived (hidden from active statusHistory relation, still in DB)
        $this->assertNotNull($oldLog->fresh()->archived_at);
        $this->assertNull($recentLog->fresh()->archived_at);
        $this->assertCount(0, $oldAppointment->fresh()->statusHistory);
        $this->assertCount(1, $recentAppointment->fresh()->statusHistory);

        // Reports revenue and totals are unbroken!
        $reportAfter = $reportService->totalsByPeriod('daily', today()->subDays(60), today());
        $this->assertSame($reportBefore->sum('revenue'), $reportAfter->sum('revenue'));
        $this->assertSame($reportBefore->sum('bookings'), $reportAfter->sum('bookings'));
        $this->assertSame(1700.0, (float) $reportAfter->sum('revenue'));
    }
}
