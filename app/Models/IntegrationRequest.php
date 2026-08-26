<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id', 'company_id', 'requested_by', 'platform_name', 'platform_url', 'plan_version',
    'tech_contact', 'api_docs_url', 'data_to_receive', 'data_to_send', 'sync_frequency',
    'scenario_description', 'comment', 'attachments', 'status', 'assigned_admin_id',
    'cost_estimate', 'dev_time_estimate',
])]
class IntegrationRequest extends Model
{
    use BelongsToTenant;

    public const STATUSES = [
        'new', 'reviewing', 'needs_info', 'possible', 'needs_pricing',
        'agreed', 'in_development', 'testing', 'connected', 'impossible',
    ];

    protected function casts(): array
    {
        return [
            'data_to_receive' => 'array',
            'data_to_send' => 'array',
            'attachments' => 'array',
            'cost_estimate' => 'float',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(IntegrationRequestMessage::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }
}
