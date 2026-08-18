<?php

namespace App\Support\Chatwoot;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ChatwootPayloadMapper
{
    public function fromWebhook(array $payload): array
    {
        if (Arr::has($payload, 'message.content')) {
            return $payload;
        }

        return $this->normalize($payload, $payload);
    }

    public function fromConversation(array $conversation, ?array $message = null): array
    {
        $message ??= $this->latestIncomingMessage($conversation);

        return $this->normalize($conversation, $message ?? []);
    }

    public function latestIncomingMessage(array $conversation): ?array
    {
        $messages = collect(Arr::get($conversation, 'messages', []))
            ->filter(fn (array $message): bool => $this->senderType($message) === 'customer')
            ->sortBy(fn (array $message): int => (int) Arr::get($message, 'created_at', 0));

        return $messages->last();
    }

    private function normalize(array $source, array $message): array
    {
        $conversation = Arr::get($source, 'conversation');

        if (! is_array($conversation)) {
            $conversation = $source;
        }

        $conversationId = $this->string([
            Arr::get($source, 'conversation_id'),
            Arr::get($message, 'conversation_id'),
            Arr::get($conversation, 'id'),
        ]);

        $messageId = $this->string([
            Arr::get($message, 'id'),
            Arr::get($source, 'message.id'),
            Arr::get($source, 'id'),
        ]);

        $sender = Arr::get($message, 'sender');

        if (! is_array($sender)) {
            $sender = Arr::get($source, 'sender');
        }

        if (! is_array($sender)) {
            $sender = Arr::get($source, 'meta.sender', []);
        }

        $inbox = Arr::get($source, 'inbox');

        if (! is_array($inbox)) {
            $inbox = [
                'id' => Arr::get($source, 'inbox_id') ?? Arr::get($message, 'inbox_id'),
                'name' => Arr::get($source, 'inbox.name') ?? 'Chatwoot Inbox',
            ];
        }

        return [
            'event' => (string) (Arr::get($source, 'event') ?? 'message_created'),
            'provider' => $this->provider($source),
            'inbox' => [
                'id' => $this->string([Arr::get($inbox, 'id')]),
                'name' => (string) (Arr::get($inbox, 'name') ?? 'Chatwoot Inbox'),
            ],
            'conversation' => [
                'id' => $conversationId,
                'subject' => $this->subject($conversationId, $conversation, $sender, $message, $source),
                'status' => (string) (Arr::get($conversation, 'status') ?? 'open'),
                'priority' => (string) (Arr::get($conversation, 'priority') ?? 'normal'),
            ],
            'sender' => [
                'name' => (string) (Arr::get($sender, 'name') ?? Arr::get($sender, 'available_name') ?? 'Chatwoot contact'),
                'email' => Arr::get($sender, 'email'),
                'phone_number' => Arr::get($sender, 'phone_number'),
                'type' => $this->senderType($message),
            ],
            'message' => [
                'id' => $messageId,
                'content' => (string) (Arr::get($message, 'content') ?? Arr::get($source, 'content') ?? ''),
            ],
        ];
    }

    private function subject(string $conversationId, array $conversation, array $sender, array $message, array $source): string
    {
        $subject = Arr::get($conversation, 'subject') ?? Arr::get($conversation, 'display_id');

        if ($subject) {
            return (string) $subject;
        }

        $name = (string) (Arr::get($sender, 'name') ?? Arr::get($sender, 'available_name') ?? 'Chatwoot contact');
        $content = (string) (Arr::get($message, 'content') ?? Arr::get($source, 'content') ?? '');

        return trim($name.' - '.Str::limit($content, 70, '')) ?: 'Chatwoot #'.$conversationId;
    }
    /**
     * ЭТАП 2 — Chatwoot sends its channel type in Rails STI form ("Channel::Whatsapp",
     * "Channel::FacebookPage", "Channel::Instagram") — `::`-separated, not `\`-separated,
     * so class_basename() alone left it untouched as "channel::whatsapp" instead of
     * "whatsapp". That's why ChatwootWebhookHandler::channel() (which keys the
     * auto-created Channel row directly off this return value) never produced a
     * whatsapp/instagram row a tenant would ever see as "Подключено" in Settings.
     * Not verified against a live WhatsApp/Instagram Chatwoot webhook (no such
     * channel is actually configured on the demo tenant) — based on Chatwoot's
     * documented channel_type naming convention.
     */
    private function provider(array $source): string
    {
        $channel = strtolower((string) (Arr::get($source, 'channel') ?? Arr::get($source, 'channel_type') ?? 'chatwoot'));

        if (str_contains($channel, 'web')) {
            return 'website';
        }

        if (str_contains($channel, 'facebook')) {
            return 'facebook';
        }

        return class_basename(str_replace('::', '\\', $channel ?: 'chatwoot'));
    }

    private function senderType(array $message): string
    {
        $raw = strtolower((string) (Arr::get($message, 'sender.type') ?? Arr::get($message, 'sender_type') ?? 'contact'));
        $messageType = Arr::get($message, 'message_type');

        if (in_array($messageType, [2, 3, '2', '3', 'activity', 'template'], true)) {
            return 'system';
        }

        if ($messageType !== null && ! in_array($messageType, [0, '0', 'incoming'], true)) {
            return 'operator';
        }

        if (str_contains($raw, 'user') || str_contains($raw, 'agent')) {
            return 'operator';
        }

        return 'customer';
    }

    private function string(array $values): string
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return '';
    }
}
