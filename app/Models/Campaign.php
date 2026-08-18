<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'company_id', 'name', 'offer_text', 'segment', 'min_purchases', 'inactive_days', 'status', 'created_by', 'approved_by', 'approved_at', 'sent_at'])]
class Campaign extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'min_purchases' => 'integer',
            'inactive_days' => 'integer',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
