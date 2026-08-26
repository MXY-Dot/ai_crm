<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'name', 'sort_order', 'is_active', 'default_modules'])]
class BusinessType extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'default_modules' => 'array'];
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
