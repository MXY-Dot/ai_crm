<?php

namespace App\Notifications;

use App\Mail\EmailVerificationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;

class EmailVerificationCode extends Notification
{
    use Queueable;

    public function __construct(private readonly string $code, private readonly string $verifyUrl)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): Mailable
    {
        return (new EmailVerificationMail($this->code, $this->verifyUrl))
            ->to($notifiable->routeNotificationFor('mail'));
    }
}
