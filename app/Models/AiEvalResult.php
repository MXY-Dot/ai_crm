<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ai_eval_example_id', 'run_id', 'provider', 'model', 'response_text', 'latency_ms', 'tokens_in', 'tokens_out', 'success', 'error_message'])]
class AiEvalResult extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['success' => 'boolean', 'created_at' => 'datetime'];
    }

    public function example(): BelongsTo
    {
        return $this->belongsTo(AiEvalExample::class, 'ai_eval_example_id');
    }
}
