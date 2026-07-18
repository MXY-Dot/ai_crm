<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'company_id', 'name', 'provider', 'status', 'handoff_threshold', 'instructions', 'settings'])]
class AiAgent extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'handoff_threshold' => 'integer',
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