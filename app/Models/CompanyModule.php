<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'company_id', 'module_key', 'enabled', 'settings'])]
class CompanyModule extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'settings' => 'array'];
    }
}
