<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'company_id', 'name', 'type', 'is_active'])]
class Resource extends Model
{
    use BelongsToTenant;

    public const TYPES = ['chair', 'cabinet', 'room', 'equipment', 'other'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
