<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'company_id', 'branch_id', 'name', 'type', 'capacity', 'is_active'])]
class Resource extends Model
{
    use BelongsToTenant;

    // 'table' -- restaurant module (ТЗ раздел 9), a table Resource with a real
    // `capacity` (seats), used by TableAvailabilityCalculator to only offer tables
    // that actually fit the requested party size.
    public const TYPES = ['chair', 'cabinet', 'room', 'table', 'equipment', 'other'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'capacity' => 'integer'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
