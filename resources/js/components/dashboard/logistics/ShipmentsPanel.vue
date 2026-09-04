<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Plus } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import DataTable from '../DataTable.vue';
import { Badge } from '../../ui/badge';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { Input } from '../../ui/input';
import NewShipmentDialog from './NewShipmentDialog.vue';
import ShipmentDetailDialog from './ShipmentDetailDialog.vue';

const props = defineProps<{ companyId: number; tenantSlug: string }>();
const locale = useLocaleStore();

type ShipmentRow = {
    id: number; tracking_number: string; status: string; service_type: string;
    sender_name: string; recipient_name: string; created_at: string;
};

const shipments = ref<ShipmentRow[]>([]);
const loading = ref(true);
const search = ref('');
const newOpen = ref(false);
const detailOpen = ref(false);
const selectedId = ref<number | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const params = new URLSearchParams();
        if (search.value.trim()) params.set('search', search.value.trim());
        const data = await apiRequest<{ data: ShipmentRow[] }>('/api/shipments?' + params, { tenant: props.tenantSlug });
        shipments.value = data.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 300);
});

function openDetail(id: number): void {
    selectedId.value = id;
    detailOpen.value = true;
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

const STATUS_TONE: Record<string, 'neutral' | 'green' | 'amber' | 'red' | 'blue'> = {
    received: 'amber', in_transit: 'blue', out_for_delivery: 'blue', delivered: 'green', returned: 'red', cancelled: 'red',
};
</script>

<template>
    <Card :title="locale.t('logistics.tabShipments')">
        <template #actions>
            <Button size="sm" @click="newOpen = true"><Plus class="h-4 w-4" />{{ locale.t('logistics.newShipment') }}</Button>
        </template>

        <DataTable
            embedded
            :loading="loading"
            :row-count="shipments.length"
            :column-count="2"
            :empty-message="locale.t('logistics.noShipments')"
            min-width="min-w-full"
        >
            <template #toolbar>
                <Input v-model="search" :placeholder="locale.t('logistics.searchPlaceholder')" class="w-64" />
            </template>

            <template #thead>
                <th class="p-3">{{ locale.t('logistics.tabShipments') }}</th>
                <th class="p-3">{{ locale.t('common.status') }}</th>
            </template>

            <tr v-for="s in shipments" :key="s.id" class="cursor-pointer transition hover:bg-muted" @click="openDetail(s.id)">
                <td class="p-3">
                    <p class="text-sm font-medium tabular-nums ui-text">{{ s.tracking_number }}</p>
                    <p class="text-xs ui-subtle">{{ s.sender_name }} → {{ s.recipient_name }} · {{ formatDate(s.created_at) }}</p>
                </td>
                <td class="p-3"><Badge :tone="STATUS_TONE[s.status] ?? 'neutral'">{{ locale.t('logistics.statuses.' + s.status) }}</Badge></td>
            </tr>
        </DataTable>

        <NewShipmentDialog v-model:open="newOpen" :company-id="companyId" :tenant-slug="tenantSlug" @created="load" />
        <ShipmentDetailDialog v-model:open="detailOpen" :shipment-id="selectedId" :tenant-slug="tenantSlug" @changed="load" />
    </Card>
</template>
