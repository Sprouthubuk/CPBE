<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The scheduled summary report (SOW 2.14). */
class ReportSummary extends Notification
{
    /** @param list<array{0: string, 1: string}> $rows */
    public function __construct(public string $title, public array $rows, public string $period) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(config('app.name').' '.$this->title)
            ->greeting($this->title)
            ->line('Here are the figures for '.$this->period.'.')
            ->markdown('notifications::email', ['blocks' => [['rows', $this->rows]]])
            ->action('Open The Business Dashboard', rtrim((string) config('app.url'), '/').'/admin/business')
            ->salutation(config('app.name'));
    }
}
