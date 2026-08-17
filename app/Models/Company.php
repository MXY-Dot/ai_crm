<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Fillable(['tenant_id', 'name', 'industry', 'phone', 'email', 'website', 'address', 'timezone', 'working_hours', 'brand_settings'])]
class Company extends Model
{
    use BelongsToTenant;

    protected $appends = ['logo_url'];

    protected function casts(): array
    {
        return ['working_hours' => 'array', 'brand_settings' => 'array'];
    }

    public function getLogoUrlAttribute(): ?string
    {
        $path = $this->brand_settings['logo_path'] ?? null;

        return $path ? Storage::disk('public')->url($path) : null;
    }

    /**
     * ЭТАП 13.5 — working_hours today is a single daily range (start/end,
     * "9:00-18:00" style), not a real per-weekday schedule — that redesign is
     * a separate decision, not part of this check. No hours configured =
     * always open (safe default, doesn't change behavior for tenants who
     * never set this).
     */
    public function isWithinWorkingHours(?Carbon $at = null): bool
    {
        $start = $this->working_hours['start'] ?? null;
        $end = $this->working_hours['end'] ?? null;

        if (! $start || ! $end) {
            return true;
        }

        $now = ($at ?? now())->copy()->setTimezone($this->timezone ?: config('app.timezone'));

        try {
            $rangeStart = $now->copy()->setTimeFromTimeString($start);
            $rangeEnd = $now->copy()->setTimeFromTimeString($end);
        } catch (Throwable) {
            return true;
        }

        return $now->between($rangeStart, $rangeEnd);
    }
}
