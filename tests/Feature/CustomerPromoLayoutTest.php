<?php

namespace Tests\Feature;

use App\Models\Promo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the customer promo page and the site footer.
 *
 * Both are layout, and a media query is not something a rendered page can prove
 * at any one viewport — so these assert the classes that *are* the rule, the way
 * `ServicesCategoryLayoutTest` does, rather than trying to render at a width.
 *
 * The promo page is included because its card changed from a picture above the
 * text to a picture beside it, and because the description stopped being
 * truncated: the offer copy is the thing a customer reads, so cutting it to a
 * character count threw away the actual offer.
 */
class CustomerPromoLayoutTest extends TestCase
{
    use RefreshDatabase;

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
    /* The promo card                                                     */
    /* ------------------------------------------------------------------ */

/**
     * The card is two areas: a picture and a body. On desktop the picture is a
     * 40% panel on the left and the text takes the rest; below `md` they stack,
     * with the picture as a band above the text.
     *
     * The layout is the reference's promo card — a flex row with
     * `align-items: stretch`, so both panels are exactly as tall as each other
     * and the text is centred in its own. It used to be `md:grid-cols-2` with
     * `items-start`, which left a long description growing the card downward with
     * the picture floating at the top of a tall empty column.
     *
     * The responsive half is asserted against `app.css` rather than the markup:
     * these are semantic classes, so the utilities live in the stylesheet
     * and the HTML carries only `home-promo-card`. Asserting `md:flex-row` in
     * the page would pass or fail for reasons that have nothing to do with the
     * card.
     */
public function test_the_promo_card_puts_the_picture_beside_the_text_on_desktop(): void
    {
        $this->makePromo();

        $html = $this->get(route('promos.index'))->assertOk()->getContent();

        foreach (['home-promo-card', 'home-promo-media', 'home-promo-body', 'home-promo-grid'] as $class) {
            $this->assertStringContainsString($class, $html, 'The promo page should still use '.$class.'.');
        }

        $card = $this->ruleFor('home-promo-card');

        $this->assertStringContainsString('flex', $card, 'The card is a flex row: picture beside text.');
        $this->assertStringContainsString('md:flex-row', $card, 'The two panels should sit side by side from md.');
        $this->assertStringContainsString('md:items-stretch', $card, 'Both panels should be exactly as tall as each other.');

        // 28px rounded corners clipping the whole card, as the reference has.
        $this->assertStringContainsString('rounded-[28px]', $card);

        $this->assertStringNotContainsString('md:grid-cols-3', $card);

        $media = $this->ruleFor('home-promo-media');

        // A minimum height rather than an aspect ratio.
        //
        // The ratio is what this used to carry — `aspect-[16/9]` on mobile and
        // `md:aspect-[4/5]` on desktop — and it fixed the panel's shape
        // independently of the picture in it. A promo image is a portrait poster,
        // so it fitted inside a landscape or 4:5 box with most of the panel
        // empty. A minimum height lets the picture and the text decide the card's
        // height between them instead.
        $this->assertStringContainsString('min-h-[240px]', $media, 'The picture should be a band on mobile.');
        $this->assertStringContainsString('md:min-h-[420px]', $media, '…and a substantial panel on desktop.');
        $this->assertStringContainsString('md:w-[40%]', $media, 'At md the picture gets the reference\'s 40% share of the card.');
        $this->assertStringContainsString('md:flex-none', $media, '…and keeps it: in a flex row, width alone is only a suggestion.');
        $this->assertStringNotContainsString('aspect-[', $media, 'The panel must not fix a shape the picture then has to fit inside.');

        // The picture must NOT be told to match the text. That was the rule
        // that made a long offer cramped: the image grew with the text column
        // instead of the text growing to fill the space the image left.
        $this->assertStringNotContainsString('md:self-stretch', $media);
        $this->assertStringNotContainsString('md:aspect-auto', $media);

        // No fixed height on the card, in any breakpoint. The panels decide it
        // between them, so a fixed height would clamp the text again.
        $this->assertStringNotContainsString('h-[280px]', $card);
        $this->assertStringNotContainsString('h-[290px]', $card);
        $this->assertStringNotContainsString('h-[320px]', $card);
        $this->assertDoesNotMatchRegularExpression('/\bh-\[?\d/', $card, 'The card must not carry a fixed height.');

        // Cards are stacked one per row, so they never stretch to match each
        // other — which is what `items-start` used to be guarding against, and
        // is now true of the grid rather than of the card's internals.
        $grid = $this->ruleFor('home-promo-grid');
        $this->assertStringContainsString('max-w-[1100px]', $grid, 'The list is the reference\'s 1100px measure.');
        $this->assertStringContainsString('gap-10', $grid, '…with the reference\'s roomy gap.');
        $this->assertDoesNotMatchRegularExpression('/grid-cols-[2-9]/', $grid, 'Promos are one per row, not a grid of cards.');

        $body = $this->ruleFor('home-promo-body');
        $this->assertStringContainsString('flex', $body);
        $this->assertStringContainsString('flex-1', $body, 'The text panel takes the width the picture did not.');
        $this->assertStringContainsString('justify-center', $body, '…and centres its copy vertically, as the reference does.');
    }

    /** The picture is the left area, and the text the right one. */
    public function test_the_picture_comes_before_the_text_in_the_card(): void
    {
        $this->makePromo();

        $html = $this->get(route('promos.index'))->assertOk()->getContent();

        $media = strpos($html, 'home-promo-media');
        $body = strpos($html, 'home-promo-body');

        $this->assertNotFalse($media);
        $this->assertNotFalse($body);
        $this->assertLessThan($body, $media, 'The picture area should precede the text area.');
    }

    /**
     * The whole description, with its paragraphs.
     *
     * It used to be truncated to 140 characters with the tags stripped, which
     * cut the offer in half on the one screen whose job is to explain the offer.
     */
    public function test_the_whole_description_is_shown(): void
    {
        $long = 'First paragraph of the offer. '.str_repeat('And a good deal more detail. ', 20);

        $this->makePromo(['description' => $long]);

        $html = $this->get(route('promos.index'))->assertOk()->getContent();

        $this->assertStringContainsString('And a good deal more detail.', $html);
        $this->assertStringNotContainsString('…', $html, 'The description should not be truncated.');

        /*
         * The stylesheet must not clamp it either.
         *
         * The assertion above only proves the copy reached the HTML. It was
         * passing while the page still truncated, because a five-line
         * `-webkit-line-clamp` hides the overflow without changing the markup —
         * so the text is present in the source and invisible on the screen.
         *
         * This is the half that was missed. A long offer has to be readable, not
         * merely present, so the rule is asserted directly.
         */
        $description = $this->ruleFor('home-promo-body > p');

        foreach (['line-clamp', '-webkit-box', 'text-ellipsis', 'overflow-hidden'] as $clamp) {
            $this->assertStringNotContainsString(
                $clamp,
                $description,
                'The description must not be clamped; the card grows to fit it instead.',
            );
        }

        // And the text panel centres what is left, rather than stretching the
        // description to fill the column.
        //
        // The description used to carry `flex-1` so a short promo pushed its
        // foot down to the bottom of the column. The body is now `justify-center`
        // — the reference's arrangement, where a two-line offer sits in the
        // middle of its panel instead of clinging to the top with a gap beneath
        // it. `flex-1` and `justify-center` both "fill the panel", so only one
        // of them can be asserted, and this is the one the card now uses.
        $this->assertStringNotContainsString('flex-1', $description);
        $this->assertStringContainsString(
            'justify-center',
            $this->ruleFor('home-promo-body'),
            'The text panel should centre its copy, as the reference card does.',
        );

        // The description is escaped text, not markup.
        $this->makePromo(['description' => 'Use <b>bold</b> & care.']);
        $html = $this->get(route('promos.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Use &lt;b&gt;bold&lt;/b&gt; &amp; care.', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html, 'Promo copy is text, never markup.');
    }

/** Paragraph breaks survive, via `whitespace-pre-line` rather than `<br>`. */
public function test_description_paragraphs_survive_without_markup(): void
    {
        $this->makePromo(['description' => "First line.\n\nSecond line."]);

        $html = $this->get(route('promos.index'))->assertOk()->getContent();

        // The rule itself is in the stylesheet; the text is in the markup.
        $this->assertStringContainsString(
            'whitespace-pre-line',
            $this->ruleFor('home-promo-body > p'),
            'Paragraph breaks should survive as CSS, not as <br> tags.',
        );

        $this->assertStringContainsString('First line.', $html);
        $this->assertStringContainsString('Second line.', $html);
    }

    /** A saved picture is shown; an absent one gets the flourish. */
    public function test_a_promo_with_a_picture_shows_it_and_one_without_gets_the_flourish(): void
    {
        $this->makePromo(['title' => 'Has a picture'])->forceFill([
            'image_path' => 'promos/offer.jpg',
        ])->save();

        $this->makePromo(['title' => 'No picture']);

        $html = $this->get(route('promos.index'))->assertOk()->getContent();

        $this->assertStringContainsString('/storage/promos/offer.jpg', $html);
        $this->assertStringContainsString('home-promo-flourish', $html, 'A promo with no picture falls back to the flourish.');

        // The picture is inside the media area, not loose in the card.
        $media = substr($html, (int) strpos($html, 'home-promo-media'), 2000);
        $this->assertStringContainsString('/storage/promos/offer.jpg', $media);
    }

/** Validity sits in the text column, where it cannot overlap the picture. */
public function test_validity_lives_in_the_text_column(): void
    {
        $promo = $this->makePromo();

        $html = $this->get(route('promos.index'))->assertOk()->getContent();

        $media = strpos($html, 'home-promo-media');
        $body = strpos($html, 'home-promo-body');
        $validity = strpos($html, $promo->validity_label);

        $this->assertNotFalse($validity, 'The promo card should show its validity window.');
        $this->assertGreaterThan($body, $validity, 'Validity belongs in the text column, not over the picture.');
        $this->assertGreaterThan($media, $validity);
    }

    /** The card's call to action goes to the services the promo advertises. */
    public function test_the_promo_card_links_to_the_services_section(): void
    {
        $this->makePromo();

        $html = $this->get(route('promos.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Book Now', $html);
        $this->assertStringContainsString('href="'.route('home').'#services"', $html);
    }

    /**
     * The validity filtering is unchanged by the redesign: only promos that are
     * active and inside their window are listed at all.
     */
    public function test_only_active_and_currently_valid_promos_are_listed(): void
    {
        $live = $this->makePromo(['title' => 'Running now']);

        $this->makePromo(['title' => 'Switched off', 'is_active' => false]);
        $this->makePromo([
            'title' => 'Already over',
            'starts_at' => today()->subDays(30),
            'ends_at' => today()->subDay(),
        ]);
        $this->makePromo([
            'title' => 'Not started',
            'starts_at' => today()->addDay(),
            'ends_at' => today()->addDays(30),
        ]);

        $html = $this->get(route('promos.index'))->assertOk()->getContent();

        $this->assertStringContainsString($live->title, $html);
        $this->assertStringNotContainsString('Switched off', $html);
        $this->assertStringNotContainsString('Already over', $html);
        $this->assertStringNotContainsString('Not started', $html);
    }

    /* ------------------------------------------------------------------ */
    /* The footer                                                         */
    /* ------------------------------------------------------------------ */

    /** Three columns: brand, quick links, visit us. */
    public function test_the_footer_is_three_columns(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();
        $footer = $this->footerOf($html);

        $this->assertMatchesRegularExpression(
            '/<div class="grid[^"]*md:grid-cols-3/',
            $footer,
            'The footer should be a three-column grid from md up.',
        );

        $this->assertStringContainsString('Quick Links', $footer);
        $this->assertStringContainsString('Visit Us', $footer);
    }

    /** The quick links are the public pages, and none of them needs a sign-in. */
    public function test_the_footer_links_to_the_public_pages(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();
        $footer = $this->footerOf($html);

        foreach ([
            'Home' => route('home'),
            'Services' => route('services.index'),
            'Promo Offers' => route('promos.index'),
            'About Us' => route('about'),
            'Contact Us' => route('contact.create'),
        ] as $label => $url) {
            $this->assertStringContainsString($label, $footer, 'The footer should link to '.$label.'.');
            $this->assertStringContainsString('href="'.$url.'"', $footer);
        }
    }

    /** The bottom bar is back: a copyright and the way to the staff portal. */
    public function test_the_footer_has_a_bottom_bar(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();
        $footer = $this->footerOf($html);

        $this->assertStringContainsString('&copy; '.now()->year, $footer);

        // Escaped, because the trading name contains an ampersand.
        $this->assertStringContainsString(e(config('salon.name')), $footer);
        $this->assertStringContainsString('href="'.route('login').'"', $footer);
    }

    /**
     * Every contact fact comes from `config/salon.php`, and the Facebook row is
     * named rather than printing its URL.
     *
     * They used to be typed out in the footer separately and had already drifted
     * — the `tel:` link dialled one number while the text beside it read another.
     */
    public function test_the_footer_contact_facts_come_from_config(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();
        $footer = $this->footerOf($html);

        $this->assertStringContainsString(config('salon.address'), $footer);
        $this->assertStringContainsString(config('salon.phone_display'), $footer);
        $this->assertStringContainsString('tel:'.config('salon.phone_e164'), $footer);
        $this->assertStringContainsString('mailto:'.config('salon.email'), $footer);
        $this->assertStringContainsString('href="'.config('salon.facebook_url').'"', $footer);

        // Named, not spelled out.
        $this->assertStringContainsString(config('salon.facebook_label'), $footer);
        $this->assertStringNotContainsString('>https://www.facebook.com/profile.php', $footer);
        $this->assertStringContainsString('Balai ti Arjud', $footer, 'The trading name is "Arjud".');
    }

    /**
     * The footer sits at the bottom of a short page rather than floating in the
     * middle of it: `mt-auto` plus the layout's flex column.
     */
    public function test_the_footer_is_pushed_to_the_bottom_of_the_page(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        // `x-data` sits on the body ahead of the class list (see the layout:
        // Alpine does not walk from the body on its own), so the attribute is
        // matched anywhere in the tag rather than assumed to come first.
        $this->assertMatchesRegularExpression(
            '/<body\b[^>]*class="[^"]*flex-col/',
            $html,
            'The body should be a flex column for the footer to be pushed down.',
        );
        $this->assertMatchesRegularExpression(
            '/<main id="main" class="[^"]*flex-1/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<footer class="[^"]*mt-auto/',
            $html,
            'The footer should carry mt-auto.',
        );
    }

    /**
     * The About page's four contact rows are uniform.
     *
     * Facebook used to be the one row with no circular badge, which is what
     * "make them all uniform" meant. Heroicons has no brand mark, so that row
     * passes its glyph through the component's `badge` slot and gets the same
     * circle as the other three.
     */
    public function test_every_about_contact_row_has_a_badge(): void
    {
        $html = $this->get(route('about'))->assertOk()->getContent();

        // The badge component: a gold circle.
        preg_match_all('/class="[^"]*rounded-full bg-gold-light\/50 text-gold-dark[^"]*"/', $html, $badges);

        $this->assertGreaterThanOrEqual(
            4,
            count($badges[0]),
            'All four contact rows in the Find Us card should carry a circular badge.',
        );

        // And the Facebook row is one of them.
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(config('salon.facebook_url'), '/').'"/',
            $html,
        );
    }

    /**
     * The body of one CSS rule from `app.css`.
     *
     * The promo card is built from semantic classes, so the responsive half of
     * each rule lives in the stylesheet and never appears in the markup. Reading
     * the rule is how those assertions are made honestly — the alternative is
     * to assert a Tailwind utility against the HTML, where it is absent for a
     * reason that has nothing to do with the card.
     */
    private function ruleFor(string $selector): string
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css, 'app.css should be readable.');

        preg_match('/\.'.preg_quote($selector, '/').'\s*\{([^}]*)\}/s', $css, $m);

        $this->assertNotEmpty($m, 'app.css should define a rule for .'.$selector.'.');

        return $m[1];
    }

    /** The footer partial, so a shared layout cannot satisfy the assertions. */
    private function footerOf(string $html): string
    {
        $start = strpos($html, '<footer');
        $end = $start === false ? false : strpos($html, '</footer>');

        $this->assertNotFalse($start, 'The page should render a footer.');
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }
}
