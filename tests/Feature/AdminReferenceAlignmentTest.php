<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\AppointmentStatus;
use App\Enums\TermsCategory;
use App\Models\Admin;
use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\TermsAndCondition;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards the three admin screens that were rebuilt against the reference project:
 * Registered Users, Terms & Conditions and Reports.
 *
 * The recurring theme is that something was removed on each screen, and a
 * removal is easy to undo by accident from a copy of an older view, so each one
 * is asserted by its absence next to a test that the surviving behaviour still
 * works. Reviews was the fourth; the rating feature is gone, and its one test
 * below pins that absence.
 */
class AdminReferenceAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => AdminRole::Admin,
            'is_active' => true,
        ]);
    }

    /**
     * The rating feature is gone from the panel entirely, not merely hidden.
     *
     * Asserted by absence on both sides: the route no longer exists, so the
     * sidebar link and any old bookmark 404 rather than rendering a dead
     * screen, and the sidebar carries no entry for it. This is the one test in
     * this file with no surviving behaviour beside it, because there is nothing
     * left to preserve — it exists so the feature cannot come back from a copy
     * of an older view.
     */
    public function test_the_reviews_screen_is_gone_from_the_panel(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get('/admin/reviews')->assertNotFound();

        $sidebar = $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->getContent();

        $this->assertStringNotContainsString('Reviews', $sidebar);
        $this->assertStringNotContainsString('/admin/reviews', $sidebar);
    }

    /* ------------------------------------------------------------------ */
    /* Terms & Conditions                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * The reference puts a textarea and a Save button on the index, one card per
     * policy. BTA now does the same, over its own categories.
     */
    public function test_the_terms_page_edits_every_category_inline_with_no_header_block(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($admin, 'admin')->get('/admin/terms')->assertOk()->getContent();

        $this->assertStringNotContainsString('Policies', $html);
        $this->assertStringNotContainsString('Versioned content per category.', $html);

        foreach (TermsCategory::cases() as $category) {
            $this->assertStringContainsString($category->label().' Terms', $html);
            $this->assertStringContainsString('name="category" value="'.$category->value.'"', $html);
        }

        // One editor per category, each posting straight back to the index.
        $this->assertSame(
            count(TermsCategory::cases()),
            substr_count($html, 'action="'.route('admin.terms.store').'"'),
            'Each category should have its own inline save form.',
        );

        $this->assertStringContainsString('name="content"', $html);

        // No "show it to customers" checkbox any more. Publishing is its own
        // action, because on a page that overwrites in place a checkbox on the
        // Save form meant every typo fix quietly changed what the public read.
        $this->assertStringNotContainsString('name="is_published"', $html);
    }

    /**
     * Publishing is an action, not a tick box — and it is the one control that
     * decides what customers see.
     */
    public function test_the_terms_page_publishes_through_its_own_action(): void
    {
        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');

        // Nothing saved yet: there is no row to publish, and the page says so
        // rather than offering a button that would 404.
        $empty = $client->get('/admin/terms')->assertOk()->getContent();
        $this->assertStringContainsString('Save the text above first', $empty);
        $this->assertStringNotContainsString(route('admin.terms.publish', ['term' => 1]), $empty);

        $client->post(route('admin.terms.store'), [
            'category' => TermsCategory::Booking->value,
            'content' => '<p>Book at least a day ahead.</p>',
        ])->assertRedirect(route('admin.terms.index'));

        $row = TermsAndCondition::forCategory(TermsCategory::Booking);

        $this->assertNotNull($row);
        $this->assertFalse($row->is_published, 'Saving text does not publish it.');

        // The button posts to the publish route, with a CSRF token of its own.
        $html = $client->get('/admin/terms')->assertOk()->getContent();

        $this->assertStringContainsString('Publish to customers', $html);
        $this->assertStringContainsString('action="'.route('admin.terms.publish', $row).'"', $html);
        $this->assertStringContainsString('id="publish-terms-'.$row->id.'"', $html);

        $client->post(route('admin.terms.publish', $row))->assertRedirect();

        $this->assertTrue($row->fresh()->is_published);
        $this->assertNotNull($row->fresh()->published_at);

        // …and once it is live the control says so instead of inviting a second
        // publish, and the date it went live is on the card.
        $live = $client->get('/admin/terms')->assertOk()->getContent();
        $this->assertStringContainsString('Already published', $live);
        $this->assertStringContainsString('Live to customers', $live);
    }

    /**
     * Saving the text of a live policy must not take it down.
     *
     * This is the bug the separate Publish action exists to prevent. With an
     * `is_published` checkbox on the Save form, editing a live policy and
     * pressing Save posted that box unticked and demoted it — so a typo fix
     * silently removed the terms from the public site.
     */
    public function test_saving_the_text_of_a_live_policy_leaves_it_live(): void
    {
        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');

        $live = TermsAndCondition::create([
            'category' => TermsCategory::Booking,
            'content' => '<p>Book at least a day ahead.</p>',
            'is_published' => true,
            'published_at' => $now = now()->subWeek()->startOfSecond(),
            'created_by' => $admin->id,
        ]);

        $client->post(route('admin.terms.store'), [
            'category' => TermsCategory::Booking->value,
            'content' => '<p>Book at least two days ahead.</p>',
        ])->assertRedirect(route('admin.terms.index'));

        $fresh = $live->fresh();

        $this->assertTrue($fresh->is_published, 'Editing live text must not unpublish it.');
        $this->assertStringContainsString('two days', $fresh->content);
        $this->assertTrue(
            $now->equalTo($fresh->published_at),
            'The date it went live should not be rewritten by an unrelated edit.',
        );
    }

    /**
     * A category owns exactly one row and a save overwrites it.
     *
     * Terms used to be append-only — every save inserted a row and bumped
     * `version`, so a policy accumulated a history and the admin list showed the
     * superseded copies. Saving now replaces the text, which is what the
     * reference gets for free from editing a single row.
     */
    public function test_saving_terms_overwrites_the_category_in_place(): void
    {
        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');

        $client->post(route('admin.terms.store'), [
            'category' => TermsCategory::Booking->value,
            'content' => '<p>Book at least a day ahead.</p>',
        ])->assertRedirect(route('admin.terms.index'));

        $client->post(route('admin.terms.store'), [
            'category' => TermsCategory::Booking->value,
            'content' => '<p>Book at least 48 hours ahead.</p>',
        ])->assertRedirect(route('admin.terms.index'));

        $rows = TermsAndCondition::where('category', TermsCategory::Booking->value)->get();

        $this->assertCount(1, $rows, 'A save overwrites rather than appending a second row.');
        $this->assertStringContainsString('48 hours', $rows[0]->content);
        $this->assertFalse($rows[0]->is_published, 'Saving twice has not published anything.');

        // Publishing is the second half of the flow, and a separate action.
        $client->post(route('admin.terms.publish', $rows[0]))->assertRedirect();

        $this->actingAs($this->makeUser(), 'web')
            ->get(route('terms.show', TermsCategory::Booking->value))
            ->assertOk()
            ->assertSee('48 hours', false)
            ->assertDontSee('a day ahead', false);
    }

    /**
     * The `version` column is gone, and there is no version indicator on the
     * page either.
     *
     * The column went when saving stopped appending rows; the display went with
     * it. There is no version to count — a category owns one row — so what the
     * page states instead is the date the current text went live, which is the
     * question an admin on that screen is actually asking.
     */
    public function test_the_terms_table_no_longer_carries_a_version_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('terms_and_conditions', 'version'),
            'Terms are no longer versioned; the column must not come back.',
        );
    }

    /** No version counter, and no Live / Not-published pill, on the page. */
    public function test_the_terms_page_shows_no_version_counter_and_no_status_pills(): void
    {
        $admin = $this->admin();

        $term = TermsAndCondition::create([
            'category' => TermsCategory::Booking,
            'content' => '<p>Book at least a day ahead.</p>',
            'is_published' => true,
            'published_at' => now()->subWeek(),
            'created_by' => $admin->id,
        ]);

        $html = $this->actingAs($admin, 'admin')->get('/admin/terms')->assertOk()->getContent();

        // The pills that are gone. `badge` is used across the admin panel, so
        // this is checked by its label rather than by the component.
        foreach (['>Preview<', '>Live<', '>Not published<'] as $pill) {
            $this->assertStringNotContainsString($pill, $html, $pill.' should not be on the Terms page.');
        }

        // A version number, however it is dressed up.
        $this->assertDoesNotMatchRegularExpression(
            '/\bv\d+\b/',
            $html,
            'There is no version to count; the column was dropped with the history.',
        );

        // What replaces it: the date the current text went live.
        $this->assertStringContainsString('Live to customers', $html);
        $this->assertStringContainsString($term->published_at->format('M j, Y'), $html);
    }

    /**
     * Publishing retires anything else the category had published.
     *
     * A category owns one row today, so the sweep is normally a no-op — but a
     * database that still carries a superseded row from before the unique index
     * existed would have two rows claiming to be live, and `publishedFor()`
     * would return whichever came back first.
     */
    public function test_publishing_retires_a_superseded_row_for_the_same_category(): void
    {
        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');

        /*
 * The unique index on `category` is what makes the sweep a no-op on a migrated
 * database, so the state it defends against cannot be built while it is in
 * place. It is dropped for this one test and put back in a `finally`, so the
 * test exercises the real controller path against the real schema.
 */
try {
    Schema::table('terms_and_conditions', function (Blueprint $table) {
        $table->dropUnique(['category']);
    });

    // Two rows for one category, as an un-migrated install would still have.
    $superseded = new TermsAndCondition;
    $superseded->forceFill([
        'category' => 'booking',
        'content' => '<p>The old booking terms.</p>',
        'is_published' => true,
        'published_at' => now()->subMonth(),
    ])->saveQuietly();

    $current = new TermsAndCondition;
    $current->forceFill([
        'category' => 'booking',
        'content' => '<p>The current booking terms.</p>',
        'is_published' => false,
        'created_by' => $admin->id,
    ])->saveQuietly();

    $client->post(route('admin.terms.publish', $current))->assertRedirect();

    $this->assertTrue($current->fresh()->is_published);
    $this->assertFalse(
        $superseded->fresh()->is_published,
        'The row this publish superseded must be retired.',
    );
    $this->assertNull($superseded->fresh()->published_at);

    $this->assertSame(1, TermsAndCondition::where('is_published', true)->count());

    // And a customer is shown the newly published text, not the old one.
    $this->actingAs($this->makeUser(), 'web')
        ->get(route('terms.show', TermsCategory::Booking->value))
        ->assertOk()
        ->assertSee('The current booking terms.', false)
        ->assertDontSee('The old booking terms.', false);
    } finally {
        // The duplicate row has to go before the index can come back: this is
        // all inside `RefreshDatabase`'s transaction, so the rows are still
        // here and a unique index over them cannot be created.
        TermsAndCondition::query()
            ->where('category', 'booking')
            ->whereKeyNot($superseded->getKey())
            ->delete();

        Schema::table('terms_and_conditions', function (Blueprint $table) {
            $table->unique('category');
        });
    }
    }

    /**
     * Un-ticking the publish box on a live policy must not send the admin to a
     * screen that has been removed.
     *
     * The edit form's error used to read "Unpublish directly from the list". The
     * version list was deleted when the index became one card per policy, so the
     * only place that error appeared — an admin un-ticking the one publish
     * control on the page — dead-ended onto a 404.
     *
     * The checkbox is the publish control, so the message now describes the
     * checkbox. This asserts it does not send them anywhere, and that the live
     * policy is still live afterwards, because a rejected save must not demote
     * it as a side effect.
     */
    public function test_unticking_publish_on_a_live_policy_explains_the_checkbox_not_a_deleted_list(): void
    {
        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');

        $live = TermsAndCondition::create([
            'category' => TermsCategory::Booking,
            'content' => '<p>Book at least a day ahead.</p>',
            'is_published' => true,
            'published_at' => now()->subWeek(),
            'created_by' => $admin->id,
        ]);

        $response = $client->put(route('admin.terms.update', $live), [
            'category' => TermsCategory::Booking->value,
            'content' => '<p>Book at least two days ahead.</p>',
            // Deliberately absent: the box was unticked.
        ]);

        $response->assertSessionHasErrors('is_published');

        $message = (string) session('errors')->first('is_published');

        $this->assertStringNotContainsString('the list', $message, 'The policy list no longer exists.');
        $this->assertStringContainsString('Publish this policy', $message, 'It should name the control that is actually on the page.');

        // Still live, and unchanged: the refusal did not demote it as a side
        // effect, nor did it apply the content that was submitted with it.
        $this->assertTrue($live->fresh()->is_published);
        $this->assertStringContainsString('a day ahead', $live->fresh()->content);
        $this->assertSame(1, TermsAndCondition::where('is_published', true)->count());
    }

    /**
     * Saving from the index replaces the customer's text, and what a customer
     * then reads is the saved copy — once it is published.
     */
    public function test_saving_from_the_index_replaces_what_the_customer_reads(): void
    {
        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');

        $client->post(route('admin.terms.store'), [
            'category' => TermsCategory::Cancellation->value,
            'content' => '<p>Cancellations need 24 hours notice.</p>',
        ])->assertRedirect(route('admin.terms.index'));

        $client->post(route('admin.terms.store'), [
            'category' => TermsCategory::Cancellation->value,
            'content' => '<p>Cancellations now need 48 hours notice.</p>',
        ])->assertRedirect(route('admin.terms.index'));

        $row = TermsAndCondition::forCategory(TermsCategory::Cancellation);

        $this->assertSame(1, TermsAndCondition::where('category', TermsCategory::Cancellation->value)->count());

        $client->post(route('admin.terms.publish', $row))->assertRedirect();

        $this->actingAs($this->makeUser(), 'web')
            ->get(route('terms.show', TermsCategory::Cancellation->value))
            ->assertOk()
            ->assertSee('48 hours notice', false)
            ->assertDontSee('24 hours notice', false);
    }

    /**
     * There is no way to reach the index's unpublish path by accident, because
     * there is no longer a checkbox to get wrong.
     *
     * This test used to assert that the checkbox defaulted to *ticked* on a live
     * policy, and before that that it defaulted to unticked — two attempts at
     * the same underlying problem, which was that publication was a property of
     * the Save button. It has been replaced by a control that only publishes:
     * the button on a live policy is inert and says so, and nothing on the page
     * posts an `is_published` value at all.
     */
    public function test_the_index_offers_no_way_to_unpublish_by_accident(): void
    {
        $admin = $this->admin();

        $row = TermsAndCondition::create([
            'category' => TermsCategory::Booking,
            'content' => '<p>Book at least a day ahead.</p>',
            'is_published' => true,
            'published_at' => now()->subWeek()->startOfSecond(),
            'created_by' => $admin->id,
        ]);

        $html = $this->actingAs($admin, 'admin')->get('/admin/terms')->assertOk()->getContent();

        // Nothing on this page posts a publication flag.
        $this->assertStringNotContainsString('name="is_published"', $html);

        // The publish control is present, inert, and says what has happened.
        $this->assertStringContainsString('form="publish-terms-'.$row->id.'"', $html);
        $this->assertMatchesRegularExpression(
            '/form="publish-terms-'.$row->id.'"[^>]*\sdisabled|disabled[^>]*form="publish-terms-'.$row->id.'"/',
            $html,
            'Publishing a policy that is already live should not be offered as a live action.',
        );
        $this->assertStringContainsString('Already published', $html);
    }

    /* ------------------------------------------------------------------ */
    /* Reports                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Start Date and End Date are the only filters, the three headline cards are
     * Total Revenue / Bookings / Services, and the revenue rows sit in the same
     * shared list card the Services screen uses.
     *
     * There used to be a third control here — a "Period" dropdown choosing
     * Daily / Weekly / Monthly. It is gone: it duplicated the two dates and could
     * contradict them, since "Monthly" over three days produced a single bucket
     * that read like a whole month. The grouping is derived from the range now,
     * and `ReportsExportTest` covers that.
     */
    public function test_the_reports_page_has_two_filters_and_three_cards(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($admin, 'admin')->get('/admin/reports')->assertOk()->getContent();

        // The removed header.
        $this->assertStringNotContainsString('Analytics', $html);
        $this->assertStringNotContainsString('Sales &amp; Usage Reports', $html);
        $this->assertStringNotContainsString('Revenue and item consumption for a chosen date range', $html);

        // The filters that stay.
        foreach (['name="from"', 'name="to"', 'Start Date', 'End Date'] as $kept) {
            $this->assertStringContainsString($kept, $html, $kept.' should still be a filter.');
        }

        // The Period dropdown, and the per-service and per-item filters that went
        // before it.
        foreach ([
            'name="type"',
            'name="service_id"',
            'name="item_id"',
            'Filter by Service',
            'Filter by Item',
        ] as $removed) {
            $this->assertStringNotContainsString($removed, $html, $removed.' should not be on the reports page.');
        }

        // The grouping is stated on the page rather than chosen on it.
        $this->assertStringContainsString('grouped by', $html);

        // The three cards.
        foreach (['Total Revenue', 'Bookings', 'Services'] as $card) {
            $this->assertStringContainsString($card, $html);
        }

        $this->assertStringNotContainsString('Average Booking', $html);
        $this->assertStringNotContainsString('Lifetime Value', $html);

        // The removed sections.
        foreach (['Revenue by Period', 'Revenue by Service', 'Item Usage', 'Summary Table'] as $section) {
            $this->assertStringNotContainsString($section, $html, $section.' was removed from the reports page.');
        }

        // The rows live in the shared admin list card: live search, entries per
        // page, table, pager.
        $this->assertStringContainsString('adminTable(', $html);
        $this->assertStringContainsString('entries per page', $html);
        $this->assertMatchesRegularExpression('/<table[^>]*class="[^"]*bta-table[^"]*".*?<\/table>/s', $html);
    }

    public function test_the_report_cards_and_rows_recalculate_for_the_chosen_range(): void
    {
        $admin = $this->admin();

        $service = $this->makeService(['price' => 400]);

        // Two completed bookings today, 400 pesos each. `makeAppointment` gives
        // each one service line of its own, and the second booking gets an extra
        // line of two units, so the range holds 1 + (1 + 2) = 4 service units.
        $first = $this->makeAppointment(null, $service, [
            'preferred_date' => today(),
            'status' => AppointmentStatus::Completed,
            'total_amount' => 400,
        ]);

        $second = $this->makeAppointment(null, $service, [
            'preferred_date' => today(),
            'status' => AppointmentStatus::Completed,
            'total_amount' => 400,
        ]);

        AppointmentService::create([
            'appointment_id' => $second->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'price' => 400,
            'duration_minutes' => 60,
            'quantity' => 2,
        ]);

        $this->assertSame(1, $first->serviceLines()->count());

        // One booking last month, outside the default range.
        $old = $this->makeAppointment(null, $service, [
            'preferred_date' => today()->subMonth(),
            'status' => AppointmentStatus::Completed,
            'total_amount' => 400,
        ]);

        AppointmentService::create([
            'appointment_id' => $old->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'price' => 400,
            'duration_minutes' => 60,
            'quantity' => 1,
        ]);

        $client = $this->actingAs($admin, 'admin');

        // Today only.
        $html = $client->get('/admin/reports?from='.today()->toDateString().'&to='.today()->toDateString())
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('₱800.00', $html, 'Two 400-peso bookings today is 800.');
        $this->assertStringNotContainsString('1,200', $html, 'Last month\'s booking is outside the range.');
        $this->assertStringContainsString('>4<', $html, 'Service units booked today: 1 + (1 + 2).');
        $this->assertStringContainsString('>2<', $html, 'Two bookings fall inside the range.');

        // A one-day range buckets by day, so the column header says Day and the
        // row is labelled with the date. It used to be possible to ask for Monthly
        // over this same range, which produced one row labelled with the month.
        $this->assertStringContainsString('<th>Day</th>', $html, false);
        $this->assertStringContainsString(today()->format('M j, Y'), $html);

        // Widening the range picks the old booking back up.
        $wide = $client->get('/admin/reports?from='.today()->subMonths(2)->toDateString().'&to='.today()->toDateString())
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('₱1,200.00', $wide);
    }

    public function test_the_reports_csv_export_mirrors_the_screen(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')->get(
            route('admin.reports.export', [
                'from' => today()->subMonth()->toDateString(),
                'to' => today()->toDateString(),
            ])
        );

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Sales Report', $csv);
        $this->assertStringContainsString('Services', $csv);
        $this->assertStringNotContainsString('ITEM USAGE', $csv);

        // The grouping is stated in the file, so whoever opens it knows what the
        // first column is without having to guess.
        $this->assertStringContainsString('Grouped by', $csv);
    }
}
