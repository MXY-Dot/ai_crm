<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'ai_agent_id', 'conversation_id', 'lead_id', 'status', 'confidence', 'intent', 'summary', 'next_action', 'started_at', 'finished_at', 'payload'])]
class AiRun extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}