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
import TourDepartureDetailDialog from './TourDepartureDetailDialog.vue';
import TourDepartureFormDialog from './TourDepartureFormDialog.vue';

const props = defineProps<{ companyId: number; tenantSlug: string }>();
const locale = useLocaleStore();

type DepartureRow = {
    id: number; departure_date: string; return_date: string | null; status: string; capacity: number | null; booked_seats: number | null;
    tour: { id: number; name: string } | null;
};

const departures = ref<DepartureRow[]>([]);
const loading = ref(true);
const newOpen = ref(false);
const detailOpen = ref(false);
const selectedId = ref<number | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const data = await apiRequest<{ data: DepartureRow[] }>('/api/tour-departures', { tenant: props.tenantSlug });
        departures.value = data.data;
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
    open: 'green', closed: 'amber', departed: 'blue', completed: 'neutral', cancelled: 'red',
};
</script>

<template>
    <Card :title="locale.t('travel.tabDepartures')">
        <template #actions>
            <Button size="sm" @click="newOpen = true"><Plus class="h-4 w-4" />{{ locale.t('travel.addDeparture') }}</Button>
        </template>

        <DataTable
            embedded
            :loading="loading"
            :row-count="departures.length"
            :column-count="3"
            :empty-message="locale.t('travel.noDepartures')"
            min-width="min-w-full"
        >
            <template #thead>
                <th class="p-3">{{ locale.t('travel.tabDepartures') }}</th>
                <th class="p-3 text-right">{{ locale.t('common.date') }}</th>
                <th class="p-3">{{ locale.t('common.status') }}</th>
            </template>

            <tr v-for="d in departures" :key="d.id" class="cursor-pointer transition hover:bg-muted" @click="openDetail(d.id)">
                <td class="p-3">
                    <p class="text-sm font-medium ui-text">{{ d.tour?.name }}</p>
                    <p v-if="d.capacity" class="text-xs ui-subtle">{{ d.booked_seats ?? 0 }}/{{ d.capacity }} {{ locale.t('travel.seatsUnit') }}</p>
                </td>
                <td class="p-3 text-right">
                    <span class="text-xs font-medium tabular-nums ui-text">{{ formatDate(d.departure_date) }}<span v-if="d.return_date"> — {{ formatDate(d.return_date) }}</span></span>
                </td>
                <td class="p-3"><Badge :tone="STATUS_TONE[d.status] ?? 'neutral'">{{ locale.t('travel.statuses.' + d.status) }}</Badge></td>
            </tr>
        </DataTable>

        <TourDepartureFormDialog v-model:open="newOpen" :departure="null" :company-id="companyId" :tenant-slug="tenantSlug" @saved="load" />
        <TourDepartureDetailDialog v-model:open="detailOpen" :departure-id="selectedId" :company-id="companyId" :tenant-slug="tenantSlug" @changed="load" />
    </Card>
</template>
