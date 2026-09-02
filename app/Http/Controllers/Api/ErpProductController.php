<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ТЗ раздел 9 -- authenticated by AuthenticateErpApiKey (Bearer token), not
 * the dashboard's session/X-Tenant-Id flow. Tenant scoping comes entirely
 * from BelongsToTenant's global scope once the middleware has set
 * TenantContext from the matched key -- no manual tenant_id filtering
 * needed here, same as every session-authenticated controller in this app.
 */
class ErpProductController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Product::query()->latest()->paginate(200));
    }

    /** An external system (1C, a warehouse manager) is the source of truth for real stock counts -- this is a plain overwrite, not a delta/decrement. */
    public function updateStock(Request $request, string $sku): JsonResponse
    {
        $product = Product::query()->where('sku', $sku)->firstOrFail();

        $data = $request->validate(['stock_quantity' => ['required', 'integer', 'min:0']]);
        $product->update(['stock_quantity' => $data['stock_quantity']]);

        return response()->json($product);
    }
}
