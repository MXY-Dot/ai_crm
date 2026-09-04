<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
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

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Код подтверждения WERO')
            ->greeting('Здравствуйте!')
            ->line('Ваш код подтверждения почты: **'.$this->code.'**')
            ->line('Код действует 15 минут.')
            ->action('Подтвердить почту', $this->verifyUrl)
            ->line('Если вы не регистрировались в WERO, просто проигнорируйте это письмо.');
    }
}
