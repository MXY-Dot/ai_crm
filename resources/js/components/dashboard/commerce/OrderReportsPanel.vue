<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { DatePicker } from '../../ui/date-picker';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { Money } from '../../ui/money';
import { Skeleton } from '../../ui/skeleton';

type Report = {
    period: { from: string; to: string };
    counts: { total: number; pending: number; completed: number; cancelled: number };
    money: { revenue: number; paid: number };
    returns: { total: number; refunded: number; refunded_amount: number };
    popular_products: { product_id: number; name: string; quantity: number; revenue: number }[];
};

const props = defineProps<{ tenantSlug: string }>();
const locale = useLocaleStore();
const loading = ref(true);
const report = ref<Report | null>(null);

function defaultFrom(): string {
    const d = new Date();
    d.setDate(d.getDate() - 30);
    return d.toISOString().slice(0, 10);
}

const dateFrom = ref(defaultFrom());
const dateTo = ref(new Date().toISOString().slice(0, 10));

async function load(): Promise<void> {
    loading.value = true;
    try {
        report.value = await apiRequest<Report>(`/api/order-reports?date_from=${dateFrom.value}&date_to=${dateTo.value}`, { tenant: props.tenantSlug });
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <Card :title="locale.t('commerce.reportsTitle')">
        <div class="flex flex-wrap items-end gap-2 pb-4">
            <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('commerce.reportsFrom') }}
                <DatePicker v-model="dateFrom" class="h-9" />
            </label>
            <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('commerce.reportsTo') }}
                <DatePicker v-model="dateTo" class="h-9" />
            </label>
            <Button size="sm" :disabled="loading" @click="load">{{ locale.t('commerce.reportsRefresh') }}</Button>
        </div>

        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 4" :key="i" class="h-14 rounded-lg" />
        </div>
        <div v-else-if="report" class="space-y-6 pb-4">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-lg border border-border p-3">
                    <p class="text-xs ui-subtle">{{ locale.t('commerce.reportsTotal') }}</p>
                    <p class="text-lg font-semibold ui-text">{{ report.counts.total }}</p>
                </div>
                <div class="rounded-lg border border-border p-3">
                    <p class="text-xs ui-subtle">{{ locale.t('commerce.reportsCompleted') }}</p>
                    <p class="text-lg font-semibold ui-text">{{ report.counts.completed }}</p>
                </div>
                <div class="rounded-lg border border-border p-3">
                    <p class="text-xs ui-subtle">{{ locale.t('commerce.reportsCancelled') }}</p>
                    <p class="text-lg font-semibold ui-text">{{ report.counts.cancelled }}</p>
                </div>
                <div class="rounded-lg border border-border p-3">
                    <p class="text-xs ui-subtle">{{ locale.t('commerce.reportsRevenue') }}</p>
                    <Money :value="report.money.revenue" tone="lg" />
                </div>
                <div class="rounded-lg border border-border p-3">
                    <p class="text-xs ui-subtle">{{ locale.t('commerce.reportsReturns') }}</p>
                    <p class="text-lg font-semibold ui-text">{{ report.returns.total }}</p>
                </div>
                <div class="rounded-lg border border-border p-3">
                    <p class="text-xs ui-subtle">{{ locale.t('commerce.reportsRefundedAmount') }}</p>
                    <Money :value="report.returns.refunded_amount" tone="lg" />
                </div>
            </div>

            <div>
                <p class="mb-2 text-sm font-semibold ui-text">{{ locale.t('commerce.reportsPopularProducts') }}</p>
                <p v-if="! report.popular_products.length" class="text-sm ui-subtle">{{ locale.t('commerce.reportsNoData') }}</p>
                <div v-else class="divide-y divide-border">
                    <div v-for="p in report.popular_products" :key="p.product_id" class="flex items-center justify-between py-2 text-sm">
                        <span class="ui-text">{{ p.name }}</span>
                        <span class="ui-subtle">{{ p.quantity }} · <Money :value="p.revenue" tone="muted" /></span>
                    </div>
                </div>
            </div>
        </div>
    </Card>
</template>
