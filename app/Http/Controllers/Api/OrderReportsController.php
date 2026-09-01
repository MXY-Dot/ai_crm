<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Каталог + заказы отчёты, тот же формат агрегации что и
 * App\Http\Controllers\Api\BookingReportsController.
 */
class OrderReportsController extends Controller
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, [User::ROLE_SUPER_ADMIN, User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_ACCOUNTANT], true), 403);

        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
        ]);

        // Reads the ResolveTenant-middleware-resolved tenant, not $user->tenant_id
        // directly -- see CancellationPolicyController/BookingReportsController for
        // why the raw-user version breaks for super_admin.
        $company = Company::withoutGlobalScopes()->where('tenant_id', $context->id())->firstOrFail();
        $from = Carbon::parse($data['date_from'])->startOfDay();
        $to = Carbon::parse($data['date_to'])->endOfDay();

        $orders = Order::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereBetween('created_at', [$from, $to])
            ->with('items:id,order_id,product_id,product_name,quantity,line_total')
            ->get(['id', 'customer_id', 'status', 'subtotal', 'delivery_fee', 'discount_amount', 'total', 'payment_status', 'created_at']);

        $counts = [
            'total' => $orders->count(),
            'pending' => $orders->where('status', Order::STATUS_PENDING)->count(),
            'completed' => $orders->where('status', Order::STATUS_COMPLETED)->count(),
            'cancelled' => $orders->where('status', Order::STATUS_CANCELLED)->count(),
        ];

        $money = [
            'revenue' => round((float) $orders->where('status', Order::STATUS_COMPLETED)->sum('total'), 2),
            'paid' => round((float) $orders->where('payment_status', 'paid')->sum('total'), 2),
        ];

        $returns = OrderReturn::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'status', 'refund_amount']);

        $returnCounts = [
            'total' => $returns->count(),
            'refunded' => $returns->where('status', OrderReturn::STATUS_REFUNDED)->count(),
            'refunded_amount' => round((float) $returns->where('status', OrderReturn::STATUS_REFUNDED)->sum('refund_amount'), 2),
        ];

        $productNames = Product::withoutGlobalScopes()->where('company_id', $company->id)->pluck('name', 'id');

        $popularProducts = $orders->flatMap->items
            ->filter(fn ($item) => $item->product_id !== null)
            ->groupBy('product_id')
            ->map(fn ($group, $productId) => [
                'product_id' => (int) $productId,
                'name' => $productNames[$productId] ?? $group->first()->product_name,
                'quantity' => (int) $group->sum('quantity'),
                'revenue' => round((float) $group->sum('line_total'), 2),
            ])
            ->sortByDesc('quantity')
            ->values()
            ->take(10);

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'counts' => $counts,
            'money' => $money,
            'returns' => $returnCounts,
            'popular_products' => $popularProducts,
        ]);
    }
}
