<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'company_id', 'channel_id', 'customer_id', 'lead_id', 'assigned_user_id', 'external_id', 'subject', 'status', 'priority', 'last_message_at', 'ai_summary'])]
class Conversation extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function reads(): HasMany
    {
        return $this->hasMany(ConversationRead::class);
    }

    public function pins(): HasMany
    {
        return $this->hasMany(ConversationPin::class);
    }
}