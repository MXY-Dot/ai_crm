<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Plus } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import DataTable from '../DataTable.vue';
import { Badge } from '../../ui/badge';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import NewRepairOrderDialog from './NewRepairOrderDialog.vue';
import RepairOrderDetailDialog from './RepairOrderDetailDialog.vue';

const props = defineProps<{ companyId: number; tenantSlug: string }>();
const locale = useLocaleStore();

type RepairOrderRow = {
    id: number;
    status: string;
    created_at: string;
    customer: { id: number; name: string; phone: string | null } | null;
    vehicle: { id: number; make: string; model: string; plate_number: string } | null;
    employee: { id: number; name: string } | null;
};

const repairOrders = ref<RepairOrderRow[]>([]);
const loading = ref(true);
const newOpen = ref(false);
const detailOpen = ref(false);
const selectedId = ref<number | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const data = await apiRequest<{ data: RepairOrderRow[] }>('/api/repair-orders', { tenant: props.tenantSlug });
        repairOrders.value = data.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function openDetail(id: number): void {
    selectedId.value = id;
    detailOpen.value = true;
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

const STATUS_TONE: Record<string, 'neutral' | 'green' | 'amber' | 'red' | 'blue'> = {
    received: 'amber', diagnosing: 'blue', awaiting_approval: 'amber', in_progress: 'blue',
    awaiting_parts: 'amber', ready_for_pickup: 'green', completed: 'neutral', cancelled: 'red',
};
</script>

<template>
    <Card :title="locale.t('autoService.tabRepairOrders')">
        <template #actions>
            <Button size="sm" @click="newOpen = true"><Plus class="h-4 w-4" />{{ locale.t('autoService.newRepairOrder') }}</Button>
        </template>

        <DataTable
            embedded
            :loading="loading"
            :row-count="repairOrders.length"
            :column-count="2"
            :empty-message="locale.t('autoService.noRepairOrders')"
            min-width="min-w-full"
        >
            <template #thead>
                <th class="p-3">{{ locale.t('autoService.tabRepairOrders') }}</th>
                <th class="p-3">{{ locale.t('common.status') }}</th>
            </template>

            <tr v-for="r in repairOrders" :key="r.id" class="cursor-pointer transition hover:bg-muted" @click="openDetail(r.id)">
                <td class="p-3">
                    <p class="text-sm font-medium ui-text">{{ r.customer?.name ?? '—' }} · {{ r.vehicle?.make }} {{ r.vehicle?.model }} · {{ r.vehicle?.plate_number }}</p>
                    <p class="text-xs ui-subtle"><span v-if="r.employee">{{ locale.t('autoService.mechanic') }}: {{ r.employee.name }} · </span>{{ formatDate(r.created_at) }}</p>
                </td>
                <td class="p-3"><Badge :tone="STATUS_TONE[r.status] ?? 'neutral'">{{ locale.t('autoService.statuses.' + r.status) }}</Badge></td>
            </tr>
        </DataTable>

        <NewRepairOrderDialog v-model:open="newOpen" :company-id="companyId" :tenant-slug="tenantSlug" @created="load" />
        <RepairOrderDetailDialog v-model:open="detailOpen" :repair-order-id="selectedId" :tenant-slug="tenantSlug" @changed="load" />
    </Card>
</template>
