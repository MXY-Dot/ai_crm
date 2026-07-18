<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'company_id', 'name', 'phone', 'email', 'source', 'tags', 'meta'])]
class Customer extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['tags' => 'array', 'meta' => 'array'];
    }
}