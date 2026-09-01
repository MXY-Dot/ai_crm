<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { CalendarClock, Plus } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { Badge } from '../../ui/badge';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { EmptyState } from '../../ui/empty-state';
import { Skeleton } from '../../ui/skeleton';
import NewTableReservationDialog from './NewTableReservationDialog.vue';
import TableReservationDetailDialog from './TableReservationDetailDialog.vue';

const props = defineProps<{ companyId: number; tenantSlug: string }>();
const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { customers } = storeToRefs(store);

type ReservationRow = {
    id: number;
    status: string;
    party_size: number;
    starts_at: string;
    customer: { id: number; name: string; phone: string | null } | null;
    resource: { id: number; name: string } | null;
};

const reservations = ref<ReservationRow[]>([]);
const loading = ref(true);
const newOpen = ref(false);
const detailOpen = ref(false);
const selectedId = ref<number | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const dateFrom = new Date();
        dateFrom.setDate(dateFrom.getDate() - 1);
        const dateTo = new Date();
        dateTo.setDate(dateTo.getDate() + 30);
        const params = new URLSearchParams({ date_from: dateFrom.toISOString(), date_to: dateTo.toISOString() });
        const data = await apiRequest<ReservationRow[]>('/api/table-reservations?' + params, { tenant: props.tenantSlug });
        reservations.value = data;
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

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

const STATUS_TONE: Record<string, 'neutral' | 'green' | 'amber' | 'red' | 'blue'> = {
    pending: 'amber', confirmed: 'blue', seated: 'blue', completed: 'green', cancelled: 'red', no_show: 'red',
};
</script>

<template>
    <Card :title="locale.t('restaurant.tabReservations')">
        <template #actions>
            <Button size="sm" @click="newOpen = true"><Plus class="h-4 w-4" />{{ locale.t('restaurant.newReservation') }}</Button>
        </template>

        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 3" :key="i" class="h-14 rounded-lg" />
        </div>
        <EmptyState v-else-if="! reservations.length" :icon="CalendarClock" :title="locale.t('restaurant.noReservations')" />
        <div v-else class="divide-y divide-border pb-2">
            <button
                v-for="r in reservations" :key="r.id" type="button"
                class="flex w-full flex-wrap items-center justify-between gap-3 py-3 text-left transition-colors hover:bg-accent/40"
                @click="openDetail(r.id)"
            >
                <div class="min-w-0">
                    <p class="text-sm font-medium ui-text">{{ r.customer?.name ?? '—' }} · {{ r.resource?.name }}</p>
                    <p class="text-xs ui-subtle">{{ locale.t('restaurant.partySize') }}: {{ r.party_size }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <span class="text-xs font-medium tabular-nums ui-text">{{ formatDateTime(r.starts_at) }}</span>
                    <Badge :tone="STATUS_TONE[r.status] ?? 'neutral'">{{ locale.t('restaurant.statuses.' + r.status) }}</Badge>
                </div>
            </button>
        </div>

        <NewTableReservationDialog
            v-model:open="newOpen"
            :company-id="companyId"
            :tenant-slug="tenantSlug"
            :customers="customers as unknown as Array<{ id: number; name: string; phone: string | null }>"
            :initial-date="new Date().toISOString().slice(0, 10)"
            @created="load"
        />
        <TableReservationDetailDialog v-model:open="detailOpen" :reservation-id="selectedId" :tenant-slug="tenantSlug" @changed="load" />
    </Card>
</template>
