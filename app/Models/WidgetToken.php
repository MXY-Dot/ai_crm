<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A named embed token for the website widget — see WidgetController::channel() for how it resolves to a tenant's single website Channel. */
#[Fillable(['tenant_id', 'company_id', 'label', 'token', 'last_used_at'])]
class WidgetToken extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
