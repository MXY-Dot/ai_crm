<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['tenant_id', 'company_id', 'name', 'position', 'phone', 'photo_path', 'is_active'])]
class Employee extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)->withPivot('custom_price')->withTimestamps();
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(EmployeeSchedule::class);
    }

    public function timeOff(): HasMany
    {
        return $this->hasMany(EmployeeTimeOff::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** ТЗ раздел 21 -- a "Специалист" login is only meaningfully scoped once linked back to their own staff profile (see BookingPolicy). Not every Employee has a User account. */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
