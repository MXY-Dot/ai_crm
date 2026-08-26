<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['integration_request_id', 'user_id', 'is_admin', 'body'])]
class IntegrationRequestMessage extends Model
{
    protected function casts(): array
    {
        return ['is_admin' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
