<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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
}
