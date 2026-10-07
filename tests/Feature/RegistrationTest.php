<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A password that satisfies every rule: 13 characters, an upper and a lower
     * case letter, digits and a symbol.
     *
     * The suite used to register with `password123`, which the tightened rules
     * refuse — correctly, and which is the whole point of tightening them.
     */
    private const PASSWORD = 'Password123!';

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@example.test',
            'contact_number' => '09171234567',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'terms' => '1',
        ], $overrides);
    }

    public function test_registration_screen_renders(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('Register')
            ->assertSee('First Name')
            ->assertSee('Confirm Password');
    }

    /**
     * The navbar no longer offers Register, so the login screen is the only
     * way in — which is why it has to keep linking to registration itself.
     *
     * Without this, dropping the navbar button would leave a visitor with no
     * path to an account at all.
     */
    public function test_registration_is_reachable_from_the_login_screen(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString(
            'href="'.route('register').'"',
            $html,
            'The login screen should link to registration.',
        );

        // Guest navbar: a Log In link, and no Register link.
        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.route('login').'"', false)
            ->assertDontSee('href="'.route('register').'"', false);
    }

    public function test_a_visitor_can_register_and_is_signed_in(): void
    {
        $response = $this->post('/register', $this->payload());

        $response->assertRedirect(route('home'));
        $this->assertAuthenticated();

        $user = User::firstWhere('email', 'juan@example.test');

        $this->assertNotNull($user);
        $this->assertSame('Juan', $user->first_name);
        $this->assertSame('Dela Cruz', $user->last_name);
        $this->assertSame('09171234567', $user->contact_number);
        $this->assertTrue($user->is_active);
    }

    public function test_password_is_hashed_and_never_stored_in_plain_text(): void
    {
        $this->post('/register', $this->payload());

        $user = User::firstWhere('email', 'juan@example.test');

        $this->assertNotSame(self::PASSWORD, $user->password);
        $this->assertTrue(password_verify(self::PASSWORD, $user->password));
    }

    public function test_a_username_is_derived_for_username_or_email_login(): void
    {
        $this->post('/register', $this->payload());

        $this->assertSame('juan', User::firstWhere('email', 'juan@example.test')->username);
    }

    public function test_email_must_be_unique(): void
    {
        $this->makeUser(['email' => 'taken@example.test']);

        $this->post('/register', $this->payload(['email' => 'taken@example.test']))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_email_is_validated(): void
    {
        $this->post('/register', $this->payload(['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /* ------------------------------------------------------------------ */
    /* Password rules                                                      */
    /* ------------------------------------------------------------------ */

    public function test_password_must_be_at_least_eight_characters(): void
    {
        $this->post('/register', $this->payload([
            'password' => 'Sh0rt1!',
            'password_confirmation' => 'Sh0rt1!',
        ]))->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_password_must_contain_an_uppercase_letter(): void
    {
        $this->post('/register', $this->payload([
            'password' => 'password123!',
            'password_confirmation' => 'password123!',
        ]))->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_password_must_contain_a_number(): void
    {
        $this->post('/register', $this->payload([
            'password' => 'Password!!!',
            'password_confirmation' => 'Password!!!',
        ]))->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_password_must_contain_a_special_character(): void
    {
        $this->post('/register', $this->payload([
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]))->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_password_confirmation_must_match(): void
    {
        $this->post('/register', $this->payload([
            'password_confirmation' => 'Different123!',
        ]))->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    /* ------------------------------------------------------------------ */
    /* Phone number                                                        */
    /* ------------------------------------------------------------------ */

    public function test_the_international_phone_format_is_accepted_and_stored_as_eleven_digits(): void
    {
        $this->post('/register', $this->payload(['contact_number' => '+639171234567']))
            ->assertRedirect(route('home'));

        // One stored format, so a later duplicate check is a string comparison
        // rather than a question about how the number was typed.
        $this->assertSame('09171234567', User::firstWhere('email', 'juan@example.test')->contact_number);
    }

    public function test_a_phone_number_typed_with_separators_is_accepted(): void
    {
        $this->post('/register', $this->payload(['contact_number' => '0917 123 4567']))
            ->assertRedirect(route('home'));

        $this->assertSame('09171234567', User::firstWhere('email', 'juan@example.test')->contact_number);
    }

    public static function refusedPhoneNumbers(): array
    {
        return [
            'ten digits' => ['0917123456'],
            'twelve digits' => ['091712345678'],
            'not a mobile prefix' => ['08171234567'],
            'a landline' => ['0281234567'],
            'letters' => ['0917ABC4567'],
            'no digits at all' => ['not-a-phone'],
            'only the country code' => ['+63'],
        ];
    }

    /** @dataProvider refusedPhoneNumbers */
    public function test_a_phone_number_that_is_not_eleven_mobile_digits_is_refused(string $number): void
    {
        $this->post('/register', $this->payload(['contact_number' => $number]))
            ->assertSessionHasErrors('contact_number');

        $this->assertGuest();
    }

    /* ------------------------------------------------------------------ */
    /* One identity, one account                                           */
    /* ------------------------------------------------------------------ */

    /**
     * The same name, email and phone number must not become a second account.
     *
     * The `unique` rule alone would refuse this too, but it says "that email is
     * already registered" — which is not what somebody who believes they are
     * registering for the first time needs to be told. This is the message
     * that answers the question they actually have.
     */
    public function test_a_repeat_of_the_same_name_email_and_phone_is_refused_with_a_clear_message(): void
    {
        $this->makeUser([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@example.test',
            'contact_number' => '09171234567',
        ]);

        $this->from('/register')
            ->post('/register', $this->payload())
            ->assertSessionHasErrors('email');

        $this->assertSame(
            'An account already exists with this name, email address and phone number. Please log in instead.',
            session('errors')->first('email'),
        );

        $this->assertSame(1, User::where('email', 'juan@example.test')->count());
    }

    /**
     * The same check has to survive the ways a person retypes themselves.
     *
     * A name in a different case and a number written in the international form
     * are the same identity. A check that missed those would be a check that
     * passes on exactly the typo it exists to catch.
     */
    public function test_the_duplicate_check_survives_capitalisation_and_the_international_phone_format(): void
    {
        $this->makeUser([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@example.test',
            'contact_number' => '09171234567',
        ]);

        $this->post('/register', $this->payload([
            'first_name' => 'JUAN',
            'last_name' => 'dela cruz',
            'email' => 'JUAN@example.test',
            'contact_number' => '+639171234567',
        ]))->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', 'juan@example.test')->count());
    }

    /** A different person with a coincidentally similar name is not a duplicate. */
    public function test_a_different_name_with_the_same_email_is_not_treated_as_the_same_identity(): void
    {
        $this->makeUser([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@example.test',
            'contact_number' => '09171234567',
        ]);

        // Same email, same phone, different person.
        $this->post('/register', $this->payload([
            'first_name' => 'Jose',
            'last_name' => 'Santos',
            'contact_number' => '09181234567',
        ]))->assertSessionHasErrors('email');

        // Still refused, because one address is one account — but by the unique
        // rule, which is a different sentence.
        $this->assertSame(
            'The email has already been taken.',
            session('errors')->first('email'),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Terms & Conditions                                                  */
    /* ------------------------------------------------------------------ */

    public function test_terms_must_be_accepted(): void
    {
        $this->post('/register', $this->payload(['terms' => null]))
            ->assertSessionHasErrors('terms');

        $this->assertGuest();
    }

    /**
     * The box starts unticked, and that is the component's doing.
     *
     * `x-ui.form.checkbox` seeds itself from `old($name, (bool) $value)`, so the
     * fix for this is the absence of a `value` on the tag rather than any
     * JavaScript — which is why the assertion is that the rendered input has no
     * `checked` attribute at all.
     */
    public function test_the_terms_checkbox_is_not_pre_ticked(): void
    {
        $html = $this->get('/register')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<input\b[^>]*name="terms"[^>]*>/',
            $html,
            'The terms checkbox should be rendered.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/<input\b[^>]*name="terms"[^>]*\bchecked\b/',
            $html,
            'The terms checkbox must not arrive already ticked.',
        );
    }

    /**
     * The terms link opens a dialog rather than navigating away.
     *
     * Both halves matter, and they are in different files. `x-terms.link`
     * carries a real `href` and is intercepted by Alpine — so the dialog has to
     * be mounted on this layout, or `preventDefault` fires, nothing opens, and
     * the only way to read the terms from the sign-up form is gone.
     */
    public function test_the_terms_link_opens_the_shared_dialog_instead_of_navigating(): void
    {
        $html = $this->get('/register')->assertOk()->getContent();

        $this->assertStringContainsString(
            'x-on:click.prevent="$dispatch(\'terms-modal\'',
            $html,
            'The terms link should open the shared dialog.',
        );

        // …and the dialog it dispatches to is actually on the page.
        $this->assertStringContainsString('id="terms-modal-title"', $html);
        $this->assertStringContainsString('x-data="termsModal', $html);
    }

    /* ------------------------------------------------------------------ */
    /* Nothing is created before the button is pressed                      */
    /* ------------------------------------------------------------------ */

    /**
     * Filling the form in must not create anything on its own.
     *
     * There is no auto-submit and no draft account: the account is written by
     * the POST from the button, and nothing else. So the form carries no
     * `x-on:submit` and no Alpine scope that could submit it, and the only
     * submit control is the button at the foot of the form.
     */
    public function test_the_form_has_nothing_that_submits_it_without_the_button(): void
    {
        $html = $this->get('/register')->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'x-on:submit',
            $html,
            'The registration form must not submit itself on a submit event.',
        );
        $this->assertStringNotContainsString(
            'requestSubmit',
            $html,
            'The registration form must not be submitted from script.',
        );
        $this->assertStringNotContainsString(
            'x-on:blur',
            $html,
            'The registration form must not submit on blur.',
        );

        // The action is the register endpoint and there is exactly one submit
        // button, so there is no second path to creating the account.
        $this->assertSame(1, substr_count($html, 'type="submit"'));
    }

    public function test_a_registered_customer_receives_a_welcome_notification(): void
    {
        $this->post('/register', $this->payload());

        $user = User::firstWhere('email', 'juan@example.test');

        $this->assertCount(1, $user->notifications);
        $this->assertSame(
            'Welcome to '.config('app.name'),
            $user->notifications()->first()->data['title'],
        );
    }

    public function test_email_is_normalised_to_lowercase(): void
    {
        $this->post('/register', $this->payload(['email' => '  Juan@Example.TEST ']));

        $this->assertDatabaseHas('users', ['email' => 'juan@example.test']);
    }

    public function test_first_and_last_name_are_required(): void
    {
        $this->post('/register', $this->payload([
            'first_name' => '',
            'last_name' => '',
        ]))->assertSessionHasErrors(['first_name', 'last_name']);

        $this->assertGuest();
    }
}