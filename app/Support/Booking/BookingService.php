<?php

namespace App\Support\Booking;

use App\Models\Booking;
use App\Models\BookingGatewayPayment;
use App\Models\BookingPaymentProof;
use App\Models\BookingStatusHistory;
use App\Models\CancellationPolicy;
use App\Models\Employee;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Payments\PaymentGatewayClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ТЗ раздел 10-19 (модуль салона) — создание/перенос/отмену брони и
 * подтверждение оплаты по скриншоту сводим сюда, а не в контроллеры, чтобы
 * проверка конфликтов и запись истории статусов не разъезжались по местам.
 */
class BookingService
{
    public function __construct(private readonly BookingReminderSender $reminders)
    {
    }

    /** $actor is null only for AiChatBookingAssistant's AI-initiated bookings (no logged-in User exists there) -- $data must then carry an explicit 'tenant_id', since it can no longer be read off $actor. */
    public function create(array $data, ?User $actor): Booking
    {
        $booking = DB::transaction(function () use ($data, $actor) {
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

            // ТЗ раздел 13 — временная бронь: unpaid slot is only held for a short,
            // owner-configurable window (default 15 min, see CancellationPolicy::hold_minutes)
            // before ExpireBookingHoldsCommand releases it automatically. A booking with no
            // prepayment needed skips the hold entirely and confirms straight away.
            $tenantId = $actor?->tenant_id ?? $data['tenant_id'];
            $holdMinutes = $this->policyFor($data['company_id'], $service->id)->hold_minutes ?: 15;
            $status = $prepayment > 0 ? Booking::STATUS_TEMP_HOLD : Booking::STATUS_CONFIRMED;

            $booking = Booking::create([
                'tenant_id' => $tenantId,
                'company_id' => $data['company_id'],
                'branch_id' => $employee->branch_id,
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
                'hold_expires_at' => $prepayment > 0 ? Carbon::now()->addMinutes($holdMinutes) : null,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor?->id,
            ]);

            $this->logStatus($booking, null, $status, $actor, $actor ? 'Запись создана' : 'Запись создана AI-ассистентом в чате');

            return $booking;
        });

        $this->notifyBestEffort($booking, fn (BookingReminderSender $r, Tenant $t, Booking $b) => $r->sendCreated($t, $b));

        return $booking;
    }

    public function reschedule(Booking $booking, Carbon $newStart, ?User $actor, bool $isClientInitiated = false, ?string $comment = null): Booking
    {
        // Same UTC-normalization concern as create() -- defend here too regardless of what the caller passed in.
        $newStart = $newStart->copy()->utc();
        $oldWhen = $this->formatLocal($booking);

        $booking = DB::transaction(function () use ($booking, $newStart, $actor, $isClientInitiated, $comment) {
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

        $this->notifyBestEffort($booking, fn (BookingReminderSender $r, Tenant $t, Booking $b) => $r->sendRescheduled($t, $b, $oldWhen));

        return $booking;
    }

    public function cancel(Booking $booking, ?User $actor, string $reason, bool $isClientInitiated = false): Booking
    {
        $booking = DB::transaction(function () use ($booking, $actor, $reason, $isClientInitiated) {
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

        $this->notifyBestEffort($booking, fn (BookingReminderSender $r, Tenant $t, Booking $b) => $r->sendCancelled($t, $b));

        return $booking;
    }

    public function updateStatus(Booking $booking, string $newStatus, User $actor, ?string $comment = null): Booking
    {
        abort_unless(in_array($newStatus, Booking::STATUSES, true), 422, 'Неизвестный статус записи.');

        $booking = DB::transaction(function () use ($booking, $newStatus, $actor, $comment) {
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

        if ($newStatus === Booking::STATUS_COMPLETED) {
            $this->notifyBestEffort($booking, fn (BookingReminderSender $r, Tenant $t, Booking $b) => $r->sendCompleted($t, $b));
        }

        return $booking;
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

    // ТЗ раздел 16 -- 'resubmission_requested' is the "запросить другой скриншот" action:
    // distinct from a flat rejection so staff/UI can tell "customer needs to try again"
    // apart from "this payment claim was denied."
    public const PROOF_DECISIONS = ['confirmed', 'rejected', 'resubmission_requested'];

    public function reviewPaymentProof(BookingPaymentProof $proof, string $decision, User $actor, ?string $comment = null): Booking
    {
        abort_unless(in_array($decision, self::PROOF_DECISIONS, true), 422);

        $booking = DB::transaction(function () use ($proof, $decision, $actor, $comment) {
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
                // Both 'rejected' and 'resubmission_requested' send the booking back to the
                // same place -- customer needs to pay/submit again -- they only differ in the
                // proof row's own status and the comment shown to staff.
                $booking->update(['status' => Booking::STATUS_AWAITING_PAYMENT, 'prepayment_status' => 'pending']);
            }

            $historyComment = match ($decision) {
                'confirmed' => 'Оплата подтверждена',
                'resubmission_requested' => 'Запрошен другой скриншот оплаты'.($comment ? ': '.$comment : ''),
                default => 'Оплата отклонена'.($comment ? ': '.$comment : ''),
            };
            $this->logStatus($booking, $oldStatus, $booking->status, $actor, $historyComment);

            return $booking->refresh();
        });

        if ($decision === 'confirmed') {
            $this->notifyBestEffort($booking, fn (BookingReminderSender $r, Tenant $t, Booking $b) => $r->sendPaymentConfirmed($t, $b));
        }

        return $booking;
    }

    /**
     * ТЗ раздел 16 -- "отметить оплату наличными": staff bypasses the screenshot flow
     * entirely because the customer paid in person. Allowed from any pre-confirmed state
     * with a real prepayment on the booking, including mid-review (staff can always
     * override what the customer submitted with what they actually received).
     */
    public function markPaidCash(Booking $booking, User $actor, ?string $comment = null): Booking
    {
        if ($booking->prepayment_amount <= 0) {
            throw new BookingConflictException('У этой записи нет предоплаты, отмечать нечего.');
        }

        if (! in_array($booking->status, [Booking::STATUS_TEMP_HOLD, Booking::STATUS_AWAITING_PAYMENT, Booking::STATUS_PAYMENT_REVIEW], true)) {
            throw new BookingConflictException('Оплату для этой записи отмечать уже поздно — проверьте текущий статус.');
        }

        $booking = DB::transaction(function () use ($booking, $actor, $comment) {
            $oldStatus = $booking->status;
            $booking->update(['status' => Booking::STATUS_CONFIRMED, 'prepayment_status' => 'confirmed']);
            $this->logStatus($booking, $oldStatus, Booking::STATUS_CONFIRMED, $actor, 'Оплата наличными подтверждена'.($comment ? ': '.$comment : ''));

            return $booking->refresh();
        });

        $this->notifyBestEffort($booking, fn (BookingReminderSender $r, Tenant $t, Booking $b) => $r->sendPaymentConfirmed($t, $b));

        return $booking;
    }

    /**
     * ТЗ раздел 19 -- refund lifecycle, kept as its own explicit action rather than
     * folded into cancel(): a refund can be requested for a booking that's already
     * cancelled (the customer only asks for their money back after finding out the visit
     * won't happen), so this must not require any particular booking status beyond "this
     * prepayment was actually paid."
     */
    public function requestRefund(Booking $booking, User $actor, ?string $reason = null): Booking
    {
        if ($booking->prepayment_status === 'refund_pending' || $booking->prepayment_status === 'refund_processing') {
            return $booking;
        }

        if ($booking->prepayment_status !== 'confirmed') {
            throw new BookingConflictException('Возврат можно оформить только для подтверждённой оплаты.');
        }

        return DB::transaction(function () use ($booking, $actor, $reason) {
            $booking->update(['prepayment_status' => 'refund_pending']);
            $this->logStatus($booking, $booking->status, $booking->status, $actor, 'Запрошен возврат предоплаты'.($reason ? ': '.$reason : ''));

            return $booking->refresh();
        });
    }

    public const REFUND_STATUSES = ['refund_processing', 'refunded', 'refund_rejected'];

    public function updateRefundStatus(Booking $booking, string $status, User $actor, ?string $comment = null): Booking
    {
        abort_unless(in_array($status, self::REFUND_STATUSES, true), 422);

        if (! in_array($booking->prepayment_status, ['refund_pending', 'refund_processing'], true)) {
            throw new BookingConflictException('Для этой записи не был запрошен возврат.');
        }

        return DB::transaction(function () use ($booking, $status, $actor, $comment) {
            $booking->update(['prepayment_status' => $status]);
            $label = match ($status) {
                'refund_processing' => 'Возврат выполняется',
                'refunded' => 'Возврат выполнен',
                default => 'Возврат отклонён',
            };
            $this->logStatus($booking, $booking->status, $booking->status, $actor, $label.($comment ? ': '.$comment : ''));

            return $booking->refresh();
        });
    }

    /**
     * Deliberately NOT one DB::transaction() around the whole method — the
     * external HTTP call to the gateway sits in the middle of it, and an
     * open transaction should never span a slow external request. That
     * matters more than style here: wrapping the try/catch-and-mark-'failed'
     * below inside a transaction that then re-throws would have Laravel roll
     * the whole thing back, silently deleting the very 'failed' row the catch
     * block just wrote — caught live while verifying this against a QA
     * tenant with no real Alif token configured (the exact case this catch
     * exists for).
     *
     * The local BookingGatewayPayment row is created FIRST (before calling
     * the gateway) specifically so its own id can be embedded in the
     * webhook_url handed to the gateway -- that id is how
     * PaymentGatewayWebhookController later finds its way back to the right
     * booking with no tenant/booking identifiers needed in the callback itself.
     */
    public function initiateGatewayPayment(Booking $booking, string $gateway, PaymentGatewayClient $client, ?User $actor): BookingGatewayPayment
    {
        if (! in_array($booking->status, [Booking::STATUS_AWAITING_PAYMENT, Booking::STATUS_TEMP_HOLD], true)) {
            throw new BookingConflictException('Для этой записи оплата через шлюз недоступна в текущем статусе.');
        }

        $amount = $booking->prepayment_amount > 0 ? $booking->prepayment_amount : $booking->price;

        $payment = BookingGatewayPayment::create([
            'booking_id' => $booking->id,
            'gateway' => $gateway,
            'amount' => $amount,
            'status' => 'pending',
        ]);

        $webhookUrl = url('/api/payments/'.$gateway.'/webhook/'.$payment->id);
        $description = 'Запись: '.($booking->service?->name ?? 'услуга');

        try {
            $invoice = $client->createInvoice($booking->tenant, $description, $amount, $webhookUrl, null, $booking->customer?->phone);
        } catch (Throwable $error) {
            $payment->update(['status' => 'failed']);

            throw $error;
        }

        return DB::transaction(function () use ($payment, $invoice, $booking, $gateway, $actor): BookingGatewayPayment {
            $payment->update([
                'external_id' => $invoice['external_id'],
                'checkout_url' => $invoice['checkout_url'],
                'raw_response' => $invoice['raw'],
            ]);

            $oldStatus = $booking->status;
            $booking->update(['status' => Booking::STATUS_AWAITING_PAYMENT]);
            $this->logStatus($booking, $oldStatus, Booking::STATUS_AWAITING_PAYMENT, $actor, 'Создан счёт на оплату через '.$gateway);

            return $payment->fresh();
        });
    }

    /** Idempotent: a gateway retrying the same webhook after we already processed it (status no longer 'pending') is a silent no-op, not an error. */
    public function confirmGatewayPayment(BookingGatewayPayment $payment, array $parsedWebhook): Booking
    {
        $succeeded = false;

        $booking = DB::transaction(function () use ($payment, $parsedWebhook, &$succeeded) {
            $payment = BookingGatewayPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== 'pending') {
                return $payment->booking;
            }

            $payment->update([
                'status' => $parsedWebhook['status'],
                'webhook_payload' => $parsedWebhook,
                'paid_at' => $parsedWebhook['status'] === 'succeeded' ? Carbon::now() : null,
            ]);

            $booking = $payment->booking;
            $oldStatus = $booking->status;

            if ($parsedWebhook['status'] === 'succeeded') {
                $booking->update(['status' => Booking::STATUS_CONFIRMED, 'prepayment_status' => 'confirmed']);
                $this->logStatus($booking, $oldStatus, Booking::STATUS_CONFIRMED, null, 'Оплата подтверждена через '.$payment->gateway);
                $succeeded = true;
            } else {
                $this->logStatus($booking, $oldStatus, $oldStatus, null, 'Оплата через '.$payment->gateway.' не прошла');
            }

            return $booking->refresh();
        });

        if ($succeeded) {
            $this->notifyBestEffort($booking, fn (BookingReminderSender $r, Tenant $t, Booking $b) => $r->sendPaymentConfirmed($t, $b));
        }

        return $booking;
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

    /** $actor is null for gateway-webhook-triggered transitions -- changed_by_user_id is nullable for exactly this. */
    private function logStatus(Booking $booking, ?string $old, string $new, ?User $actor, ?string $comment): void
    {
        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'old_status' => $old,
            'new_status' => $new,
            'changed_by_user_id' => $actor?->id,
            'comment' => $comment,
        ]);
    }

    /** Serializes concurrent booking writes for the same employee/resource by locking their row for the duration of the transaction. */
    private function lockRow(string $table, int $id): void
    {
        DB::table($table)->where('id', $id)->lockForUpdate()->first();
    }

    /** Same "d.m в H:i" shape BookingReminderSender/AiChatBookingAssistant already use for customer-facing times. */
    private function formatLocal(Booking $booking): string
    {
        $company = $booking->company ?? \App\Models\Company::withoutGlobalScopes()->find($booking->company_id);
        $timezone = $company?->timezone ?: config('app.timezone');

        return $booking->starts_at->copy()->setTimezone($timezone)->translatedFormat('d.m в H:i');
    }

    /**
     * Every reminder/notification send (see BookingReminderSender) is best-effort by
     * design: a customer with no messageable channel on file, or a transient send
     * failure, must never turn a successful booking write into a failed API response.
     */
    private function notifyBestEffort(Booking $booking, callable $send): void
    {
        try {
            $tenant = Tenant::query()->find($booking->tenant_id);

            if ($tenant) {
                $send($this->reminders, $tenant, $booking);
            }
        } catch (Throwable $error) {
            Log::warning('BookingService: notification failed', ['booking_id' => $booking->id, 'error' => $error->getMessage()]);
        }
    }
}
