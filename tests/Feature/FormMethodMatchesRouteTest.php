<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Promo;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Every form in the app submits a verb its target route actually accepts.
 *
 * This is a narrow check for a specific, twice-invisible class of bug.
 *
 * HTML forms can only speak GET and POST, so a Laravel app fakes the rest with a
 * hidden `_method` field. Nothing stops that field from disagreeing with the
 * route: the route compiles, the controller method exists, and every test that
 * calls `$this->post(...)` or `$this->put(...)` on it directly passes — while
 * the rendered page submits a verb the router refuses and the admin gets a 405.
 *
 * That is not hypothetical. The Archive dialog on the admin appointments list
 * pointed at `admin.appointments.archive`, a POST route, but inherited
 * `method="DELETE"` from `x-ui.confirm-dialog`'s default, so archiving a booking
 * answered "The DELETE method is not supported for route
 * admin/appointments/31/archive" — for a route the suite was posting to and
 * passing elsewhere in the same file.
 *
 * So this renders the pages, reads each form's `_method`, and asks the router
 * whether it would route that request.
 *
 * Forms whose action Alpine fills in are skipped: a row dialog binds it three
 * ways in this codebase — `x-bind:action`, `:action`, or an `x-on:click` that
 * assigns it — and in every case the target is assembled in the browser from an
 * id template, so there is no URL in the markup to match against here. The
 * archive dialog is the one that mattered and it is asserted directly in
 * AppointmentArchiveTest, by its rendered heading id.
 */
class FormMethodMatchesRouteTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ */
    /* The sweep                                                           */
    /* ------------------------------------------------------------------ */

    public function test_every_form_on_an_admin_page_submits_an_accepted_verb(): void
    {
        $admin = $this->makeAdmin();

        $this->assertNoFormVerbMismatch('admin', $admin, $this->adminPages($admin));
    }

    public function test_every_form_on_a_customer_page_submits_an_accepted_verb(): void
    {
        $user = $this->makeUser();

        $this->assertNoFormVerbMismatch('web', $user, $this->customerPages($user));
    }

    /**
     * Registration and contact redirect a signed-in visitor, so the guest pages
     * need their own pass.
     */
    public function test_every_form_on_a_guest_page_submits_an_accepted_verb(): void
    {
        $this->makeSalonSettings();

        foreach (['register', 'contact', 'home'] as $name) {
            $response = $this->get(route($name === 'contact' ? 'contact.create' : $name));

            $this->assertTrue($response->isSuccessful(), "The {$name} page did not render.");

            foreach ($this->formsIn((string) $response->getContent()) as $form) {
                $this->assertRouteAccepts($name, $form);
            }
        }
    }

    /**
     * Render each page as `$user` and check every concrete-action form on it.
     *
     * @param  array<string, string>  $pages  human label => URL
     */
    private function assertNoFormVerbMismatch(string $guard, User|\App\Models\Admin $user, array $pages): void
    {
        foreach ($pages as $label => $url) {
            $response = $this->actingAs($user, $guard)->get($url);

            $this->assertTrue(
                $response->isSuccessful(),
                "The {$label} page returned {$response->getStatusCode()}, so its forms went unchecked.",
            );

            foreach ($this->formsIn((string) $response->getContent()) as $form) {
                $this->assertRouteAccepts($label, $form);
            }
        }
    }

    /**
     * Pull the concrete-action forms out of a page, with the verb each will
     * actually be submitted as.
     *
     * @return list<array{action: string, verb: string, spoofed: ?string}>
     */
    private function formsIn(string $html): array
    {
        // Only forms carrying a real `action` attribute. `x-bind:action` is
        // Alpine, and a row dialog's target is assembled client-side from an id,
        // so there is no URL in the markup to check.
        if (! preg_match_all('#<form\b(?![^>]*x-bind:action)([^>]*)>(.*?)</form>#s', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $forms = [];

        foreach ($matches as [, $openTag, $body]) {
            // The attribute is matched with a lookbehind so `x-bind:action` and
            // Blade's `:action` cannot satisfy it — `\baction` would match either,
            // since both put a non-word character in front of "action". Those
            // Alpine bindings are skipped anyway: their value is the literal
            // string "action", filled in per row by the browser.
            if (! preg_match('#(?<![\w:-])action="(https?://[^"]+)"#', $openTag, $action)) {
                continue;
            }

            // A GET filter form is still worth checking — it just needs a GET
            // route rather than a spoofed verb — so the form's own method is
            // read rather than assumed.
            $method = preg_match('#(?<![\w:-])method="([A-Za-z]+)"#', $openTag, $found)
                ? strtoupper($found[1])
                : 'GET';

            $forms[] = [
                'action' => $action[1],
                'verb' => $method,
                // An absent `_method` means the form's own method stands.
                'spoofed' => preg_match('#name="_method"\s+value="([A-Z]+)"#', $body, $verb) ? $verb[1] : null,
            ];
        }

        return $forms;
    }

    /**
     * Ask the router whether it would route this verb at this URL.
     *
     * `Route::matches()` is the router's own path-and-verb test, so parameter
     * patterns like `appointments/{appointment}/archive` resolve without this
     * needing to know anything about the shape of the URL.
     */
    private function assertRouteAccepts(string $label, array $form): void
    {
        $verb = $form['spoofed'] ?? $form['verb'];

        $request = Request::create($form['action'], $verb);

        $routed = collect(app('router')->getRoutes()->getRoutes())
            ->contains(fn ($route) => $route->matches($request));

        $this->assertTrue(
            $routed,
            "The {$label} page renders a form posting to {$form['action']} with a spoofed method of "
            .($form['spoofed'] ?? 'none, so a plain POST').', which no route accepts.',
        );
    }

    /* ------------------------------------------------------------------ */
    /* Pages                                                               */
    /* ------------------------------------------------------------------ */

    /** @return array<string, string> */
    private function adminPages(\App\Models\Admin $admin): array
    {
        $this->makeSalonSettings();

        $service = $this->makeService();
        $category = ServiceCategory::firstOrCreate(['slug' => 'form-check'], ['name' => 'Form Check']);
        $promo = Promo::firstOrCreate(
            ['title' => 'Form Check Promo'],
            [
                'description' => 'A promo so the edit screen renders.',
                'starts_at' => today(),
                'ends_at' => today()->addMonth(),
            ],
        );

        $completed = $this->makeAppointment($this->makeUser(), $service, [
            'status' => AppointmentStatus::Completed,
            'completed_at' => now()->subDays(2),
        ]);

        // A second, archived row, so the archive screen renders its own forms.
        $this->makeAppointment($this->makeUser(), $service, [
            'status' => AppointmentStatus::Cancelled,
            'archived_at' => now(),
            'archived_by' => $admin->id,
        ]);

        return [
            'admin dashboard' => route('admin.dashboard'),
            'admin appointments' => route('admin.appointments.index'),
            'admin archived appointments' => route('admin.appointments.archived'),
            'admin appointment show' => route('admin.appointments.show', $completed),
            'admin services' => route('admin.services.index'),
            'admin service create' => route('admin.services.create'),
            'admin service edit' => route('admin.services.edit', $service),
            'admin service categories' => route('admin.categories.index'),
            'admin service category create' => route('admin.categories.create'),
            'admin service category edit' => route('admin.categories.edit', $category),
            'admin inventory' => route('admin.inventory.index'),
            'admin technicians' => route('admin.technicians.index'),
            'admin technician create' => route('admin.technicians.create'),
            'admin users' => route('admin.users.index'),
            'admin promos' => route('admin.promos.index'),
            'admin promo create' => route('admin.promos.create'),
            'admin promo edit' => route('admin.promos.edit', $promo),
            'admin terms' => route('admin.terms.index'),
            'admin profile' => route('admin.profile.edit'),
            'admin messages' => route('admin.messages.index'),
        ];
    }

    /** @return array<string, string> */
    private function customerPages(User $user): array
    {
        $this->makeSalonSettings();

        $confirmed = $this->makeAppointment($user, null, [
            'status' => AppointmentStatus::Confirmed,
            'preferred_date' => $this->bookableDate(),
        ]);

        return [
            'customer appointments' => route('appointments.index'),
            'customer booking' => $this->bookingUrl(),
            'customer cancel' => route('appointments.cancel', $confirmed),
            'customer reschedule' => route('appointments.reschedule', $confirmed),
            'customer profile' => route('profile.edit'),
            'customer notifications' => route('notifications.index'),
        ];
    }
}
