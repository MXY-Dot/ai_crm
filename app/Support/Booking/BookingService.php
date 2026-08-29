<?php

namespace App\Support\Booking;

use App\Models\Booking;
use App\Models\BookingPaymentProof;
use App\Models\BookingStatusHistory;
use App\Models\CancellationPolicy;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ТЗ раздел 10-19 (модуль салона) — создание/перенос/отмену брони и
 * подтверждение оплаты по скриншоту сводим сюда, а не в контроллеры, чтобы
 * проверка конфликтов и запись истории статусов не разъезжались по местам.
 */
class BookingService
{
    public function create(array $data, User $actor): Booking
    {
        return DB::transaction(function () use ($data, $actor) {
            $service = Service::query()->findOrFail($data['service_id']);
            $employee = Employee::query()->findOrFail($data['employee_id']);
            $this->lockRow('employees', $employee->id);
            if ($service->required_resource_id) {
                $this->lockRow('resources', $service->required_resource_id);
            }

            // Eloquent's date cast does not normalize timezone on save -- it formats the Carbon
            // using whatever offset it currently carries, so a client-sent "+05:00" instant would
            // otherwise get written to the (UTC-assumed) DB column as the wrong wall-clock value.
            $startsAt = Carbon::parse($data['starts_at'])->utc();
            $endsAt = $startsAt->copy()->addMinutes($service->duration_minutes);

            $this->assertNoConflict($employee->id, $service->required_resource_id, $startsAt, $endsAt, $service->buffer_after_minutes, null);

            $price = (float) (DB::table('employee_service')
                ->where('employee_id', $employee->id)
                ->where('service_id', $service->id)
                ->value('custom_price') ?? $service->price);

            $prepayment = $service->prepaymentAmountFor($price);
            $status = $prepayment > 0 ? Booking::STATUS_AWAITING_PAYMENT : Booking::STATUS_CONFIRMED;

            $booking = Booking::create([
                'tenant_id' => $actor->tenant_id,
                'company_id' => $data['company_id'],
                'customer_id' => $data['customer_id'],
                'service_id' => $service->id,
                'employee_id' => $employee->id,
                'resource_id' => $service->required_resource_id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $status,
                'price' => $price,
                'prepayment_amount' => $prepayment,
                'prepayment_status' => $prepayment > 0 ? 'pending' : 'none',
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor->id,
            ]);

            $this->logStatus($booking, null, $status, $actor, 'Запись создана');

            return $booking;
        });
    }

    public function reschedule(Booking $booking, Carbon $newStart, User $actor, bool $isClientInitiated = false, ?string $comment = null): Booking
    {
        // Same UTC-normalization concern as create() -- defend here too regardless of what the caller passed in.
        $newStart = $newStart->copy()->utc();

        return DB::transaction(function () use ($booking, $newStart, $actor, $isClientInitiated, $comment) {
            $this->lockRow('employees', $booking->employee_id);
            if ($booking->resource_id) {
                $this->lockRow('resources', $booking->resource_id);
            }

            if (! in_array($booking->status, Booking::ACTIVE_STATUSES, true)) {
                throw new BookingConflictException('Эту запись нельзя перенести — она уже завершена или отменена.');
            }

            $policy = $this->policyFor($booking->company_id, $booking->service_id);
            $countsAsUsedAttempt = false;

            if ($isClientInitiated) {
                $hoursUntil = Carbon::now()->diffInHours($booking->starts_at, false);

                if ($hoursUntil < $policy->late_reschedule_hours) {
                    throw new BookingConflictException("Перенос менее чем за {$policy->late_reschedule_hours} ч. до визита возможен только через администратора.");
                }

                if ($hoursUntil < $policy->free_reschedule_hours) {
                    if ($booking->reschedule_count >= $policy->max_client_reschedules) {
                        throw new BookingConflictException('Превышено число самостоятельных переносов этой записи. Обратитесь к администратору.');
                    }
                    $countsAsUsedAttempt = true;
                }
            }

            $service = $booking->service;
            $newEnd = $newStart->copy()->addMinutes($service->duration_minutes);
            $this->assertNoConflict($booking->employee_id, $booking->resource_id, $newStart, $newEnd, $service->buffer_after_minutes, $booking->id);

            $oldStatus = $booking->status;
            $booking->update([
                'starts_at' => $newStart,
                'ends_at' => $newEnd,
                'reschedule_count' => $booking->reschedule_count + ($countsAsUsedAttempt ? 1 : 0),
            ]);

            $this->logStatus($booking, $oldStatus, $booking->status, $actor, $comment ?? 'Запись перенесена');

            return $booking->refresh();
        });
    }

    public function cancel(Booking $booking, User $actor, string $reason, bool $isClientInitiated = false): Booking
    {
        return DB::transaction(function () use ($booking, $actor, $reason, $isClientInitiated) {
            if (! in_array($booking->status, Booking::ACTIVE_STATUSES, true)) {
                throw new BookingConflictException('Эта запись уже завершена или отменена.');
            }

            $oldStatus = $booking->status;
            $prepaymentStatus = $booking->prepayment_status;

            if ($booking->prepayment_amount > 0 && $prepaymentStatus === 'confirmed') {
                $policy = $this->policyFor($booking->company_id, $booking->service_id);
                $hoursUntil = Carbon::now()->diffInHours($booking->starts_at, false);
                $late = $isClientInitiated && $hoursUntil < $policy->late_reschedule_hours;
                $prepaymentStatus = ($late && $policy->no_show_forfeits_prepayment) ? 'kept' : 'refund_pending';
            } elseif ($booking->prepayment_amount > 0 && $prepaymentStatus === 'pending') {
                $prepaymentStatus = 'rejected';
            }

            $booking->update([
                'status' => Booking::STATUS_CANCELLED,
                'cancelled_reason' => $reason,
                'prepayment_status' => $prepaymentStatus,
            ]);

            $this->logStatus($booking, $oldStatus, Booking::STATUS_CANCELLED, $actor, $reason);

            return $booking->refresh();
        });
    }

    public function updateStatus(Booking $booking, string $newStatus, User $actor, ?string $comment = null): Booking
    {
        abort_unless(in_array($newStatus, Booking::STATUSES, true), 422, 'Неизвестный статус записи.');

        return DB::transaction(function () use ($booking, $newStatus, $actor, $comment) {
            $oldStatus = $booking->status;
            $updates = ['status' => $newStatus];

            if ($newStatus === Booking::STATUS_NO_SHOW && $booking->prepayment_status === 'confirmed') {
                $policy = $this->policyFor($booking->company_id, $booking->service_id);
                if ($policy->no_show_forfeits_prepayment) {
                    $updates['prepayment_status'] = 'kept';
                }
            }

            $booking->update($updates);
            $this->logStatus($booking, $oldStatus, $newStatus, $actor, $comment);

            return $booking->refresh();
        });
    }

    public function storePaymentProof(Booking $booking, string $filePath, ?float $amount, ?string $operationNumber, User $actor): BookingPaymentProof
    {
        return DB::transaction(function () use ($booking, $filePath, $amount, $operationNumber, $actor) {
            $proof = BookingPaymentProof::create([
                'booking_id' => $booking->id,
                'file_path' => $filePath,
                'amount' => $amount,
                'operation_number' => $operationNumber,
                'status' => 'pending',
            ]);

            $oldStatus = $booking->status;
            $booking->update(['status' => Booking::STATUS_PAYMENT_REVIEW, 'prepayment_status' => 'review']);
            $this->logStatus($booking, $oldStatus, Booking::STATUS_PAYMENT_REVIEW, $actor, 'Клиент прислал подтверждение оплаты');

            return $proof;
        });
    }

    public function reviewPaymentProof(BookingPaymentProof $proof, string $decision, User $actor, ?string $comment = null): Booking
    {
        abort_unless(in_array($decision, ['confirmed', 'rejected'], true), 422);

        return DB::transaction(function () use ($proof, $decision, $actor, $comment) {
            if ($proof->status !== 'pending') {
                throw new BookingConflictException('Этот скриншот уже проверен.');
            }

            if ($decision === 'confirmed' && $proof->operation_number) {
                $reused = BookingPaymentProof::query()
                    ->where('operation_number', $proof->operation_number)
                    ->where('status', 'confirmed')
                    ->where('id', '!=', $proof->id)
                    ->exists();

                if ($reused) {
                    throw new BookingConflictException('Этот номер операции уже был использован для другой оплаты.');
                }
            }

            $proof->update([
                'status' => $decision,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => Carbon::now(),
                'comment' => $comment,
            ]);

            $booking = $proof->booking;
            $oldStatus = $booking->status;

            if ($decision === 'confirmed') {
                $booking->update(['status' => Booking::STATUS_CONFIRMED, 'prepayment_status' => 'confirmed']);
            } else {
                $booking->update(['status' => Booking::STATUS_AWAITING_PAYMENT, 'prepayment_status' => 'pending']);
            }

            $this->logStatus($booking, $oldStatus, $booking->status, $actor, $decision === 'confirmed' ? 'Оплата подтверждена' : ('Оплата отклонена'.($comment ? ': '.$comment : '')));

            return $booking->refresh();
        });
    }

    public function policyFor(int $companyId, ?int $serviceId): CancellationPolicy
    {
        if ($serviceId) {
            $override = CancellationPolicy::query()->where('company_id', $companyId)->where('service_id', $serviceId)->first();
            if ($override) {
                return $override;
            }
        }

        return CancellationPolicy::query()->where('company_id', $companyId)->whereNull('service_id')->first()
            ?? new CancellationPolicy(CancellationPolicy::defaultFor($companyId));
    }

    private function assertNoConflict(int $employeeId, ?int $resourceId, Carbon $start, Carbon $end, int $bufferAfterMinutes, ?int $excludeBookingId): void
    {
        $blockEnd = $end->copy()->addMinutes($bufferAfterMinutes);
        $windowStart = $start->copy()->subDay();
        $windowEnd = $blockEnd->copy()->addDay();

        $query = Booking::query()
            ->with('service:id,buffer_after_minutes')
            ->where(function ($q) use ($employeeId, $resourceId) {
                $q->where('employee_id', $employeeId);
                if ($resourceId) {
                    $q->orWhere('resource_id', $resourceId);
                }
            })
            ->where(function ($q) {
                $q->whereIn('status', array_values(array_diff(Booking::ACTIVE_STATUSES, [Booking::STATUS_TEMP_HOLD])))
                    ->orWhere(fn ($q2) => $q2->where('status', Booking::STATUS_TEMP_HOLD)->where('hold_expires_at', '>', Carbon::now()));
            })
            ->where('starts_at', '<', $windowEnd)
            ->where('ends_at', '>', $windowStart);

        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }

        foreach ($query->get() as $existing) {
            $sameEmployee = $existing->employee_id === $employeeId;
            $sameResource = $resourceId && $existing->resource_id === $resourceId;

            if (! $sameEmployee && ! $sameResource) {
                continue;
            }

            $existingEnd = $existing->ends_at->copy()->addMinutes($existing->service?->buffer_after_minutes ?? 0);

            if ($existing->starts_at->lt($blockEnd) && $existingEnd->gt($start)) {
                throw new BookingConflictException('Это время уже занято.');
            }
        }
    }

    private function logStatus(Booking $booking, ?string $old, string $new, User $actor, ?string $comment): void
    {
        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'old_status' => $old,
            'new_status' => $new,
            'changed_by_user_id' => $actor->id,
            'comment' => $comment,
        ]);
    }

    /** Serializes concurrent booking writes for the same employee/resource by locking their row for the duration of the transaction. */
    private function lockRow(string $table, int $id): void
    {
        DB::table($table)->where('id', $id)->lockForUpdate()->first();
    }
}
