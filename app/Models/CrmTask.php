<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'company_id', 'lead_id', 'assigned_user_id', 'title', 'description', 'status', 'due_at', 'priority'])]
class CrmTask extends Model
{
    use BelongsToTenant;

    protected $table = 'tasks';

    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }
}