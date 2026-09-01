<?php

namespace App\Support\Restaurant;

use App\Models\Company;
use App\Models\Resource;
use App\Models\TableReservation;
use App\Models\TableReservationStatusHistory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ТЗ раздел 9 (Ресторан и кафе) — create/reschedule/cancel/status write path
 * for table reservations, mirroring App\Support\Booking\BookingService's
 * discipline (DB::transaction() per write, row-locked against concurrent
 * double-booking, every status change logged) but deliberately without a
 * CancellationPolicy engine or prepayment lifecycle -- neither exists for
 * table reservations this round (see TableReservation's own docblock).
 */
class TableReservationService
{
    public function __construct(private readonly TableReservationReminderSender $reminders)
    {
    }

    /**
     * @param array{tenant_id?:int, company_id:int, branch_id?:?int, customer_id:int, resource_id:int, party_size:int, starts_at:string, duration_minutes?:int, notes?:?string} $data
     */
    public function create(array $data, ?User $actor): TableReservation
    {
        $reservation = DB::transaction(function () use ($data, $actor) {
            $table = Resource::query()->findOrFail($data['resource_id']);
            $this->lockRow('resources', $table->id);

            if ($table->type !== 'table') {
                throw new TableReservationConflictException('Этот ресурс не является столиком.');
            }

            if ($table->capacity !== null && $data['party_size'] > $table->capacity) {
                throw new TableReservationConflictException("Этот столик рассчитан максимум на {$table->capacity} гостей.");
            }

            // Same UTC-normalization concern BookingService::create() defends against --
            // Eloquent's date cast writes whatever offset the Carbon instance currently
            // carries, not a normalized UTC instant.
            $startsAt = Carbon::parse($data['starts_at'])->utc();
            $duration = (int) ($data['duration_minutes'] ?? TableAvailabilityCalculator::DEFAULT_DURATION_MINUTES);
            $endsAt = $startsAt->copy()->addMinutes($duration);

            $this->assertNoConflict($table->id, $startsAt, $endsAt, null);

            $tenantId = $actor?->tenant_id ?? $data['tenant_id'] ?? null;

            $reservation = TableReservation::create([
                'tenant_id' => $tenantId,
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'] ?? $table->branch_id,
                'customer_id' => $data['customer_id'],
                'resource_id' => $table->id,
                'party_size' => $data['party_size'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => TableReservation::STATUS_PENDING,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor?->id,
            ]);

            $this->logStatus($reservation, null, TableReservation::STATUS_PENDING, $actor, $actor ? 'Бронь создана' : 'Бронь создана AI-ассистентом в чате');

            return $reservation;
        });

        $this->notifyBestEffort($reservation, fn (TableReservationReminderSender $r, Tenant $t, TableReservation $res) => $r->sendCreated($t, $res));

        return $reservation;
    }

    public function reschedule(TableReservation $reservation, Carbon $newStart, ?User $actor, ?string $comment = null): TableReservation
    {
        $newStart = $newStart->copy()->utc();
        $oldWhen = $this->formatLocal($reservation);

        $reservation = DB::transaction(function () use ($reservation, $newStart, $actor, $comment) {
            $this->lockRow('resources', $reservation->resource_id);

            if (! in_array($reservation->status, TableReservation::ACTIVE_STATUSES, true)) {
                throw new TableReservationConflictException('Эту бронь нельзя перенести — она уже завершена или отменена.');
            }

            $duration = $reservation->starts_at->diffInMinutes($reservation->ends_at);
            $newEnd = $newStart->copy()->addMinutes($duration);
            $this->assertNoConflict($reservation->resource_id, $newStart, $newEnd, $reservation->id);

            $oldStatus = $reservation->status;
            $reservation->update([
                'starts_at' => $newStart,
                'ends_at' => $newEnd,
                'reschedule_count' => $reservation->reschedule_count + 1,
            ]);

            $this->logStatus($reservation, $oldStatus, $reservation->status, $actor, $comment ?? 'Бронь перенесена');

            return $reservation->refresh();
        });

        $this->notifyBestEffort($reservation, fn (TableReservationReminderSender $r, Tenant $t, TableReservation $res) => $r->sendRescheduled($t, $res, $oldWhen));

        return $reservation;
    }

    public function cancel(TableReservation $reservation, ?User $actor, string $reason): TableReservation
    {
        $reservation = DB::transaction(function () use ($reservation, $actor, $reason) {
            if (! in_array($reservation->status, TableReservation::ACTIVE_STATUSES, true)) {
                throw new TableReservationConflictException('Эта бронь уже завершена или отменена.');
            }

            $oldStatus = $reservation->status;
            $reservation->update(['status' => TableReservation::STATUS_CANCELLED, 'cancelled_reason' => $reason]);
            $this->logStatus($reservation, $oldStatus, TableReservation::STATUS_CANCELLED, $actor, $reason);

            return $reservation->refresh();
        });

        $this->notifyBestEffort($reservation, fn (TableReservationReminderSender $r, Tenant $t, TableReservation $res) => $r->sendCancelled($t, $res));

        return $reservation;
    }

    public function updateStatus(TableReservation $reservation, string $newStatus, User $actor, ?string $comment = null): TableReservation
    {
        abort_unless(in_array($newStatus, TableReservation::STATUSES, true), 422, 'Неизвестный статус брони.');

        $reservation = DB::transaction(function () use ($reservation, $newStatus, $actor, $comment) {
            $oldStatus = $reservation->status;
            $reservation->update(['status' => $newStatus]);
            $this->logStatus($reservation, $oldStatus, $newStatus, $actor, $comment);

            return $reservation->refresh();
        });

        if ($newStatus === TableReservation::STATUS_COMPLETED) {
            $this->notifyBestEffort($reservation, fn (TableReservationReminderSender $r, Tenant $t, TableReservation $res) => $r->sendCompleted($t, $res));
        }

        return $reservation;
    }

    private function assertNoConflict(int $resourceId, Carbon $start, Carbon $end, ?int $excludeReservationId): void
    {
        $windowStart = $start->copy()->subDay();
        $windowEnd = $end->copy()->addDay();

        $query = TableReservation::query()
            ->where('resource_id', $resourceId)
            ->whereIn('status', TableReservation::ACTIVE_STATUSES)
            ->where('starts_at', '<', $windowEnd)
            ->where('ends_at', '>', $windowStart);

        if ($excludeReservationId) {
            $query->where('id', '!=', $excludeReservationId);
        }

        foreach ($query->get() as $existing) {
            if ($existing->starts_at->lt($end) && $existing->ends_at->gt($start)) {
                throw new TableReservationConflictException('Этот столик уже забронирован на это время.');
            }
        }
    }

    private function logStatus(TableReservation $reservation, ?string $old, string $new, ?User $actor, ?string $comment): void
    {
        TableReservationStatusHistory::create([
            'table_reservation_id' => $reservation->id,
            'old_status' => $old,
            'new_status' => $new,
            'changed_by_user_id' => $actor?->id,
            'comment' => $comment,
        ]);
    }

    /** Serializes concurrent writes for the same table by locking its row for the duration of the transaction. */
    private function lockRow(string $table, int $id): void
    {
        DB::table($table)->where('id', $id)->lockForUpdate()->first();
    }

    private function formatLocal(TableReservation $reservation): string
    {
        $company = $reservation->company ?? Company::withoutGlobalScopes()->find($reservation->company_id);
        $timezone = $company?->timezone ?: config('app.timezone');

        return $reservation->starts_at->copy()->setTimezone($timezone)->translatedFormat('d.m в H:i');
    }

    /** Best-effort by design -- a customer with no messageable channel on file must never turn a successful reservation write into a failed API response. */
    private function notifyBestEffort(TableReservation $reservation, callable $send): void
    {
        try {
            $tenant = Tenant::query()->find($reservation->tenant_id);
            if ($tenant) {
                $send($this->reminders, $tenant, $reservation);
            }
        } catch (Throwable $error) {
            Log::warning('TableReservationService: notify failed', ['reservation_id' => $reservation->id, 'error' => $error->getMessage()]);
        }
    }
}
