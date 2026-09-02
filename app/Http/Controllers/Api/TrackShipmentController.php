<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;

/**
 * Public, unauthenticated -- a customer looking up their own package, same
 * trust model as any real courier's tracking page: the tracking number
 * itself is the credential (see Shipment's own docblock for why it's
 * globally unique, not per-tenant), so no X-Tenant-Id/auth is possible or
 * needed here, same reasoning as WidgetController's site-key trust model.
 * Deliberately returns only what a customer should see -- no internal
 * notes, no pricing, no staff attribution on events.
 */
class TrackShipmentController extends Controller
{
    public function __invoke(string $trackingNumber): JsonResponse
    {
        $shipment = Shipment::withoutGlobalScopes()
            ->where('tracking_number', $trackingNumber)
            ->with(['trackingEvents' => fn ($q) => $q->oldest('id')])
            ->first();

        if (! $shipment) {
            return response()->json(['message' => 'Отправление с таким трек-номером не найдено.'], 404);
        }

        return response()->json([
            'tracking_number' => $shipment->tracking_number,
            'status' => $shipment->status,
            'service_type' => $shipment->service_type,
            'origin_address' => $shipment->origin_address,
            'destination_address' => $shipment->destination_address,
            'estimated_delivery_at' => $shipment->estimated_delivery_at,
            'delivered_at' => $shipment->delivered_at,
            'events' => $shipment->trackingEvents->map(fn ($e) => [
                'status' => $e->status,
                'location' => $e->location,
                'description' => $e->description,
                'created_at' => $e->created_at,
            ]),
        ]);
    }
}
