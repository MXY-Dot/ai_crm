<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'knowledge_document_id', 'position', 'content', 'token_count', 'meta'])]
class KnowledgeChunk extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }
}