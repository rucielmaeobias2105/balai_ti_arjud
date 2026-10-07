<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Services page as a set of category cards.
 *
 * The shape this pins: one card per category, split into two columns — the
 * category name and its services on the left, ONE representative photo on the
 * right — at most four services before a "See All" button appears, and the two
 * columns stacking into one below `sm`.
 *
 * The three decisions that are easy to undo by accident are asserted as absences
 * alongside the behaviour: no pagination (a category split across pages is the
 * failure this layout exists to prevent), no ratings (the review feature is
 * gone from everywhere), and no count in the disclosure's own label — "See All"
 * is the same control in every category, so it cannot be wrong about a number.
 */
class ServicesCategoryLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function unescaped(string $html): string
    {
        return str_replace('\\', '', str_replace('\u0022', '"', $html));
    }

    /**
     * @return array{0: \Illuminate\Testing\TestResponse, 1: string}
     */
    private function render(int $servicesInCategory = 2, string $category = 'Manicure & Pedicure'): array
    {
        for ($i = 0; $i < $servicesInCategory; $i++) {
            $this->makeService(['name' => $category.' Service '.($i + 1), 'category' => $category]);
        }

        $response = $this->get(route('services.index'))->assertOk();

        return [$response, $response->getContent()];
    }

    /* ------------------------------------------------------------------ */
    /* 1. One section per category                                         */
    /* ------------------------------------------------------------------ */

    public function test_each_category_gets_its_own_section_with_its_name(): void
    {
        $this->makeService(['name' => 'Gel Manicure', 'category' => 'Manicure & Pedicure']);
        $this->makeService(['name' => 'Brow Threading', 'category' => 'Threading']);

        $html = $this->get(route('services.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Manicure &amp; Pedicure', $html);
        $this->assertStringContainsString('Threading', $html);

        // Both services are on the page, each under its own heading.
        $this->assertStringContainsString('Gel Manicure', $html);
        $this->assertStringContainsString('Brow Threading', $html);
    }

    /**
     * One image per card, in a column beside the price list.
     *
     * Asserted as a single `<img>` carrying the category's resolved URL — the
     * real invariant here, since a category showing one photo per service would
     * be the thing that makes this page unreadable.
     *
     * The ordering assertion has been inverted twice now, which is the point of
     * pinning it. The card was full width with the photo in a column to the
     * right; that became a half-width card with the photo as a banner across the
     * top, because at half width side by side left the service names and prices
     * in a column too narrow to scan; and it is back beside the list, now that
     * the photo column is narrow on purpose while the list beside it keeps the
     * width. So the photo follows its own category's text — second in the source,
     * which is the right-hand column on screen — and appears exactly once.
     */
    public function test_a_card_pictures_its_category_once_beside_its_price_list(): void
    {
        [, $html] = $this->render();

        $image = ServiceCategory::firstOrNew(['name' => 'Manicure & Pedicure'])->imageUrl();

        $this->assertStringContainsString($image, $this->unescaped($html));

        // Exactly one image for this category, not one per service.
        $this->assertSame(1, substr_count($this->unescaped($html), $image));

        // The photo column follows the list it belongs to.
        $this->assertGreaterThan(
            strpos($html, 'Manicure &amp; Pedicure Service 1'),
            strpos($this->unescaped($html), $image),
            'The category photo should sit beside the list, after it in the source.',
        );

        // And it is a column, not a banner: the card splits into two from `sm` up.
        $this->assertStringContainsString('sm:flex-row', $html);
        $this->assertStringNotContainsString('h-28', $html);
    }

    /* ------------------------------------------------------------------ */
    /* 1b. Two categories to a row, and one on a narrow screen             */
    /* ------------------------------------------------------------------ */

    /**
     * The categories are a grid, not a stack.
     *
     * The layout this replaced gave every category a full-width section, so a
     * salon with six of them was six screenfuls tall and a customer comparing two
     * categories had to scroll the whole page between them. `md:grid-cols-2` is
     * the two-up rule and the single column below it is the mobile fallback —
     * asserted as substrings because a media query is not something a rendered
     * page can prove at any one viewport.
     */
    public function test_the_categories_are_a_two_column_grid_that_collapses_on_mobile(): void
    {
        [, $html] = $this->render();

        $this->assertStringContainsString('md:grid-cols-2', $html, 'Two categories per row from md up.');

        // The grid wrapper has no `grid-cols-1`, so below `md` the grid is a
        // single column by default — which is the responsive collapse.
        $this->assertMatchesRegularExpression(
            '/<div class="grid[^"]*gap-5[^"]*md:grid-cols-2/',
            $html,
            'The category wrapper should be a grid that goes two-up at md.',
        );

        // Scoped to this page's own content rather than the whole document. The
        // footer is a three-column grid in its own right, so a whole-page search
        // for `grid-cols-3` now trips over the footer while saying nothing at
        // all about the category list this assertion is about.
        $start = strpos($html, '<main id="main"');
        $end = strpos($html, '</main>');

        $this->assertNotFalse($start, 'The services page should open <main id="main">.');
        $this->assertNotFalse($end, 'The services page should close </main>.');

        // A negative length here would silently hand back "everything except the
        // last N bytes", which is the whole document with the footer in it.
        $this->assertGreaterThan($start, $end, '</main> must come after <main>.');

        $main = substr($html, $start, $end - $start);

        $this->assertStringNotContainsString(
            'grid-cols-3',
            $main,
            'The category grid must not go three-up; it is two from md, one below.',
        );
    }

    /**
     * Cards are equal height and the disclosure sits on the bottom edge.
     *
     * Two categories in a row are rarely the same size, and without this the
     * "See More" buttons land at different heights and the row reads as ragged.
     * `items-stretch` on the grid plus `flex-1`/`mt-auto` on the card body is
     * what lines them up.
     */
    public function test_cards_stretch_and_push_the_disclosure_to_the_bottom(): void
    {
        [, $html] = $this->render(6);

        $this->assertStringContainsString('items-stretch', $html);
        $this->assertStringContainsString('flex flex-1 flex-col', $html);
        $this->assertStringContainsString('mt-auto', $html);
    }

    /**
     * The photo column and the row padding stay compact.
     *
     * Guarded on the specific numbers rather than "smaller", because the two
     * things that could creep back while editing something else are a photo
     * column wide enough to squeeze the price list out of shape, and roomy row
     * padding that undoes the two-up grid.
     *
     * The width has to *step* with the card rather than hold one value: the cards
     * are half the grid from `md` up, so the column that is right beside a
     * full-width price list at `sm` is twice as much of the card at `md`. Asserted
     * as the whole progression rather than one breakpoint, because dropping any
     * one of them would collapse it to whatever is left — which is either a
     * column squeezed into nothing or a photo with no room to be a photo.
     */
    public function test_the_card_photo_column_and_row_padding_are_compact(): void
    {
        [, $html] = $this->render(4);

        // The photo fills its own column and takes the card's full height rather
        // than being a fixed-height panel inside it.
        $this->assertStringContainsString('sm:w-40', $html);
        $this->assertStringContainsString('md:w-32', $html);
        $this->assertStringContainsString('lg:w-48', $html);
        $this->assertStringContainsString('xl:w-56', $html);
        $this->assertStringNotContainsString('lg:h-full', $html);
        $this->assertStringNotContainsString('sm:w-1/2', $html);

        // Card padding down from p-6 sm:p-8; rows down from py-3 to py-2.
        $this->assertStringContainsString('p-4 sm:p-5', $html);
        $this->assertStringContainsString('py-2 first:pt-0', $html);
        $this->assertStringNotContainsString('p-6 sm:p-8', $html);
    }

    /**
     * The photo sits centred in a fixed-ratio box, cropped, never stretched.
     *
     * The important part is `self-center`, and it is easy to lose. The card is a
     * flex row, and a flex item is `align-self: stretch` by default — which sets
     * its height to the height of the tallest item beside it and **overrides the
     * aspect ratio**. The browser still reports `aspect-ratio` and then ignores
     * it, which is why the ratio alone was not enough: the photo came out
     * 292px tall collapsed and 562px expanded on the same card, and 292px against
     * 206px between two cards.
     *
     * So all four of these have to be true together:
     *
     *   - `self-center` on the wrapper, so its height comes from the ratio and the
     *     length of the price list cannot reach it;
     *   - a known ratio, `sm:aspect-[5/7]`, so only the width decides the height;
     *   - `object-cover` + `object-center`, so the photo fills that box, crops the
     *     overflow and keeps its own proportions;
     *   - `overflow-hidden`, so a crop cannot escape the card's rounded corner.
     *
     * The ratio and the centring are asserted separately on purpose: the ratio
     * was present and correct in the version that still stretched, so a test that
     * only checked the ratio would have gone green on the bug.
     */
    public function test_the_category_photo_is_centred_and_cropped_not_stretched(): void
    {
        [, $html] = $this->render(4);

        // The box: a known ratio, a clip, and — the fix — `self-center`.
        $this->assertMatchesRegularExpression(
            '/<div class="order-first flex aspect-\[16\/9\][^"]*self-center[^"]*overflow-hidden[^"]*">/',
            $html,
            'The photo box must not be allowed to stretch with the card.',
        );

        $this->assertStringContainsString('sm:aspect-[5/7]', $html);
        $this->assertStringContainsString('items-center justify-center', $html);

        // `stretch` anywhere on this element would put the bug straight back.
        $this->assertStringNotContainsString('sm:self-stretch', $html);
        $this->assertStringNotContainsString('self-stretch', $html);

        // The image: fills the box, crops to it, and is centred within it.
        $this->assertMatchesRegularExpression(
            '/<img[^>]*class="h-full w-full object-cover object-center"/',
            $html,
            'The photo must be cropped into its box and centred, not scaled to fit.',
        );

        // `object-fill` is the CSS default and is what "stretched" looks like.
        $this->assertStringNotContainsString('object-fill', $html);
        $this->assertStringNotContainsString('object-contain', $html);
    }

    /**
     * Both cards' photos are the same size.
     *
     * Asserted across two categories of different lengths, which is the pair that
     * disagreed before: a ten-service card and a three-service card rendered
     * photos of 292px and 206px from the same markup, because the height was
     * coming from the list. One shared element means one size.
     */
    public function test_both_cards_render_the_same_photo_markup(): void
    {
        $this->makeService(['name' => 'Gel Manicure', 'category' => 'Manicure & Pedicure']);
        $this->makeService(['name' => 'Gel Pedicure', 'category' => 'Manicure & Pedicure']);
        $this->makeService(['name' => 'Brow Threading', 'category' => 'Threading']);

        $html = $this->get(route('services.index'))->assertOk()->getContent();

        $wrapper = '/<div class="order-first flex aspect-\[16\/9\][^"]*self-center[^"]*overflow-hidden[^"]*">/';

        $this->assertSame(
            2,
            preg_match_all($wrapper, $html),
            'Both cards must use the identical photo wrapper, so they cannot differ in size.',
        );

        $this->assertSame(
            2,
            substr_count($html, 'class="h-full w-full object-cover object-center"'),
        );
    }

    /**
     * One photo per category card, in either state.
     *
     * Collapsed and expanded cards render the same element through the same loop,
     * so there is only one to get wrong — but only if the classes really are
     * shared. Asserted against a category long enough to have a "See All" button,
     * so a class dropped for the overflow rows could not hide behind the
     * short-category case.
     *
     * The disclosure reveals service rows; it does not reveal a second copy of
     * the photo, so one card is one photo however far the list is expanded.
     */
    public function test_the_cropped_photo_survives_the_expanded_card_and_the_stacked_layout(): void
    {
        // Nine services in one category: four shown, five behind the disclosure.
        [, $html] = $this->render(9);

        $this->assertStringContainsString('See More (5 more)', $html, 'This category should have an overflow to reveal.');

        $this->assertSame(
            1,
            substr_count($html, 'class="h-full w-full object-cover object-center"'),
            'One photo per category card, in either state.'
        );

        // Nothing about the photo's box may depend on how long the list is. No
        // percentage height to resolve against a box that moves with the list,
        // and no `h-full` on the wrapper that would reinstate the stretch.
        $this->assertStringNotContainsString('sm:h-auto', $html);
        $this->assertStringNotContainsString('absolute inset-0', $html);

        // Two ratios, one per breakpoint: a wide banner on a phone, a portrait
        // crop on a desktop. Both are declarations, not measurements — which is
        // the whole point, since the measurement was what used to move.
        $this->assertSame(1, substr_count($html, 'aspect-[16/9]'));
        $this->assertSame(1, substr_count($html, 'sm:aspect-[5/7]'));
    }

    /**
     * Below `sm` the two columns cannot both fit, so the photo stacks — on top.
     *
     * Asserted as `order-first` plus `sm:order-none` because that is the pair
     * that says "above the list on a phone, back in the right-hand column from
     * `sm` up", which is the part of the responsive behaviour a rendered page at
     * one viewport cannot prove.
     */
    public function test_the_photo_stacks_above_the_list_on_a_narrow_screen(): void
    {
        [, $html] = $this->render();

        $this->assertStringContainsString('order-first', $html);
        $this->assertStringContainsString('sm:order-none', $html);
    }

    /**
     * Both copies of a service row come from one component.
     *
     * The overflow rows used to be a second, hand-copied block in the view, and
     * the two had drifted: only the visible copy dropped its top padding, so
     * revealing the fifth service left a double gap above it. Asserting the
     * component is used for both is what stops a third copy appearing.
     */
    public function test_visible_and_hidden_rows_come_from_one_component(): void
    {
        [, $html] = $this->render(6);

        $this->assertSame(6, substr_count($html, '₱'), 'Every service should render one price.');
        $this->assertSame(6, substr_count($html, 'Book Now'), 'Every service should render one book action.');

        // How many times the component is used, from the view source rather than
        // the output: Blade compiles `<x-salon.service-row>` away, so the tag
        // cannot appear in what it renders. Two call sites in the view is what
        // "one component, used twice" looks like.
        $view = (string) file_get_contents(resource_path('views/services/index.blade.php'));

        $this->assertSame(
            2,
            substr_count($view, '<x-salon.service-row'),
            'The visible rows and the overflow rows should both come from the component.',
        );

        // And the overflow rows reach it carrying the directives that hide them,
        // rather than re-declaring its markup.
        $this->assertMatchesRegularExpression(
            '/<x-salon\.service-row\s+:service="\$service"\s+x-show="expanded"/',
            $view,
        );

        $component = (string) file_get_contents(
            resource_path('views/components/salon/service-row.blade.php'),
        );
        $this->assertStringContainsString('{{ $attributes }}', $component);
    }

    /**
     * The single service page has no breadcrumb.
     *
     * It was hand-written markup rather than a shared component, so removing it
     * could not affect anything else — asserted here so a later change that adds
     * a breadcrumb back (or reintroduces it as a shared component across the
     * site) has to be deliberate.
     *
     * The spacing matters as much as the markup: the `<nav>` carried `mb-6`, so
     * removing the element and leaving the margin would open the page with an
     * empty band where the trail used to be.
     */
    public function test_the_single_service_page_has_no_breadcrumb(): void
    {
        $this->makeService(['name' => 'Arm Paraffin', 'slug' => 'arm-paraffin', 'category' => 'Manicure & Pedicure']);

        $html = $this->get(route('services.show', 'arm-paraffin'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('aria-label="Breadcrumb"', $html);
        $this->assertStringNotContainsString('mb-6 flex items-center gap-2', $html);

        // What was on the page has not been lost with the trail: the name is the
        // heading and the category is still a chip on the card.
        $this->assertStringContainsString('Arm Paraffin', $html);
        $this->assertStringContainsString('Manicure &amp; Pedicure', $html);
    }

    /**
     * No page in the customer site grew a shared breadcrumb by accident.
     *
     * The removal was local to `services/show.blade.php`. This pins that, so a
     * shared `x-ui.breadcrumb` cannot appear somewhere else and quietly reintroduce
     * the trail the client asked to lose.
     */
    public function test_the_breadcrumb_is_not_a_shared_component(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/components/ui/breadcrumb.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/components/breadcrumb.blade.php'));

        // Nothing under resources/views may reference a breadcrumb component.
        $hits = [];
        foreach (glob(resource_path('views/**/*.blade.php')) ?: [] as $file) {
            if (stripos((string) file_get_contents($file), 'x-ui.breadcrumb') !== false) {
                $hits[] = $file;
            }
        }

        $this->assertSame([], $hits, 'No view should mount a breadcrumb component.');
    }

    /**
     * A service row's only link is "Book Now".
     *
     * The name used to wrap an `<a>` to the single-service page, which made the
     * row offer two routes to the same destination: the name went to a page
     * carrying a heading, a price and a Book button, and the button went straight
     * to the booking form. One of them is now the only one.
     *
     * Asserted on the rendered page rather than on the component source, because
     * the requirement is about what a customer can click: a link could be added
     * to either and this is the test that would notice.
     */
    public function test_a_service_row_has_no_link_other_than_book_now(): void
    {
        [, $html] = $this->render(4);

        // Every anchor on the page that mentions a service or books one.
        preg_match_all('/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/s', $html, $matches, PREG_SET_ORDER);

        $serviceAnchors = [];

        foreach ($matches as $match) {
            $href = $match[1];

            // A single-service path is `/services/<slug>`. The listing itself is
            // `/services` with nothing after the slash, so a prefix test is
            // enough here and does not need a regex to tell them apart.
            if (str_starts_with($href, '/services/') || str_contains($href, '/book')) {
                $serviceAnchors[] = trim(preg_replace('/\s+/', ' ', strip_tags($match[2])));
            }
        }

        $this->assertNotEmpty($serviceAnchors, 'Book Now buttons should still be links.');

        foreach ($serviceAnchors as $label) {
            $this->assertSame(
                'Book Now',
                $label,
                "A service row should offer only \"Book Now\", found a link reading \"{$label}\".",
            );
        }
    }

    /**
     * No view links to the single-service page any more.
     *
     * The row was the only link to it, so this is the check that the page cannot
     * quietly start being linked again from a listing. The route and the page
     * both still exist and are still reachable by URL — this only says nothing
     * *links* to them.
     */
    public function test_no_view_links_to_the_single_service_page(): void
    {
        $hits = [];

        foreach (glob(resource_path('views/**/*.blade.php')) ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), 'services.show')) {
                $hits[] = $file;
            }
        }

        $this->assertSame([], $hits, 'No view should link to services.show any more.');

        // The route and its page are untouched, so the URL still works.
        $this->get(route('services.show', 'no-such-service'))->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /* 2. Four services, then "See More"                                    */
    /* ------------------------------------------------------------------ */

    public function test_four_services_are_shown_and_a_fifth_is_hidden_behind_the_toggle(): void
    {
        [, $html] = $this->render(5);

        $this->assertStringContainsString('See More (1 more)', $html);
        $this->assertStringContainsString('See Less', $html);

        // The disclosure is wired to its own component and its own state.
        $this->assertStringContainsString('serviceCategory()', $html);
        $this->assertStringContainsString('x-on:click="toggle()"', $html);
    }

    public function test_a_category_of_four_or_fewer_has_no_toggle_at_all(): void
    {
        [, $html] = $this->render(4);

        $this->assertStringNotContainsString('See More', $html);
        $this->assertStringNotContainsString('See All', $html);
        $this->assertStringNotContainsString('serviceCategory()', $html);
    }

    /**
     * The hidden services are in the document, hidden by `x-show` — not absent.
     *
     * That distinction is the whole point of the disclosure: the prices are
     * server-rendered once, so "See All" reveals them without a request and
     * without a second source of truth about what the category costs.
     */
    public function test_the_hidden_services_are_rendered_but_not_shown(): void
    {
        [, $html] = $this->render(6);

        for ($i = 1; $i <= 6; $i++) {
            $this->assertStringContainsString('Manicure &amp; Pedicure Service '.$i, $html);
        }

        $this->assertStringContainsString('x-show="expanded"', $html);
    }

    /**
     * The button says what it will do, and how much is behind it.
     *
     * It is now "See All" rather than "See More (2 more)": the label is the same
     * in every category, so it cannot be wrong about a number, and the toggle
     * reads as one control changing state rather than two different buttons. The
     * size of the list is still there for anyone who wants it, as the screen
     * reader text next to it — which is why the count is asserted at all.
     */
    /**
     * The label says how much is hidden, not just that something is.
     *
     * Was the opposite: "See All" visible, with the count tucked into an
     * `sr-only` span for screen readers only. That is backwards. The count is
     * the part a customer uses to decide whether to bother — "See More (6
     * more)" is a decision, "See All" is a promise with no size attached.
     */
    public function test_the_disclosure_names_how_many_services_are_hidden(): void
    {
        [, $html] = $this->render(9);

        // Five of the nine are behind the disclosure.
        $this->assertStringContainsString('See More (5 more)', $html);
        $this->assertStringContainsString('See Less', $html);
    }

    /** Both labels are cloaked, so neither paints before Alpine boots. */
    public function test_both_labels_are_cloaked_so_neither_paints_before_alpine(): void
    {
        [, $html] = $this->render(9);

        // Without `x-cloak` on the *first* span, both labels are in the document
        // at once and the button briefly reads "See More (6 more)See Less".
        $this->assertMatchesRegularExpression(
            '/<span x-show="! expanded" x-cloak>See More \(\d+ more\)<\/span>/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<span x-show="expanded" x-cloak>See Less<\/span>/',
            $html,
        );
    }

    /**
     * The control is named by what it discloses, not only by its verb.
     *
     * A price list with seven categories renders seven buttons that all read
     * "See More". As a list of links that is seven copies of the same control,
     * so the category name goes in an `sr-only` span next to the visible label.
     */
    public function test_the_disclosure_is_named_for_a_screen_reader(): void
    {
        [, $html] = $this->render(6);

        $this->assertMatchesRegularExpression(
            '/sr-only[^>]*>Manicure &amp; Pedicure services</',
            $this->unescaped($html),
        );
    }

    /**
     * The disclosure sits at the bottom of the card, on the right.
     *
     * `mt-auto` was already pinned above; what is new is the alignment. It
     * belongs to the price list it discloses, so it is right-aligned against the
     * list's own edge rather than floating at the far end of the whole card,
     * under the photo.
     */
    public function test_the_disclosure_sits_at_the_bottom_right_of_the_card(): void
    {
        [, $html] = $this->render(6);

        $this->assertMatchesRegularExpression(
            '/class="mt-auto flex justify-end[^"]*"[^>]*>\s*<button[^>]*>\s*<span x-show="! expanded" x-cloak>See More \(\d+ more\)/',
            $html,
        );
    }

    /* ------------------------------------------------------------------ */
    /* 3. Each line still sells the service                                 */
    /* ------------------------------------------------------------------ */

    public function test_each_service_shows_its_name_price_and_a_book_action(): void
    {
        $service = $this->makeService([
            'name' => 'Gel Manicure',
            'category' => 'Manicure & Pedicure',
            'price' => 350,
            'duration_minutes' => 60,
        ]);

        $html = $this->get(route('services.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Gel Manicure', $html);
        $this->assertStringContainsString('₱350', $html);
        $this->assertStringContainsString('Book Now', $html);

        // The duration used to sit under the name here too ("1 hr"). The list is
        // a price list, so the row is the name, the price and the action.
        $this->assertStringNotContainsString('1 hr', $html, 'No duration on the price list.');

        // Book Now goes to the booking form with the service pre-selected, the
        // same target the old service card used.
        $this->assertStringContainsString(
            route('appointments.create', ['services' => $service->slug]),
            $this->unescaped($html),
        );
    }

    /* ------------------------------------------------------------------ */
    /* 4. What must NOT be there                                           */
    /* ------------------------------------------------------------------ */

    /**
     * No pagination.
     *
     * It used to paginate the flat list, which is what let a category straddle
     * a page boundary — a heading on one page and its remaining services on the
     * next, with no way to tell they belong together.
     */
    public function test_the_list_is_not_paginated(): void
    {
        // More rows than the old 12-per-page limit.
        for ($i = 0; $i < 14; $i++) {
            $this->makeService(['name' => 'Service '.$i, 'category' => 'Manicure & Pedicure']);
        }

        $html = $this->get(route('services.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Service 13', $html);
        $this->assertStringNotContainsString('page=2', $this->unescaped($html));
    }

    /** The rating feature is gone, so no stars and no counts appear here. */
    public function test_no_rating_ui_is_rendered(): void
    {
        $this->makeService(['name' => 'Gel Manicure', 'category' => 'Manicure & Pedicure']);

        $html = $this->get(route('services.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('star-rating', $html);
        $this->assertStringNotContainsString('reviews_count', $html);
    }

    /* ------------------------------------------------------------------ */
    /* 5. Where the image comes from                                       */
    /* ------------------------------------------------------------------ */

    /**
     * A photo uploaded against the category beats every other source.
     *
     * The whole point of the field: it is the only step in the chain the salon
     * controls without a developer, so it has to win outright. A category with
     * its own photo *and* a service in it with a photo is the case that proves
     * the order rather than merely the presence.
     */
    public function test_an_uploaded_category_photo_beats_a_service_photo_and_the_config(): void
    {
        Storage::fake('public');

        $category = ServiceCategory::create([
            'name' => 'Brow & Lash Extension',
            'color' => '#4F46E5',
            'sort_order' => 0,
            'photo' => 'categories/brow-lash.jpg',
        ]);

        Storage::disk('public')->put($category->photo, 'x');

        // Both fallbacks that used to answer for this category are in play here,
        // so neither can be what the page ends up rendering.
        $this->makeService([
            'name' => 'Lash Extensions',
            'category' => 'Brow & Lash Extension',
            'photo_path' => 'photos/lash.jpg',
        ]);

        Storage::disk('public')->put('photos/lash.jpg', 'x');

        $rendered = $this->unescaped($this->get(route('services.index'))->assertOk()->getContent());

        $this->assertSame('storage/categories/brow-lash.jpg', $category->imagePath());
        $this->assertStringContainsString('storage/categories/brow-lash.jpg', $rendered);
        $this->assertStringNotContainsString('storage/photos/lash.jpg', $rendered);
    }

    /**
     * A category that has never had a photo still renders one.
     *
     * The column is nullable on purpose, so every category that existed before
     * it was added has nothing in it. Asserted through the rest of the chain —
     * service photo, then the configured file, then the shared default — because
     * a new first step is exactly where a row with nothing in it falls through to
     * a broken image.
     */
    public function test_a_category_with_no_photo_of_its_own_still_resolves_one(): void
    {
        $category = ServiceCategory::create(['name' => 'Threading', 'color' => '#65A30D', 'sort_order' => 0]);

        $this->assertNull($category->photo);
        $this->assertNull($category->photo_url, 'The raw upload accessor has nothing to report.');

        // Falls through to the configured image for this name.
        $this->assertSame(ServiceCategory::configuredImageFor('Threading'), $category->imagePath());

        // …and a name with no configured entry lands on the shared default.
        $unconfigured = ServiceCategory::firstOrNew(['name' => 'A Category Nobody Configured']);

        $this->assertSame((string) config('salon.default_category_image'), $unconfigured->imagePath());
        $this->assertStringStartsWith('http', $unconfigured->imageUrl());
    }

    /** The literal string "NULL" is not a photo on the category's own column. */
    public function test_a_category_photo_of_the_literal_string_null_is_not_treated_as_a_photo(): void
    {
        $category = ServiceCategory::create([
            'name' => 'Spa',
            'color' => '#8B5CF6',
            'sort_order' => 0,
            'photo' => 'NULL',
        ]);

        $this->assertNull($category->photo_url);
        $this->assertSame(ServiceCategory::configuredImageFor('Spa'), $category->imagePath());
        $this->assertStringNotContainsString('/storage/NULL', $category->imageUrl());
    }

    /**
     * An uploaded service photo beats the configured category image.
     *
     * This is what makes the config a fallback rather than a hardcoded list: the
     * salon can replace these one at a time by uploading a photo against a
     * service, without anyone editing `config/salon.php`.
     */
    public function test_an_uploaded_service_photo_wins_over_the_configured_image(): void
    {
        Storage::fake('public');

        $this->makeService([
            'name' => 'Gel Manicure',
            'category' => 'Manicure & Pedicure',
            'photo_path' => 'photos/gel.jpg',
        ]);

        Storage::disk('public')->put('photos/gel.jpg', 'x');

        $html = $this->get(route('services.index'))->assertOk()->getContent();

        $this->assertStringContainsString('storage/photos/gel.jpg', $this->unescaped($html));
    }

    /**
     * A `photo_path` holding the literal string "NULL" is not a photo.
     *
     * `whereNotNull()` rules out the absence of a value, not a value that spells
     * the absence of one out — and a row cleared by a script or an import quite
     * plausibly holds those four characters rather than a real null. That is
     * exactly what was in this project's database, and left unchecked it made
     * every category image resolve to `storage/NULL`: a 404 on the Services
     * page, for every category, with the layout looking otherwise correct.
     */
    public function test_a_photo_path_of_the_literal_string_null_is_not_treated_as_a_photo(): void
    {
        Service::create([
            'name' => 'Brow Threading',
            'slug' => 'brow-threading',
            'category' => 'Threading',
            'price' => 150,
            'duration_minutes' => 30,
            'is_active' => true,
            'photo_path' => 'NULL',
        ]);

        $category = ServiceCategory::firstOrNew(['name' => 'Threading']);

        $this->assertSame(
            ServiceCategory::configuredImageFor('Threading'),
            $category->imagePath(),
        );
        $this->assertStringNotContainsString('NULL', $category->imageUrl());
    }

    /** Every category resolves to some image, so none renders a broken one. */
    public function test_every_category_resolves_to_an_image(): void
    {
        ServiceCategory::create(['name' => 'A Category Nobody Configured', 'color' => '#7A241B', 'sort_order' => 99]);

        $this->makeService(['name' => 'Mystery Service', 'category' => 'A Category Nobody Configured']);

        $html = $this->get(route('services.index'))->assertOk()->getContent();

        $this->assertStringContainsString(
            asset((string) config('salon.default_category_image')),
            $this->unescaped($html),
        );

        // And no category on the page resolves to a broken path.
        $this->assertStringNotContainsString('/storage/NULL', $this->unescaped($html));
    }
}
