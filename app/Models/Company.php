<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'name', 'industry', 'phone', 'email', 'website', 'address', 'timezone', 'working_hours', 'brand_settings'])]
class Company extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['working_hours' => 'array', 'brand_settings' => 'array'];
    }
}