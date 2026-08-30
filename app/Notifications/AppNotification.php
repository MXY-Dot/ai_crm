<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

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
        return ['database'];
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
}
