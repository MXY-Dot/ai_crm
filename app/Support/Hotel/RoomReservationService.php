<?php

namespace App\Support\Hotel;

use App\Models\CancellationPolicy;
use App\Models\Company;
use App\Models\Resource;
use App\Models\RoomReservation;
use App\Models\RoomReservationGatewayPayment;
use App\Models\RoomReservationPaymentProof;
use App\Models\RoomReservationStatusHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Payments\PaymentGatewayClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ТЗ раздел 9 (Гостиница/хостел) — create/reschedule/cancel/status write
 * path plus the full payment lifecycle for room reservations, mirroring
 * App\Support\Booking\BookingService's discipline (DB::transaction() per
 * write, row-locked against concurrent double-booking, every status change
 * logged) closely -- a room reservation has real money attached directly to
 * it, same as a Booking, unlike TableReservation whose money flows through
 * an Order instead. Deliberately simpler than BookingService in one place:
 * no CancellationPolicy-driven client-self-service reschedule-attempt
 * limiting (there's no AI-chat/customer self-service surface for hotel
 * reservations this round, staff manage everything through the CRM), the
 * policy is reused only for its shared hold_minutes/no_show_forfeits_prepayment
 * fields (company-wide, service_id always null here).
 */
class RoomReservationService
{
    public function __construct(private readonly RoomReservationReminderSender $reminders)
    {
    }

    /**
     * @param array{tenant_id?:int, company_id:int, branch_id?:?int, customer_id:int, resource_id:int, guests_count:int, starts_at:string, ends_at:string, notes?:?string} $data
     */
    public function create(array $data, ?User $actor): RoomReservation
    {
        $reservation = DB::transaction(function () use ($data, $actor) {
            $room = Resource::query()->findOrFail($data['resource_id']);
            $this->lockRow('resources', $room->id);

            if ($room->type !== 'room') {
                throw new RoomReservationConflictException('Этот ресурс не является номером.');
            }

            if ($room->capacity !== null && $data['guests_count'] > $room->capacity) {
                throw new RoomReservationConflictException("Этот номер рассчитан максимум на {$room->capacity} гостей.");
            }

            // Same UTC-normalization concern as BookingService::create() -- Eloquent's
            // date cast writes whatever offset the Carbon instance currently carries.
            $checkIn = Carbon::parse($data['starts_at'])->utc();
            $checkOut = Carbon::parse($data['ends_at'])->utc();

            if ($checkOut->lte($checkIn)) {
                throw new RoomReservationConflictException('Дата выезда должна быть позже даты заезда.');
            }

            $this->assertNoConflict($room->id, $checkIn, $checkOut, null);

            $pricePerNight = (float) ($room->price_per_night ?? 0);
            $nights = max(1, $checkIn->copy()->startOfDay()->diffInDays($checkOut->copy()->startOfDay()));
            $totalAmount = round($pricePerNight * $nights, 2);

            $company = Company::withoutGlobalScopes()->find($data['company_id']);
            $prepaymentPercent = (float) (data_get($company?->brand_settings, 'hotel.prepayment_percent') ?: 0);
            $prepayment = $prepaymentPercent > 0 ? round($totalAmount * $prepaymentPercent / 100, 2) : 0.0;

            $tenantId = $actor?->tenant_id ?? $data['tenant_id'] ?? null;
            $holdMinutes = $this->policyFor($data['company_id'])->hold_minutes ?: 15;
            $status = $prepayment > 0 ? RoomReservation::STATUS_TEMP_HOLD : RoomReservation::STATUS_CONFIRMED;

            $reservation = RoomReservation::create([
                'tenant_id' => $tenantId,
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'] ?? $room->branch_id,
                'customer_id' => $data['customer_id'],
                'resource_id' => $room->id,
                'guests_count' => $data['guests_count'],
                'starts_at' => $checkIn,
                'ends_at' => $checkOut,
                'status' => $status,
                'price_per_night' => $pricePerNight,
                'total_amount' => $totalAmount,
                'prepayment_amount' => $prepayment,
                'prepayment_status' => $prepayment > 0 ? 'pending' : 'none',
                'hold_expires_at' => $prepayment > 0 ? Carbon::now()->addMinutes($holdMinutes) : null,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor?->id,
            ]);

            $this->logStatus($reservation, null, $status, $actor, $actor ? 'Бронь создана' : 'Бронь создана AI-ассистентом в чате');

            return $reservation;
        });

        $this->notifyBestEffort($reservation, fn (RoomReservationReminderSender $r, Tenant $t, RoomReservation $res) => $r->sendCreated($t, $res));

        return $reservation;
    }

    public function reschedule(RoomReservation $reservation, Carbon $newCheckIn, Carbon $newCheckOut, ?User $actor, ?string $comment = null): RoomReservation
    {
        $newCheckIn = $newCheckIn->copy()->utc();
        $newCheckOut = $newCheckOut->copy()->utc();
        $oldWhen = $this->formatLocal($reservation);

        $reservation = DB::transaction(function () use ($reservation, $newCheckIn, $newCheckOut, $actor, $comment) {
            $this->lockRow('resources', $reservation->resource_id);

            if (! in_array($reservation->status, RoomReservation::ACTIVE_STATUSES, true)) {
                throw new RoomReservationConflictException('Эту бронь нельзя перенести — она уже завершена или отменена.');
            }

            if ($newCheckOut->lte($newCheckIn)) {
                throw new RoomReservationConflictException('Дата выезда должна быть позже даты заезда.');
            }

            $this->assertNoConflict($reservation->resource_id, $newCheckIn, $newCheckOut, $reservation->id);

            $nights = max(1, $newCheckIn->copy()->startOfDay()->diffInDays($newCheckOut->copy()->startOfDay()));
            $oldStatus = $reservation->status;

            $reservation->update([
                'starts_at' => $newCheckIn,
                'ends_at' => $newCheckOut,
                'total_amount' => round($reservation->price_per_night * $nights, 2),
                'reschedule_count' => $reservation->reschedule_count + 1,
            ]);

            $this->logStatus($reservation, $oldStatus, $reservation->status, $actor, $comment ?? 'Бронь перенесена');

            return $reservation->refresh();
        });

        $this->notifyBestEffort($reservation, fn (RoomReservationReminderSender $r, Tenant $t, RoomReservation $res) => $r->sendRescheduled($t, $res, $oldWhen));

        return $reservation;
    }

    public function cancel(RoomReservation $reservation, ?User $actor, string $reason): RoomReservation
    {
        $reservation = DB::transaction(function () use ($reservation, $actor, $reason) {
            if (! in_array($reservation->status, RoomReservation::ACTIVE_STATUSES, true)) {
                throw new RoomReservationConflictException('Эта бронь уже завершена или отменена.');
            }

            $oldStatus = $reservation->status;
            $prepaymentStatus = $reservation->prepayment_status;

            if ($reservation->prepayment_amount > 0 && $prepaymentStatus === 'confirmed') {
                $policy = $this->policyFor($reservation->company_id);
                $hoursUntil = Carbon::now()->diffInHours($reservation->starts_at, false);
                $late = $hoursUntil < $policy->late_reschedule_hours;
                $prepaymentStatus = ($late && $policy->no_show_forfeits_prepayment) ? 'kept' : 'refund_pending';
            } elseif ($reservation->prepayment_amount > 0 && $prepaymentStatus === 'pending') {
                $prepaymentStatus = 'rejected';
            }

            $reservation->update([
                'status' => RoomReservation::STATUS_CANCELLED,
                'cancelled_reason' => $reason,
                'prepayment_status' => $prepaymentStatus,
            ]);

            $this->logStatus($reservation, $oldStatus, RoomReservation::STATUS_CANCELLED, $actor, $reason);

            return $reservation->refresh();
        });

        $this->notifyBestEffort($reservation, fn (RoomReservationReminderSender $r, Tenant $t, RoomReservation $res) => $r->sendCancelled($t, $res));

        return $reservation;
    }

    public function updateStatus(RoomReservation $reservation, string $newStatus, User $actor, ?string $comment = null): RoomReservation
    {
        abort_unless(in_array($newStatus, RoomReservation::STATUSES, true), 422, 'Неизвестный статус брони.');

        $reservation = DB::transaction(function () use ($reservation, $newStatus, $actor, $comment) {
            $oldStatus = $reservation->status;
            $updates = ['status' => $newStatus];

            if ($newStatus === RoomReservation::STATUS_NO_SHOW && $reservation->prepayment_status === 'confirmed') {
                $policy = $this->policyFor($reservation->company_id);
                if ($policy->no_show_forfeits_prepayment) {
                    $updates['prepayment_status'] = 'kept';
                }
            }

            $reservation->update($updates);
            $this->logStatus($reservation, $oldStatus, $newStatus, $actor, $comment);

            return $reservation->refresh();
        });

        if ($newStatus === RoomReservation::STATUS_CHECKED_OUT) {
            $this->notifyBestEffort($reservation, fn (RoomReservationReminderSender $r, Tenant $t, RoomReservation $res) => $r->sendCheckedOut($t, $res));
        }

        return $reservation;
    }

    // $actor nullable for the same reason as BookingService::storePaymentProof() --
    // a customer-initiated proof with no staff user behind it.
    public function storePaymentProof(RoomReservation $reservation, string $filePath, ?float $amount, ?string $operationNumber, ?User $actor): RoomReservationPaymentProof
    {
        return DB::transaction(function () use ($reservation, $filePath, $amount, $operationNumber, $actor) {
            $proof = RoomReservationPaymentProof::create([
                'room_reservation_id' => $reservation->id,
                'file_path' => $filePath,
                'amount' => $amount,
                'operation_number' => $operationNumber,
                'status' => 'pending',
            ]);

            $oldStatus = $reservation->status;
            $reservation->update(['status' => RoomReservation::STATUS_PAYMENT_REVIEW, 'prepayment_status' => 'review']);
            $this->logStatus($reservation, $oldStatus, RoomReservation::STATUS_PAYMENT_REVIEW, $actor, 'Клиент прислал подтверждение оплаты');

            return $proof;
        });
    }

    public const PROOF_DECISIONS = ['confirmed', 'rejected', 'resubmission_requested'];

    public function reviewPaymentProof(RoomReservationPaymentProof $proof, string $decision, User $actor, ?string $comment = null): RoomReservation
    {
        abort_unless(in_array($decision, self::PROOF_DECISIONS, true), 422);

        $reservation = DB::transaction(function () use ($proof, $decision, $actor, $comment) {
            if ($proof->status !== 'pending') {
                throw new RoomReservationConflictException('Этот скриншот уже проверен.');
            }

            if ($decision === 'confirmed' && $proof->operation_number) {
                $reused = RoomReservationPaymentProof::query()
                    ->where('operation_number', $proof->operation_number)
                    ->where('status', 'confirmed')
                    ->where('id', '!=', $proof->id)
                    ->exists();

                if ($reused) {
                    throw new RoomReservationConflictException('Этот номер операции уже был использован для другой оплаты.');
                }
            }

            $proof->update([
                'status' => $decision,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => Carbon::now(),
                'comment' => $comment,
            ]);

            $reservation = $proof->roomReservation;
            $oldStatus = $reservation->status;

            if ($decision === 'confirmed') {
                $reservation->update(['status' => RoomReservation::STATUS_CONFIRMED, 'prepayment_status' => 'confirmed']);
            } else {
                $reservation->update(['status' => RoomReservation::STATUS_AWAITING_PAYMENT, 'prepayment_status' => 'pending']);
            }

            $historyComment = match ($decision) {
                'confirmed' => 'Оплата подтверждена',
                'resubmission_requested' => 'Запрошен другой скриншот оплаты'.($comment ? ': '.$comment : ''),
                default => 'Оплата отклонена'.($comment ? ': '.$comment : ''),
            };
            $this->logStatus($reservation, $oldStatus, $reservation->status, $actor, $historyComment);

            return $reservation->refresh();
        });

        if ($decision === 'confirmed') {
            $this->notifyBestEffort($reservation, fn (RoomReservationReminderSender $r, Tenant $t, RoomReservation $res) => $r->sendPaymentConfirmed($t, $res));
        }

        return $reservation;
    }

    public function markPaidCash(RoomReservation $reservation, User $actor, ?string $comment = null): RoomReservation
    {
        if ($reservation->prepayment_amount <= 0) {
            throw new RoomReservationConflictException('У этой брони нет предоплаты, отмечать нечего.');
        }

        if (! in_array($reservation->status, [RoomReservation::STATUS_TEMP_HOLD, RoomReservation::STATUS_AWAITING_PAYMENT, RoomReservation::STATUS_PAYMENT_REVIEW], true)) {
            throw new RoomReservationConflictException('Оплату для этой брони отмечать уже поздно — проверьте текущий статус.');
        }

        $reservation = DB::transaction(function () use ($reservation, $actor, $comment) {
            $oldStatus = $reservation->status;
            $reservation->update(['status' => RoomReservation::STATUS_CONFIRMED, 'prepayment_status' => 'confirmed']);
            $this->logStatus($reservation, $oldStatus, RoomReservation::STATUS_CONFIRMED, $actor, 'Оплата наличными подтверждена'.($comment ? ': '.$comment : ''));

            return $reservation->refresh();
        });

        $this->notifyBestEffort($reservation, fn (RoomReservationReminderSender $r, Tenant $t, RoomReservation $res) => $r->sendPaymentConfirmed($t, $res));

        return $reservation;
    }

    public function requestRefund(RoomReservation $reservation, User $actor, ?string $reason = null): RoomReservation
    {
        if ($reservation->prepayment_status === 'refund_pending' || $reservation->prepayment_status === 'refund_processing') {
            return $reservation;
        }

        if ($reservation->prepayment_status !== 'confirmed') {
            throw new RoomReservationConflictException('Возврат можно оформить только для подтверждённой оплаты.');
        }

        return DB::transaction(function () use ($reservation, $actor, $reason) {
            $reservation->update(['prepayment_status' => 'refund_pending']);
            $this->logStatus($reservation, $reservation->status, $reservation->status, $actor, 'Запрошен возврат предоплаты'.($reason ? ': '.$reason : ''));

            return $reservation->refresh();
        });
    }

    public const REFUND_STATUSES = ['refund_processing', 'refunded', 'refund_rejected'];

    public function updateRefundStatus(RoomReservation $reservation, string $status, User $actor, ?string $comment = null): RoomReservation
    {
        abort_unless(in_array($status, self::REFUND_STATUSES, true), 422);

        if (! in_array($reservation->prepayment_status, ['refund_pending', 'refund_processing'], true)) {
            throw new RoomReservationConflictException('Для этой брони не был запрошен возврат.');
        }

        return DB::transaction(function () use ($reservation, $status, $actor, $comment) {
            $reservation->update(['prepayment_status' => $status]);
            $label = match ($status) {
                'refund_processing' => 'Возврат выполняется',
                'refunded' => 'Возврат выполнен',
                default => 'Возврат отклонён',
            };
            $this->logStatus($reservation, $reservation->status, $reservation->status, $actor, $label.($comment ? ': '.$comment : ''));

            return $reservation->refresh();
        });
    }

    /**
     * Deliberately not wrapped in one DB::transaction() -- same reasoning as
     * BookingService::initiateGatewayPayment(): the external gateway HTTP call
     * must never sit inside an open transaction.
     */
    public function initiateGatewayPayment(RoomReservation $reservation, string $gateway, PaymentGatewayClient $client, ?User $actor): RoomReservationGatewayPayment
    {
        if (! in_array($reservation->status, [RoomReservation::STATUS_AWAITING_PAYMENT, RoomReservation::STATUS_TEMP_HOLD], true)) {
            throw new RoomReservationConflictException('Для этой брони оплата через шлюз недоступна в текущем статусе.');
        }

        $amount = $reservation->prepayment_amount > 0 ? $reservation->prepayment_amount : $reservation->total_amount;

        $payment = RoomReservationGatewayPayment::create([
            'room_reservation_id' => $reservation->id,
            'gateway' => $gateway,
            'amount' => $amount,
            'status' => 'pending',
        ]);

        $webhookUrl = url('/api/payments/'.$gateway.'/webhook/room_reservation/'.$payment->id);
        $description = 'Бронь номера: '.($reservation->resource?->name ?? 'номер');

        try {
            $invoice = $client->createInvoice($reservation->tenant, $description, $amount, $webhookUrl, null, $reservation->customer?->phone);
        } catch (Throwable $error) {
            $payment->update(['status' => 'failed']);

            throw $error;
        }

        return DB::transaction(function () use ($payment, $invoice, $reservation, $gateway, $actor): RoomReservationGatewayPayment {
            $payment->update([
                'external_id' => $invoice['external_id'],
                'checkout_url' => $invoice['checkout_url'],
                'raw_response' => $invoice['raw'],
            ]);

            $oldStatus = $reservation->status;
            $reservation->update(['status' => RoomReservation::STATUS_AWAITING_PAYMENT]);
            $this->logStatus($reservation, $oldStatus, RoomReservation::STATUS_AWAITING_PAYMENT, $actor, 'Создан счёт на оплату через '.$gateway);

            return $payment->fresh();
        });
    }

    /** Idempotent, same as BookingService::confirmGatewayPayment(). */
    public function confirmGatewayPayment(RoomReservationGatewayPayment $payment, array $parsedWebhook): RoomReservation
    {
        $succeeded = false;

        $reservation = DB::transaction(function () use ($payment, $parsedWebhook, &$succeeded) {
            $payment = RoomReservationGatewayPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== 'pending') {
                return $payment->roomReservation;
            }

            $payment->update([
                'status' => $parsedWebhook['status'],
                'webhook_payload' => $parsedWebhook,
                'paid_at' => $parsedWebhook['status'] === 'succeeded' ? Carbon::now() : null,
            ]);

            $reservation = $payment->roomReservation;
            $oldStatus = $reservation->status;

            if ($parsedWebhook['status'] === 'succeeded') {
                $reservation->update(['status' => RoomReservation::STATUS_CONFIRMED, 'prepayment_status' => 'confirmed']);
                $this->logStatus($reservation, $oldStatus, RoomReservation::STATUS_CONFIRMED, null, 'Оплата подтверждена через '.$payment->gateway);
                $succeeded = true;
            } else {
                $this->logStatus($reservation, $oldStatus, $oldStatus, null, 'Оплата через '.$payment->gateway.' не прошла');
            }

            return $reservation->refresh();
        });

        if ($succeeded) {
            $this->notifyBestEffort($reservation, fn (RoomReservationReminderSender $r, Tenant $t, RoomReservation $res) => $r->sendPaymentConfirmed($t, $res));
        }

        return $reservation;
    }

    private function policyFor(int $companyId): CancellationPolicy
    {
        return CancellationPolicy::query()->where('company_id', $companyId)->whereNull('service_id')->first()
            ?? new CancellationPolicy(CancellationPolicy::defaultFor($companyId));
    }

    private function assertNoConflict(int $resourceId, Carbon $start, Carbon $end, ?int $excludeReservationId): void
    {
        $query = RoomReservation::query()
            ->where('resource_id', $resourceId)
            ->whereIn('status', RoomReservation::ACTIVE_STATUSES)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);

        if ($excludeReservationId) {
            $query->where('id', '!=', $excludeReservationId);
        }

        if ($query->exists()) {
            throw new RoomReservationConflictException('Этот номер уже забронирован на выбранные даты.');
        }
    }

    private function logStatus(RoomReservation $reservation, ?string $old, string $new, ?User $actor, ?string $comment): void
    {
        RoomReservationStatusHistory::create([
            'room_reservation_id' => $reservation->id,
            'old_status' => $old,
            'new_status' => $new,
            'changed_by_user_id' => $actor?->id,
            'comment' => $comment,
        ]);
    }

    /** Serializes concurrent writes for the same room by locking its row for the duration of the transaction. */
    private function lockRow(string $table, int $id): void
    {
        DB::table($table)->where('id', $id)->lockForUpdate()->first();
    }

    private function formatLocal(RoomReservation $reservation): string
    {
        $company = $reservation->company ?? Company::withoutGlobalScopes()->find($reservation->company_id);
        $timezone = $company?->timezone ?: config('app.timezone');

        return $reservation->starts_at->copy()->setTimezone($timezone)->translatedFormat('d.m.Y').' — '.$reservation->ends_at->copy()->setTimezone($timezone)->translatedFormat('d.m.Y');
    }

    /** Best-effort by design -- a customer with no messageable channel on file must never turn a successful reservation write into a failed API response. */
    private function notifyBestEffort(RoomReservation $reservation, callable $send): void
    {
        try {
            $tenant = Tenant::query()->find($reservation->tenant_id);

            if ($tenant) {
                $send($this->reminders, $tenant, $reservation);
            }
        } catch (Throwable $error) {
            Log::warning('RoomReservationService: notification failed', ['reservation_id' => $reservation->id, 'error' => $error->getMessage()]);
        }
    }
}
