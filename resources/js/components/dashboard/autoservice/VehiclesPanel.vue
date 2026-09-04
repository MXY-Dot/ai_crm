<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import DataTable from '../DataTable.vue';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
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

        <DataTable
            embedded
            :loading="loading"
            :row-count="vehicles.length"
            :column-count="2"
            :empty-message="locale.t('autoService.noVehicles')"
            min-width="min-w-full"
        >
            <template #thead>
                <th class="p-3">{{ locale.t('autoService.tabVehicles') }}</th>
                <th class="p-3 text-right">{{ locale.t('common.actions') }}</th>
            </template>

            <tr v-for="vehicle in vehicles" :key="vehicle.id">
                <td class="p-3">
                    <p class="text-sm font-medium ui-text">{{ vehicle.make }} {{ vehicle.model }}<span v-if="vehicle.year"> · {{ vehicle.year }}</span> · {{ vehicle.plate_number }}</p>
                    <p class="text-xs ui-subtle">{{ vehicle.customer?.name }}<span v-if="vehicle.customer?.phone"> · {{ vehicle.customer.phone }}</span></p>
                </td>
                <td class="p-3 text-right">
                    <div class="flex items-center justify-end gap-2">
                        <Button variant="ghost" size="icon" @click="openEdit(vehicle)"><Pencil class="h-4 w-4" /></Button>
                        <Button variant="ghost" size="icon" @click="remove(vehicle)"><Trash2 class="h-4 w-4 text-destructive" /></Button>
                    </div>
                </td>
            </tr>
        </DataTable>

        <VehicleFormDialog v-model:open="dialogOpen" :vehicle="editing" :company-id="companyId" :tenant-slug="tenantSlug" :customers="customers as unknown as Array<{ id: number; name: string; phone: string | null }>" @saved="load" />
    </Card>
</template>
