<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Welcome Back')
            ->assertSee('Username or Email');
    }

    public function test_a_customer_can_log_in_with_email(): void
    {
        $user = $this->makeUser(['email' => 'juan@example.test']);

        $this->post('/login', [
            'login' => 'juan@example.test',
            'password' => 'password',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_customer_can_log_in_with_username(): void
    {
        $user = $this->makeUser(['email' => 'juan@example.test', 'username' => 'juan']);

        $this->post('/login', [
            'login' => 'juan',
            'password' => 'password',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_identifier_is_case_insensitive(): void
    {
        $user = $this->makeUser(['email' => 'juan@example.test']);

        $this->post('/login', [
            'login' => '  JUAN@Example.TEST ',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        $this->makeUser(['email' => 'juan@example.test']);

        $this->post('/login', [
            'login' => 'juan@example.test',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_login_fails_for_an_unknown_account(): void
    {
        $this->post('/login', [
            'login' => 'nobody@example.test',
            'password' => 'password',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        $this->makeUser(['email' => 'juan@example.test']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', [
                'login' => 'juan@example.test',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('login');
        }

        // The sixth attempt is blocked by the throttle, even with the right password.
        $this->post('/login', [
            'login' => 'juan@example.test',
            'password' => 'password',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
        $this->assertNotNull(RateLimiter::tooManyAttempts('juan@example.test|127.0.0.1', 5));
    }

    public function test_a_deactivated_account_cannot_log_in(): void
    {
        $this->makeUser(['email' => 'juan@example.test', 'is_active' => false]);

        $this->post('/login', [
            'login' => 'juan@example.test',
            'password' => 'password',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_last_login_at_is_stamped(): void
    {
        $user = $this->makeUser(['email' => 'juan@example.test']);

        $this->post('/login', [
            'login' => 'juan@example.test',
            'password' => 'password',
        ]);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_a_customer_can_log_out(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_guests_are_redirected_away_from_protected_pages(): void
    {
        $this->get('/appointments')->assertRedirect(route('login'));
        $this->get('/appointments')->assertRedirect(route('login'));
        $this->get('/profile')->assertRedirect(route('login'));
    }

    public function test_authenticated_customers_are_redirected_away_from_the_login_screen(): void
    {
        $this->actingAs($this->makeUser())
            ->get('/login')
            ->assertRedirect(route('home'));
    }

    /**
     * The admin guard must be a genuinely separate session: being an
     * authenticated customer must not grant access to the admin panel.
     */
    public function test_a_customer_session_cannot_reach_the_admin_panel(): void
    {
        $this->actingAs($this->makeUser());

        $this->get('/admin')->assertRedirect(route('login'));
        $this->get('/admin/appointments')->assertRedirect(route('login'));
    }

    public function test_the_old_admin_login_url_redirects_to_the_unified_login(): void
    {
        $this->get('/admin/login')->assertRedirect(route('login'));

        $this->post('/admin/login')->assertRedirect(route('login'));
    }

    public function test_admin_login_uses_the_admin_guard(): void
    {
        $admin = $this->makeAdmin(['username' => 'admin', 'email' => 'admin@example.test']);

        $this->post('/login', [
            'login' => 'admin',
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertTrue(auth('admin')->check());
        $this->assertFalse(auth('web')->check());
        $this->assertSame($admin->id, auth('admin')->id());
    }

    public function test_admin_login_rejects_customer_credentials(): void
    {
        $this->makeUser(['email' => 'juan@example.test', 'username' => 'juan']);

        $this->post(route('login.store'), [
            'username' => 'juan@example.test',
            'password' => 'password',
        ])->assertRedirect(route('home'));

        $this->assertFalse(auth('admin')->check());
        $this->assertTrue(auth('web')->check());
    }

    public function test_shared_login_never_leaks_role_on_invalid_credentials(): void
    {
        $this->makeAdmin(['username' => 'admin', 'email' => 'admin@example.test']);
        $this->makeUser(['email' => 'juan@example.test', 'username' => 'juan']);

        $this->post('/login', [
            'login' => 'admin',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors(['login' => 'Invalid credentials.']);

        $this->post('/login', [
            'login' => 'juan@example.test',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors(['login' => 'Invalid credentials.']);
    }

    public function test_admin_logout_redirects_to_login(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/logout')
            ->assertRedirect(route('login'));

        $this->assertFalse(auth('admin')->check());
    }
}
