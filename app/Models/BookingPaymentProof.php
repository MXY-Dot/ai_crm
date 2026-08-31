<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['booking_id', 'file_path', 'amount', 'operation_number', 'status', 'reviewed_by_user_id', 'reviewed_at', 'comment'])]
class BookingPaymentProof extends Model
{
    // ТЗ раздел 16 -- 'resubmission_requested' is deliberately distinct from a flat 'rejected':
    // it means the booking goes back to awaiting a NEW screenshot, not that the payment claim
    // itself was denied.
    public const STATUSES = ['pending', 'confirmed', 'rejected', 'resubmission_requested'];

    protected $appends = ['file_url'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'reviewed_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
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
