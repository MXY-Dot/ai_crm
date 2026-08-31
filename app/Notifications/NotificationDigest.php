<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\User;
use App\Notifications\Channels\TenantTelegramChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ТЗ раздел 18 — the bundled counterpart to AppNotification for hourly/
 * daily/weekly delivery frequencies (see AppNotification::passesFrequencyGate()).
 * One digest, however many items accumulated since the last one; database
 * writes never happen here (each item already has its own real
 * AppNotification database row) -- this only fires mail/Telegram.
 */
class NotificationDigest extends Notification
{
    /**
     * @param array<int, array{title: string, body: ?string, action_url: ?string, created_at: string}> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly string $periodLabel,
    ) {
    }

    public function via(object $notifiable): array
    {
        return array_filter([
            $this->wantsMail($notifiable) ? 'mail' : null,
            $this->wantsTelegram($notifiable) ? TenantTelegramChannel::class : null,
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->items);
        $message = (new MailMessage())
            ->subject("WERO: {$count} уведомлени(й) за {$this->periodLabel}")
            ->greeting("За {$this->periodLabel} накопилось {$count} уведомлени(й):");

        foreach (array_slice($this->items, 0, 20) as $item) {
            $line = "• {$item['title']}";
            if ($item['body']) {
                $line .= ' — '.$item['body'];
            }
            $message->line($line);
        }

        if ($count > 20) {
            $message->line('...и ещё '.($count - 20).'.');
        }

        return $message->action('Открыть уведомления в WERO', url('/notifications'));
    }

    public function toTenantTelegram(object $notifiable): string
    {
        $count = count($this->items);
        $text = "*WERO: {$count} уведомлени(й) за {$this->periodLabel}*\n\n";

        foreach (array_slice($this->items, 0, 15) as $item) {
            $text .= '• '.$this->escapeMarkdown($item['title']).($item['body'] ? ' — '.$this->escapeMarkdown($item['body']) : '')."\n";
        }

        if ($count > 15) {
            $text .= "\n...и ещё ".($count - 15).'.';
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
