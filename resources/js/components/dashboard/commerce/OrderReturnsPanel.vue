<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Check, X } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { Skeleton } from '../../ui/skeleton';

type ReturnRow = {
    id: number;
    order_id: number;
    reason: string;
    status: string;
    refund_amount: number | null;
    order: { id: number; total: number } | null;
    customer: { id: number; name: string; phone: string | null } | null;
};

const props = defineProps<{ tenantSlug: string }>();
const locale = useLocaleStore();

const returns = ref<ReturnRow[]>([]);
const loading = ref(true);
const busyId = ref<number | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const res = await apiRequest<{ data: ReturnRow[] }>('/api/order-returns', { tenant: props.tenantSlug });
        returns.value = res.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

async function decide(row: ReturnRow, decision: 'approved' | 'rejected'): Promise<void> {
    const refundAmount = decision === 'approved' ? Number(prompt(locale.t('commerce.refundAmount'), String(row.order?.total ?? 0)) ?? 0) : null;
    if (decision === 'approved' && (refundAmount === null || Number.isNaN(refundAmount))) return;

    busyId.value = row.id;
    try {
        await apiRequest(`/api/orders/${row.order_id}/return/${row.id}`, {
            method: 'PATCH',
            body: { decision, refund_amount: refundAmount },
            tenant: props.tenantSlug,
        });
        toast.success(locale.t('commerce.saved'));
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busyId.value = null;
    }
}
</script>

<template>
    <Card :title="locale.t('commerce.tabReturns')">
        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 3" :key="i" class="h-16 rounded-lg" />
        </div>
        <p v-else-if="! returns.length" class="pb-4 text-sm ui-subtle">{{ locale.t('commerce.noReturns') }}</p>
        <div v-else class="divide-y divide-border pb-2">
            <div v-for="row in returns" :key="row.id" class="flex flex-wrap items-start justify-between gap-3 py-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium ui-text">{{ row.customer?.name ?? '—' }} · {{ locale.t('commerce.orderNumber') }} #{{ row.order_id }}</p>
                    <p class="text-xs ui-subtle">{{ row.reason }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <Button size="sm" variant="outline" :disabled="busyId === row.id" @click="decide(row, 'approved')">
                        <Check class="h-4 w-4" />{{ locale.t('commerce.approve') }}
                    </Button>
                    <Button size="sm" variant="ghost" :disabled="busyId === row.id" @click="decide(row, 'rejected')">
                        <X class="h-4 w-4 text-destructive" />{{ locale.t('commerce.reject') }}
                    </Button>
                </div>
            </div>
        </div>
    </Card>
</template>
