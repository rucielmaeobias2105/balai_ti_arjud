<?php

namespace App\Notifications;

use App\Models\Promo;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to customers when an admin publishes a promo (Admin Flow 13).
 */
class PromoAnnouncementNotification extends Notification
{
    use Queueable;

    /**
     * @param  bool  $withMail  Whether this recipient should also be emailed.
     *
     * The database half is the announcement; the mail half is a copy of it.
     * They are separable because the request that sends them has a finite
     * budget on shared hosting — see `PromoController::announce()` — and
     * "every customer saw the promo" must not depend on how long a mail
     * server took to answer for the customer before them.
     */
    public function __construct(
        public readonly Promo $promo,
        public readonly bool $withMail = true,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->withMail ? ['database', 'mail'] : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Promo: '.$this->promo->title,
            'message' => \Illuminate\Support\Str::limit(strip_tags($this->promo->description), 140),
            'icon' => 'gift',
            'tone' => 'gold',
            'url' => route('services.index'),
            'promo_id' => $this->promo->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->promo->title.' — '.config('app.name'))
            ->greeting("Hello {$notifiable->first_name},")
            ->line(strip_tags($this->promo->description))
            ->line('Valid '.$this->promo->validity_label.'.')
            ->action('Explore Services', route('services.index'));
    }
}
