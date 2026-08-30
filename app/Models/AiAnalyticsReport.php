<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'period_type', 'period_start', 'period_end', 'content', 'snapshot', 'generated_by'])]
class AiAnalyticsReport extends Model
{
    use BelongsToTenant;

    public const PERIOD_TYPES = ['weekly', 'monthly'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'snapshot' => 'array',
        ];
    }
}
