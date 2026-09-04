<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent from TenantUserController::store() -- deliberately reads as coming
 * from the actual inviting owner/manager at their real company ("{Имя} ·
 * {Компания}"), not the "Вера" persona CompanyNotificationMail uses for
 * automated alerts. An invite is a personal act by a real person, and
 * should feel like one.
 *
 * Carries no password -- only a signed one-click link
 * (WelcomeSetupController::acceptInvite()) that logs the invitee straight
 * in; a plaintext password sitting in an inbox indefinitely is a real risk
 * worth avoiding, not just theater. They set their own password on the
 * welcome-setup form right after clicking through.
 */
class TeamInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $inviteeName,
        public readonly ?string $companyName,
        public readonly ?string $inviterName,
        public readonly string $acceptUrl,
    ) {
    }

    public function build(): self
    {
        $fromName = $this->inviterName
            ? $this->inviterName.($this->companyName ? ' · '.$this->companyName : '')
            : ($this->companyName ?? 'WERO');

        return $this
            ->from((string) config('mail.from.address'), $fromName)
            ->subject($this->companyName ? "Приглашение в {$this->companyName} на WERO" : 'Приглашение в WERO')
            ->view('emails.team-invite')
            ->with([
                'inviteeName' => $this->inviteeName,
                'companyName' => $this->companyName,
                'inviterName' => $this->inviterName,
                'acceptUrl' => $this->acceptUrl,
                'logoUrl' => rtrim((string) config('app.url'), '/').'/storage/logo/logo.png',
            ]);
    }
}
