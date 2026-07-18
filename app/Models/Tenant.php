<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'status', 'plan_id', 'trial_ends_at', 'settings'])]
class Tenant extends Model
{
    protected function casts(): array
    {
        return ['settings' => 'array', 'trial_ends_at' => 'datetime'];
    }

    protected function slug(): Attribute
    {
        return Attribute::make(set: fn (?string $value, array $attrs) => $value ?: Str::slug($attrs['name']));
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}