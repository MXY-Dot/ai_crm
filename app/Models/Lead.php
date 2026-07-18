<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'company_id', 'customer_id', 'title', 'status', 'source', 'score', 'assigned_user_id', 'ai_summary'])]
class Lead extends Model
{
    use BelongsToTenant;
}