<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** SOW 2.18: one reminder about an unpaid checkout, only to players who opted in to marketing emails. */
class AbandonedCheckout extends Notification
{
    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $o = $this->order->loadMissing('lines.competition');
        $site = rtrim((string) config('app.frontend_url'), '/');
        $rows = $o->lines->map(fn ($l) => [(string) $l->competition?->title, $l->quantity.' tickets'])->all();

        return (new MailMessage)->subject('Your Tickets Are Still Waiting')
            ->greeting('Hi '.$notifiable->first_name.',')
            ->line('You didn’t finish checking out. Here’s what you picked:')
            ->markdown('notifications::email', ['blocks' => [['rows', $rows]]])
            ->action('Finish Checking Out', $site.'/basket?recover='.urlencode($o->number))
            ->line('Competitions close when they sell out or reach their closing time. You can turn off these emails in your account settings. 18+ UK only. Please play responsibly.')
            ->salutation('The '.config('app.name').' team');
    }
}
