<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\ItemTag;
use App\Models\InventoryItem;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reports: the date range is the only filter, and both reports export.
 *
 * Two things changed and each can fail on its own, so they are tested apart.
 *
 * The Period dropdown is gone. It said "Daily / Weekly / Monthly" and only ever
 * decided how the rows were grouped, which Start Date and End Date already
 * determine — and the two could contradict each other, since "Monthly" over three
 * days gave one bucket that read like a whole month. The grouping is derived now,
 * and the tests below pin that it still respects the dates.
 *
 * Both reports export as CSV. Sales already did; Inventory did not, and an export
 * that only exists for one of two reports is how a report goes unused.
 */
class ReportsExportTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        return $this->makeAdmin();
    }

    private function revenueOn(string $date, float $amount, string $status = 'confirmed'): void
    {
        $this->makeAppointment($this->makeUser(), null, [
            'status' => $status,
            'preferred_date' => $date,
            'total_amount' => $amount,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* 1. The date range still drives the report                           */
    /* ------------------------------------------------------------------ */

    public function test_the_report_respects_the_start_and_end_dates(): void
    {
        $this->admin();

        $inRange = today()->subDays(5);
        $tooEarly = today()->subDays(30);
        $tooLate = today()->addDays(10);

        $this->revenueOn($inRange, 1000);
        $this->revenueOn($tooEarly, 5000);
        $this->revenueOn($tooLate, 9000);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.reports.index', [
                'from' => $inRange->toDateString(),
                'to' => $inRange->toDateString(),
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('1,000.00', $html, 'The booking inside the range should be counted.');
        $this->assertStringNotContainsString('5,000.00', $html, 'A booking before the range must not be.');
        $this->assertStringNotContainsString('9,000.00', $html, 'A booking after the range must not be.');
    }

    public function test_a_wider_range_rolls_two_bookings_into_one_total(): void
    {
        $this->revenueOn(today()->subDays(3), 1000);
        $this->revenueOn(today()->subDays(4), 2500);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.reports.index', [
                'from' => today()->subDays(6)->toDateString(),
                'to' => today()->toDateString(),
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('3,500.00', $html);
    }

    /** An empty range is an empty report, not an error and not a fallback to "all time". */
    public function test_a_range_with_nothing_in_it_totals_zero(): void
    {
        $this->revenueOn(today()->subDay(), 4000);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.reports.index', [
                'from' => today()->subDays(90)->toDateString(),
                'to' => today()->subDays(80)->toDateString(),
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No revenue recorded in this range.', $html);
    }

    /* ------------------------------------------------------------------ */
    /* 2. The grouping is derived, and says which one is in use            */
    /* ------------------------------------------------------------------ */

    /** A short range buckets by day, and the header says so. */
    public function test_a_short_range_is_grouped_by_day(): void
    {
        $this->revenueOn(today()->subDays(2), 500);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.reports.index', [
                'from' => today()->subDays(6)->toDateString(),
                'to' => today()->toDateString(),
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('grouped by day', $html);
        $this->assertStringContainsString('<th>Day</th>', $html, false);
    }

    /**
     * A long range buckets by month rather than producing hundreds of day rows.
     *
     * Fourteen months, which is past the 400-day weekly threshold — not six, which
     * is weekly. The span is deliberate: it has to clear the threshold being
     * tested, and picking a shorter one would quietly assert the weekly bucket
     * instead.
     */
    public function test_a_long_range_is_grouped_by_month(): void
    {
        $this->revenueOn(today()->subMonths(14), 800);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.reports.index', [
                'from' => today()->subMonths(15)->toDateString(),
                'to' => today()->toDateString(),
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('grouped by month', $html);
        $this->assertStringContainsString('<th>Month</th>', $html, false);
    }

    /**
     * The thresholds, asserted directly.
     *
     * These are a readability decision rather than arithmetic, so they are pinned
     * explicitly: a change from 62 to 90 is a deliberate edit to how readable the
     * table is, not a silent drift.
     */
    public function test_the_grouping_thresholds(): void
    {
        $service = app(ReportService::class);

        $this->assertSame('daily', $service->granularityFor(
            now()->subDays(6)->startOfDay(),
            now()->endOfDay(),
        ));

        $this->assertSame('weekly', $service->granularityFor(
            now()->subDays(120)->startOfDay(),
            now()->endOfDay(),
        ));

        $this->assertSame('monthly', $service->granularityFor(
            now()->subMonths(14)->startOfDay(),
            now()->endOfDay(),
        ));
    }

    /** A range typed backwards is swapped rather than refused. */
    public function test_a_backwards_range_is_swapped_not_rejected(): void
    {
        $this->revenueOn(today()->subDay(), 1200);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.reports.index', [
                'from' => today()->toDateString(),
                'to' => today()->subDays(5)->toDateString(),
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('1,200.00', $html);
    }

    /* ------------------------------------------------------------------ */
    /* 3. Sales CSV export                                                 */
    /* ------------------------------------------------------------------ */

    public function test_the_sales_export_returns_a_csv_file(): void
    {
        $this->revenueOn(today()->subDay(), 1750);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.reports.export', [
                'from' => today()->subDays(6)->toDateString(),
                'to' => today()->toDateString(),
            ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('bta-sales-report-', $response->headers->get('Content-Disposition'));
    }

    public function test_the_sales_export_carries_the_same_figures_as_the_screen(): void
    {
        $this->revenueOn(today()->subDay(), 1750);
        $this->revenueOn(today()->subDays(2), 250);

        $params = [
            'from' => today()->subDays(6)->toDateString(),
            'to' => today()->toDateString(),
        ];

        $csv = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.reports.export', $params))
            ->streamedContent();

        $this->assertStringContainsString('2,000.00', $csv, 'The total should match the screen.');
        $this->assertStringContainsString('Balai ti Arjud', $csv);
    }

    /** The export honours the dates too — a stale figure in a file is worse than none. */
    public function test_the_sales_export_respects_the_date_range(): void
    {
        $this->revenueOn(today()->subDay(), 1750);
        $this->revenueOn(today()->subDays(60), 9999);

        $csv = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.reports.export', [
                'from' => today()->subDays(6)->toDateString(),
                'to' => today()->toDateString(),
            ]))
            ->streamedContent();

        $this->assertStringContainsString('1,750.00', $csv);
        $this->assertStringNotContainsString('9,999.00', $csv);
    }

    /* ------------------------------------------------------------------ */
    /* 4. Inventory CSV export                                             */
    /* ------------------------------------------------------------------ */

    private function item(array $attributes = []): InventoryItem
    {
        return InventoryItem::create(array_merge([
            'name' => 'Gel Polish',
            'category' => 'Nail Care',
            'quantity' => 10,
            'unit' => 'pcs',
            'reorder_threshold' => 2,
            'status_tag' => ItemTag::Available,
            'is_active' => true,
        ], $attributes));
    }

    public function test_the_inventory_export_returns_a_csv_file(): void
    {
        $this->item();

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.inventory.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('bta-inventory-report-', $response->headers->get('Content-Disposition'));
    }

    public function test_the_inventory_export_lists_every_item(): void
    {
        $this->item(['name' => 'Gel Polish', 'quantity' => 12]);
        $this->item(['name' => 'Base Coat', 'quantity' => 4, 'unit' => 'bottles']);

        $csv = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.inventory.export'))
            ->streamedContent();

        $this->assertStringContainsString('Gel Polish', $csv);
        $this->assertStringContainsString('Base Coat', $csv);
        $this->assertStringContainsString('12', $csv);
    }

    /** A deleted item is not in the salon's stock, so it is not in the file either. */
    public function test_the_inventory_export_excludes_deleted_items(): void
    {
        $this->item(['name' => 'Kept Polish']);
        $this->item(['name' => 'Retired Polish'])->delete();

        $csv = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.inventory.export'))
            ->streamedContent();

        $this->assertStringContainsString('Kept Polish', $csv);
        $this->assertStringNotContainsString('Retired Polish', $csv);
    }

    /** The search filter carries into the file, so it is the list an admin saw. */
    public function test_the_inventory_export_honours_the_search(): void
    {
        $this->item(['name' => 'Gel Polish']);
        $this->item(['name' => 'Base Coat']);

        $csv = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.inventory.export', ['search' => 'Base']))
            ->streamedContent();

        $this->assertStringContainsString('Base Coat', $csv);
        $this->assertStringNotContainsString('Gel Polish', $csv);
    }

    /**
     * The Inventory screen offers the export too, not only the Reports screen.
     *
     * An admin reading the stock list and pressing Export should get a file, not
     * have to know it lives on another screen. Asserted on the link carrying the
     * current search, since that is what makes the file match the list.
     */
    public function test_the_inventory_list_offers_its_own_export(): void
    {
        $this->item(['name' => 'Gel Polish']);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.inventory.index', ['search' => 'Gel']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Export Inventory CSV', $html);
        $this->assertStringContainsString(
            e(route('admin.inventory.export', ['search' => 'Gel'])),
            $html,
            'The export link should carry the search box.',
        );
    }

    /** Low and sold-out counts, because that is what a stock file is opened for. */
    public function test_the_inventory_export_summarises_the_stock_health(): void
    {
        $this->item(['name' => 'Plenty', 'quantity' => 50, 'reorder_threshold' => 2]);
        $this->item(['name' => 'Running Low', 'quantity' => 1, 'reorder_threshold' => 2]);
        $this->item(['name' => 'All Gone', 'quantity' => 0, 'reorder_threshold' => 2]);

        $csv = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.inventory.export'))
            ->streamedContent();

        $this->assertStringContainsString('Low stock items', $csv);
        $this->assertStringContainsString('Sold out items', $csv);
    }

    /* ------------------------------------------------------------------ */
    /* 5. Both exports are reachable from the Reports screen               */
    /* ------------------------------------------------------------------ */

    public function test_the_reports_screen_offers_both_exports(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('admin.reports.export'), $html);
        $this->assertStringContainsString(route('admin.inventory.export'), $html);
    }

    /** The export links carry the range on screen, so the file matches the page. */
    public function test_the_sales_export_link_carries_the_current_range(): void
    {
        $params = [
            'from' => today()->subDays(10)->toDateString(),
            'to' => today()->subDays(2)->toDateString(),
        ];

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.reports.index', $params))
            ->assertOk()
            ->getContent();

        // Escaped, because the route carries `&` separators and the attribute
        // renders them as `&amp;` — hence escaping back off before comparing.
        $this->assertStringContainsString(
            e(route('admin.reports.export', $params)),
            $html,
        );
    }

    /* ------------------------------------------------------------------ */
    /* 6. Access control                                                   */
    /* ------------------------------------------------------------------ */

    public function test_a_customer_cannot_reach_either_export(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get(route('admin.reports.export'))->assertRedirect(route('login'));
        $this->actingAs($user)->get(route('admin.inventory.export'))->assertRedirect(route('login'));
    }

    public function test_a_guest_cannot_reach_either_export(): void
    {
        $this->get(route('admin.reports.export'))->assertRedirect(route('login'));
        $this->get(route('admin.inventory.export'))->assertRedirect(route('login'));
    }

    /** Revenue counts only what was actually earned — a cancelled booking is not income. */
    public function test_a_cancelled_booking_is_not_counted_as_revenue(): void
    {
        $this->revenueOn(today()->subDay(), 5000, AppointmentStatus::Cancelled->value);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.reports.index', [
                'from' => today()->subDays(6)->toDateString(),
                'to' => today()->toDateString(),
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('5,000.00', $html);
    }
}