<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Vip\VipScoreCalculator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'company_id', 'customer_id', 'title', 'status', 'source', 'score', 'next_action', 'amount', 'won_at', 'assigned_user_id', 'ai_summary', 'lost_reason'])]
class Lead extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'won_at' => 'datetime',
        ];
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $lead): void {
            if ($lead->isDirty('status') && $lead->status === 'won' && ! $lead->won_at) {
                $lead->won_at = now();
            }
        });

        // Single entry point for VIP recalculation (ЭТАП 12.2) regardless of which
        // controller/flow touched the lead — LeadController today, a future Sales
        // Engine tomorrow. Only fires on the fields the score actually depends on.
        static::saved(function (self $lead): void {
            if ($lead->customer_id && ($lead->wasChanged('status') || $lead->wasChanged('amount'))) {
                $customer = Customer::withoutGlobalScopes()->find($lead->customer_id);
                $customer && app(VipScoreCalculator::class)->recalculate($customer);
            }
        });
    }
}
