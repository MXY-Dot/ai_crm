<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * "Вера" is a deliberate persona, not a typo for "Vera" the person -- every
 * automated notification a company gets (large order, complaint, AI error,
 * operator activity) reads as coming from a named team member "Вера · {их
 * компания}" rather than a faceless "notifications@" system sender, same
 * idea as Notion's "Ana" or Intercom's "Fin" — see the light-themed
 * emails/notification.blade.php view for the actual layout.
 */
class CompanyNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $title,
        public readonly ?string $body,
        public readonly ?string $actionUrl,
        public readonly ?string $companyName,
        public readonly bool $urgent = false,
    ) {
    }

    public function build(): self
    {
        $fromName = 'Вера'.($this->companyName ? ' · '.$this->companyName : '');

        return $this
            ->from((string) config('mail.from.address'), $fromName)
            ->subject($this->title)
            ->view('emails.notification')
            ->with([
                'title' => $this->title,
                'body' => $this->body,
                'actionUrl' => $this->actionUrl,
                'companyName' => $this->companyName,
                'urgent' => $this->urgent,
                'logoUrl' => rtrim((string) config('app.url'), '/').'/storage/logo/logo.png',
            ]);
    }
}
