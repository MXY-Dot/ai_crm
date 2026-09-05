<?php

namespace App\Support\AutoService;

use App\Models\RepairOrder;
use App\Models\RepairOrderStatusHistory;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ТЗ раздел 9 (Автосервис/автомойка) — create/updateStatus/updateDetails/cancel
 * for repair jobs, mirroring this session's established write-service
 * discipline (DB::transaction() per write, row-locked against a real
 * conflict, every status change logged) but deliberately lighter than
 * Booking/TableReservation/RoomReservation: there's no time slot to book
 * and no availability calculator here -- a repair job just occupies a
 * vehicle's "one active job at a time" slot, not a calendar.
 */
class RepairOrderService
{
    /**
     * @param array{tenant_id?:int, company_id:int, branch_id?:?int, customer_id:int, vehicle_id:int, employee_id?:?int, problem_description:string, estimated_total?:?float, promised_at?:?string, notes?:?string} $data
     */
    public function create(array $data, ?User $actor): RepairOrder
    {
        return DB::transaction(function () use ($data, $actor) {
            $vehicle = Vehicle::query()->findOrFail($data['vehicle_id']);
            $this->lockRow('vehicles', $vehicle->id);

            $hasActiveJob = RepairOrder::query()
                ->where('vehicle_id', $vehicle->id)
                ->whereIn('status', RepairOrder::ACTIVE_STATUSES)
                ->exists();

            if ($hasActiveJob) {
                throw new RepairOrderConflictException('У этого автомобиля уже есть незавершённый заказ-наряд.');
            }

            $tenantId = $actor?->tenant_id ?? $data['tenant_id'] ?? null;

            $repairOrder = RepairOrder::create([
                'tenant_id' => $tenantId,
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'channel_id' => $data['channel_id'] ?? null,
                'vehicle_id' => $vehicle->id,
                'employee_id' => $data['employee_id'] ?? null,
                'status' => RepairOrder::STATUS_RECEIVED,
                'problem_description' => $data['problem_description'],
                'estimated_total' => $data['estimated_total'] ?? null,
                'promised_at' => isset($data['promised_at']) ? Carbon::parse($data['promised_at'])->utc() : null,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor?->id,
            ]);

            $this->logStatus($repairOrder, null, RepairOrder::STATUS_RECEIVED, $actor, 'Заказ-наряд создан');

            return $repairOrder;
        });
    }

    public function updateStatus(RepairOrder $repairOrder, string $newStatus, User $actor, ?string $comment = null): RepairOrder
    {
        abort_unless(in_array($newStatus, RepairOrder::STATUSES, true) && $newStatus !== RepairOrder::STATUS_CANCELLED, 422, 'Неизвестный статус заказ-наряда.');

        return DB::transaction(function () use ($repairOrder, $newStatus, $actor, $comment) {
            if (! in_array($repairOrder->status, RepairOrder::ACTIVE_STATUSES, true)) {
                throw new RepairOrderConflictException('Этот заказ-наряд уже завершён или отменён.');
            }

            $oldStatus = $repairOrder->status;
            $updates = ['status' => $newStatus];

            if ($newStatus === RepairOrder::STATUS_COMPLETED) {
                $updates['completed_at'] = Carbon::now();
            }

            $repairOrder->update($updates);
            $this->logStatus($repairOrder, $oldStatus, $newStatus, $actor, $comment);

            return $repairOrder->refresh();
        });
    }

    /** Diagnosis notes / estimate / promised date can all change while the job is in progress -- kept as one general edit rather than three endpoints. Only fields actually present in $data are touched (so e.g. omitting promised_at leaves it as-is, but explicitly sending it as null clears it). */
    public function updateDetails(RepairOrder $repairOrder, array $data, User $actor): RepairOrder
    {
        return DB::transaction(function () use ($repairOrder, $data, $actor) {
            $updates = [];

            if (array_key_exists('diagnosis_notes', $data)) {
                $updates['diagnosis_notes'] = $data['diagnosis_notes'];
            }
            if (array_key_exists('estimated_total', $data)) {
                $updates['estimated_total'] = $data['estimated_total'];
            }
            if (array_key_exists('promised_at', $data)) {
                $updates['promised_at'] = $data['promised_at'] ? Carbon::parse($data['promised_at'])->utc() : null;
            }

            if ($updates === []) {
                return $repairOrder;
            }

            $repairOrder->update($updates);
            $this->logStatus($repairOrder, $repairOrder->status, $repairOrder->status, $actor, 'Обновлены детали заказ-наряда');

            return $repairOrder->refresh();
        });
    }

    // $actor nullable for the same reason as BookingService::storePaymentProof()
    // and every other module's own cancel() -- a customer-initiated cancel from
    // AI-chat has no staff user behind it (see RepairOrderChatAssistant).
    public function cancel(RepairOrder $repairOrder, ?User $actor, string $reason): RepairOrder
    {
        return DB::transaction(function () use ($repairOrder, $actor, $reason) {
            if (! in_array($repairOrder->status, RepairOrder::ACTIVE_STATUSES, true)) {
                throw new RepairOrderConflictException('Этот заказ-наряд уже завершён или отменён.');
            }

            $oldStatus = $repairOrder->status;
            $repairOrder->update(['status' => RepairOrder::STATUS_CANCELLED, 'cancelled_reason' => $reason]);
            $this->logStatus($repairOrder, $oldStatus, RepairOrder::STATUS_CANCELLED, $actor, $reason);

            return $repairOrder->refresh();
        });
    }

    private function logStatus(RepairOrder $repairOrder, ?string $old, string $new, ?User $actor, ?string $comment): void
    {
        RepairOrderStatusHistory::create([
            'repair_order_id' => $repairOrder->id,
            'old_status' => $old,
            'new_status' => $new,
            'changed_by_user_id' => $actor?->id,
            'comment' => $comment,
        ]);
    }

    /** Serializes concurrent writes for the same vehicle by locking its row for the duration of the transaction. */
    private function lockRow(string $table, int $id): void
    {
        DB::table($table)->where('id', $id)->lockForUpdate()->first();
    }
}
