<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\User;
use App\Notifications\Channels\TenantTelegramChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ТЗ раздел 18 — настройки уведомлений actually do something now. via() used
 * to be hardcoded ['database'] regardless of what the tenant's own
 * notification-preferences toggle (company.brand_settings.notifications,
 * see NotificationPreferencesPanel.vue) said -- email/telegram were UI-only.
 * Now genuinely conditional: mail only if the company hasn't turned it off
 * (default on, matching that panel's own default) and the user has an email;
 * Telegram only if the company has it on AND this specific user has linked
 * their own Telegram (see TelegramWebhookController's `/link` handling) --
 * the toggle alone isn't enough, since WERO has no way to message a Telegram
 * account it was never given.
 */
class AppNotification extends Notification
{
    /** No new column/migration — stored inside the existing `data` JSON blob, same as title/body/action_url. */
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public function __construct(
        private readonly string $type,
        private readonly string $title,
        private readonly ?string $body = null,
        private readonly ?string $actionUrl = null,
        private readonly string $priority = 'normal',
    ) {
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($this->wantsMail($notifiable)) {
            $channels[] = 'mail';
        }

        if ($this->wantsTelegram($notifiable)) {
            $channels[] = TenantTelegramChannel::class;
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->actionUrl,
            'priority' => in_array($this->priority, self::PRIORITIES, true) ? $this->priority : 'normal',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage())->subject($this->title)->greeting($this->title);

        if ($this->body) {
            $message->line($this->body);
        }

        if ($this->actionUrl) {
            $message->action('Открыть в WERO', url($this->actionUrl));
        }

        return $message;
    }

    /** Consumed by TenantTelegramChannel, not a Laravel-recognized to* method -- Telegram isn't a built-in channel. */
    public function toTenantTelegram(object $notifiable): string
    {
        $text = "*{$this->escapeMarkdown($this->title)}*";

        if ($this->body) {
            $text .= "\n".$this->escapeMarkdown($this->body);
        }

        return $text;
    }

    private function escapeMarkdown(string $text): string
    {
        return preg_replace('/([_*\[\]()~`>#+\-=|{}.!])/u', '\\\\$1', $text) ?? $text;
    }

    private function wantsMail(object $notifiable): bool
    {
        if (! $notifiable instanceof User || ! $notifiable->email) {
            return false;
        }

        return (bool) ($this->preferences($notifiable)['email'] ?? true);
    }

    private function wantsTelegram(object $notifiable): bool
    {
        if (! $notifiable instanceof User || ! $notifiable->telegram_chat_id) {
            return false;
        }

        return (bool) ($this->preferences($notifiable)['telegram_bot'] ?? false);
    }

    private function preferences(User $notifiable): array
    {
        if (! $notifiable->tenant_id) {
            return [];
        }

        $company = Company::withoutGlobalScopes()->where('tenant_id', $notifiable->tenant_id)->first();

        return (array) ($company?->brand_settings['notifications'] ?? []);
    }
}
