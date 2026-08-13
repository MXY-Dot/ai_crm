<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

class SuperAdminBillingController extends Controller
{
    private const PLAN_PRICES = ['starter' => 29, 'pro' => 99, 'business' => 249];

    public function index(): JsonResponse
    {
        $tenants = Tenant::query()->get(['id', 'status', 'settings', 'created_at', 'trial_ends_at']);
        $active = $tenants->where('status', 'active');

        $mrr = (int) $active->sum(fn (Tenant $t) => self::PLAN_PRICES[Arr::get($t->settings, 'billing.plan', 'starter')] ?? 0);

        $byPlan = collect(self::PLAN_PRICES)->map(function (int $price, string $planId) use ($active) {
            $subscribers = $active->filter(fn (Tenant $t) => Arr::get($t->settings, 'billing.plan', 'starter') === $planId)->count();

            return [
                'plan' => $planId,
                'price' => $price,
                'subscribers' => $subscribers,
                'revenue' => $subscribers * $price,
            ];
        })->values();

        $trialEndingSoon = $tenants->where('status', 'trial')
            ->filter(fn (Tenant $t) => $t->trial_ends_at && $t->trial_ends_at->isFuture() && $t->trial_ends_at->diffInDays(now()) <= 7)
            ->count();

        return response()->json([
            'kpis' => [
                'mrr' => $mrr,
                'arr' => $mrr * 12,
                'active_subscriptions' => $active->count(),
                'trial_subscriptions' => $tenants->where('status', 'trial')->count(),
                'trial_ending_soon' => $trialEndingSoon,
            ],
            'by_plan' => $byPlan,
        ]);
    }
}
