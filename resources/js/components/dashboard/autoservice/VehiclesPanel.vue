<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { Car, Pencil, Plus, Trash2 } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { EmptyState } from '../../ui/empty-state';
import { Skeleton } from '../../ui/skeleton';
import VehicleFormDialog, { type VehicleRow } from './VehicleFormDialog.vue';

const props = defineProps<{ companyId: number; tenantSlug: string }>();
const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { customers } = storeToRefs(store);

const vehicles = ref<VehicleRow[]>([]);
const loading = ref(true);
const dialogOpen = ref(false);
const editing = ref<VehicleRow | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const data = await apiRequest<{ data: VehicleRow[] }>('/api/vehicles', { tenant: props.tenantSlug });
        vehicles.value = data.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function openCreate(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function openEdit(vehicle: VehicleRow): void {
    editing.value = vehicle;
    dialogOpen.value = true;
}

async function remove(vehicle: VehicleRow): Promise<void> {
    if (! confirm(vehicle.plate_number + '?')) return;
    try {
        await apiRequest(`/api/vehicles/${vehicle.id}`, { method: 'DELETE', tenant: props.tenantSlug });
        vehicles.value = vehicles.value.filter((v) => v.id !== vehicle.id);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}
</script>

<template>
    <Card :title="locale.t('autoService.tabVehicles')">
        <template #actions>
            <Button size="sm" @click="openCreate"><Plus class="h-4 w-4" />{{ locale.t('autoService.addVehicle') }}</Button>
        </template>

        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 3" :key="i" class="h-12 rounded-lg" />
        </div>
        <EmptyState v-else-if="! vehicles.length" :icon="Car" :title="locale.t('autoService.noVehicles')" />
        <div v-else class="divide-y divide-border pb-2">
            <div v-for="vehicle in vehicles" :key="vehicle.id" class="flex items-center justify-between gap-3 py-3">
                <div>
                    <p class="text-sm font-medium ui-text">{{ vehicle.make }} {{ vehicle.model }}<span v-if="vehicle.year"> · {{ vehicle.year }}</span> · {{ vehicle.plate_number }}</p>
                    <p class="text-xs ui-subtle">{{ vehicle.customer?.name }}<span v-if="vehicle.customer?.phone"> · {{ vehicle.customer.phone }}</span></p>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="ghost" size="icon" @click="openEdit(vehicle)"><Pencil class="h-4 w-4" /></Button>
                    <Button variant="ghost" size="icon" @click="remove(vehicle)"><Trash2 class="h-4 w-4 text-destructive" /></Button>
                </div>
            </div>
        </div>

        <VehicleFormDialog v-model:open="dialogOpen" :vehicle="editing" :company-id="companyId" :tenant-slug="tenantSlug" :customers="customers as unknown as Array<{ id: number; name: string; phone: string | null }>" @saved="load" />
    </Card>
</template>
