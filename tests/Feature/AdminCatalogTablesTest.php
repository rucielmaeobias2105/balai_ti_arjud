<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Promo;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Guards the three catalogue admin screens.
 *
 * Each list is the same shape — a card with the add button at its top right, a
 * live search box with the entries-per-page select beside it, a table, and a
 * pager below it — so the assertions are made per screen rather than once: a
 * control that quietly comes back, or a search that turns into a page reload,
 * would otherwise only show up on whichever page nobody was looking at.
 */
class AdminCatalogTablesTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        return $this->makeAdmin();
    }

    /**
     * A URL as `@js()` writes it inside an Alpine attribute: single-quoted, with
     * its slashes escaped, which is how `x-ui.icon-action` emits a per-row
     * delete URL into its `x-on:click`.
     *
     * Needed because the escaped and unescaped spellings are not interchangeable
     * in an assertion — `\/admin\/services\/7` is what actually renders, and
     * asserting the plain URL against it would fail on a page that is correct.
     */
    private function jsUrl(string $url): string
    {
        return str_replace('/', '\\/', $url);
    }

    /* ------------------------------------------------------------------ */
    /* Shared helpers */
    /* ------------------------------------------------------------------ */

    /**
     * The page's card, so an assertion about list chrome cannot be satisfied by
     * the shared sidebar or the top bar.
     */
    private function cardOf(string $html): string
    {
        preg_match('/<div class="bta-card p-5".*?<\/table>/s', $html, $matches);

        $this->assertNotEmpty($matches, 'The page should render a bta-card.');

        return $matches[0];
    }

    /**
     * The page's `<table class="…">` element, so a column assertion cannot be
     * satisfied by the sidebar or a page heading.
     */
    private function tableOf(string $html, string $class = 'bta-table'): string
    {
        preg_match('/<table[^>]*class="[^"]*'.preg_quote($class, '/').'[^"]*".*?<\/table>/s', $html, $matches);

        $this->assertNotEmpty($matches, 'The page should render a '.$class.' table.');

        return $matches[0];
    }

    /**
     * The byte offset of a row's first mention, or a failure — which is what
     * makes "A is listed above B" assertable without depending on the markup
     * around the cells.
     */
    private function positionOf(string $html, string $needle): int
    {
        $position = strpos($html, $needle);

        $this->assertIsInt($position, 'The row for "'.$needle.'" should be on the page.');

        return $position;
    }

    /**
     * Rendered markup, with the escaping undone.
     *
     * `{{ }}` inside an attribute writes `http:\/\/` for a URL, so a rendered
     * asset path is not a substring of the source it came from. Asserting on the
     * unescaped form keeps a test about behaviour from being a test about
     * Blade's escaping rules.
     */
    private function unescaped(string $html): string
    {
        return str_replace('\\', '', str_replace('\u0022', '"', $html));
    }

    private function makePromo(array $attributes = []): Promo
    {
        static::$sequence++;

        return Promo::create(array_merge([
            'title' => 'Glow Package '.static::$sequence,
            'description' => 'A bundled offer.',
            'starts_at' => today()->subDay(),
            'ends_at' => today()->addDays(30),
            'is_active' => true,
        ], $attributes));
    }

    /* ------------------------------------------------------------------ */
    /* The shared list chrome */
    /* ------------------------------------------------------------------ */

    /**
     * Every list filters in the browser, so there is nothing to submit and
     * nothing to clear — a GET form on any of these pages would mean the search
     * is a page reload in disguise.
     */
    public function test_the_lists_filter_in_the_browser_with_no_search_or_clear_button(): void
    {
        $admin = $this->admin();

        // `makeService()` creates its 'Nail Care' category on the way in — the
        // Service model keeps the two in step — so there is nothing to add here.
        $this->makeService();
        $this->makePromo();

        $client = $this->actingAs($admin, 'admin');

        foreach (['/admin/services', '/admin/categories', '/admin/promos'] as $path) {
            $html = $client->get($path)->assertOk()->getContent();
            $card = $this->cardOf($html);
            $context = $path.' should filter as the admin types.';

            $this->assertStringNotContainsString('method="GET"', $html, $context.' There should be no form to submit.');
            $this->assertStringNotContainsString('>Clear<', $card, $context.' There should be no Clear button.');
            $this->assertStringNotContainsString('>Search<', $card, $context.' The search control should be labelled "Search:".');
            $this->assertStringContainsString('Search:', $card);

            // The box is bound to the Alpine scope, so a keystroke is not a
            // request, and the rows it acts on are all rendered up front.
            $this->assertMatchesRegularExpression(
                '/<input[^>]*type="search"[^>]*x-model="search"/s',
                $card,
                $context.' The search box should be bound with x-model.',
            );
            $this->assertStringContainsString('x-data="adminTable(', $card);
            $this->assertStringContainsString('data-row', $this->tableOf($html));
        }
    }

    /**
     * The entries-per-page select sits to the right of the search box, and the
     * pager sits below the table — the reference's arrangement, where the two
     * share a row above the table and the pager hangs off its bottom right.
     */
    public function test_the_lists_put_the_page_length_beside_search_and_the_pager_below_the_table(): void
    {
        $admin = $this->admin();

        foreach (range(1, 11) as $ignored) {
            $this->makeService();
        }

        $html = $this->actingAs($admin, 'admin')->get('/admin/services')->assertOk()->getContent();

        // The length select and the search box share the row above the table.
        $this->assertStringContainsString('Entries per page', $html);
        $this->assertMatchesRegularExpression(
            '/perPage|Entries per page.*Search|Search.*Entries per page/s',
            $html,
            'The page-length control and the search box belong to the same toolbar.',
        );

        // The pager hangs off the bottom right of the card.
        $this->assertStringContainsString('pageNumbers', $html, 'The pager is rendered.');
        $this->assertStringContainsString('isFirstPage', $html);
        $this->assertStringContainsString('isLastPage', $html);
    }

    /**
     * Deleting a service asks first, through the shared dialog.
     *
     * The trash button used to raise a native `confirm()`. It now dispatches
     * `confirm-delete-service`, and one `x-ui.confirm-dialog` at the foot of the
     * page is the single form behind every row — so the row's delete URL has to
     * travel in the event rather than be baked into a per-row form.
     *
     * It used to announce an id instead, for a `{id}` placeholder on the dialog
     * to be filled in. That was the 404: the first row to open the dialog
     * consumed the placeholder, so every row after it posted the first service's
     * DELETE, and route model binding answers 404 for a row already soft-deleted.
     */
    public function test_deleting_a_service_uses_the_shared_confirmation_dialog(): void
    {
        $admin = $this->admin();
        $service = Service::create([
            'name' => 'Glow Foot Spa',
            'slug' => 'glow-foot-spa',
            'category' => 'Nail Care',
            'price' => '799',
            'duration_minutes' => 60,
        ]);

        $html = $this->actingAs($admin, 'admin')->get('/admin/services')->assertOk()->getContent();

        // The row's trash button dispatches the event carrying its delete
        // URL and the name. Both render into the attribute in the single quotes
        // @js() emits, and the name is what the dialog shows, so it has to travel
        // with the event rather than live only on the button's title attribute.
        $this->assertStringContainsString('confirm-delete-service', $html);
        $this->assertStringContainsString(
            'action: \''.$this->jsUrl(route('admin.services.destroy', $service))."', name: 'Glow Foot Spa'",
            $html,
            'The row should carry its own delete URL, so there is no placeholder left to resolve.',
        );

        // The placeholder that could only ever be resolved once is gone.
        $this->assertStringNotContainsString('{id}', $html, 'No unresolved placeholder should remain on the page.');

        // One dialog for the whole table, with the URL arriving on the event.
        $this->assertStringContainsString('Delete service', $html, 'The dialog should say what it is deleting.');
        // The labels render as button text, not as attributes.
        $this->assertMatchesRegularExpression('/>Yes</', $html);
        $this->assertMatchesRegularExpression('/>No</', $html);
        $this->assertStringContainsString('btn-danger', $html, 'Yes should be danger-styled.');
        $this->assertStringContainsString('This removes the service from the catalogue', $html);

        // The dialog renders the name when one is given, and the counted
        // sentence when one is not.
        $this->assertStringContainsString('x-text="label"', $html, 'The dialog can name the row.');
        $this->assertStringContainsString('subjectPhrase', $html, 'Bulk callers still get the counted sentence.');

        // One DELETE form for the whole table, not one per row. The dialog's own
        // form carries the spoof, so the meaningful count is exactly one however
        // many services are listed — a per-row form would scale with the table.
        $this->assertSame(
            1,
            substr_count($html, 'name="_method" value="DELETE"'),
            'There should be one DELETE form behind the dialog, not one per row.',
        );
    }

    /**
     * The 404, stated as a test: two rows, one shared dialog.
     *
     * Both trash buttons open the same dialog and therefore submit the same one
     * form, so the URL has to be decided by whichever row was clicked. A
     * placeholder consumed on the first open left every later row posting the
     * first service's endpoint — which, once that service is soft-deleted, is a
     * 404 rather than a delete. Asserting each row carries its own URL, and that
     * the second service is still deletable, pins both halves down.
     */
    public function test_each_service_row_carries_its_own_delete_url(): void
    {
        $admin = $this->admin();

        $first = Service::create([
            'name' => 'Glow Foot Spa',
            'slug' => 'glow-foot-spa-a',
            'category' => 'Nail Care',
            'price' => '799',
            'duration_minutes' => 60,
        ]);
        $second = Service::create([
            'name' => 'Brow Sculpt',
            'slug' => 'brow-sculpt-b',
            'category' => 'Nail Care',
            'price' => '499',
            'duration_minutes' => 45,
        ]);

        $html = $this->actingAs($admin, 'admin')->get('/admin/services')->assertOk()->getContent();

        foreach ([$first, $second] as $service) {
            $this->assertStringContainsString(
                "action: '".$this->jsUrl(route('admin.services.destroy', $service))."'",
                $html,
                "{$service->name} should carry its own delete URL.",
            );
        }

        // And the second row's URL is one that exists — the first row's is not
        // reachable a second time.
        $this->actingAs($admin, 'admin')
            ->delete(route('admin.services.destroy', $second))
            ->assertRedirect(route('admin.services.index'))
            ->assertSessionHas('status', 'Service "Brow Sculpt" deleted.');

        $this->assertSoftDeleted($second);
        $this->assertNotSoftDeleted($first);
    }

    /** The dialog really deletes on Yes, and the row is soft-deleted. */
    public function test_the_service_delete_route_still_soft_deletes(): void
    {
        $admin = $this->admin();
        $service = Service::create([
            'name' => 'Glow Foot Spa',
            'slug' => 'glow-foot-spa-2',
            'category' => 'Nail Care',
            'price' => '799',
            'duration_minutes' => 60,
        ]);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.services.destroy', $service))
            ->assertRedirect(route('admin.services.index'))
            ->assertSessionHas('status', 'Service "Glow Foot Spa" deleted.');

        $this->assertSoftDeleted($service);
    }
public function test_a_promo_image_is_uploaded_replaced_and_removed(): void
    {
        Storage::fake('public');

        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');

        $client->post('/admin/promos', [
            'title' => 'Glow Package',
            'description' => 'A bundled offer.',
            'starts_at' => today()->toDateString(),
            'ends_at' => today()->addDays(7)->toDateString(),
            'is_active' => '1',
            'image' => UploadedFile::fake()->image('offer.jpg'),
        ])->assertRedirect(route('admin.promos.index'));

        $promo = Promo::sole();
        $original = $promo->image_path;

        $this->assertNotNull($original);
        $this->assertStringStartsWith(Promo::IMAGE_DIRECTORY.'/', $original);
        Storage::disk('public')->assertExists($original);
        $this->assertTrue($promo->hasImage());

        $client->put(route('admin.promos.update', $promo), [
            'title' => 'Glow Package',
            'description' => 'A bundled offer.',
            'starts_at' => today()->toDateString(),
            'ends_at' => today()->addDays(7)->toDateString(),
            'is_active' => '1',
            'image' => UploadedFile::fake()->image('offer-2.jpg'),
        ])->assertRedirect(route('admin.promos.index'));

        $replacement = $promo->fresh()->image_path;

        $this->assertNotSame($original, $replacement);
        Storage::disk('public')->assertMissing($original);
        Storage::disk('public')->assertExists($replacement);

        $client->put(route('admin.promos.update', $promo), [
            'title' => 'Glow Package',
            'description' => 'A bundled offer.',
            'starts_at' => today()->toDateString(),
            'ends_at' => today()->addDays(7)->toDateString(),
            'is_active' => '1',
            'remove_image' => '1',
        ])->assertRedirect(route('admin.promos.index'));

        $this->assertNull($promo->fresh()->image_path);
        Storage::disk('public')->assertMissing($replacement);
    }

    /**
     * A rename must not quietly empty the picture.
     *
     * An absent file input is not an empty one. Saving a promo without touching
     * the image field means "keep the picture", and treating it as "no picture"
     * would mean the first save after every upload silently lost it.
     */
public function test_saving_a_promo_without_touching_the_image_keeps_it(): void
    {
        Storage::fake('public');

        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');

        $client->post('/admin/promos', [
            'title' => 'Glow Package',
            'description' => 'A bundled offer.',
            'starts_at' => today()->toDateString(),
            'ends_at' => today()->addDays(7)->toDateString(),
            'is_active' => '1',
            'image' => UploadedFile::fake()->image('offer.jpg'),
        ])->assertRedirect(route('admin.promos.index'));

        $promo = Promo::sole();
        $uploaded = $promo->image_path;

        $client->put(route('admin.promos.update', $promo), [
            'title' => 'Glow Package Renamed',
            'description' => 'A bundled offer.',
            'starts_at' => today()->toDateString(),
            'ends_at' => today()->addDays(7)->toDateString(),
            'is_active' => '1',
        ])->assertRedirect(route('admin.promos.index'));

        $this->assertSame('Glow Package Renamed', $promo->fresh()->title);
        $this->assertSame($uploaded, $promo->fresh()->image_path);
        Storage::disk('public')->assertExists($uploaded);
    }

    /** Deleting a promo takes its upload with it. */
public function test_deleting_a_promo_deletes_its_upload(): void
    {
        Storage::fake('public');

        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin');

        $client->post('/admin/promos', [
            'title' => 'Glow Package',
            'description' => 'A bundled offer.',
            'starts_at' => today()->toDateString(),
            'ends_at' => today()->addDays(7)->toDateString(),
            'is_active' => '1',
            'image' => UploadedFile::fake()->image('offer.jpg'),
        ])->assertRedirect(route('admin.promos.index'));

        $promo = Promo::sole();
        $uploaded = $promo->image_path;
        Storage::disk('public')->assertExists($uploaded);

        $client->delete(route('admin.promos.destroy', $promo))
            ->assertRedirect(route('admin.promos.index'));

        Storage::disk('public')->assertMissing($uploaded);
    }

    /** A non-image, or an oversized one, is refused with a message that says so. */
public function test_a_promo_refuses_a_file_that_is_not_a_usable_image(): void
    {
        Storage::fake('public');

        $client = $this->actingAs($this->admin(), 'admin');

        $payload = [
            'title' => 'Glow Package',
            'description' => 'A bundled offer.',
            'starts_at' => today()->toDateString(),
            'ends_at' => today()->addDays(7)->toDateString(),
            'is_active' => '1',
        ];

        $client->post('/admin/promos', $payload + [
            'image' => UploadedFile::fake()->create('notes.pdf', 64, 'application/pdf'),
        ])->assertSessionHasErrors('image');

        $client->post('/admin/promos', $payload + [
            'image' => UploadedFile::fake()->image('huge.jpg')->size(5000),
        ])->assertSessionHasErrors('image');

        $this->assertSame(0, Promo::count(), 'A refused upload must not create a promo.');
    }

    /**
     * The two ways a nullable string column ends up holding something that is
     * not a path: an empty string, and the four characters `NULL` left by a
     * hand-edited query. Both would otherwise render as `storage/NULL`, which is
     * a 404 in the middle of the customer's promo card.
     */
public function test_a_promo_with_a_bogus_stored_image_falls_back_to_no_image(): void
    {
        foreach (['', 'NULL'] as $bogus) {
            $promo = $this->makePromo();
            $promo->forceFill(['image_path' => $bogus])->save();

            $this->assertFalse($promo->hasImage(), '"'.$bogus.'" is not a picture.');
            $this->assertNull($promo->image_url);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Inventory */
    /* ------------------------------------------------------------------ */

    /**
     * The inventory list is the promo card: an add button at the top right of a
     * single white card, the search box beneath it, then the table. The old
     * eyebrow/heading/subtitle block and the three stat tiles above the table
     * are gone — the top bar already says "Inventory", and a count of low stock
     * is on the sidebar badge and the tagging screen.
     */
    public function test_the_inventory_list_is_one_card_with_no_header_block_or_stat_tiles(): void
    {
        $admin = $this->admin();
        $this->makeItem();

        $html = $this->actingAs($admin, 'admin')->get('/admin/inventory')->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'font-display text-2xl font-bold tracking-tight text-primary',
            $html,
            'The inventory list should not render a page-header heading.',
        );
        $this->assertStringNotContainsString('Track stock levels, reorder thresholds', $html);
        $this->assertStringNotContainsString('Inventory Items', $html);

        // The stat tiles were `bta-card flex items-center gap-4 p-4` blocks
        // outside the table card. "Total Items" is unique to one of them; the
        // other two titles are also badge labels, so the markup is the tell.
        $this->assertStringNotContainsString('Total Items', $html);
        $this->assertStringNotContainsString('bta-card flex items-center gap-4 p-4', $html);

        $card = $this->cardOf($html);
        $this->assertStringContainsString('Add Item', $card);
        $this->assertLessThan(
            strpos($card, 'Search:'),
            $this->positionOf($card, 'Add Item'),
            'The add button should sit at the top of the card, above the search row.',
        );

        // The whole row set is rendered so the browser can filter it, and there
        // is no GET filter form left to turn the search into a reload.
        $this->assertStringContainsString('data-row', $this->tableOf($html));
        $this->assertStringNotContainsString('name="tag"', $html);
        $this->assertStringNotContainsString('name="category"', $this->cardOf($html));
    }

    public function test_the_inventory_list_keeps_its_columns_and_sorts(): void
    {
        $admin = $this->admin();
        $this->makeItem(['name' => 'Zinc Cream', 'supplier' => 'Aura Supplies']);
        $this->makeItem(['name' => 'Argan Oil', 'supplier' => 'Zenith Trading']);

        $html = $this->actingAs($admin, 'admin')->get('/admin/inventory')->assertOk()->getContent();

        foreach (['Item', 'Quantity', 'Tag', 'Expiry', 'Actions'] as $heading) {
            $this->assertStringContainsString($heading, $html, 'The inventory table should have a '.$heading.' column.');
        }

        $ascending = $this->actingAs($admin, 'admin')
            ->get('/admin/inventory?sort=name&direction=asc')
            ->assertOk()
            ->getContent();

        $this->assertLessThan(
            $this->positionOf($ascending, 'Zinc Cream'),
            $this->positionOf($ascending, 'Argan Oil'),
            'Sorting by name ascending should list A before Z.',
        );

        // A column outside the allow-list falls back to the default rather than
        // reaching the database.
        $this->actingAs($admin, 'admin')->get('/admin/inventory?sort=drop_table')->assertOk();
    }

    /**
     * Reorder At, Supplier and Linked Services are gone from the table, and a
     * `?sort=` on any of them falls back to the default rather than reaching a
     * column the screen no longer shows.
     *
     * The service↔item relationship itself stays in the database on purpose:
     * booking a service still deducts the stock it consumes.
     */
    public function test_the_inventory_list_drops_the_reorder_supplier_and_linked_service_columns(): void
    {
        $admin = $this->admin();
        $service = $this->makeService(['name' => 'Gelish Manicure']);
        $item = $this->makeItem(['name' => 'Argan Oil', 'supplier' => 'Aura Supplies', 'reorder_threshold' => 5]);
        $this->linkItemToService($service, $item);

        $html = $this->actingAs($admin, 'admin')->get('/admin/inventory')->assertOk()->getContent();
        $table = $this->tableOf($html);

        foreach (['Reorder At', 'Supplier', 'Linked Services'] as $heading) {
            $this->assertStringNotContainsString($heading, $table, $heading.' was removed from the inventory table.');
        }

        // The link survives in the database even though nothing renders it.
        $this->assertTrue($item->fresh()->services()->whereKey($service->id)->exists());

        foreach (['reorder_threshold', 'supplier'] as $column) {
            $sorted = $this->actingAs($admin, 'admin')
                ->get('/admin/inventory?sort='.$column)
                ->assertOk()
                ->getContent();

            $this->assertMatchesRegularExpression(
                '/aria-sort="ascending"[^>]*>\s*<span>Item<\/span>/s',
                $this->tableOf($sorted),
                'Sorting on the removed column "'.$column.'" should fall back to the default sort.',
            );
        }
    }

    /**
     * The Category column is gone from the inventory table, the sort list and the
     * CSV export.
     *
     * The last of the three places that asked for a second set of category names.
     * The form field went first, and leaving the badge column behind meant the
     * list still displayed a value the salon could not change — every new and
     * edited row reads `InventoryItem::DEFAULT_CATEGORY`, so the gold badge was a
     * column of identical text.
     *
     * The `?sort=` case is the load-bearing one: `category` was still a sortable
     * column, so the header would have offered to reorder rows that all share one
     * value. A control that cannot change the answer is worse than no control.
     *
     * The column stays in the database and existing rows keep their value, so no
     * historical data is rewritten. Search still matches it, exactly as it still
     * matches the retired `supplier` — an old name typed into the search box finds
     * its rows.
     */
    public function test_the_inventory_list_drops_the_category_column(): void
    {
        $admin = $this->admin();

        // A row whose stored category is deliberately *not* the default, so this
        // cannot pass just because every row happens to read "General".
        $this->makeItem(['name' => 'Argan Oil', 'category' => 'Nail Care']);
        $this->makeItem(['name' => 'Zinc Cream', 'category' => 'Hair Care']);

        $html = $this->actingAs($admin, 'admin')->get('/admin/inventory')->assertOk()->getContent();
        $table = $this->tableOf($html);

        $this->assertStringNotContainsString('Category', $table, 'The Category column was removed from the inventory table.');

        foreach (['Nail Care', 'Hair Care'] as $badge) {
            $this->assertStringNotContainsString($badge, $table, 'A stored category should no longer be rendered in the list.');
        }

        // No longer a sort target: it falls back to the default, as the other
        // removed columns do.
        $sorted = $this->actingAs($admin, 'admin')
            ->get('/admin/inventory?sort=category')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/aria-sort="ascending"[^>]*>\s*<span>Item<\/span>/s',
            $this->tableOf($sorted),
            'Sorting on the removed category column should fall back to the default sort.',
        );

        // And the export does not carry it either — the sheet should describe the
        // stock, not re-file it under names the salon no longer maintains.
        $csv = $this->actingAs($admin, 'admin')
            ->get('/admin/inventory/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString('Category', $csv, 'The Category column was removed from the inventory export.');
        $this->assertStringNotContainsString('Nail Care', $csv);
        $this->assertStringContainsString('Argan Oil', $csv, 'The export should still list the items themselves.');
    }

    /**
     * Edit and delete are icon buttons in the row, as on the other tables, and
     * deleting opens a dialog rather than the browser's own confirm().
     */
    public function test_the_inventory_rows_edit_and_delete_with_icon_actions(): void
    {
        $admin = $this->admin();
        $item = $this->makeItem(['name' => 'Argan Oil']);

        $html = $this->actingAs($admin, 'admin')->get('/admin/inventory')->assertOk()->getContent();
        $table = $this->tableOf($html);

        $this->assertStringContainsString(route('admin.inventory.edit', $item), $table);
        $this->assertStringContainsString(route('admin.inventory.destroy', $item), $table);
        $this->assertStringContainsString('icon-action-primary', $table);
        $this->assertStringContainsString('icon-action-danger', $table);

        // The row hands the dialog its label and its delete URL, and the dialog
        // holds the one real form. No inline form per row, and no confirm().
        $this->assertStringContainsString('confirm-inventory-delete', $table);
        $this->assertStringNotContainsString('onsubmit="return confirm', $table);

        // The dialog sits outside the table in the shared modal stack and posts
        // a real DELETE to whatever action the row supplied.
        $this->assertStringContainsString('id="delete-item-title"', $html);
        $this->assertStringContainsString('Delete Item', $html);
        $this->assertMatchesRegularExpression('/<form method="POST" :action="action">/', $html);
        $this->assertStringContainsString('value="DELETE"', $html);
    }

    /**
     * The dialog's form really deletes.
     */
    public function test_the_inventory_delete_dialog_deletes_the_item_it_was_given(): void
    {
        $admin = $this->admin();
        $keep = $this->makeItem(['name' => 'Argan Oil']);
        $remove = $this->makeItem(['name' => 'Zinc Cream']);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.inventory.destroy', $remove))
            ->assertRedirect(route('admin.inventory.index'));

        $this->assertSoftDeleted($remove);
        $this->assertNotSoftDeleted($keep);
    }

    /**
     * The item form is four fields in the Add Item card: name, date in, expiry
     * date and quantity.
     *
     * Two columns are written rather than asked for, which is why neither
     * `name="unit"` nor `name="category"` is here:
     *
     *   - the unit was a dropdown of eight choices — pcs, ml, bottles, boxes,
     *     sachets, grams, sets, kits — for a salon that counts everything in
     *     pieces, so in practice it was `pcs` on every row and the other seven
     *     existed to be mis-picked;
     *   - the category was a dropdown of the admin's own service categories, so
     *     stock maintained a second, parallel set of category names that nothing
     *     kept in step with the catalogue's.
     */
    public function test_the_item_form_is_name_dates_and_quantity_with_neither_unit_nor_category(): void
    {
        $admin = $this->admin();
        ServiceCategory::create(['name' => 'Nail Care', 'color' => '#E11D48', 'sort_order' => 0, 'is_active' => true]);

        $html = $this->actingAs($admin, 'admin')->get('/admin/inventory/create')->assertOk()->getContent();

        foreach (['name="name"', 'name="date_in"', 'name="expiry_date"', 'name="quantity"'] as $field) {
            $this->assertStringContainsString($field, $html, 'The item form should still have '.$field.'.');
        }

        $this->assertStringNotContainsString('name="unit"', $html, 'The unit is no longer a form field.');
        $this->assertStringNotContainsString('name="category"', $html, 'The category is no longer a form field.');

        // With both dropdowns gone the form has no `<select>` left at all.
        $this->assertStringNotContainsString('<select', $html, 'Nothing on the item form is a dropdown any more.');

        // The header block and everything the old form carried is gone.
        $this->assertStringNotContainsString('font-display text-2xl font-bold tracking-tight text-primary', $html);
        $this->assertStringNotContainsString('Create a new tracked item and link it to the services that consume it.', $html);

        foreach (['name="sku"', 'name="reorder_threshold"', 'name="supplier"', 'name="notes"', 'name="status_tag"', 'name="is_active"', 'name="services[]"'] as $field) {
            $this->assertStringNotContainsString($field, $html, $field.' was removed from the item form.');
        }

        $this->assertStringContainsString('Add Item', $html);
    }

    /**
     * The same must hold on the edit form: an existing item keeps what it was
     * saved with, but the admin is offered no control to change either value.
     */
    public function test_the_item_edit_form_has_no_unit_or_category_field(): void
    {
        $admin = $this->admin();
        ServiceCategory::create(['name' => 'Nail Care', 'color' => '#E11D48', 'sort_order' => 0, 'is_active' => true]);
        $item = $this->makeItem(['name' => 'Argan Oil', 'unit' => 'bottles', 'category' => 'Nail Art']);

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.inventory.edit', $item))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('name="unit"', $html, 'The unit is no longer a form field on edit either.');
        $this->assertStringNotContainsString('name="category"', $html, 'The category is no longer a form field on edit either.');

        // Rendering must not quietly rewrite history either.
        $this->assertSame('bottles', $item->fresh()->unit);
        $this->assertSame('Nail Art', $item->fresh()->category);
    }

    /**
     * The category dropdown, and with it the last `<select>` on the item form, is
     * gone.
     *
     * This test used to assert the dropdown *worked* — that it offered the
     * admin's active categories and withheld the retired one. It is inverted now
     * rather than deleted, because "the field is gone" is exactly the kind of
     * thing a future copy of an older form would silently reintroduce.
     */
    public function test_the_item_form_offers_no_category_dropdown(): void
    {
        $admin = $this->admin();
        ServiceCategory::create(['name' => 'Nail Care', 'color' => '#E11D48', 'sort_order' => 0, 'is_active' => true]);
        ServiceCategory::create(['name' => 'Hair Care', 'color' => '#EC4899', 'sort_order' => 1, 'is_active' => true]);
        ServiceCategory::create(['name' => 'Retired Group', 'color' => '#8B5CF6', 'sort_order' => 2, 'is_active' => false]);

        $html = $this->actingAs($admin, 'admin')->get('/admin/inventory/create')->assertOk()->getContent();

        $this->assertStringNotContainsString('name="category"', $html, 'Stock is no longer filed under a chosen category.');
        $this->assertStringNotContainsString('Select a category', $html);
        $this->assertStringNotContainsString('<select', $html, 'The form has no dropdown left.');

        // Neither is the free-text datalist that predated the dropdown.
        $this->assertStringNotContainsString('bta-item-categories', $html);
    }

    /**
     * The form writes exactly the four fields it shows; the stock code is derived
     * from the name rather than typed; and the unit and category are set for the
     * admin.
     *
     * The post below still carries `unit` and `category`, the way a browser would
     * send a stale form or a crafted request would smuggle them. Both are ignored:
     * neither is in `InventoryItemRequest::fields()`, so the controller writes
     * both itself.
     */
    public function test_an_item_is_created_from_the_four_fields_with_the_unit_and_category_written_for_it(): void
    {
        $admin = $this->admin();
        ServiceCategory::create(['name' => 'Nail Care', 'color' => '#E11D48', 'sort_order' => 0, 'is_active' => true]);

        $this->actingAs($admin, 'admin')
            ->post('/admin/inventory', [
                'name' => '  Gelish Top Coat  ',
                'category' => 'Smuggled Category',
                'date_in' => '2026-09-01',
                'expiry_date' => '2027-09-01',
                'quantity' => '12',
                'unit' => 'bottles',
            ])
            ->assertRedirect(route('admin.inventory.index'))
            ->assertSessionHas('status');

        $item = InventoryItem::sole();

        $this->assertSame('Gelish Top Coat', $item->name);
        $this->assertSame('2026-09-01', $item->date_in->toDateString());
        $this->assertSame('2027-09-01', $item->expiry_date->toDateString());
        $this->assertSame('12.00', $item->quantity);

        $this->assertSame(InventoryItem::DEFAULT_UNIT, $item->unit, 'The unit is written by the controller, not taken from the post.');
        $this->assertSame('pcs', $item->unit);
        $this->assertSame(InventoryItem::DEFAULT_CATEGORY, $item->category, 'The category is written by the controller too.');
        $this->assertNotSame('Smuggled Category', $item->category);

        // No SKU field, so the model writes one — and a second item with the
        // same name still gets its own.
        $this->assertSame('GELISH-TOP-COAT', $item->sku);

        $this->actingAs($admin, 'admin')
            ->post('/admin/inventory', [
                'name' => 'Gelish Top Coat',
                'date_in' => '2026-09-02',
                'quantity' => '3',
            ])
            ->assertRedirect(route('admin.inventory.index'));

        $this->assertSame(2, InventoryItem::count());
        $this->assertSame(
            'GELISH-TOP-COAT-2',
            InventoryItem::latest('id')->first()->sku,
            'A second item with the same name should get its own stock code.',
        );

        // An expiry date is optional; an item that does not expire simply has none.
        $this->assertNull(InventoryItem::latest('id')->first()->expiry_date);
    }

    public function test_the_item_form_rejects_an_expiry_before_the_date_in(): void
    {
        $admin = $this->admin();
        ServiceCategory::create(['name' => 'Nail Care', 'color' => '#E11D48', 'sort_order' => 0, 'is_active' => true]);

        $this->actingAs($admin, 'admin')
            ->post('/admin/inventory', [
                'name' => 'Argan Oil',
                'category' => 'Nail Care',
                'date_in' => '2026-09-10',
                'expiry_date' => '2026-09-01',
                'quantity' => '5',
                'unit' => 'ml',
            ])
            ->assertSessionHasErrors('expiry_date');

        $this->assertSame(0, InventoryItem::count());
    }

    /**
     * A column the form no longer offers cannot be written by a crafted post,
     * and renaming an item leaves its stock code alone.
     */
    public function test_an_item_post_cannot_write_columns_the_form_dropped(): void
    {
        $admin = $this->admin();
        ServiceCategory::create(['name' => 'Nail Care', 'color' => '#E11D48', 'sort_order' => 0, 'is_active' => true]);

        $this->actingAs($admin, 'admin')
            ->post('/admin/inventory', [
                'name' => 'Argan Oil',
                'category' => 'Smuggled Category',
                'date_in' => '2026-09-01',
                'quantity' => '5',
                'unit' => 'ml',
                'sku' => 'CRAFTED',
                'reorder_threshold' => '99',
                'supplier' => 'Crafted Supplier',
                'status_tag' => 'sold_out',
                'is_active' => '0',
            ])
            ->assertRedirect(route('admin.inventory.index'));

        $item = InventoryItem::sole();

        $this->assertNotSame('CRAFTED', $item->sku);
        $this->assertNull($item->reorder_threshold);
        $this->assertNull($item->supplier);
        $this->assertTrue($item->is_active);
        $this->assertNotSame('sold_out', $item->status_tag->value);
        $this->assertSame(InventoryItem::DEFAULT_UNIT, $item->unit, 'A crafted post cannot smuggle a unit past the field that is gone.');
        $this->assertSame(InventoryItem::DEFAULT_CATEGORY, $item->category, 'Nor a category.');
        $this->assertNotSame('Smuggled Category', $item->category);
    }

    /**
     * The edit form posts to the update route with the item in the URL.
     *
     * The resource's placeholder is named `inventory_item` so the controller's
     * `$inventory_item` binds by name; a `route('admin.inventory.update')` with
     * no argument then fails where the mistake is, instead of the binding
     * quietly falling back and the save going to the wrong row.
     */
    public function test_editing_an_item_posts_to_the_update_route_for_that_item(): void
    {
        $admin = $this->admin();
        ServiceCategory::create(['name' => 'Nail Care', 'color' => '#E11D48', 'sort_order' => 0, 'is_active' => true]);
        $item = $this->makeItem(['name' => 'Argan Oil', 'unit' => 'ml', 'category' => 'Nail Art']);

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.inventory.edit', $item))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('action="'.route('admin.inventory.update', $item).'"', $html);
        $this->assertStringContainsString('value="PUT"', $html);
        $this->assertStringContainsString('Update Item', $html);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.inventory.update', $item), [
                'name' => 'Argan Oil Refined',
                'category' => 'Smuggled Category',
                'date_in' => '2026-09-01',
                'expiry_date' => '2027-01-01',
                'quantity' => '7',
                'unit' => 'ml',
            ])
            ->assertRedirect(route('admin.inventory.index'));

        $item->refresh();

        $this->assertSame('Argan Oil Refined', $item->name);
        $this->assertSame('7.00', $item->quantity);
        $this->assertSame('2027-01-01', $item->expiry_date->toDateString());
        $this->assertSame(
            InventoryItem::DEFAULT_UNIT,
            $item->unit,
            'Editing a row that was saved as ml normalises it to pcs, so a quantity in pieces never sits beside a stale unit.',
        );
        $this->assertSame(
            InventoryItem::DEFAULT_CATEGORY,
            $item->category,
            'The category is normalised the same way, so a save cannot re-file stock under an arbitrary label.',
        );

        // The stock code is written once, on create, so a rename does not
        // invalidate a code already written on a stock sheet.
        $this->assertSame($item->sku, $item->fresh()->sku);
        $this->assertNotSame('ARGAN-OIL-REFINED', $item->sku);
    }
}
