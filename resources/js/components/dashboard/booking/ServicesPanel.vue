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
import { Switch } from '../../ui/switch';
import ServiceFormDialog, { type ServiceRow } from './ServiceFormDialog.vue';

const props = defineProps<{ companyId: number; tenantSlug: string }>();
const locale = useLocaleStore();

const services = ref<ServiceRow[]>([]);
const resources = ref<Array<{ id: number; name: string }>>([]);
const loading = ref(true);
const dialogOpen = ref(false);
const editing = ref<ServiceRow | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const [servicesRes, resourcesRes] = await Promise.all([
            apiRequest<{ data: ServiceRow[] }>('/api/services', { tenant: props.tenantSlug }),
            apiRequest<{ data: Array<{ id: number; name: string }> }>('/api/resources', { tenant: props.tenantSlug }),
        ]);
        services.value = servicesRes.data;
        resources.value = resourcesRes.data;
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

function openEdit(service: ServiceRow): void {
    editing.value = service;
    dialogOpen.value = true;
}

async function toggleActive(service: ServiceRow): Promise<void> {
    const next = ! service.is_active;
    service.is_active = next;
    try {
        await apiRequest(`/api/services/${service.id}`, { method: 'PATCH', body: { is_active: next }, tenant: props.tenantSlug });
        toast.success(next ? 'Услуга включена' : 'Услуга выключена');
    } catch (error) {
        service.is_active = ! next;
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}

async function remove(service: ServiceRow): Promise<void> {
    if (! confirm(service.name + '?')) return;
    try {
        await apiRequest(`/api/services/${service.id}`, { method: 'DELETE', tenant: props.tenantSlug });
        services.value = services.value.filter((s) => s.id !== service.id);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}

function prepaymentLabel(service: ServiceRow): string {
    if (service.prepayment_type === 'none') return locale.t('booking.prepaymentNone');
    if (service.prepayment_type === 'fixed') return `${service.prepayment_value} (${locale.t('booking.prepaymentFixed').toLowerCase()})`;
    if (service.prepayment_type === 'percent') return `${service.prepayment_value}%`;
    return locale.t('booking.prepaymentFull');
}
</script>

<template>
    <Card :title="locale.t('booking.tabServices')">
        <template #actions>
            <Button size="sm" @click="openCreate"><Plus class="h-4 w-4" />{{ locale.t('booking.addService') }}</Button>
        </template>

        <DataTable
            embedded
            :loading="loading"
            :row-count="services.length"
            :column-count="3"
            :empty-message="locale.t('booking.noServices')"
            min-width="min-w-full"
        >
            <template #thead>
                <th class="p-3">{{ locale.t('booking.tabServices') }}</th>
                <th class="p-3 text-right">{{ locale.t('common.price') }}</th>
                <th class="p-3 text-right">{{ locale.t('common.actions') }}</th>
            </template>

            <tr v-for="service in services" :key="service.id">
                <td class="p-3">
                    <p class="text-sm font-medium ui-text">{{ service.name }}</p>
                    <p class="text-xs ui-subtle">{{ service.duration_minutes }} {{ locale.t('booking.minutesUnit') }} · {{ prepaymentLabel(service) }}</p>
                </td>
                <td class="p-3 text-right"><Money :value="service.price" tone="lg" /></td>
                <td class="p-3 text-right">
                    <div class="flex items-center justify-end gap-2">
                        <Switch :model-value="service.is_active" @update:model-value="toggleActive(service)" />
                        <Button variant="ghost" size="icon" @click="openEdit(service)"><Pencil class="h-4 w-4" /></Button>
                        <Button variant="ghost" size="icon" @click="remove(service)"><Trash2 class="h-4 w-4 text-destructive" /></Button>
                    </div>
                </td>
            </tr>
        </DataTable>

        <ServiceFormDialog v-model:open="dialogOpen" :service="editing" :company-id="companyId" :tenant-slug="tenantSlug" :resources="resources" @saved="load" />
    </Card>
</template>
