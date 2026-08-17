<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'company_id', 'name', 'provider', 'model', 'status', 'handoff_threshold', 'goal', 'instructions', 'channels', 'settings'])]
class AiAgent extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'handoff_threshold' => 'integer',
            'channels' => 'array',
            'settings' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }
}