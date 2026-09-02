<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Luggage, Plus } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Badge } from '../../ui/badge';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { EmptyState } from '../../ui/empty-state';
import { Skeleton } from '../../ui/skeleton';
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

        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 3" :key="i" class="h-14 rounded-lg" />
        </div>
        <EmptyState v-else-if="! departures.length" :icon="Luggage" :title="locale.t('travel.noDepartures')" />
        <div v-else class="divide-y divide-border pb-2">
            <button
                v-for="d in departures" :key="d.id" type="button"
                class="flex w-full flex-wrap items-center justify-between gap-3 py-3 text-left transition-colors hover:bg-accent/40"
                @click="openDetail(d.id)"
            >
                <div class="min-w-0">
                    <p class="text-sm font-medium ui-text">{{ d.tour?.name }}</p>
                    <p class="text-xs ui-subtle">
                        {{ formatDate(d.departure_date) }}<span v-if="d.return_date"> — {{ formatDate(d.return_date) }}</span>
                        <span v-if="d.capacity"> · {{ d.booked_seats ?? 0 }}/{{ d.capacity }} {{ locale.t('travel.seatsUnit') }}</span>
                    </p>
                </div>
                <Badge :tone="STATUS_TONE[d.status] ?? 'neutral'">{{ locale.t('travel.statuses.' + d.status) }}</Badge>
            </button>
        </div>

        <TourDepartureFormDialog v-model:open="newOpen" :departure="null" :company-id="companyId" :tenant-slug="tenantSlug" @saved="load" />
        <TourDepartureDetailDialog v-model:open="detailOpen" :departure-id="selectedId" :company-id="companyId" :tenant-slug="tenantSlug" @changed="load" />
    </Card>
</template>
