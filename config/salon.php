<?php

return [

    /*
    |--------------------------------------------------------------------------
    | How to reach the salon
    |--------------------------------------------------------------------------
    |
    | The trading name, the address, the phone number and the Facebook page.
    |
    | These were written into four different views — the About page's Find Us
    | card, the contact page's detail cards, and the footer's Visit Us column —
    | and had already drifted: the footer's `tel:` link pointed at +63 900 000
    | 000 while every visible string said +63 965 6244 405, and the About page
    | punctuated the street name differently from the footer. A visitor who
    | tapped the footer's number dialled nobody.
    |
    | One place to change them now. `phone_display` is what a human reads and
    | `phone_e164` is what `tel:` needs; both are here so they cannot disagree,
    | and `x-saloon.contact` renders the pair together.
    |
    | Override any of these from .env without touching markup.
    */

    'name' => 'Balai ti Arjud, Glow & Co. Beauty Lounge',

    /*
    |--------------------------------------------------------------------------
    | Footer tagline
    |--------------------------------------------------------------------------
    |
    | The one sentence under the logo in the site footer.
    |
    | It was written inline in the footer, commented out along with the rest of
    | the brand column, and the logo was left stranded across half the width.
    | It is here now so the wording is config rather than another string living
    | in a view — the same reason the contact facts above are.
    */
    'tagline' => 'Where beauty meets hospitality. Book your services online and let our therapists pamper you.',

    'address' => 'Unit 4, 2 wins Bldg. Abra Kalinga Rd. Patucannay, Tayum, Abra',

    'phone_display' => '+63 965 6244 405',

    'phone_e164' => '+639656244405',

    'email' => 'balaitiarjud@gmail.com',

    'facebook_url' => 'https://www.facebook.com/profile.php?id=61550541591947',

    /*
    |--------------------------------------------------------------------------
    | Facebook link text
    |--------------------------------------------------------------------------
    |
    | What the About page's Facebook row reads, as distinct from where it points.
    |
    | It used to print the profile URL as its own visible text, so the page
    | showed a bare `https://www.facebook.com/profile.php?id=61550541591947` to
    | anyone looking for it — a query-string identifier, not a name. The link
    | still goes to exactly that URL; only the words in it changed.
    |
    | It read "Balai ti Arud" for a while — the trading name with the `j`
    | dropped, which is the sort of thing that survives a rename because it is
    | nobody's job to read it back. The About card and the footer both print
    | this string, so it is corrected once here.
    */
    'facebook_label' => 'Balai ti Arjud',

    /*
    |--------------------------------------------------------------------------
    | Find Us map
    |--------------------------------------------------------------------------
    |
    | The embed used on the public About page's "Find Us" section.
    |
    | This is a Street View panorama rather than a top-down map, so it opens
    | facing the shopfront. The pin and the address text are deliberately not
    | used as the map query: a text address resolves to whichever building
    | Google guesses, which is how this ended up pointing at a gas station
    | further down Abra - Kalinga Rd.
    |
    | The `pb` parameter carries everything the viewer needs:
    |   apTNKjgpSFkhDHaIb1O_Nw  panorama ID
    |   17.613647 / 120.6525178 location
    |   149.92                  heading, in degrees, facing the shop
    |   0                       pitch
    |   0.7820865974627469      field of view
    |
    | Override with MAP_EMBED_URL in .env if the address ever changes, so the
    | value does not have to be edited into markup.
    |
    | If this stops rendering a blank or grey frame, fall back to the plain
    | pin for the same coordinates:
    |
    |   https://www.google.com/maps?q=17.613647,120.6525178&z=18&output=embed
    |
    */

    'map_embed_url' => env(
        'MAP_EMBED_URL',
        'https://www.google.com/maps/embed?pb='
            .'!4v1727540000000!6m8!1m7!1sapTNKjgpSFkhDHaIb1O_Nw'
            .'!2m2!1d17.613647!2d120.6525178'
            .'!3f149.92!4f0!5f0.7820865974627469',
    ),

    /*
    | The coordinates behind the embed above, kept for the printed address and
    | the fallback pin so all three cannot drift apart.
    */
    'latitude' => '17.613647',
    'longitude' => '120.6525178',

    /*
    |--------------------------------------------------------------------------
    | Category images
    |--------------------------------------------------------------------------
    |
    | The Services page is laid out one section per category: the name and its
    | services on the left, ONE representative image on the right. A category
    | has no image column of its own, so the representative shot is configured
    | here, keyed by the category name the salon actually uses in the catalogue.
    |
    | Lookup is forgiving, not exact. The same normalisation the calendar's
    | palette uses is applied to the configured keys and the category name, so
    | "Spa Services" in the config still matches a category stored as "Spa", and
    | "Brow & Lash Extension" matches "Brow & Lash". An entry for a category that
    | no longer exists is simply never read.
    |
    | These are the salon's own photographs of the shop rather than
    | treatment-specific shots — it has not yet photographed each treatment
    | separately. Replacing a value here is the whole job of putting a real
    | photo against a category; the layout does not change. Drop a file into
    | `public/images/` and point the key at it, e.g. 'images/brow-lash.jpg'.
    |
    | A service that has uploaded its own photo wins over all of this: if any
    | service in the category has a `photo_path`, that photo is the
    | representative one. So uploading photos service by service gradually
    | replaces these fallbacks without anyone editing this file.
    */

    'category_images' => [
        'Brow & Lash Extension' => 'images/here.jpg',
        'Threading' => 'images/here.jpg',
        'Manicure & Pedicure' => 'images/1.jpg',
        'Nail Art & Extension' => 'images/1.jpg',
        'Bleaching' => 'images/service-1.jpg',
        'Hair Care' => 'images/9.jpg',
        'Hair Waxing Removal' => 'images/9.jpg',
        'Spa Services' => 'images/10.jpg',
        'Massage' => 'images/10.jpg',
        'Glutathione Push OR Drip' => 'images/hero2.jpg',
        'Kiddie Services' => 'images/hero2.jpg',
    ],

    /*
    | Shown for any category with no entry above, and as the card's placeholder
    | if the configured file is later deleted. Never a broken image: a category
    | that cannot be pictured still gets a picture.
    */
    'default_category_image' => 'images/hero.jpg',

];
