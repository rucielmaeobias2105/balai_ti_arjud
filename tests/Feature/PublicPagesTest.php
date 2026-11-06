<?php

namespace Tests\Feature;

use App\Models\PasswordResetCode;
use App\Models\Service;
use App\Notifications\PasswordResetCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Guards the public, unauthenticated surface: the landing page, both service
 * browsing variants, contact, the T&C pages and the admin login screen.
 */
class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_landing_page_renders(): void
    {
        $this->makeSalonSettings();

        $this->get('/')
            ->assertOk()
            ->assertSee('Balai ti Arjud')
            ->assertSee('Glow &amp; Co. Beauty Lounge', false)
            ->assertSee('Book an Appointment')
            ->assertSee('images/hero.jpg', false);
    }

    public function test_browsing_services_requires_no_authentication(): void
    {
        $this->makeSalonSettings();
        $this->makeService(['name' => 'Gelish Manicure', 'category' => 'Nail Care']);

        $this->get('/services')
            ->assertOk()
            ->assertSee('Browse Services')
            ->assertSee('Gelish Manicure');
    }

    public function test_services_can_be_filtered_by_category_and_price(): void
    {
        $this->makeSalonSettings();
        $this->makeService(['name' => 'Cheap Service', 'category' => 'Nail Care', 'price' => 300]);
        $this->makeService(['name' => 'Fancy Service', 'category' => 'Makeup', 'price' => 5000]);

        $this->get('/services?category=Nail Care')
            ->assertOk()
            ->assertSee('Cheap Service')
            ->assertDontSee('Fancy Service');

        $this->get('/services?min_price=1000')
            ->assertOk()
            ->assertSee('Fancy Service')
            ->assertDontSee('Cheap Service');
    }

    public function test_the_refined_grid_view_renders(): void
    {
        $this->makeSalonSettings();
        $this->makeService(['name' => 'Spa Pedicure', 'category' => 'Nail Care']);

        $this->get('/services-grid')
            ->assertOk()
            ->assertSee('Browse by Category')
            ->assertSee('Spa Pedicure');

        $this->get('/services-grid?category=Nail Care')
            ->assertOk()
            ->assertSee('Spa Pedicure');
    }

    /**
     * A service detail page is its name, its price and a way to book it.
     *
     * The photo, the variant table and the duration line are all gone from the
     * customer surface, so they are asserted absent here rather than left to be
     * rediscovered: a variant row still exists in the database and would
     * otherwise look like the feature had merely been re-hidden.
     */
    public function test_a_service_detail_page_renders_name_price_and_booking_only(): void
    {
        $this->makeSalonSettings();

        $service = $this->makeService([
            'name' => 'Signature Blowout',
            'slug' => 'signature-blowout',
            'price' => 750,
            'description' => 'A wash, blow-dry and finish.',
        ]);
        $service->variants()->create(['name' => 'Long Hair', 'price' => 550, 'base_price' => 550]);

        $html = $this->get('/services/signature-blowout')
            ->assertOk()
            ->assertSee('Signature Blowout')
            ->assertSee('₱750')
            ->assertSee('A wash, blow-dry and finish.')
            ->assertSee('Book Now')
            ->getContent();

        $this->assertStringNotContainsString('Long Hair', $html, 'Variants are no longer part of a service.');
        $this->assertStringNotContainsString('Choose a Variant', $html);
    }

    /**
     * The service list is grouped under its category headings.
     */
    public function test_the_service_list_is_grouped_by_category(): void
    {
        $this->makeSalonSettings();
        $this->makeService(['name' => 'Gelish Manicure', 'category' => 'Nail Care']);
        $this->makeService(['name' => 'Hair Wash', 'category' => 'Hair Care']);

        $html = $this->get('/services')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Nail Care.*Gelish Manicure/s', $html);
        $this->assertMatchesRegularExpression('/Hair Care.*Hair Wash/s', $html);
    }

    public function test_inactive_services_are_not_publicly_reachable(): void
    {
        $this->makeSalonSettings();

        $service = $this->makeService([
            'name' => 'Hidden Service',
            'slug' => 'hidden-service',
            'is_active' => false,
        ]);

        $this->get('/services/'.$service->slug)->assertNotFound();
    }

    public function test_the_contact_page_renders(): void
    {
        $this->get('/contact')
            ->assertOk()
            // The page-header is gone, so the form's own title is what the page
            // announces now.
            ->assertSee('Send Us a Message')
            ->assertSee('Full Name')
            ->assertSee('Email Address')
            ->assertSee('Topic')
            // The detail cards, in the order they are stacked.
            ->assertSeeInOrder(['Address', 'Operating Hours', 'Phone', 'Email', 'Send Us a Message'])
            ->assertSee('+63 965 6244 405')
            // The salon's real address, not the `hello@balaitiarjud.test`
            // placeholder the config used to carry.
            ->assertSee('balaitiarjud@gmail.com');
    }

    /**
     * The public pages start directly with content.
     *
     * No hero banner and no photo banner, so none of the marketing headings that
     * used to open these pages may come back. Services and Promo both keep a
     * plain `page-header` title, matching each other; the other two open on
     * their first section. The in-page section headings that replaced the
     * removed blocks are asserted here too, so a removal cannot quietly take
     * the content with it.
     */
    public function test_the_public_pages_start_with_content_rather_than_a_banner(): void
    {
        $settings = \App\Models\SalonSetting::current();
        $settings->operating_hours = \App\Models\SalonSetting::defaultHours();
        $settings->save();

        $pages = [];

        // Scoped per page: "Browse Services" is the services page's own title and
        // the nav's link text, so it is only forbidden on promos, where it was
        // the empty state's action button.
        $forbidden = [
            '/services' => [
                'Refined Grid',                    // view-switch button
                'Try widening your price range',   // empty-state copy
                'Reset Filters',                   // empty-state action
                'No services match your filters',  // old empty title
            ],
            '/promos' => [
                'Special Offers',                  // eyebrow
                'Current promos, just for you',    // heading
                'Limited-time packages and discounts', // description
                'We have nothing on offer',        // empty-state copy
                'Browse Services',                 // empty-state action
            ],
            '/about' => [
                'Beauty meets hospitality',        // hero heading
                'Opening Hours',                   // hours card
            ],
            '/contact' => [
                'Get in Touch',                    // page-header eyebrow
            ],
        ];

        $emptyTitles = [
            '/services' => 'No services yet',
            '/promos' => 'No promos right now',
        ];

        // Services and Promo are titled identically in style: one plain heading,
        // no eyebrow and no description line.
        $plainTitles = [
            '/services' => 'Browse Services',
            '/promos' => 'Promo Offers',
        ];

        foreach ($forbidden as $path => $needles) {
            $html = $this->get($path)->assertOk()->getContent();
            $pages[$path] = $html;

            if (isset($emptyTitles[$path])) {
                $this->assertStringContainsString($emptyTitles[$path], $html);
            }

            if (isset($plainTitles[$path])) {
                $this->assertStringContainsString($plainTitles[$path], $html);
            }

            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $html, "{$path} should not still contain \"{$needle}\".");
            }
        }

        $about = $pages['/about'];
        $contact = $pages['/contact'];

        // The replacement sections are present and in order.
        $this->assertStringContainsString('Our Story', $about);
        $this->assertStringContainsString('Find Us', $about);
        $this->assertMatchesRegularExpression(
            '/Our Story.*Find Us/s',
            $about,
            'Our Story should come before Find Us.',
        );
        // The About page carries no operating hours; those live on Contact. The
        // removed About card was titled "Opening Hours" and the contact card is
        // "Operating Hours", so the two checks use different wording on purpose.
        $this->assertStringNotContainsString('Opening Hours', $about);
        $this->assertStringContainsString('Operating Hours', $contact);
    }

    /**
     * About Us is story plus location and nothing else.
     *
     * The feature cards and the "Open in Maps" link were both removed: the map
     * embed already carries the location, and the cards were generic claims
     * with nothing behind them. Nothing replaces them, so these are asserted
     * absent rather than rearranged.
     */
    public function test_the_about_page_ends_at_find_us(): void
    {
        $this->makeSalonSettings();

        $html = $this->get('/about')->assertOk()->getContent();

        foreach ([
            'Open in Maps',
            'Professional Therapists',
            'Safe & Clean Environment',
            'Quality Products',
            'Relax · Rejuvenate · Feel Beautiful',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "/about should no longer contain \"{$needle}\".");
        }

        // What is left is the story and the location, and the map is still there.
        $this->assertStringContainsString('Our Story', $html);
        $this->assertStringContainsString('Find Us', $html);

        // The address now comes from `config/salon.php` rather than being written
        // out here. It was duplicated across the About page, the contact page and
        // the footer, and the three had already drifted apart — the About page
        // punctuated the street name differently from the footer. Asserting the
        // configured value is what keeps them agreeing.
        $this->assertStringContainsString((string) config('salon.address'), $html);

        // The embed is a Street View panorama addressed by ID, with the camera
        // that faces the shopfront. Asserted value-by-value because a plain
        // coordinates query still returns 200 while resolving to whatever is
        // nearest — which is how this once pointed at a gas station further
        // down the road.
        foreach ([
            'apTNKjgpSFkhDHaIb1O_Nw',  // panorama
            '1d17.613647',             // latitude
            '2d120.6525178',           // longitude
            '3f149.92',                // heading, facing the shop
            '!4f0',                    // pitch
        ] as $part) {
            $this->assertStringContainsString($part, $html, "The embed should carry \"{$part}\".");
        }

        // Not the legacy keyless form, and not an address query.
        $this->assertStringNotContainsString('output=svembed', $html);
        $this->assertStringNotContainsString('q=' . urlencode('Unit 4'), $html);

        // One source of truth, so the address can be repointed without editing
        // markup, and exactly one map on the page.
        $this->assertSame(1, substr_count($html, config('salon.map_embed_url')));
        $this->assertSame(1, substr_count($html, '<iframe'), '/about should hold exactly one iframe.');
    }

    public function test_a_visitor_can_send_a_contact_message(): void
    {
        $this->post('/contact', [
            'name' => 'Liza Mercado',
            'email' => 'liza@example.test',
            'topic' => 'pricing_question',
            'message' => 'Do you have a package for hair colour plus a blowout?',
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('contact_messages', [
            'email' => 'liza@example.test',
            'topic' => 'pricing_question',
        ]);
    }

    public function test_a_contact_message_validates_its_fields(): void
    {
        $this->post('/contact', [
            'name' => '',
            'email' => 'nope',
            'topic' => 'not_a_topic',
            'message' => 'short',
        ])->assertSessionHasErrors(['name', 'email', 'topic', 'message']);
    }

    public function test_the_about_page_renders(): void
    {
        $this->get('/about')->assertOk()->assertSee('About Us');
    }

    public function test_the_unified_login_page_is_public_and_the_old_admin_url_redirects(): void
    {
        $html = $this->get('/login')
            ->assertOk()
            ->assertSee('Welcome Back')
            ->assertSee('Username or Email')
            ->getContent();

        $card = substr($html, (int) strpos($html, 'admin-auth-card'), (int) strrpos($html, '</main>') - (int) strpos($html, 'admin-auth-card'));

        $this->assertStringNotContainsString('Staff Portal', $card);

        $this->get('/admin/login')->assertRedirect(route('login'));
    }

    public function test_the_password_reset_flow_starts_at_the_email_step(): void
    {
        // The new-password step is not reachable before a code is verified.
        $this->get('/password/reset')->assertForbidden();

        $this->get('/password')
            ->assertOk()
            ->assertSee('Forgot Password');
    }

    public function test_a_password_reset_code_is_issued_and_the_code_step_unlocks(): void
    {
        Notification::fake();

        $user = $this->makeUser(['email' => 'juan@example.test']);

        $this->post('/password', ['email' => 'juan@example.test'])
            ->assertRedirect(route('password.code'));

        $this->get('/password/code')
            ->assertOk()
            ->assertSee('Verify Email')
            ->assertSee('juan@example.test');

        // Only the hash is persisted — never the plaintext code.
        $record = PasswordResetCode::where('email', 'juan@example.test')->first();

        $this->assertNotNull($record);
        $this->assertNotSame('000000', $record->code_hash);
        $this->assertTrue($record->expires_at->isFuture());

        Notification::assertSentTo($user, PasswordResetCodeNotification::class);
    }

    public function test_the_password_flow_never_reveals_whether_an_email_is_registered(): void
    {
        Notification::fake();

        $this->makeUser(['email' => 'juan@example.test']);

        $this->post('/password', ['email' => 'nobody@example.test'])
            ->assertRedirect(route('password.code'))
            ->assertSessionHas('status');
    }

    public function test_a_wrong_reset_code_is_rejected(): void
    {
        Notification::fake();

        $this->makeUser(['email' => 'juan@example.test']);

        $this->post('/password', ['email' => 'juan@example.test']);
        $this->post('/password/code', ['code' => '000000'])
            ->assertSessionHasErrors('code');
    }

    public function test_a_valid_reset_code_allows_a_new_password(): void
    {
        Notification::fake();

        $user = $this->makeUser(['email' => 'juan@example.test']);

        $this->post('/password', ['email' => 'juan@example.test']);

        // Read the plaintext straight out of the faked notification.
        $code = null;
        Notification::assertSentTo(
            $user,
            PasswordResetCodeNotification::class,
            function ($notification) use (&$code) {
                $code = $notification->code;

                return true;
            },
        );

        $this->post('/password/code', ['code' => $code])
            ->assertRedirect(route('password.reset'));

        $this->get('/password/reset')->assertOk()->assertSee('New Password');

        $this->post('/password/reset', [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertRedirect(route('home'));

        $this->assertTrue(password_verify('newpassword123', $user->fresh()->password));
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_a_reset_code_cannot_be_reused(): void
    {
        Notification::fake();

        $user = $this->makeUser(['email' => 'juan@example.test']);

        $this->post('/password', ['email' => 'juan@example.test']);

        $code = null;
        Notification::assertSentTo(
            $user,
            PasswordResetCodeNotification::class,
            function ($notification) use (&$code) {
                $code = $notification->code;

                return true;
            },
        );

        $this->post('/password/code', ['code' => $code]);
        $this->post('/password/reset', [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $this->assertNotNull(
            PasswordResetCode::where('email', 'juan@example.test')->first()->used_at,
        );
    }

    public function test_the_new_password_step_is_unreachable_without_a_verified_code(): void
    {
        $this->get('/password/reset')->assertForbidden();
        $this->post('/password/reset', ['password' => 'password123'])->assertForbidden();
    }
}
