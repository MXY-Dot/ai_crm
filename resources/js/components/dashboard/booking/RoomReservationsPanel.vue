<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { Plus } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import DataTable from '../DataTable.vue';
import { Badge } from '../../ui/badge';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { Money } from '../../ui/money';
import NewRoomReservationDialog from './NewRoomReservationDialog.vue';
import RoomReservationDetailDialog from './RoomReservationDetailDialog.vue';

const props = defineProps<{ companyId: number; tenantSlug: string }>();
const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { customers } = storeToRefs(store);

type ReservationRow = {
    id: number;
    status: string;
    guests_count: number;
    starts_at: string;
    ends_at: string;
    total_amount: number;
    prepayment_amount: number;
    prepayment_status: string;
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
        dateTo.setDate(dateTo.getDate() + 90);
        const params = new URLSearchParams({ date_from: dateFrom.toISOString(), date_to: dateTo.toISOString() });
        const data = await apiRequest<ReservationRow[]>('/api/room-reservations?' + params, { tenant: props.tenantSlug });
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

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

const STATUS_TONE: Record<string, 'neutral' | 'green' | 'amber' | 'red' | 'blue'> = {
    temp_hold: 'amber', awaiting_payment: 'amber', payment_review: 'blue', confirmed: 'blue',
    checked_in: 'green', checked_out: 'neutral', cancelled: 'red', no_show: 'red',
};
</script>

<template>
    <Card :title="locale.t('hotel.tabReservations')">
        <template #actions>
            <Button size="sm" @click="newOpen = true"><Plus class="h-4 w-4" />{{ locale.t('hotel.newReservation') }}</Button>
        </template>

        <DataTable
            embedded
            :loading="loading"
            :row-count="reservations.length"
            :column-count="4"
            :empty-message="locale.t('hotel.noReservations')"
            min-width="min-w-full"
        >
            <template #thead>
                <th class="p-3">{{ locale.t('hotel.tabReservations') }}</th>
                <th class="p-3 text-right">{{ locale.t('common.price') }}</th>
                <th class="p-3 text-right">{{ locale.t('common.date') }}</th>
                <th class="p-3">{{ locale.t('common.status') }}</th>
            </template>

            <tr v-for="r in reservations" :key="r.id" class="cursor-pointer transition hover:bg-muted" @click="openDetail(r.id)">
                <td class="p-3">
                    <p class="text-sm font-medium ui-text">{{ r.customer?.name ?? '—' }} · {{ r.resource?.name }}</p>
                    <p class="text-xs ui-subtle">{{ locale.t('hotel.guests') }}: {{ r.guests_count }}</p>
                </td>
                <td class="p-3 text-right"><Money :value="r.total_amount" tone="muted" /></td>
                <td class="p-3 text-right"><span class="text-xs font-medium tabular-nums ui-text">{{ formatDate(r.starts_at) }} — {{ formatDate(r.ends_at) }}</span></td>
                <td class="p-3"><Badge :tone="STATUS_TONE[r.status] ?? 'neutral'">{{ locale.t('hotel.statuses.' + r.status) }}</Badge></td>
            </tr>
        </DataTable>

        <NewRoomReservationDialog
            v-model:open="newOpen"
            :company-id="companyId"
            :tenant-slug="tenantSlug"
            :customers="customers as unknown as Array<{ id: number; name: string; phone: string | null }>"
            @created="load"
        />
        <RoomReservationDetailDialog v-model:open="detailOpen" :reservation-id="selectedId" :tenant-slug="tenantSlug" @changed="load" />
    </Card>
</template>
