<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailConfirmationLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $verifyUrl)
    {
    }

    public function build(): self
    {
        return $this->subject('Подтвердите почту в WERO')
            ->view('emails.confirmation-link')
            ->with([
                'verifyUrl' => $this->verifyUrl,
                'logoUrl' => rtrim((string) config('app.url'), '/').'/storage/logo/logo.png',
            ]);
    }
}
