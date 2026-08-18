<?php

namespace App\Support\Campaigns;

use App\Models\Campaign;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * ЭТАП 18.2 — Audience Selection, deliberately the simple version: a
 * structured filter over fields that already exist (Customer.segment,
 * already computed daily by VipScoreCalculator; purchases_count;
 * last_purchase_at), not a natural-language-to-query AI layer. All
 * conditions given are AND'd together; an unset condition is just skipped.
 */
class CampaignAudience
{
    public function query(Campaign $campaign): Builder
    {
        $query = Customer::query()->where('company_id', $campaign->company_id);

        if ($campaign->segment) {
            $query->where('segment', $campaign->segment);
        }

        if ($campaign->min_purchases !== null) {
            $query->where('purchases_count', '>=', $campaign->min_purchases);
        }

        if ($campaign->inactive_days !== null) {
            $cutoff = now()->subDays($campaign->inactive_days);
            $query->where(fn (Builder $q) => $q->whereNull('last_purchase_at')->orWhere('last_purchase_at', '<', $cutoff));
        }

        return $query;
    }
}
