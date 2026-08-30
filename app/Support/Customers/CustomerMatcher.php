<?php

namespace App\Support\Customers;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Tenant;
use App\Support\Vip\VipScoreCalculator;
use Illuminate\Support\Facades\DB;

/**
 * ЭТАП 12.1 — Customer Identity Resolution. The phone -> email -> name matching
 * here is exactly what ChatwootWebhookHandler::customer() already did inline
 * for Telegram/Chatwoot; extracted so the website widget can share the same
 * logic instead of always inserting a fresh row (see WidgetController::phone()).
 */
class CustomerMatcher
{
    public function __construct(private readonly VipScoreCalculator $vip)
    {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function findOrCreate(Tenant $tenant, Company $company, ?string $name, ?string $phone, ?string $email, string $source, array $meta = [], ?string $avatarUrl = null): Customer
    {
        $query = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('company_id', $company->id);

        $customer = null;

        if ($phone) {
            $customer = (clone $query)->where('phone', $phone)->first();
        }

        if (! $customer && $email) {
            $customer = (clone $query)->where('email', $email)->first();
        }

        if (! $customer && $name) {
            $customer = (clone $query)->where('name', $name)->first();
        }

        $data = array_filter([
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'source' => $source,
            'meta' => $meta ?: null,
            'avatar_url' => $avatarUrl,
        ], fn ($value) => $value !== null);

        if ($customer) {
            $customer->update($data);

            return $customer;
        }

        return Customer::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id, 'name' => $name ?? 'Unknown customer'] + $data);
    }

    public function findByPhone(Tenant $tenant, Company $company, string $phone, ?int $excludeCustomerId = null): ?Customer
    {
        $query = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('company_id', $company->id)
            ->where('phone', $phone);

        if ($excludeCustomerId !== null) {
            $query->where('id', '!=', $excludeCustomerId);
        }

        return $query->first();
    }

    /**
     * Reassigns every Lead/Conversation from $loser onto $winner, then deletes
     * $loser. Must reassign before deleting — both FKs are nullOnDelete(), so
     * deleting first would silently orphan the loser's conversation history
     * instead of moving it. Recalculates $winner's VIP score afterward since
     * its lead set just changed.
     */
    public function mergeInto(Customer $winner, Customer $loser): void
    {
        if ($winner->id === $loser->id) {
            return;
        }

        DB::transaction(function () use ($winner, $loser): void {
            Lead::withoutGlobalScopes()->where('customer_id', $loser->id)->update(['customer_id' => $winner->id]);
            Conversation::withoutGlobalScopes()->where('customer_id', $loser->id)->update(['customer_id' => $winner->id]);
            $loser->delete();
        });

        $this->vip->recalculate($winner->fresh());
    }
}
