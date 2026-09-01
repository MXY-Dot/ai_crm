<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'company_id', 'branch_id', 'name', 'type', 'capacity', 'price_per_night', 'is_active'])]
class Resource extends Model
{
    use BelongsToTenant;

    // 'table' -- restaurant module (ТЗ раздел 9), a table Resource with a real
    // `capacity` (seats), used by TableAvailabilityCalculator to only offer tables
    // that actually fit the requested party size. 'room' had existed unused since
    // the type list was first written -- repurposed for the hotel module (ТЗ раздел
    // 9, "Гостиница/хостел") as a guest room, with `capacity` now doubling as max
    // guests and the new `price_per_night` as its nightly rate.
    public const TYPES = ['chair', 'cabinet', 'room', 'table', 'equipment', 'other'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'capacity' => 'integer', 'price_per_night' => 'float'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
