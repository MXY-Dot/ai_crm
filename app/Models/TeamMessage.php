<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'sender_id', 'recipient_id', 'body', 'meta', 'reply_to_message_id', 'read_at', 'edited_at', 'deleted_at'])]
class TeamMessage extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'read_at' => 'datetime',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }
}
