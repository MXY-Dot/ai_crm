<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ТЗ раздел 9 (Туристическая компания) -- the tour OFFERING (destination,
 * per-person price, length in days). Deliberately separate from
 * TourDeparture: one tour ("Тур в Стамбул") can run as several scheduled
 * заезды (departures), each with its own dates/capacity/price. Same role
 * in this module as Course plays in the education module.
 */
#[Fillable(['tenant_id', 'company_id', 'name', 'destination', 'description', 'category', 'price', 'duration_days', 'is_active'])]
class Tour extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['price' => 'float', 'duration_days' => 'integer', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function departures(): HasMany
    {
        return $this->hasMany(TourDeparture::class);
    }
}
