<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed the instant a Message row is created (see Message::booted()) so the
 * CRM shows an inbound customer message immediately, before AI generation
 * (which runs later, decoupled, in ProcessAiReplyJob) even starts. Broadcast
 * on two channels: the conversation thread (for whoever has it open) and the
 * tenant-wide list (so the sidebar can reorder/preview without polling).
 */
class MessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->message->conversation_id),
            new PrivateChannel('tenant.'.$this->message->tenant_id.'.conversations'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message->loadMissing('replyTo')->toArray(),
            // Not a Message column -- the tenant-wide "new message" toast (useUnreadStore)
            // wants to show which channel this came from (WhatsApp/Telegram/Instagram/
            // Facebook icon+color) without a second round-trip to look up the conversation.
            'provider' => $this->message->conversation?->channel?->provider,
        ];
    }
}
