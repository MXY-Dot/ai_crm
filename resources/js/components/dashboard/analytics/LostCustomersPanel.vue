<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { TrendingDown } from '@lucide/vue';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { Skeleton } from '../../ui/skeleton';

export type LostCustomerRow = {
    id: number;
    title: string | null;
    customer_name: string | null;
    customer_phone: string | null;
    amount: number | null;
    lost_reason: string | null;
    updated_at: string | null;
};

export type LostCustomers = {
    total: number;
    by_reason: { reason: string; total: number; percent: number }[];
    recent: LostCustomerRow[];
};

defineProps<{ data: LostCustomers | null; loading: boolean }>();
const locale = useLocaleStore();

// Same limitation DissatisfiedCustomersPanel's openChat() already accepts --
// no lead-detail deep link exists in the router, so this opens the list, not
// the specific record.
function openLead(): void {
    router.visit('/leads');
}
</script>

<template>
    <Card :title="locale.t('analytics.lost.title')" :subtitle="locale.t('analytics.lost.subtitle')">
        <template #actions>
            <TrendingDown class="h-4 w-4 text-destructive" />
        </template>

        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 3" :key="i" class="h-16 rounded-lg" />
        </div>
        <template v-else-if="data && data.total > 0">
            <p class="pb-3 text-2xl font-semibold ui-text">{{ data.total }}</p>

            <div v-if="data.by_reason.length" class="mb-4 grid gap-1.5">
                <div v-for="row in data.by_reason" :key="row.reason" class="flex items-center justify-between text-xs">
                    <span class="ui-subtle">{{ row.reason }}</span>
                    <span class="ui-text">{{ row.total }} · {{ row.percent }}%</span>
                </div>
            </div>

            <div class="divide-y divide-border">
                <button
                    v-for="row in data.recent.slice(0, 8)"
                    :key="row.id"
                    type="button"
                    class="grid w-full gap-1 py-2.5 text-left text-sm hover:bg-accent/40"
                    @click="openLead()"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="font-medium ui-text">{{ row.customer_name || row.title || '—' }} <span class="font-normal ui-subtle">· {{ row.customer_phone || '—' }}</span></p>
                        <span class="text-xs ui-subtle">{{ row.updated_at ? new Date(row.updated_at).toLocaleDateString() : '' }}</span>
                    </div>
                    <p class="text-xs ui-subtle">{{ row.lost_reason || locale.t('analytics.lost.noReason') }}<span v-if="row.amount"> · {{ row.amount }}</span></p>
                </button>
            </div>
        </template>
        <p v-else class="pb-4 text-sm ui-subtle">{{ locale.t('analytics.lost.empty') }}</p>
    </Card>
</template>
