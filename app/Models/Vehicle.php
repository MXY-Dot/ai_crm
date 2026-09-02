<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ТЗ раздел 9 (Автосервис/автомойка) -- a customer's car. Deliberately its
 * own model rather than shoehorned into Resource (which models things the
 * COMPANY owns -- tables, rooms, chairs -- not things a customer brings in).
 */
#[Fillable(['tenant_id', 'company_id', 'customer_id', 'make', 'model', 'year', 'plate_number', 'vin', 'color', 'notes', 'created_by_user_id'])]
class Vehicle extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['year' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function repairOrders(): HasMany
    {
        return $this->hasMany(RepairOrder::class)->latest('id');
    }
}
