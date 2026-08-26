<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['input_text', 'expected_reply', 'expected_intent', 'notes'])]
class AiEvalExample extends Model
{
    public function results(): HasMany
    {
        return $this->hasMany(AiEvalResult::class);
    }
}
