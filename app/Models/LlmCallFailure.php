<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'provider', 'model', 'cause', 'detail'])]
class LlmCallFailure extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime'];
}
