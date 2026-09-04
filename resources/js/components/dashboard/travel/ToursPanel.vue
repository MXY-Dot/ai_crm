<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import DataTable from '../DataTable.vue';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { Money } from '../../ui/money';
import TourFormDialog, { type TourRow } from './TourFormDialog.vue';

const props = defineProps<{ companyId: number; tenantSlug: string }>();
const locale = useLocaleStore();

const tours = ref<TourRow[]>([]);
const loading = ref(true);
const dialogOpen = ref(false);
const editing = ref<TourRow | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const data = await apiRequest<{ data: TourRow[] }>('/api/tours', { tenant: props.tenantSlug });
        tours.value = data.data;
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

function openEdit(tour: TourRow): void {
    editing.value = tour;
    dialogOpen.value = true;
}

async function remove(tour: TourRow): Promise<void> {
    if (! confirm(tour.name + '?')) return;
    try {
        await apiRequest(`/api/tours/${tour.id}`, { method: 'DELETE', tenant: props.tenantSlug });
        tours.value = tours.value.filter((t) => t.id !== tour.id);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}
</script>

<template>
    <Card :title="locale.t('travel.tabTours')">
        <template #actions>
            <Button size="sm" @click="openCreate"><Plus class="h-4 w-4" />{{ locale.t('travel.addTour') }}</Button>
        </template>

        <DataTable
            embedded
            :loading="loading"
            :row-count="tours.length"
            :column-count="3"
            :empty-message="locale.t('travel.noTours')"
            min-width="min-w-full"
        >
            <template #thead>
                <th class="p-3">{{ locale.t('travel.tabTours') }}</th>
                <th class="p-3 text-right">{{ locale.t('common.price') }}</th>
                <th class="p-3 text-right">{{ locale.t('common.actions') }}</th>
            </template>

            <tr v-for="tour in tours" :key="tour.id">
                <td class="p-3">
                    <p class="text-sm font-medium ui-text">{{ tour.name }}<span v-if="tour.destination" class="font-normal ui-subtle"> · {{ tour.destination }}</span></p>
                    <p v-if="tour.duration_days" class="text-xs ui-subtle">{{ tour.duration_days }} {{ locale.t('travel.daysUnit') }}</p>
                </td>
                <td class="p-3 text-right"><Money :value="tour.price" tone="muted" /></td>
                <td class="p-3 text-right">
                    <div class="flex items-center justify-end gap-2">
                        <Button variant="ghost" size="icon" @click="openEdit(tour)"><Pencil class="h-4 w-4" /></Button>
                        <Button variant="ghost" size="icon" @click="remove(tour)"><Trash2 class="h-4 w-4 text-destructive" /></Button>
                    </div>
                </td>
            </tr>
        </DataTable>

        <TourFormDialog v-model:open="dialogOpen" :tour="editing" :company-id="companyId" :tenant-slug="tenantSlug" @saved="load" />
    </Card>
</template>
