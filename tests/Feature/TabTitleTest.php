<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Promo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the browser tab title format for every public and admin section:
 * "SECTION | Balai ti Arjud" (admin pages keep the trailing "Admin").
 */
class TabTitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_public_section_uses_the_tab_title_format(): void
    {
        $this->makeSalonSettings();
        $this->makeService(['name' => 'Gelish Manicure', 'category' => 'Nail Care']);

        $sections = [
            '/' => 'HOME | Balai ti Arjud',
            '/about' => 'ABOUT US | Balai ti Arjud',
            '/contact' => 'CONTACT US | Balai ti Arjud',
            '/services' => 'SERVICES | Balai ti Arjud',
            '/services-grid' => 'REFINED GRID VIEW | Balai ti Arjud',
            '/login' => 'LOG IN | Balai ti Arjud',
            '/register' => 'REGISTER | Balai ti Arjud',
            '/password' => 'FORGOT PASSWORD | Balai ti Arjud',
        ];

        foreach ($sections as $uri => $expected) {
            $this->get($uri)
                ->assertOk()
                ->assertSee('<title>'.$expected.'</title>', false);
        }
    }

    public function test_a_service_detail_page_titles_from_the_service_name(): void
    {
        $this->makeSalonSettings();
        $service = $this->makeService(['name' => 'Gelish Manicure', 'category' => 'Nail Care']);

        $this->get("/services/{$service->slug}")
            ->assertOk()
            ->assertSee('<title>GELISH MANICURE | Balai ti Arjud</title>', false);
    }

    public function test_promos_have_their_own_page_rather_than_a_home_section(): void
    {
        $this->makeSalonSettings();
        $this->makeService(['name' => 'Gelish Manicure', 'category' => 'Nail Care']);

        // Promos used to be an `#offers` section on the landing page that the
        // nav jumped to. They now have a dedicated page, so the tab title comes
        // from the page's own @section('title') instead of data-tab-title.
        Promo::create([
            'title' => 'Glow Package',
            'description' => 'Manicure and facial for one price.',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'is_active' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('id="offers"', false)
            ->assertSee('href="'.route('promos.index').'"', false);

        $this->get('/promos')
            ->assertOk()
            ->assertSee('<title>PROMO | Balai ti Arjud</title>', false)
            ->assertSee('Glow Package');
    }

    public function test_admin_pages_keep_the_admin_suffix(): void
    {
        $admin = Admin::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'username' => 'tabtitle',
            'email' => 'tabtitle@example.test',
            'password' => 'password',
            'role' => AdminRole::Admin,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('<title>DASHBOARD | Balai ti Arjud Admin</title>', false);
    }
}
