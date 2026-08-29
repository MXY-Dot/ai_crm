<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'company_id', 'service_id', 'free_reschedule_hours', 'late_reschedule_hours', 'max_client_reschedules', 'no_show_forfeits_prepayment'])]
class CancellationPolicy extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'free_reschedule_hours' => 'integer',
            'late_reschedule_hours' => 'integer',
            'max_client_reschedules' => 'integer',
            'no_show_forfeits_prepayment' => 'boolean',
        ];
    }

    public static function defaultFor(int $companyId): array
    {
        return [
            'company_id' => $companyId,
            'service_id' => null,
            'free_reschedule_hours' => 48,
            'late_reschedule_hours' => 24,
            'max_client_reschedules' => 2,
            'no_show_forfeits_prepayment' => true,
        ];
    }
}
