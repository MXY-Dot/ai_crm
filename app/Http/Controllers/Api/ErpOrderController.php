<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ТЗ раздел 9 -- lets an external system (1C) pull new/updated orders for
 * import into accounting/fulfillment, then mark each as received so it
 * isn't re-pulled next time. Authenticated by AuthenticateErpApiKey, same
 * tenant-scoping reasoning as ErpProductController.
 */
class ErpOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'since' => ['nullable', 'date'],
            'unsynced_only' => ['nullable', 'boolean'],
        ]);

        $orders = Order::query()
            ->with(['customer:id,name,phone,email', 'items:id,order_id,product_id,product_name,quantity,unit_price,line_total'])
            ->when($data['since'] ?? null, fn ($q, $since) => $q->where('updated_at', '>=', Carbon::parse($since)))
            ->when($data['unsynced_only'] ?? null, fn ($q) => $q->whereNull('external_synced_at'))
            ->latest()
            ->paginate(200);

        return response()->json($orders);
    }

    public function updateSyncStatus(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['external_reference' => ['nullable', 'string', 'max:120']]);

        $order->update([
            'external_synced_at' => now(),
            'external_reference' => $data['external_reference'] ?? $order->external_reference,
        ]);

        return response()->json($order);
    }
}
