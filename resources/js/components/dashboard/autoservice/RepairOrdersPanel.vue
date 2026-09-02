<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { ClipboardList, Plus } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Badge } from '../../ui/badge';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { EmptyState } from '../../ui/empty-state';
import { Skeleton } from '../../ui/skeleton';
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

        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 3" :key="i" class="h-14 rounded-lg" />
        </div>
        <EmptyState v-else-if="! repairOrders.length" :icon="ClipboardList" :title="locale.t('autoService.noRepairOrders')" />
        <div v-else class="divide-y divide-border pb-2">
            <button
                v-for="r in repairOrders" :key="r.id" type="button"
                class="flex w-full flex-wrap items-center justify-between gap-3 py-3 text-left transition-colors hover:bg-accent/40"
                @click="openDetail(r.id)"
            >
                <div class="min-w-0">
                    <p class="text-sm font-medium ui-text">{{ r.customer?.name ?? '—' }} · {{ r.vehicle?.make }} {{ r.vehicle?.model }} · {{ r.vehicle?.plate_number }}</p>
                    <p class="text-xs ui-subtle"><span v-if="r.employee">{{ locale.t('autoService.mechanic') }}: {{ r.employee.name }} · </span>{{ formatDate(r.created_at) }}</p>
                </div>
                <Badge :tone="STATUS_TONE[r.status] ?? 'neutral'">{{ locale.t('autoService.statuses.' + r.status) }}</Badge>
            </button>
        </div>

        <NewRepairOrderDialog v-model:open="newOpen" :company-id="companyId" :tenant-slug="tenantSlug" @created="load" />
        <RepairOrderDetailDialog v-model:open="detailOpen" :repair-order-id="selectedId" :tenant-slug="tenantSlug" @changed="load" />
    </Card>
</template>
