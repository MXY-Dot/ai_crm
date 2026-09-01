<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['room_reservation_id', 'file_path', 'amount', 'operation_number', 'status', 'reviewed_by_user_id', 'reviewed_at', 'comment'])]
class RoomReservationPaymentProof extends Model
{
    // Same distinction as BookingPaymentProof -- 'resubmission_requested' sends the
    // reservation back to awaiting a NEW screenshot, not a flat denial.
    public const STATUSES = ['pending', 'confirmed', 'rejected', 'resubmission_requested'];

    protected $appends = ['file_url'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'reviewed_at' => 'datetime'];
    }

    public function roomReservation(): BelongsTo
    {
        return $this->belongsTo(RoomReservation::class);
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
