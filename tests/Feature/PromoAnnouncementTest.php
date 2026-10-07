<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PromoController;
use App\Models\Admin;
use App\Models\Promo;
use App\Notifications\PromoAnnouncementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Announcing a promo to every customer happens inside the admin's request.
 *
 * There is no queue to move it onto and no worker to run it if there were, so
 * the two halves of the notification — the in-app row and the email copy — are
 * subject to the same wall clock as everything else in that request. The budget
 * exists so that a mail server which will not answer costs the *emailing* a
 * stop, not the announcement, and not a retry that announces twice.
 *
 * @see PromoController::announce()
 */
class PromoAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return $this->makeAdmin();
    }

    private function promo(): Promo
    {
        return Promo::create([
            'title' => 'Rainy Season Glow',
            'description' => 'Fifteen minutes off every foot spa in August.',
            'starts_at' => today(),
            'ends_at' => today()->addDays(7),
            'is_active' => true,
        ]);
    }

    /**
     * The announcement reaches the customers it is meant for, and nothing else,
     * and the promo is marked as announced so the button can tell.
     */
    public function test_announcing_reaches_every_active_customer_and_marks_the_promo_announced(): void
    {
        Notification::fake();

        $active = $this->makeUser();
        $alsoActive = $this->makeUser();
        $inactive = $this->makeUser(['is_active' => false]);
        $promo = $this->promo();

        $this->actingAs($this->admin(), 'admin')
            ->from(route('admin.promos.index'))
            ->post(route('admin.promos.announce', $promo), ['audience' => 'all'])
            ->assertRedirect(route('admin.promos.index'))
            ->assertSessionHas('status');

        Notification::assertSentTo($active, PromoAnnouncementNotification::class);
        Notification::assertSentTo($alsoActive, PromoAnnouncementNotification::class);
        Notification::assertNotSentTo($inactive, PromoAnnouncementNotification::class);

        $this->assertTrue($promo->fresh()->notified);
    }

    /**
     * `via()` is where the budget reaches the notification, so it is the whole
     * switch: off the mail channel and the row in `notifications` still lands.
     *
     * Asserted as a unit because the budget path cannot be waited out inside a
     * test — this is the contract `announce()` relies on when it passes `false`.
     */
    public function test_dropping_the_mail_channel_leaves_the_in_app_announcement_intact(): void
    {
        $promo = $this->promo();
        $customer = $this->makeUser();

        $this->assertSame(
            ['database', 'mail'],
            (new PromoAnnouncementNotification($promo))->via($customer),
            'The default still emails as well as writing in-app.',
        );

        $this->assertSame(
            ['database'],
            (new PromoAnnouncementNotification($promo, false))->via($customer),
            'A spent budget must still write the in-app row.',
        );
    }

    /**
     * The exhausted path: nobody is emailed, everybody is announced to, the
     * promo is marked announced, and the admin is told what happened.
     *
     * `MAIL_ANNOUNCE_BUDGET=0` is the seam that makes this testable — with no
     * budget the deadline is already behind the first send, so every iteration
     * takes the in-app-only branch without the test having to sit and watch a
     * clock. It also proves the ordering of the check rather than the arithmetic
     * of it: no recipient is special-cased.
     */
    public function test_an_exhausted_budget_stops_the_emails_but_not_the_announcement(): void
    {
        Notification::fake();
        config()->set('mail.promo_announce_budget', 0);

        $promo = $this->promo();
        $customers = [$this->makeUser(), $this->makeUser(), $this->makeUser()];

        $this->actingAs($this->admin(), 'admin')
            ->from(route('admin.promos.index'))
            ->post(route('admin.promos.announce', $promo), ['audience' => 'all'])
            ->assertRedirect(route('admin.promos.index'))
            ->assertSessionHas(
                'status',
                fn (string $status) => str_contains($status, 'Emailing stopped at the time limit'),
            );

        foreach ($customers as $customer) {
            Notification::assertSentTo(
                $customer,
                PromoAnnouncementNotification::class,
                fn (PromoAnnouncementNotification $notification, array $channels) => $channels === ['database'],
            );
        }

        $this->assertTrue(
            $promo->fresh()->notified,
            'A spent budget is not a failed announcement, so the button should close.',
        );
    }

    /**
     * The other half: when the budget is plenty, the message says nothing about
     * a limit it never reached. The flash is the only place the admin learns
     * whether all of the emails went, so it has to describe what happened rather
     * than always describing the safeguard.
     */
    public function test_an_announcement_that_used_no_budget_does_not_mention_a_limit(): void
    {
        Notification::fake();
        config()->set('mail.promo_announce_budget', 15);

        $promo = $this->promo();
        $customer = $this->makeUser();

        $this->actingAs($this->admin(), 'admin')
            ->from(route('admin.promos.index'))
            ->post(route('admin.promos.announce', $promo), ['audience' => 'all'])
            ->assertRedirect(route('admin.promos.index'))
            ->assertSessionHas(
                'status',
                fn (string $status) => str_contains($status, 'Promo announced to 1 customer(s).')
                    && ! str_contains($status, 'Emailing stopped'),
            );

        Notification::assertSentTo(
            $customer,
            PromoAnnouncementNotification::class,
            fn (PromoAnnouncementNotification $notification, array $channels) => $channels === ['database', 'mail'],
        );
    }
}
