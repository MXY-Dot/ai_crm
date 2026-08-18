<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'company_id', 'channel_id', 'customer_id', 'lead_id', 'assigned_user_id', 'external_id', 'subject', 'status', 'priority', 'last_message_at', 'first_response_at', 'resolved_at', 'ai_summary', 'labels'])]
class Conversation extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'first_response_at' => 'datetime', 'resolved_at' => 'datetime', 'labels' => 'array'];
    }

    /** ЭТАП 3.7 — merges a new label in (dedup, order-preserving), used by both AI auto-labeling and manual add. */
    public function addLabel(string $label): void
    {
        $this->labels = array_values(array_unique([...($this->labels ?? []), $label]));
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

    /** ЭТАП 13.6 — captured once, from wherever a reply actually goes out (AiWorkflow::autoReply() or ConversationReplyController). */
    public function markFirstResponse(): void
    {
        if ($this->first_response_at === null) {
            $this->forceFill(['first_response_at' => now()])->save();
        }
    }
}