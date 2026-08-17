<?php

namespace App\Support\Vip;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Support\Arr;

/**
 * ЭТАП 12.2 — computes an explainable 0-100 VIP score purely from signals that
 * actually exist in this schema (won/lost Leads, customer tenure, conversation
 * count). Explicitly does NOT use reviews or sentiment — neither exists in this
 * codebase — and explicitly does not treat "left one good message" as VIP on its
 * own (the spec's own warning against that), since the score is dominated by
 * real purchase behavior, not chat tone.
 */
class VipScoreCalculator
{
    private const DEFAULT_THRESHOLDS = [
        'min_purchases' => 5,
        'min_revenue' => null,
        'min_score' => 80,
        'revenue_scale' => 10000.0,
    ];

    private const LOST_ACTIVITY_DAYS = 90;

    public function recalculate(Customer $customer): void
    {
        $leads = Lead::withoutGlobalScopes()->where('customer_id', $customer->id)->get();
        $won = $leads->where('status', 'won');
        $lost = $leads->where('status', 'lost');

        $purchasesCount = $won->count();
        $totalRevenue = (float) $won->sum('amount');
        $cancellations = $lost->count();
        $lastPurchaseAt = $won->max('won_at');
        $tenureDays = $customer->created_at?->diffInDays(now()) ?? 0;
        $conversationsCount = Conversation::withoutGlobalScopes()->where('customer_id', $customer->id)->count();
        $lastActivityAt = Conversation::withoutGlobalScopes()->where('customer_id', $customer->id)->max('last_message_at');

        $thresholds = $this->thresholds($customer->tenant);
        $score = $this->score($purchasesCount, $totalRevenue, $cancellations, $tenureDays, $conversationsCount, $thresholds['revenue_scale']);
        $status = $this->statusFor($score, $purchasesCount, $totalRevenue, $thresholds);
        $segment = $this->segmentFor($purchasesCount, $status, $lastActivityAt, $customer->is_business);
        $reason = $this->reason($purchasesCount, $totalRevenue, $cancellations, $status);

        $customer->forceFill([
            'vip_score' => $score,
            'vip_status' => $status,
            'vip_reason' => $reason,
            'segment' => $segment,
            'purchases_count' => $purchasesCount,
            'total_revenue' => $totalRevenue,
            'last_purchase_at' => $lastPurchaseAt,
            'vip_calculated_at' => now(),
        ])->save();
    }

    public function recalculateAll(Tenant $tenant): int
    {
        $count = 0;

        Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->each(function (Customer $customer) use (&$count): void {
            $this->recalculate($customer);
            $count++;
        });

        return $count;
    }

    private function score(int $purchases, float $revenue, int $cancellations, int $tenureDays, int $conversations, float $revenueScale): int
    {
        $purchasesComponent = min($purchases * 12, 50);
        $revenueComponent = $revenueScale > 0 ? min($revenue / $revenueScale * 30, 30) : 0;
        $tenureComponent = min($tenureDays / 180 * 10, 10);
        $activityComponent = min($conversations, 10);
        $penalty = min($cancellations * 5, 20);

        return (int) max(0, min(100, round($purchasesComponent + $revenueComponent + $tenureComponent + $activityComponent - $penalty)));
    }

    /**
     * @return array{min_purchases: int, min_revenue: ?float, min_score: int, revenue_scale: float}
     */
    private function thresholds(?Tenant $tenant): array
    {
        $settings = $tenant?->settings ?? [];

        return [
            'min_purchases' => (int) Arr::get($settings, 'vip.min_purchases', self::DEFAULT_THRESHOLDS['min_purchases']),
            'min_revenue' => Arr::get($settings, 'vip.min_revenue', self::DEFAULT_THRESHOLDS['min_revenue']),
            'min_score' => (int) Arr::get($settings, 'vip.min_score', self::DEFAULT_THRESHOLDS['min_score']),
            'revenue_scale' => (float) Arr::get($settings, 'vip.revenue_scale', self::DEFAULT_THRESHOLDS['revenue_scale']),
        ];
    }

    /**
     * @param array{min_purchases: int, min_revenue: ?float, min_score: int, revenue_scale: float} $thresholds
     */
    private function statusFor(int $score, int $purchases, float $revenue, array $thresholds): string
    {
        if ($score >= 90) {
            return 'top_vip';
        }

        $isVip = $score >= $thresholds['min_score']
            || $purchases >= $thresholds['min_purchases']
            || ($thresholds['min_revenue'] !== null && $revenue >= $thresholds['min_revenue']);

        if ($isVip) {
            return 'vip';
        }

        if ($purchases >= 2 || $score >= 40) {
            return 'loyal';
        }

        return 'regular';
    }

    /** ЭТАП 12.3 — cheap segmentation from the same underlying data, surfaced as one extra field rather than a separate stage/page. */
    private function segmentFor(int $purchases, string $status, mixed $lastActivityAt, bool $isBusiness): string
    {
        // ЭТАП 12.3 — B2B has no automatic signal anywhere in this schema (unlike
        // VIP, which is derived from real purchase data) — it's purely the
        // operator's own manual flag, checked first since it's a hard fact about
        // who the customer is, not a behavior-derived bucket like the others.
        if ($isBusiness) {
            return 'b2b';
        }

        if (in_array($status, ['vip', 'top_vip'], true)) {
            return 'vip';
        }

        if ($lastActivityAt !== null && now()->diffInDays($lastActivityAt) > self::LOST_ACTIVITY_DAYS) {
            return 'lost';
        }

        return $purchases > 0 ? 'returning' : 'new';
    }

    private function reason(int $purchases, float $revenue, int $cancellations, string $status): string
    {
        if ($status === 'regular') {
            return $purchases === 0 ? 'Пока нет завершённых покупок.' : 'Мало покупок для более высокого статуса.';
        }

        $parts = [$purchases.' '.$this->pluralizePurchases($purchases), 'оборот '.number_format($revenue, 0, ',', ' ').' TJS'];

        if ($cancellations > 0) {
            $parts[] = $cancellations.' '.($cancellations === 1 ? 'отмена' : 'отмен(ы)');
        }

        return ucfirst(implode(', ', $parts)).'.';
    }

    private function pluralizePurchases(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'покупка';
        }

        if (in_array($mod10, [2, 3, 4], true) && ! in_array($mod100, [12, 13, 14], true)) {
            return 'покупки';
        }

        return 'покупок';
    }
}
