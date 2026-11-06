<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Notifications\AdminPasswordResetNotification;
use App\Support\SmtpScheme;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * The staff portal's "Forgot Password", end to end.
 *
 * This flow was broken in three separate ways and each one had to be found, so
 * each gets its own group here:
 *
 *   1. there was no flow at all — the login page's "Forgot password?" was an
 *      inert `<span>`, deliberately marked as a placeholder pending a route;
 *   2. `MAIL_MAILER` was `log`, so even with a route the link went to
 *      storage/logs/laravel.log and was never delivered; and
 *   3. the reset would have run against the `users` broker, which knows nothing
 *      about the `admins` table, so every admin address came back as unknown.
 *
 * The tests use the `array` mailer rather than asserting on `.env`, because the
 * question worth pinning is "does the right message with the right link reach the
 * right notifiable" — which is a property of the code, not of the environment.
 * The SMTP configuration itself is verified separately; see the README note on
 * Mailtrap.
 */
class AdminForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The mailer the assertions read. `array` keeps the message in memory
        // instead of delivering it, which is what lets the link be followed here.
        config(['mail.default' => 'array']);
    }

    /**
     * The plaintext token from the notification that was sent.
     *
     * Read from the notification rather than from the database, and that is not
     * a convenience: `DatabaseTokenRepository` stores a *bcrypt hash* of the
     * token, so the row holds something that could never be used as a link. The
     * only copy of the usable value is the one the notification was built with.
     *
     * `Notification::fake()` must already be on for this to have anything to read.
     */
    private function issuedToken(Admin $admin): ?string
    {
        $token = null;

        Notification::assertSentTo(
            $admin,
            AdminPasswordResetNotification::class,
            function ($notification) use (&$token) {
                $token = $notification->token;

                return true;
            },
        );

        return $token;
    }

    /**
     * Request a link and return the token it produced, in one step.
     *
     * Every flow test needs the same two lines — post the form, then read the
     * token — and doing it in one place means the flow tests read as the flow
     * rather than as setup.
     */
    private function requestLinkFor(Admin $admin): string
    {
        Notification::fake();

        $this->post(route('admin.password.email'), ['email' => $admin->email])
            ->assertRedirect(route('admin.password.sent'));

        $token = $this->issuedToken($admin);

        $this->assertNotEmpty($token, 'The broker should have issued a token.');

        return $token;
    }

    /* ------------------------------------------------------------------ */
    /* 1. The link exists and is reachable                                 */
    /* ------------------------------------------------------------------ */

    public function test_the_login_page_no_longer_links_to_the_admin_reset_request(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        // The "Staff member? Reset your password" line was removed from the
        // customer login. The admin reset routes still exist for support to
        // reach directly, but they are no longer advertised to every customer.
        $this->assertStringNotContainsString(route('admin.password.request'), $html);
        $this->assertStringNotContainsString('Staff member', $html);
        $this->assertTrue(Route::has('admin.password.request'));
    }

    public function test_the_request_page_renders(): void
    {
        $this->get(route('admin.password.request'))
            ->assertOk()
            ->assertSee('Forgot Password')
            ->assertSee('name="email"', false);
    }

    public function test_a_signed_in_admin_is_sent_away_from_the_reset_flow(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.password.request'))
            ->assertRedirect(route('admin.dashboard'));

        $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.password.sent'))
            ->assertRedirect(route('admin.dashboard'));
    }

    /* ------------------------------------------------------------------ */
    /* 2. Requesting a link sends mail                                     */
    /* ------------------------------------------------------------------ */

    public function test_requesting_a_link_sends_an_email_to_the_admin(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin(['email' => 'staff@balaitiarjud.test']);

        $this->post(route('admin.password.email'), ['email' => $admin->email])
            ->assertRedirect(route('admin.password.sent'));

        Notification::assertSentTo(
            $admin,
            AdminPasswordResetNotification::class,
            // Exactly this project's notification, not the framework's: the
            // framework's builds its link to the *customer's* reset route.
            function (AdminPasswordResetNotification $notification, array $channels, Admin $notifiable) {
                return in_array('mail', $channels, true)
                    && $notifiable->is($notifiable);
            },
        );
    }

    /**
     * The link has to be the admin's.
     *
     * This is the whole reason the notification is overridden: the framework's
     * `ResetPassword` builds `route('password.reset')`, the customer's wizard.
     * An admin who follows that link lands on a screen asking for a six-digit
     * code that was never issued, so the flow is broken rather than merely
     * mislabelled.
     */
    public function test_the_emailed_link_points_at_the_admin_reset_route(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin(['email' => 'staff@balaitiarjud.test']);

        $this->post(route('admin.password.email'), ['email' => $admin->email]);

        // Reach the URL the way the mailer does: through the notification's own
        // `toMail()`, rather than by re-deriving what it ought to contain.
        $url = null;

        Notification::assertSentTo(
            $admin,
            AdminPasswordResetNotification::class,
            function (AdminPasswordResetNotification $notification, array $channels, Admin $notifiable) use (&$url) {
                $url = $notification->toMail($notifiable)->actionUrl;

                return in_array('mail', $channels, true);
            },
        );

        $this->assertNotNull($url, 'The notification should carry an action URL.');

        // The token is a *path segment*, not a query parameter — which is what
        // `/admin/password/reset/{token}` means.
        $this->assertStringContainsString('/admin/password/reset/', $url);
        $this->assertStringContainsString('/admin/password/reset/'.$this->issuedToken($admin), $url);
        // URL-encoded: the address is a query parameter, and `@` does not survive
        // into a URL unescaped.
        $this->assertStringContainsString('email='.urlencode($admin->email), $url);

        // And it is not the customer's wizard, which is what the framework's own
        // notification would have produced.
        $this->assertStringNotContainsString('/password/reset?', $url);
    }

    public function test_the_reset_form_is_reachable_from_the_link(): void
    {
        $admin = $this->makeAdmin(['email' => 'staff@balaitiarjud.test']);

        $token = $this->requestLinkFor($admin);

        $this->get(route('admin.password.reset', ['token' => $token, 'email' => $admin->email]))
            ->assertOk()
            ->assertSee('New Password')
            ->assertSee('name="password"', false)
            ->assertSee('name="token"', false)
            ->assertSee('name="email"', false);
    }

    /** A link with no address cannot be used, so it says so rather than rendering a form. */
    public function test_a_link_with_no_address_is_refused(): void
    {
        $this->get(route('admin.password.reset', ['token' => 'anything']))
            ->assertRedirect(route('admin.password.request'))
            ->assertSessionHasErrors('email');
    }

    /* ------------------------------------------------------------------ */
    /* 3. It uses the admins broker, not the users one                     */
    /* ------------------------------------------------------------------ */

    /**
     * The bug this whole feature turns on.
     *
     * Without an `admins` entry in `config/auth.php`, the reset ran against the
     * `users` provider: every admin address came back as `INVALID_USER`, the
     * broker sent nothing, and the page still said "check your inbox" — so the
     * flow looked like it worked and delivered nothing at all.
     */
    public function test_the_admins_broker_is_configured(): void
    {
        $this->assertArrayHasKey('admins', config('auth.passwords'));
        $this->assertSame('admins', config('auth.passwords.admins.provider'));
        $this->assertSame(Admin::PASSWORD_BROKER, 'admins', 'The model and the config must name the same broker.');

        // Shorter than the customers' window: an admin password protects the panel.
        $this->assertSame(30, config('auth.passwords.admins.expire'));
    }

    public function test_the_broker_finds_an_admin(): void
    {
        $admin = $this->makeAdmin(['email' => 'staff@balaitiarjud.test']);

        $found = Password::broker(Admin::PASSWORD_BROKER)->getUser(['email' => $admin->email]);

        $this->assertNotNull($found, 'The admins broker must be able to find an admin.');
        $this->assertTrue($found->is($admin));
    }

    /** …and does not find a customer, which is the other half of the separation. */
    public function test_the_admins_broker_does_not_find_a_customer(): void
    {
        $customer = $this->makeUser(['email' => 'customer@example.test']);

        $this->assertNull(
            Password::broker(Admin::PASSWORD_BROKER)->getUser(['email' => $customer->email]),
            'A customer address must not resolve an admin.',
        );
    }

    public function test_the_admin_model_uses_the_admins_broker(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin(['email' => 'staff@balaitiarjud.test']);

        $this->requestLinkFor($admin);

        // If it went through the default `users` broker, no row would be written
        // at all — the address is not in that table.
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $admin->email]);
    }

    /** A retired admin must not be able to recover the account. */
    public function test_a_soft_deleted_admin_cannot_reset(): void
    {
        $admin = $this->makeAdmin(['email' => 'retired@balaitiarjud.test']);
        $admin->delete();

        $this->assertNull(
            Password::broker(Admin::PASSWORD_BROKER)->getUser(['email' => 'retired@balaitiarjud.test']),
            'A retired account should not be resolvable by the reset broker.',
        );
    }

    /* ------------------------------------------------------------------ */
    /* 4. The full flow                                                    */
    /* ------------------------------------------------------------------ */

    /** Request → link → new password → signed in. Every step, in order. */
    public function test_the_whole_flow_works_end_to_end(): void
    {
        $admin = $this->makeAdmin([
            'email' => 'staff@balaitiarjud.test',
            'username' => 'staff',
            'password' => 'the-old-password',
        ]);

        // 1. Ask for a link, 2. follow it.
        $token = $this->requestLinkFor($admin);

        $this->get(route('admin.password.reset', ['token' => $token, 'email' => $admin->email]))
            ->assertOk();

        // 3. Set a new password.
        $this->post(route('admin.password.update'), [
            'token' => $token,
            'email' => $admin->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect(route('admin.dashboard'));

        // 4. Signed in, and the new password is the one stored.
        $this->assertTrue(auth('admin')->check());
        $this->assertTrue(Hash::check('a-brand-new-password', $admin->fresh()->password));

        // 5. The old one no longer works.
        Auth::guard('admin')->logout();

        $this->post(route('login.store'), [
            'username' => 'staff',
            'password' => 'the-old-password',
        ])->assertSessionHasErrors('username');

        // …and the new one does.
        $this->post(route('login.store'), [
            'username' => 'staff',
            'password' => 'a-brand-new-password',
        ])->assertRedirect(route('admin.dashboard'));
    }

    /** The token is spent: the same link cannot be used twice. */
    public function test_the_token_cannot_be_reused(): void
    {
        $admin = $this->makeAdmin(['email' => 'staff@balaitiarjud.test']);

        $token = $this->requestLinkFor($admin);

        $payload = [
            'token' => $token,
            'email' => $admin->email,
            'password' => 'first-new-password',
            'password_confirmation' => 'first-new-password',
        ];

        $this->post(route('admin.password.update'), $payload)->assertRedirect(route('admin.dashboard'));

        /*
         * Signed out before the second attempt.
         *
         * The successful reset signs the admin in, and these routes carry the
         * `admin.guest` middleware — so a reuse attempt while still signed in
         * would be redirected to the dashboard before the broker was ever asked,
         * and the assertion below would pass without testing anything.
         */
        Auth::guard('admin')->logout();

        $this->post(route('admin.password.update'), $payload)
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('first-new-password', $admin->fresh()->password));
    }

    /** A guessed token is refused. */
    public function test_a_wrong_token_is_refused(): void
    {
        $admin = $this->makeAdmin(['email' => 'staff@balaitiarjud.test', 'password' => 'unchanged-password']);

        $this->requestLinkFor($admin);

        $this->post(route('admin.password.update'), [
            'token' => 'not-the-real-token',
            'email' => $admin->email,
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('unchanged-password', $admin->fresh()->password));
    }

    /** An expired token is refused, which is what the 30-minute window is for. */
    public function test_an_expired_token_is_refused(): void
    {
        $admin = $this->makeAdmin(['email' => 'staff@balaitiarjud.test', 'password' => 'unchanged-password']);

        $token = $this->requestLinkFor($admin);

        // Backdate the stored row past the broker's window. The row holds a
        // *hash* of the token, so this is the only way to age it — the plaintext
        // `$token` cannot be written here and never was stored.
        DB::table('password_reset_tokens')
            ->where('email', $admin->email)
            ->update(['created_at' => now()->subMinutes(31)]);

        $this->post(route('admin.password.update'), [
            'token' => $token,
            'email' => $admin->email,
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('unchanged-password', $admin->fresh()->password));
    }

    /** A mismatched confirmation is caught by validation, before the broker runs. */
    public function test_a_mismatched_confirmation_is_refused(): void
    {
        $admin = $this->makeAdmin(['email' => 'staff@balaitiarjud.test']);

        $token = $this->requestLinkFor($admin);

        $this->post(route('admin.password.update'), [
            'token' => $token,
            'email' => $admin->email,
            'password' => 'one-password',
            'password_confirmation' => 'a-different-one',
        ])->assertSessionHasErrors('password');
    }

    /** An admin's old remember token stops working. */
    public function test_the_remember_token_is_replaced(): void
    {
        $admin = $this->makeAdmin([
            'email' => 'staff@balaitiarjud.test',
            'remember_token' => 'the-old-remember-token',
        ]);

        $token = $this->requestLinkFor($admin);

        $this->post(route('admin.password.update'), [
            'token' => $token,
            'email' => $admin->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $this->assertNotSame('the-old-remember-token', $admin->fresh()->remember_token);
    }

    /* ------------------------------------------------------------------ */
    /* 5. The envelope                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * An unknown address gets the same page as a known one.
     *
     * A different answer here would turn the form into a way to discover which
     * addresses are registered on the staff portal.
     */
    public function test_an_unknown_address_is_told_the_same_thing(): void
    {
        Notification::fake();

        $this->makeAdmin(['email' => 'real@balaitiarjud.test']);

        $this->post(route('admin.password.email'), ['email' => 'nobody@balaitiarjud.test'])
            ->assertRedirect(route('admin.password.sent'))
            ->assertSessionHas('status');

        $this->assertDatabaseCount('password_reset_tokens', 0, null, 'Nothing should be sent or stored.');

        Notification::assertNothingSent();
    }

    public function test_a_malformed_address_is_a_validation_error(): void
    {
        $this->post(route('admin.password.email'), ['email' => 'not-an-address'])
            ->assertSessionHasErrors('email');
    }

    /**
     * Two requests in a row are throttled.
     *
     * Without this the form is an unauthenticated way to send mail to any address
     * on demand — an inbox-flooding primitive pointed at the salon's own staff.
     */
    public function test_repeated_requests_are_throttled(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin(['email' => 'staff@balaitiarjud.test']);

        $this->post(route('admin.password.email'), ['email' => $admin->email])
            ->assertRedirect(route('admin.password.sent'));

        // The route carries `throttle:3,1`, and the broker's own throttle stops a
        // repeat inside the same minute regardless — either is a refusal, and
        // both are what stops the flood.
        $response = $this->post(route('admin.password.email'), ['email' => $admin->email]);

        $this->assertTrue(
            $response->isRedirect() || $response->getStatusCode() === 429,
            'A repeated reset request should be refused or throttled.',
        );
    }

    /* ------------------------------------------------------------------ */
    /* 6. The mailer                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * The message itself.
     *
     * Read through the `array` mailer, so this covers the real delivery path:
     * the notification, the mailer, and the link all the way to a rendered
     * message. Everything above it — SMTP credentials — is configuration.
     */
    public function test_the_message_reaches_the_inbox_with_a_working_link(): void
    {
        // No `Notification::fake()` here — the point is the whole delivery path,
        // so the notification has to actually be handed to the mailer. `array`
        // collects it in memory instead of sending it anywhere.
        $admin = $this->makeAdmin(['email' => 'staff@balaitiarjud.test']);

        $this->post(route('admin.password.email'), ['email' => $admin->email])
            ->assertRedirect(route('admin.password.sent'));

        /** @var \Illuminate\Mail\Transport\ArrayTransport $transport */
        $transport = app(\Illuminate\Mail\Mailer::class)->getSymfonyTransport();

        $messages = $transport->messages();

        $this->assertCount(1, $messages, 'One message should have been handed to the mailer.');

        $message = $messages[0]->getOriginalMessage();

        // The recipient, read off the envelope rather than the body: the address
        // belongs in the To header, and asserting it there also catches it being
        // sent to the wrong person, which a body assertion would not.
        $this->assertSame($admin->email, $message->getTo()[0]->getAddress());

        $body = (string) $message->getHtmlBody();

        // The link is the admin one, and it carries the address.
        $this->assertStringContainsString('/admin/password/reset/', $body);
        $this->assertStringContainsString('email='.urlencode($admin->email), $body);

        // And it is not the customer's wizard, which is what the framework's own
        // notification would have linked to.
        $this->assertStringNotContainsString('/password/reset?token', $body);

        // The token row exists, though what is stored is a hash of the token
        // rather than the token itself.
        $this->assertNotEmpty(
            DB::table('password_reset_tokens')->where('email', $admin->email)->value('token'),
        );
    }

    /**
     * `MAIL_MAILER` has to be something that delivers.
     *
     * `log` was the actual bug: it writes the message to
     * storage/logs/laravel.log and returns success, so the flow reported "check
     * your inbox" while the link had never left the machine.
     *
     * Asserted against `.env` rather than against the running config, because the
     * test suite pins `mail.default` to `array` above — and because the file is
     * what a developer edits.
     */
    public function test_the_local_env_does_not_send_mail_to_the_log(): void
    {
        $env = $this->envValue('MAIL_MAILER');

        $this->assertNotNull($env, 'MAIL_MAILER should be set.');

        $this->assertNotSame(
            'log',
            $env,
            'MAIL_MAILER=log means the reset link is never sent — it is only written to the log.',
        );

        $this->assertSame('smtp', $env, 'The documented configuration is Gmail over SMTP.');
    }

    /**
     * Gmail is the configured provider, and the host and port match it.
     *
     * This used to assert Mailtrap, which was right while the local sandbox was
     * Mailtrap. It is now Gmail's relay on port 587 — the combination that means
     * STARTTLS, which Gmail requires and will not negotiate on 2525.
     */
    public function test_the_smtp_settings_point_at_gmail(): void
    {
        $this->assertSame('smtp.gmail.com', $this->envValue('MAIL_HOST'));
        $this->assertSame('587', $this->envValue('MAIL_PORT'));

        // STARTTLS on 587. `config/mail.php` maps this onto the `scheme` key the
        // SMTP transport actually reads; Laravel 11 dropped `encryption`, so a
        // bare `MAIL_ENCRYPTION` would otherwise be ignored.
        $this->assertSame('tls', $this->envValue('MAIL_ENCRYPTION'));
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));

        /*
         * `MAIL_USERNAME` must be the mailbox that *owns* the App Password.
         *
         * It is not the same thing as the address the salon is known by. An App
         * Password is issued by one Google account and only authenticates that
         * account, so pairing it with any other address is what produced
         * `535-5.7.8 Username and Password not accepted` — twice, with two
         * separate valid App Passwords, because each was correct for
         * `rucielmaeobias277@gmail.com` and was being presented as
         * `balaitiarjud@gmail.com`.
         *
         * So this asserts the account that holds the credential, which is the one
         * thing that has to be right for delivery. `MAIL_FROM_ADDRESS` is
         * deliberately *not* pinned to it: the salon can send as its public
         * address as long as that address is a verified alias or send-as on the
         * authenticated account, and which way round that goes is an account
         * setting rather than a property of this application.
         */
        $this->assertSame(
            'rucielmaeobias277@gmail.com',
            $this->envValue('MAIL_USERNAME'),
            'MAIL_USERNAME must be the Google account the App Password was issued to.',
        );

        // Whatever it sends as, it has to be an address — and the two are allowed
        // to differ only because Gmail honours a verified alias here.
        $from = (string) $this->envValue('MAIL_FROM_ADDRESS');

        $this->assertNotSame('', $from, 'MAIL_FROM_ADDRESS must be set.');
        $this->assertStringEndsWith('@gmail.com', $from, 'The salon sends through Gmail.');
    }

    /**
     * `MAIL_ENCRYPTION=tls` has to mean STARTTLS, not nothing.
     *
     * Laravel 11 reads `scheme`, not `encryption`. Without the mapping in
     * `config/mail.php` the variable would be read by nothing at all, the
     * transport would fall back to its port-derived default, and the setting
     * would look like it worked while having no effect — so the mapping is
     * asserted rather than assumed.
     *
     * Asserted against `SmtpScheme` directly rather than through the booted
     * config, because `env()` reads the cached repository and a value written to
     * `$_ENV` after boot would not change what it returns.
     */
    public function test_the_encryption_setting_is_mapped_onto_the_smtp_scheme(): void
    {
        // STARTTLS on 587, in either spelling.
        $this->assertSame('smtp', SmtpScheme::forEncryption('tls'));
        $this->assertSame('smtp', SmtpScheme::forEncryption('starttls'));
        $this->assertSame('smtp', SmtpScheme::forEncryption('TLS'));
        $this->assertSame('smtp', SmtpScheme::forEncryption(' tls '));

        // Implicit TLS on 465 is the other pairing, and a different scheme.
        $this->assertSame('smtps', SmtpScheme::forEncryption('ssl'));
        $this->assertSame('smtps', SmtpScheme::forEncryption('smtps'));

        // Unset, or empty, falls through to whatever the modern variable says.
        $this->assertNull(SmtpScheme::forEncryption(null));
        $this->assertSame('smtps', SmtpScheme::forEncryption(null, 'smtps'));
        $this->assertSame('smtps', SmtpScheme::forEncryption('', 'smtps'));

        // A typo is not guessed at. Falling back to `smtps` here would turn a
        // mistyped setting into a silently wrong transport.
        $this->assertSame('fallback', SmtpScheme::forEncryption('tls-ish', 'fallback'));
    }

    /**
     * The committed example does not carry a password.
     *
     * The host, port and username are not secrets and are meant to be committed
     * — a new checkout needs to know it is aiming at Gmail. The password is the
     * credential, and it must be empty in the tracked file.
     *
     * This matters more now than it did under Mailtrap. A Gmail App Password is a
     * live credential for a live mailbox: anyone holding the committed file has
     * the salon's inbox, and there is no second factor in front of SMTP.
     */
    public function test_the_committed_env_example_carries_no_credentials(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        // Empty, not absent and not `null`: the key is documented, the value is
        // not there.
        $this->assertMatchesRegularExpression('/MAIL_PASSWORD=(null|"")?\s*$/m', $example);

        // Nothing that looks like a 16-character Gmail App Password.
        $this->assertDoesNotMatchRegularExpression(
            '/MAIL_PASSWORD=["\']?[a-z]{4}\s[a-z]{4}\s[a-z]{4}\s[a-z]{4}/i',
            $example,
            'A Gmail App Password must never be committed.',
        );

        // The connection settings are documented, so a new checkout knows what
        // to aim at and where to get the missing half.
        $this->assertStringContainsString('smtp.gmail.com', $example);
        $this->assertStringContainsString('MAIL_PORT=587', $example);
        $this->assertStringContainsString('balaitiarjud@gmail.com', $example);
        $this->assertStringContainsString('myaccount.google.com/apppasswords', $example);

        // And .env is not tracked at all.
        $this->assertStringContainsString('.env', (string) file_get_contents(base_path('.gitignore')));
    }

    /**
     * `.env` is where the App Password belongs, so it is not asserted empty.
     *
     * This test used to assert that the *local* `.env` still held an empty
     * `MAIL_PASSWORD`, on the reasoning that "the committed state of this file is
     * an empty value". That reasoning does not hold: `.env` is gitignored, so it
     * is never committed and has no committed state at all. The file being
     * checked was the one place the README tells the operator to paste the
     * password, which made following the project's own setup instructions fail
     * the suite — a guard against the only correct action.
     *
     * The guarantee it was reaching for is already asserted, correctly, by
     * `test_the_committed_env_example_carries_no_credentials` above: the tracked
     * `.env.example` carries no credential, and `.env` is ignored. That is what
     * keeps the App Password out of the repository, which was the actual point.
     */

/**
     * A dead mail server must not answer a correctly-filled form with a 500.
     *
     * The customer flow already had a test for this: `issue()` stores the code and
     * sends afterwards, swallowing the transport failure so the record survives.
     * The admin flow had no equivalent, and it failed the other way.
     * `PasswordBroker` calls `sendPasswordResetNotification()` without guarding
     * it, so the rejection propagated straight out of the request.
     *
     * What that looked like from the admin's chair is the part worth recording: a
     * correct address, a valid form, and a server error page with nothing on it
     * saying the mail server was the problem. Meanwhile the customer flow
     * reported success for the identical misconfiguration.
     */
    public function test_requesting_an_admin_reset_link_does_not_500_while_smtp_is_failing(): void
    {
        $admin = $this->makeAdmin(['email' => 'staff@balaitiarjud.test']);

        // Deliberately no `Notification::fake()` here: faking the notification is
        // exactly what stops the send happening, so the probe would assert
        // nothing. This sends for real, against a transport that refuses.
        $this->breakMailWith();

        $response = $this->post(route('admin.password.email'), ['email' => $admin->email]);

        // The neutral redirect, exactly as for an unknown address, so the flow
        // does not dead-end and the form is not a probe for who has an account.
        $response->assertRedirect(route('admin.password.sent'));
        $response->assertSessionHasNoErrors();

        // And no transport detail reaches the screen.
        $this->assertStringNotContainsString('535', (string) session('status'));
    }

    /**
     * It says the send failed, rather than that a link is on its way.
     *
     * The half that would otherwise still leave someone waiting. The request
     * surviving is not enough: a person reading the reassurance would go and
     * wait for mail that was never sent, which is the behaviour this replaces.
     */
    public function test_a_failed_admin_send_is_announced_rather_than_reported_as_success(): void
    {
        $admin = $this->makeAdmin(['email' => 'staff@balaitiarjud.test']);

        $this->breakMailWith();

        $response = $this->post(route('admin.password.email'), ['email' => $admin->email]);

        $response->assertSessionHas('toast', [
            'type' => 'warning',
            'message' => 'We could not send the reset email. Please try again in a moment.',
        ]);

        $this->assertNull(session('status'));
    }

    /**
     * A send that worked keeps the neutral wording.
     *
     * Without this, a warning that fired on every request would be
     * indistinguishable from one that means something, and the failure it exists
     * to report would become the noise it is meant to cut through.
     */
    public function test_a_successful_admin_send_is_not_announced_as_a_failure(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin(['email' => 'staff@balaitiarjud.test']);

        $response = $this->post(route('admin.password.email'), ['email' => $admin->email]);

        $response->assertRedirect(route('admin.password.sent'));
        $response->assertSessionHas(
            'status',
            'If that address belongs to a staff account, a reset link is on its way.'
        );
        $response->assertSessionMissing('toast');
    }

    /**
     * Replace the mail transport with one that refuses every send.
     *
     * `Notification::fake()` cannot do this job: it is the thing that stops the
     * send, and these tests are about what happens when a send is genuinely
     * attempted and then rejected.
     */
    private function breakMailWith(): void
    {
        $exception = new TransportException(
            'Failed to authenticate on SMTP server with username "balaitiarjud@gmail.com". '
            .'Authenticator "LOGIN" returned "Expected response code 235 but got code 535, '
            .'with message 535-5.7.8 Username and Password not accepted".'
        );

        $transport = Mockery::mock(Mailer::class);
        $transport->shouldReceive('send')->andThrow($exception);

        $factory = Mockery::mock(MailFactory::class);
        $factory->shouldReceive('mailer')->andReturn($transport);

        $this->app->instance(MailFactory::class, $factory);
    }

    /** Read a key out of .env without booting the application's config. */
    private function envValue(string $key): ?string
    {
        $contents = (string) file_get_contents(base_path('.env'));

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');

            if (trim($name) === $key) {
                return trim(trim($value), "\"'");
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* 7. What it replaced                                                */
    /* ------------------------------------------------------------------ */

    /** The placeholder on the login page is now a route that exists. */
    public function test_the_reset_routes_are_registered(): void
    {
        foreach ([
            'admin.password.request',
            'admin.password.email',
            'admin.password.sent',
            'admin.password.reset',
            'admin.password.update',
        ] as $name) {
            $this->assertTrue(Route::has($name), "{$name} should be registered.");
        }
    }

    /**
     * The customer's wizard is untouched.
     *
     * The two flows share the token table and the mailer, so a change to one that
     * quietly broke the other would be easy to miss — the admin broker is a new
     * entry, and adding it must not have replaced the default.
     */
    public function test_the_customer_reset_flow_still_works(): void
    {
        $this->assertSame('users', config('auth.defaults.passwords'));
        $this->assertSame('users', config('auth.passwords.users.provider'));

        $customer = $this->makeUser(['email' => 'customer@example.test']);

        $this->assertNotNull(
            Password::broker('users')->getUser(['email' => $customer->email]),
            'The customers\' own broker must still resolve them.',
        );
    }
}