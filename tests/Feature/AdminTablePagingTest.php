<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The entries-per-page control and the pager are one mechanism.
 *
 * `adminTable` renders every row and decides what is on screen: `keep` is the
 * search filter, `onPage` is the page window on top of it, and `isShown(index)`
 * is what every row asks. `perPage` sizes the window, and `page` says which part
 * of it is showing.
 *
 * Both live in the shared `x-ui.admin-table` card, so this is asserted against
 * the component rather than per screen — the seven admin lists that use it
 * (Services, Categories, Promo, Inventory, Registered Users, Technicians and
 * Reports) all go through the same three lines of JavaScript.
 *
 * There is no JavaScript test runner in this project, so this pins the source:
 * the failure these guard against is one where the page number changes, every
 * getter that reads it follows, the rows do not, and nothing in the PHP suite
 * notices. That is exactly the bug that was there.
 *
 * The rendering half — that the card actually offers the control, on the pages
 * that have it — is covered in AdminCatalogTablesTest and TechnicianTest.
 */
class AdminTablePagingTest extends TestCase
{
    private function adminTable(): string
    {
        $source = (string) file_get_contents(base_path('resources/js/app.js'));

        $start = strpos($source, "Alpine.data('adminTable'");
        $this->assertIsInt($start, 'adminTable should still be defined.');

        // Slice to the end of the component: up to the next Alpine.data call.
        $end = strpos($source, 'Alpine.data(', $start + 1);
        $this->assertIsInt($end);

        return substr($source, $start, $end - $start);
    }

    /**
     * Moving to a page has to re-slice the window.
     *
     * `go()` used to set `this.page` and stop. Everything that reads `page` —
     * `pages`, `hasPages`, the pager window, the current-page highlight — is a
     * getter, so they all followed, while `onPage`, the array the rows ask
     * `isShown()` about, still described the previous page. Pressing "2"
     * restyled the pager and changed nothing on screen.
     *
     * It also made the page-size select look broken: the two controls are the
     * same mechanism from two ends, so an admin who picked 25 rows per page,
     * clicked to page 2, saw no change, and concluded the 25 had not taken.
     */
    public function test_going_to_a_page_re_slices_the_row_window(): void
    {
        $component = $this->adminTable();

        $go = Str::between($component, 'go(page) {', '}),');

        $this->assertNotSame('go(page) {', $go, 'go() should still exist.');

        $this->assertStringContainsString(
            'this.pageSlice();',
            $go,
            'go() must re-slice, or the pager restyles itself without moving the rows.',
        );
    }

    /**
     * The page is clamped before it is sliced.
     *
     * Narrowing `perPage` while on a late page can leave `page` past the end, and
     * slicing that page would render an empty table — a pager that says page 4 of
     * 4 while showing nothing.
     */
    public function test_going_to_a_page_is_clamped_to_the_available_range(): void
    {
        $go = Str::between($this->adminTable(), 'go(page) {', '}),');

        $clamp = strpos($go, 'Math.min(Math.max(1, page), this.pages)');
        $slice = strpos($go, 'this.pageSlice();');

        $this->assertIsInt($clamp);
        $this->assertIsInt($slice);
        $this->assertLessThan(
            $slice,
            $clamp,
            'The page should be clamped before the window is computed.',
        );
    }

    /**
     * Every way the state changes re-slices, so no path can leave the rows stale.
     *
     * The two `$watch`es and `filter()` already did. `go()` was the one that did
     * not, and the fact that it was the exception is why this is asserted as a
     * list rather than as a single method.
     */
    public function test_every_path_that_changes_the_page_or_page_size_re_slices(): void
    {
        $component = $this->adminTable();

        // Both watchers: each resets to page 1 and re-filters.
        foreach (['perPage', 'search'] as $property) {
            $this->assertMatchesRegularExpression(
                "/\\\$watch\\('{$property}',\\s*\\(\\)\\s*=>\\s*\\{\\s*this\\.page\\s*=\\s*1;\\s*this\\.filter\\(\\);\\s*\\}\\);/",
                $component,
                "Changing {$property} should reset to page 1 and re-filter.",
            );
        }

        // `filter()` is what both of those reach, and it must end in a slice.
        $filter = Str::between($component, 'filter() {', 'pageSlice() {');

        $this->assertStringContainsString(
            'this.pageSlice();',
            $filter,
            'filter() should recompute the window, not only the match list.',
        );

        // And `go()`, which is the one that was missed.
        $this->assertStringContainsString('this.pageSlice();', Str::between($component, 'go(page) {', '}),'));
    }

    /**
     * The pager and the page-size select are wired to the same component.
     *
     * Checked here rather than per screen because a list that renders the card
     * gets both for free — the card owns the markup, so there is no per-page
     * wiring left to get wrong.
     */
    public function test_the_shared_card_wires_the_pager_and_the_page_size_to_the_one_component(): void
    {
        $card = (string) file_get_contents(resource_path('views/components/ui/admin-table.blade.php'));

        $this->assertStringContainsString('x-data="adminTable(', $card);
        $this->assertStringContainsString('x-model.number="perPage"', $card);
        $this->assertStringContainsString('entries per page', $card);

        // The pager drives the same component, not a second one.
        $this->assertStringContainsString('@click="go(page - 1)"', $card);
        $this->assertStringContainsString('@click="go(number)"', $card);
        $this->assertStringContainsString('@click="go(page + 1)"', $card);

        // One scope, so there is one set of rows to decide what shows.
        $this->assertSame(
            1,
            substr_count($card, 'x-data="adminTable('),
            'The card should mount the table component once.',
        );
    }

    /**
     * `isShown()` answers for the page window, not the match list.
     *
     * It read `keep[index] !== false` — the search filter — which left every
     * matching row visible whatever `perPage` said. The pager buttons moved and
     * the row count did not, which is the symptom that reads as "the entries
     * per page select does nothing".
     */
    public function test_row_visibility_reads_the_page_window_and_not_the_search_filter(): void
    {
        $component = $this->adminTable();

        $this->assertStringContainsString(
            'return this.onPage[index] !== false;',
            Str::between($component, 'isShown(index) {', 'isCurrentPage'),
            'isShown() should gate on onPage, the page window.',
        );

        $this->assertStringNotContainsString(
            'this.keep[index]',
            Str::between($component, 'isShown(index) {', 'isCurrentPage'),
            'isShown() must not gate on keep, which is the search filter alone.',
        );
    }

    /**
     * The page-size select offers the size the screen is already using.
     *
     * `x-ui.admin-table` takes a `perPage` prop and the Reports screen passes
     * 12 — one row per month of a range. The select was populated from a fixed
     * `[10, 15, 25, 50, 100]`, and a `<select>` whose value matches none of its
     * options renders blank while the rows underneath keep paging at 12: a
     * control that looks dead on arrival and disagrees with the list below it.
     *
     * The sizes are computed from the live value rather than the shared list
     * being widened, because the point is that any screen can name its own
     * page size and stay honest without the prop and the list being kept in
     * step by hand.
     */
    public function test_the_page_size_select_always_offers_the_size_the_screen_asks_for(): void
    {
        $component = $this->adminTable();

        // The standard list is still the standard list...
        $this->assertMatchesRegularExpression(
            '/standardPerPageOptions:\s*\[10,\s*15,\s*25,\s*50,\s*100\]/',
            $component,
            'The shared page-size list should still offer the standard sizes.',
        );

        // ...and `perPageOptions` is derived from it plus the live value.
        $options = Str::between($component, 'get perPageOptions() {', 'get pages() {');

        $this->assertNotSame(
            'get perPageOptions() {',
            $options,
            'perPageOptions should be a getter, so it follows perPage.',
        );

        $this->assertStringContainsString(
            'options.add(Number(this.perPage) || 10);',
            $options,
            'The live perPage must be added, or a screen asking for 12 gets a blank select.',
        );

        $this->assertStringContainsString(
            'initialPerPage: Number(config.perPage) || 10,',
            $component,
            'The size the screen started at is kept, so 12 stays reachable after trying 25.',
        );

        $this->assertStringContainsString(
            'options.add(Number(this.initialPerPage) || 10);',
            $options,
            'The starting size must be offered too, not just the current one.',
        );

        // The component must not also carry a fixed perPageOptions property:
        // two definitions, and which one wins depends on where in the object
        // literal each sits.
        $this->assertDoesNotMatchRegularExpression(
            '/perPageOptions:\s*\[/',
            $component,
            'perPageOptions should have exactly one definition, as a getter.',
        );

        // And the screen that asked for 12 still asks for it.
        $reports = (string) file_get_contents(resource_path('views/admin/reports/index.blade.php'));

        $this->assertStringContainsString(
            ':per-page="12"',
            $reports,
            'Reports is one row per month; the select has to offer 12 for that.',
        );
    }
}
