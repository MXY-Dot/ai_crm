<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Package, Plus } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Badge } from '../../ui/badge';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { EmptyState } from '../../ui/empty-state';
import { Input } from '../../ui/input';
import { Skeleton } from '../../ui/skeleton';
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

        <Input v-model="search" :placeholder="locale.t('logistics.searchPlaceholder')" class="mb-3" />

        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 3" :key="i" class="h-14 rounded-lg" />
        </div>
        <EmptyState v-else-if="! shipments.length" :icon="Package" :title="locale.t('logistics.noShipments')" />
        <div v-else class="divide-y divide-border pb-2">
            <button
                v-for="s in shipments" :key="s.id" type="button"
                class="flex w-full flex-wrap items-center justify-between gap-3 py-3 text-left transition-colors hover:bg-accent/40"
                @click="openDetail(s.id)"
            >
                <div class="min-w-0">
                    <p class="text-sm font-medium tabular-nums ui-text">{{ s.tracking_number }}</p>
                    <p class="text-xs ui-subtle">{{ s.sender_name }} → {{ s.recipient_name }} · {{ formatDate(s.created_at) }}</p>
                </div>
                <Badge :tone="STATUS_TONE[s.status] ?? 'neutral'">{{ locale.t('logistics.statuses.' + s.status) }}</Badge>
            </button>
        </div>

        <NewShipmentDialog v-model:open="newOpen" :company-id="companyId" :tenant-slug="tenantSlug" @created="load" />
        <ShipmentDetailDialog v-model:open="detailOpen" :shipment-id="selectedId" :tenant-slug="tenantSlug" @changed="load" />
    </Card>
</template>
