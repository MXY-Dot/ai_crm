<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Check, X } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import DataTable from '../DataTable.vue';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';

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
        <DataTable
            embedded
            :loading="loading"
            :row-count="returns.length"
            :column-count="2"
            :empty-message="locale.t('commerce.noReturns')"
            min-width="min-w-full"
        >
            <template #thead>
                <th class="p-3">{{ locale.t('commerce.tabReturns') }}</th>
                <th class="p-3 text-right">{{ locale.t('common.actions') }}</th>
            </template>

            <tr v-for="row in returns" :key="row.id">
                <td class="p-3">
                    <p class="text-sm font-medium ui-text">{{ row.customer?.name ?? '—' }} · {{ locale.t('commerce.orderNumber') }} #{{ row.order_id }}</p>
                    <p class="text-xs ui-subtle">{{ row.reason }}</p>
                </td>
                <td class="p-3 text-right">
                    <div class="flex items-center justify-end gap-2">
                        <Button size="sm" variant="outline" :disabled="busyId === row.id" @click="decide(row, 'approved')">
                            <Check class="h-4 w-4" />{{ locale.t('commerce.approve') }}
                        </Button>
                        <Button size="sm" variant="ghost" :disabled="busyId === row.id" @click="decide(row, 'rejected')">
                            <X class="h-4 w-4 text-destructive" />{{ locale.t('commerce.reject') }}
                        </Button>
                    </div>
                </td>
            </tr>
        </DataTable>
    </Card>
</template>
