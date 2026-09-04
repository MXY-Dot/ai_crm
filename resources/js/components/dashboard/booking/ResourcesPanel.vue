<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import DataTable from '../DataTable.vue';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { Money } from '../../ui/money';
import ResourceFormDialog, { type ResourceRow } from './ResourceFormDialog.vue';

const props = defineProps<{ companyId: number; tenantSlug: string; type?: string; title?: string }>();
const locale = useLocaleStore();

const resources = ref<ResourceRow[]>([]);
const branches = ref<Array<{ id: number; name: string }>>([]);
const loading = ref(true);
const dialogOpen = ref(false);
const editing = ref<ResourceRow | null>(null);

const panelTitle = computed(() => props.title ?? locale.t('booking.tabResources'));

async function load(): Promise<void> {
    loading.value = true;
    try {
        const params = new URLSearchParams();
        if (props.type) params.set('type', props.type);
        const [resourcesRes, branchesRes] = await Promise.all([
            apiRequest<{ data: ResourceRow[] }>('/api/resources?' + params, { tenant: props.tenantSlug }),
            apiRequest<{ data: Array<{ id: number; name: string }> }>('/api/branches', { tenant: props.tenantSlug }),
        ]);
        resources.value = resourcesRes.data;
        branches.value = branchesRes.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

onMounted(load);
watch(() => props.type, load);

function openCreate(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function openEdit(resource: ResourceRow): void {
    editing.value = resource;
    dialogOpen.value = true;
}

async function remove(resource: ResourceRow): Promise<void> {
    if (! confirm(resource.name + '?')) return;
    try {
        await apiRequest(`/api/resources/${resource.id}`, { method: 'DELETE', tenant: props.tenantSlug });
        resources.value = resources.value.filter((r) => r.id !== resource.id);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}
</script>

<template>
    <Card :title="panelTitle">
        <template #actions>
            <Button size="sm" @click="openCreate"><Plus class="h-4 w-4" />{{ locale.t('booking.addResource') }}</Button>
        </template>

        <DataTable
            embedded
            :loading="loading"
            :row-count="resources.length"
            :column-count="2"
            :empty-message="locale.t('booking.noResources')"
            min-width="min-w-full"
        >
            <template #thead>
                <th class="p-3">{{ panelTitle }}</th>
                <th class="p-3 text-right">{{ locale.t('common.actions') }}</th>
            </template>

            <tr v-for="resource in resources" :key="resource.id">
                <td class="p-3">
                    <p class="text-sm font-medium ui-text">{{ resource.name }}</p>
                    <p class="text-xs ui-subtle">
                        {{ locale.t('booking.resourceTypes.' + resource.type) }}
                        <span v-if="resource.capacity"> · {{ resource.capacity }} {{ resource.type === 'room' ? locale.t('hotel.guestsUnit') : locale.t('restaurant.seatsUnit') }}</span>
                        <span v-if="resource.branch_id"> · {{ branches.find((b) => b.id === resource.branch_id)?.name }}</span>
                    </p>
                    <p v-if="resource.price_per_night" class="text-xs ui-subtle"><Money :value="resource.price_per_night" tone="muted" /> / {{ locale.t('hotel.night') }}</p>
                </td>
                <td class="p-3 text-right">
                    <div class="flex items-center justify-end gap-2">
                        <Button variant="ghost" size="icon" @click="openEdit(resource)"><Pencil class="h-4 w-4" /></Button>
                        <Button variant="ghost" size="icon" @click="remove(resource)"><Trash2 class="h-4 w-4 text-destructive" /></Button>
                    </div>
                </td>
            </tr>
        </DataTable>

        <ResourceFormDialog v-model:open="dialogOpen" :resource="editing" :company-id="companyId" :tenant-slug="tenantSlug" :branches="branches" :default-type="type" @saved="load" />
    </Card>
</template>
