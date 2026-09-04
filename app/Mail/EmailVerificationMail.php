<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $code, public readonly string $verifyUrl)
    {
    }

    public function build(): self
    {
        return $this->subject('Код подтверждения WERO')
            ->view('emails.verification')
            ->with([
                'code' => $this->code,
                'verifyUrl' => $this->verifyUrl,
                'logoUrl' => rtrim((string) config('app.url'), '/').'/storage/logo/logo_dark.png',
            ]);
    }
}
