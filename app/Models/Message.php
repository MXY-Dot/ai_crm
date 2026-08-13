<?php

namespace App\Models;

use App\Events\MessageCreated;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `deleted_at` is a plain manually-managed tombstone column here, deliberately
 * NOT Eloquent's SoftDeletes trait: a deleted message stays visible in the
 * thread as a "message deleted" placeholder (like Telegram/WhatsApp), it isn't
 * hidden from queries — SoftDeletes' auto-exclude behavior would fight that,
 * and this codebase's heavy use of withoutGlobalScopes() for tenant-scoped
 * reads would make the auto-exclusion easy to accidentally bypass anyway.
 */
#[Fillable(['tenant_id', 'conversation_id', 'reply_to_message_id', 'sender_type', 'sender_name', 'body', 'external_id', 'sent_at', 'edited_at', 'deleted_at', 'meta'])]
class Message extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::created(fn (Message $message) => broadcast(new MessageCreated($message)));
    }

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_message_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Message::class, 'reply_to_message_id');
    }
}
