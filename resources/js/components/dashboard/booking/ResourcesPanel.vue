<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { Skeleton } from '../../ui/skeleton';
import ResourceFormDialog, { type ResourceRow } from './ResourceFormDialog.vue';

const props = defineProps<{ companyId: number; tenantSlug: string }>();
const locale = useLocaleStore();

const resources = ref<ResourceRow[]>([]);
const loading = ref(true);
const dialogOpen = ref(false);
const editing = ref<ResourceRow | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const res = await apiRequest<{ data: ResourceRow[] }>('/api/resources', { tenant: props.tenantSlug });
        resources.value = res.data;
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
    <Card :title="locale.t('booking.tabResources')">
        <template #actions>
            <Button size="sm" @click="openCreate"><Plus class="h-4 w-4" />{{ locale.t('booking.addResource') }}</Button>
        </template>

        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 3" :key="i" class="h-12 rounded-lg" />
        </div>
        <p v-else-if="! resources.length" class="pb-4 text-sm ui-subtle">{{ locale.t('booking.noResources') }}</p>
        <div v-else class="divide-y divide-border pb-2">
            <div v-for="resource in resources" :key="resource.id" class="flex items-center justify-between gap-3 py-3">
                <div>
                    <p class="text-sm font-medium ui-text">{{ resource.name }}</p>
                    <p class="text-xs ui-subtle">{{ locale.t('booking.resourceTypes.' + resource.type) }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="ghost" size="icon" @click="openEdit(resource)"><Pencil class="h-4 w-4" /></Button>
                    <Button variant="ghost" size="icon" @click="remove(resource)"><Trash2 class="h-4 w-4 text-destructive" /></Button>
                </div>
            </div>
        </div>

        <ResourceFormDialog v-model:open="dialogOpen" :resource="editing" :company-id="companyId" :tenant-slug="tenantSlug" @saved="load" />
    </Card>
</template>
