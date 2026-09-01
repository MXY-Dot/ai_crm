<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['order_id', 'file_path', 'amount', 'operation_number', 'status', 'reviewed_by_user_id', 'reviewed_at', 'comment'])]
class OrderPaymentProof extends Model
{
    // Same distinction as BookingPaymentProof (see its docblock) -- 'resubmission_requested'
    // means the order goes back to awaiting a NEW screenshot, not that the claim was denied.
    public const STATUSES = ['pending', 'confirmed', 'rejected', 'resubmission_requested'];

    protected $appends = ['file_url'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'reviewed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function getFileUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }
}
