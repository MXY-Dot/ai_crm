<?php

namespace App\Support\Travel;

use App\Models\TourBooking;
use App\Models\TourBookingStatusHistory;
use App\Models\TourDeparture;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ТЗ раздел 9 (Туристическая компания) — "заявки на туры". Mirrors
 * EnrollmentService's shape (book()/updateStatus()/cancel(), row-locked
 * against the departure's own capacity), with one real difference: a
 * booking can consume more than one seat (pax_count), so the capacity
 * check sums pax_count across active bookings rather than just counting
 * rows.
 */
class TourBookingService
{
    public function book(array $data, ?User $actor): TourBooking
    {
        return DB::transaction(function () use ($data, $actor) {
            $departure = TourDeparture::query()->lockForUpdate()->findOrFail($data['tour_departure_id']);

            if (! in_array($departure->status, TourDeparture::BOOKABLE_STATUSES, true)) {
                throw new TravelConflictException('Набор на этот заезд закрыт.');
            }

            $paxCount = max(1, (int) ($data['pax_count'] ?? 1));

            if ($departure->capacity !== null) {
                $bookedSeats = (int) TourBooking::query()
                    ->where('tour_departure_id', $departure->id)
                    ->whereIn('status', TourBooking::ACTIVE_STATUSES)
                    ->sum('pax_count');

                if ($bookedSeats + $paxCount > $departure->capacity) {
                    $free = max(0, $departure->capacity - $bookedSeats);
                    throw new TravelConflictException("На этот заезд осталось свободных мест: {$free}.");
                }
            }

            $tenantId = $actor?->tenant_id ?? $data['tenant_id'] ?? null;

            $booking = TourBooking::create([
                'tenant_id' => $tenantId,
                'company_id' => $data['company_id'],
                'tour_departure_id' => $departure->id,
                'customer_id' => $data['customer_id'],
                'pax_count' => $paxCount,
                'status' => TourBooking::STATUS_REQUESTED,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor?->id,
            ]);

            $this->logStatus($booking, null, TourBooking::STATUS_REQUESTED, $actor, 'Заявка создана');

            return $booking;
        });
    }

    public function updateStatus(TourBooking $booking, string $newStatus, User $actor, ?string $comment = null): TourBooking
    {
        abort_unless(in_array($newStatus, [TourBooking::STATUS_CONFIRMED, TourBooking::STATUS_COMPLETED], true), 422, 'Для этого статуса используйте отдельное действие.');

        return DB::transaction(function () use ($booking, $newStatus, $actor, $comment) {
            if (! in_array($booking->status, TourBooking::ACTIVE_STATUSES, true)) {
                throw new TravelConflictException('Эта заявка уже завершена или отменена.');
            }

            $oldStatus = $booking->status;
            $booking->update(['status' => $newStatus]);
            $this->logStatus($booking, $oldStatus, $newStatus, $actor, $comment);

            return $booking->refresh();
        });
    }

    // $actor nullable for the same reason as RepairOrderService's/
    // EnrollmentService's own cancel() fix -- a customer-initiated cancel from
    // AI-chat has no staff user behind it (see TravelChatAssistant).
    public function cancel(TourBooking $booking, ?User $actor, string $reason): TourBooking
    {
        return DB::transaction(function () use ($booking, $actor, $reason) {
            if (! in_array($booking->status, TourBooking::ACTIVE_STATUSES, true)) {
                throw new TravelConflictException('Эта заявка уже завершена или отменена.');
            }

            $oldStatus = $booking->status;
            $booking->update(['status' => TourBooking::STATUS_CANCELLED, 'cancelled_reason' => $reason]);
            $this->logStatus($booking, $oldStatus, TourBooking::STATUS_CANCELLED, $actor, $reason);

            return $booking->refresh();
        });
    }

    private function logStatus(TourBooking $booking, ?string $old, string $new, ?User $actor, ?string $comment): void
    {
        TourBookingStatusHistory::create([
            'tour_booking_id' => $booking->id,
            'old_status' => $old,
            'new_status' => $new,
            'changed_by_user_id' => $actor?->id,
            'comment' => $comment,
        ]);
    }
}
