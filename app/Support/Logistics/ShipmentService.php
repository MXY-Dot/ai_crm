<?php

namespace App\Support\Logistics;

use App\Models\Shipment;
use App\Models\ShipmentTrackingEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ТЗ раздел 9 (Логистическая компания) — create/updateStatus for
 * shipments. Deliberately the lightest write-service this session, on par
 * with TourDepartureService: no conflict guard of any kind (a shipment
 * doesn't compete for a shared resource the way every other module's
 * central entity does) and no separate cancel()/reason field -- 'cancelled'
 * and 'returned' are just ordinary target statuses here, logged through the
 * same updateStatus() as every other transition.
 */
class ShipmentService
{
    /**
     * @param array{tenant_id?:int, company_id:int, branch_id?:?int, customer_id?:?int, sender_name:string, sender_phone:string, recipient_name:string, recipient_phone:string, origin_address?:?string, destination_address?:?string, service_type?:string, weight_kg?:?float, price?:?float, estimated_delivery_at?:?string, notes?:?string} $data
     */
    public function create(array $data, ?User $actor): Shipment
    {
        return DB::transaction(function () use ($data, $actor) {
            $tenantId = $actor?->tenant_id ?? $data['tenant_id'] ?? null;

            $shipment = Shipment::create([
                'tenant_id' => $tenantId,
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'tracking_number' => $this->generateTrackingNumber(),
                'sender_name' => $data['sender_name'],
                'sender_phone' => $data['sender_phone'],
                'recipient_name' => $data['recipient_name'],
                'recipient_phone' => $data['recipient_phone'],
                'origin_address' => $data['origin_address'] ?? null,
                'destination_address' => $data['destination_address'] ?? null,
                'service_type' => $data['service_type'] ?? 'standard',
                'weight_kg' => $data['weight_kg'] ?? null,
                'price' => $data['price'] ?? null,
                'status' => Shipment::STATUS_RECEIVED,
                'estimated_delivery_at' => $data['estimated_delivery_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor?->id,
            ]);

            $this->logEvent($shipment, Shipment::STATUS_RECEIVED, $data['origin_address'] ?? null, 'Отправление принято', $actor);

            return $shipment;
        });
    }

    public function updateStatus(Shipment $shipment, string $newStatus, ?User $actor, ?string $location = null, ?string $description = null): Shipment
    {
        abort_unless(in_array($newStatus, Shipment::STATUSES, true), 422, 'Неизвестный статус отправления.');

        return DB::transaction(function () use ($shipment, $newStatus, $actor, $location, $description) {
            if (! in_array($shipment->status, Shipment::ACTIVE_STATUSES, true)) {
                throw new ShipmentException('Это отправление уже завершено или отменено.');
            }

            $updates = ['status' => $newStatus];
            if ($newStatus === Shipment::STATUS_DELIVERED) {
                $updates['delivered_at'] = now();
            }

            $shipment->update($updates);
            $this->logEvent($shipment, $newStatus, $location, $description, $actor);

            return $shipment->refresh();
        });
    }

    /** WERO-XXXXXXXX, checked for real uniqueness (global, not per-tenant -- see Shipment's own docblock). Collisions are astronomically unlikely but the loop makes the guarantee real rather than assumed. */
    private function generateTrackingNumber(): string
    {
        do {
            $candidate = 'WERO-'.strtoupper(Str::random(8));
        } while (Shipment::withoutGlobalScopes()->where('tracking_number', $candidate)->exists());

        return $candidate;
    }

    private function logEvent(Shipment $shipment, string $status, ?string $location, ?string $description, ?User $actor): void
    {
        ShipmentTrackingEvent::create([
            'shipment_id' => $shipment->id,
            'status' => $status,
            'location' => $location,
            'description' => $description,
            'changed_by_user_id' => $actor?->id,
        ]);
    }
}
